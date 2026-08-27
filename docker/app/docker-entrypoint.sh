#!/bin/sh
set -e

# Waits for the Doctrine connection named in $1 to answer. Both databases are waited for before anything is migrated:
# the ledger and the web database are equally required, and a container that comes up against one that is not ready
# fails on its first request instead of at startup, where it can be seen.
wait_for_database() {
	echo "Waiting for the $1 database to be ready..."
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q --connection="$1" "SELECT 1" 2>&1); do
		if [ $? -eq 255 ]; then
			# If the Doctrine command exits with 255, an unrecoverable error occurred
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
		echo "Still waiting for the $1 database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
	done

	if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
		echo "The $1 database is not up or not reachable:"
		echo "$DATABASE_ERROR"
		return 1
	fi

	echo "The $1 database is now ready and reachable"
}

# Where the entrypoint records that it came up without migrating, so the healthcheck stays red and the workers,
# which wait on it, stay down.
MIGRATIONS_SKIPPED_MARKER=/app/var/.migrations-skipped

# Clears the marker above once both databases answer, but only after establishing that neither has a migration
# pending, which outside a deploy is the normal case. So a container that started during an outage heals itself
# without a migration ever running unattended; an interrupted deploy, which is the case that has work to do, keeps
# the marker and waits for somebody.
clear_marker_when_nothing_to_migrate() {
	while [ -f "$MIGRATIONS_SKIPPED_MARKER" ]; do
		sleep 60

		php bin/console dbal:run-sql -q --connection=default "SELECT 1" >/dev/null 2>&1 || continue
		php bin/console dbal:run-sql -q --connection=web "SELECT 1" >/dev/null 2>&1 || continue

		if
			! php bin/console doctrine:migrations:up-to-date --configuration=config/packages/migrations/default.yaml >/dev/null 2>&1 \
			|| ! php bin/console doctrine:migrations:up-to-date --configuration=config/packages/migrations/web.yaml >/dev/null 2>&1
		then
			echo "Both databases answer, but a migration is pending: this container started in the middle of a deploy. Restart it to migrate."
			return 0
		fi

		echo "Both databases answer and nothing is pending to migrate; the container is healthy again."
		rm -f "$MIGRATIONS_SKIPPED_MARKER"
		return 0
	done
}

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    if [ "$APP_ENV" = "dev" ]; then
        if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
            composer install --no-cache --prefer-dist --no-progress --no-interaction
        fi

        # AssetMapper serves whatever is in public/assets/ in preference to the live files, so the previous run's
        # compiled output has to go. Nothing recreates it here: in development the asset map is served on demand, and
        # `asset-map:compile` belongs to the production build.
        rm -rf public/assets/

        # One watcher per compiled language. Sass alone leaves TypeScript compiled once and never again, which reads
        # as a Stimulus controller whose changes do not take until the container is restarted.
        php bin/console sass:build --watch > /dev/stdout 2>&1 &
        php bin/console typescript:build --watch > /dev/stdout 2>&1 &
        php bin/console importmap:install
    fi

	# Display information about the application or errors during initialization
	php bin/console -V

	# Not reaching a database is no longer fatal: exiting here means a restart during an outage crash-loops the
	# container, so nothing serves the maintenance page and nothing reports why.
	rm -f "$MIGRATIONS_SKIPPED_MARKER"
	DATABASES_REACHABLE=1

	if [ -n "$DATABASE_DSN" ]; then
		wait_for_database default || DATABASES_REACHABLE=0
		wait_for_database web || DATABASES_REACHABLE=0
	fi

	# The image is built with `cache:clear --no-optional-warmers` (see Dockerfile): the optional warmers instantiate
	# both entity managers and the cache pools, none of which the build has the environment for. It does exist here,
	# so the caches are built once at startup rather than by whichever request happens to need them first.
	if [ "$APP_ENV" = "prod" ]; then
		php bin/console cache:warmup
	fi

	# `app` is the single container that migrates. SKIP_MIGRATIONS is left as a way to start one that does not, for
	# an operator who needs the application up while the schema is being dealt with by hand; nothing sets it. That
	# is a deliberate skip and leaves no marker, unlike an unreachable database.
	if [ "$DATABASES_REACHABLE" -eq 0 ]; then
		echo "Starting without migrating: a database could not be reached. Serving (the maintenance page, if MAINTENANCE is set) but reporting unhealthy, so no worker starts."
		touch "$MIGRATIONS_SKIPPED_MARKER"
		clear_marker_when_nothing_to_migrate &
	elif [ -n "$DATABASE_DSN" ] && [ -z "$SKIP_MIGRATIONS" ]; then
		# One set per database, each naming its own connection; a command given no configuration finds none.
		for set in database web; do
			if find "./migrations/$set" -iname '*.php' -print -quit | grep --quiet .; then
				case "$set" in
					database) configuration=config/packages/migrations/default.yaml ;;
					web) configuration=config/packages/migrations/web.yaml ;;
				esac

				php bin/console doctrine:migrations:migrate \
					--no-interaction \
					--all-or-nothing \
					--configuration="$configuration"
			fi
		done
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
