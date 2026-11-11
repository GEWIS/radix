<div align="center">
    <h1>radix - The GEWIS Website and Decision & Membership Database</h1>

<!-- Shield group -->
[![Latest Release](https://img.shields.io/github/v/release/GEWIS/radix)](https://github.com/GEWIS/radix/releases)
[![Build](https://img.shields.io/github/check-runs/GEWIS/radix/main)](https://github.com/GEWIS/radix/actions)
[![Uptime](https://uptime.gewis.nl/api/badge/1/uptime)](https://gewis.nl/en/)
[![Issues](https://img.shields.io/github/issues/GEWIS/radix)](https://github.com/GEWIS/radix/issues)
[![Commit Activity](https://img.shields.io/github/commit-activity/m/GEWIS/radix/main)](https://github.com/GEWIS/radix/commits/main)
[![License](https://img.shields.io/github/license/GEWIS/radix.svg)](./LICENSE.txt)

<p>radix is the <a href="https://gewis.nl" target="_blank">website</a> and the decision and membership database of GEWIS - <em>GEmeenschap van Wiskunde en Informatica Studenten</em> - in a single application. It replaced GEWISWEB and GEWISDB, which were separate applications; a sync script copied the data directly from GEWISDB to GEWISWEB.</p>
</div>

## What radix Is
Two things about the same association, once two applications, are now one:

- **The register.** Every meeting, decision, member, body and mailing list of the association. This is the ledger: the
  authoritative record of what GEWIS decided and who its members are, written by the board and read by every other
  GEWIS system through the API.
- **The website.** Activities, photos, course documents, custom pages, and the careers portal, for members and for the
  world.

The website shows the association's decisions, the bodies that exist because of them, and the members installed in
those bodies, and it reads them from the application that records them.

So there are two databases, and the split matters for anything that touches data:

- **PostgreSQL** stores the ledger - the editable source of truth, behind the `default` connection.
- **MariaDB** stores everything else, behind the `web` connection: the website's own data, and the projection of the
  ledger that the site and the API read from.

## Features
The website has the following features:

- **Activities**: members create activities with sign-up lists and their options, such as capacity limits and
  waitlists, and join them through the activity pages.
- **Career**: companies that collaborate with GEWIS publish vacancies, and members browse them and sign up.
- **Decisions**: visitors read the association's decisions, meetings and bodies.
- **Education**: an archive of course documents, such as exams and summaries, grouped by course.
- **Pages**: custom pages written by the board for content and announcements that do not belong to a fixed section.
- **Photos**: albums of pictures from activities, which members can add to.

The register has the following parts:

- **Meetings and decisions**: the meetings that take place in the association, and the decisions taken in them, from
  budgets and statements to the installation of members in bodies. A decision is corrected when it needs to change,
  not rewritten, so the record stays what was decided.
- **Memberships**: the join page that [join.gewis.nl](https://join.gewis.nl) points to, new memberships, and the
  membership fee, collected with Stripe. Student information is checked against the student administration, and
  member data is edited in the administration.
- **Checker**: the consistency checks derived from the Articles of Association and the Internal Regulations, run
  over the data in the register. It reports a violation when, for example, a member is installed in a body while
  their membership has expired.
- **API**: the projection of the ledger, exposed to the other GEWIS systems. It covers members, bodies, board and
  body installations, keyholders, mailing lists, activities and photos, so the applications that use it do not
  connect to AD or LDAP. A token is limited to the permissions it was given, down to individual properties of a
  member.

## Getting Started
radix is a PHP application on the [Symfony framework](https://symfony.com/).

### Prerequisites
We recommend developing natively on a Linux machine or through WSL2 on Windows (note: Arch-based distributions are **not** recommended) with the [PhpStorm](https://www.jetbrains.com/phpstorm/) IDE or another IDE with good support for PHP.<br/>
Alternatively, you can use [GitHub Codespaces](https://github.com/codespaces/new?hide_repo_select=false&repo=gewis/radix&geo=EuropeWest&machine=basicLinux32gb).

You will need at least:
- `docker` and `docker compose` (make sure that you have enabled [Buildkit](https://docs.docker.com/build/buildkit/#getting-started))
- `git`
- `make`
- A `.xlf` file editor (e.g. POEdit)

PHP, Composer, and all other runtime tooling are in the Docker image, no need to install them yourself.

It is possible to use [rootless docker](https://docs.docker.com/engine/security/rootless/) on many Linux systems. For this, install `uidmap`, ensure IP forwarding is enabled, run `dockerd-rootless-setuptool.sh install` and set the `DOCKER_HOST` variable in your profile (e.g. `.bashrc`). Note that a rootless daemon cannot bind a port below 1024, which is where the application (80) and Matomo (82) are published by default. Move them in your `.env.local`:

```
APP_PORT=8000
APP_URL=http://localhost:8000
MATOMO_PORT=8082
```

`APP_URL` is what the URLs in that file are built from, so the application is reached on `http://localhost:8000/` and everywhere the documentation below says `http://localhost/` you read that instead. Alternatively, allow the low ports on the host once with `sudo sysctl net.ipv4.ip_unprivileged_port_start=80` (persist it in `/etc/sysctl.d/`) and leave the ports as they are.

### Installation
To set up radix locally, follow these steps:

1. [Fork the repository](https://github.com/GEWIS/radix/fork).
2. Clone your fork (`git clone git@github.com:{username}/radix.git`).
3. Run `make start` to build and serve the application (a `.env.local` will be created for you; alter it to your needs). The first build may take 5-10 minutes.
4. Run `make seed` to get some test data (migrations will run automatically).
5. Go to [`http://localhost/`](http://localhost/) in your browser to see the GEWIS website.
6. Log in with membership number `8000` and the password `gewiswebgewis`.
7. The register is part of the administration, under [`http://localhost/en/admin/`](http://localhost/en/admin/); the same sign-in works there, and member `8000` holds both the website's and the register's administrator roles.

#### Other Accessible Services
During development, several other services are accessible on your local machine:

- **phpMyAdmin** - Management interface for MariaDB at [`http://localhost:8080/`](http://localhost:8080/).
- **pgAdmin** - Management interface for PostgreSQL at [`http://localhost:8081/`](http://localhost:8081/).
- **MailPit** - Email testing at [`http://localhost:8025/`](http://localhost:8025/).
- **Mailman** - Mailing list management at [`http://localhost:8021/`](http://localhost:8021/) (its REST API is on `8020`).
- **Listmonk** - Newsletter management at [`http://localhost:8022/`](http://localhost:8022/).
- **RabbitMQ** - Message broker management at [`http://localhost:15672/`](http://localhost:15672/).
- **Matomo** - Analytics platform at [`http://localhost:82/`](http://localhost:82/).

### Deployment
Production runs the image `abc.docker-registry.gewis.nl/web/radix/app` through Docker, deployed with Portainer.
`compose.yaml` *is* the production stack; `compose.override.yaml` is the development part of it, and
`docker compose up` in a checkout merges the two. Portainer names `compose.yaml` on its own, which is also what stops
compose merging the development override. Every variable is guarded with `${VAR:?...}`, so one that Portainer's stack
environment is missing aborts the deploy rather than using a development default. The single `app` container
runs the migrations on start.

Values that rarely differ between deployments are passed the same way, but with a default instead of a guard: the mail
display names, the pinned Stripe and Mailman API versions, the watermark tag, the TU/e subnets. `config/services.yaml`
defaults each of them as well, which is the default for a run outside the stack, so setting one in the stack
environment is all it takes to change it.

### Contributing
Contributions from GEWIS members and others are welcome. To contribute:

1. Perform the steps from [Installation](#installation).
2. Create a branch for your feature or bug fix (`git switch -c feature/my-amazing-feature`).
3. Commit your changes (`git commit -m 'feat: added my amazing feature'`). <ins>**NOTE:** radix requires commits to be signed, see [this GitHub article](https://docs.github.com/en/authentication/managing-commit-signature-verification/signing-commits) for more information on how to sign commits.</ins>
4. Push to the branch (`git push origin feature/my-amazing-feature`).
5. Open a pull request.

> [!NOTE]
> More detailed information on GEWIS' contribution guidelines, including conventions on branch names and commit messages, can be found in the [contribution guidelines](https://github.com/GEWIS/.github/blob/main/CONTRIBUTING.md).

> [!NOTE]
> The [AI contribution policy](./AI_POLICY.md) governs how AI tools may be used on contributions to this repository. AI tools may help with exploration and code, but the prose you submit, and the understanding of what you submit, must be your own.

### Useful Commands During Development
While developing, you will mostly use these commands from the Makefile:

- `make bash` - Shell into the FrankenPHP `app` container.
- `make sf c='...'` - Run a Symfony console command inside the container (e.g. `make sf c=check:database`).
- `make composer c='...'` - Run a Composer command inside the container (e.g. `make composer c=update`).
- `make translations` - Extract translatable strings into the `.xlf` files. Run this whenever you add or edit a user-facing string in PHP, Twig, or a form type.
- `make lint` / `make lint-fix` - Run PHP_CodeSniffer (or PHPCBF to autofix) against the project's coding standard.
- `make lint-twig` - Validate the Twig templates.
- `make phpstan` - Perform static analysis using PHPStan.
- `make igor` - Run Igor to validate the codebase for FrankenPHP's worker mode.
- `make cc` - Clear the cache and restart the worker.

For a complete list of available commands, run `make help`.

> [!TIP]
> If you are using an AI coding tool (Claude Code, Copilot, Cursor, ...), it reads `AGENTS.md` automatically. That file documents the architecture, conventions and pitfalls in more depth than this README, and it is for people as well as for tools.

### Testing
The test suite runs with PHPUnit, inside the `app` container, against isolated copies of both databases:

- `make test-prepare` - Build the test schemas and load the seed into them. Run this once, and again after a schema or fixture change; the tests roll back their own writes, so one seed serves the whole run.
- `make test` - Run the suite. Pass `c=` for PHPUnit options, for example `make test c="--stop-on-failure"`.
- `make test-browser` - Run the browser tests, which drive a real Chromium against a server of their own (Symfony Panther). They are excluded from the ordinary run, and because that server writes outside the transaction the other tests roll back, the target re-seeds the databases when the run ends, passed or failed.

Tests that need a database run against real PostgreSQL and MariaDB matching production rather than an in-memory
substitute, because the schema uses features of both databases that an in-memory database does not implement.

When a change touches what a page does once its JavaScript has run (a form flow, an editor, a live component, a
lock), prefer a test in `tests/Browser/` over a unit test: the rest of the suite renders the template and stops, so
it cannot say what the page does afterwards.

Beyond the tests, `make sf c=check:database` runs the consistency checks derived from the Articles of Association and
Internal Regulations over the register. It is worth running after anything that changes decisions or installations.

### Project Structure
The important folders:

```txt
./
├── assets                  # Front-end sources (Sass, TypeScript Stimulus controllers).
├── config                  # Global configuration files for the application.
├── data                    # Persistent private data-related files, such as cryptographic keys and logs.
├── docker                  # Docker-related files to construct the containers.
├── migrations              # Doctrine migrations, one directory per database.
├── public                  # Publicly accessible files, including the entry point (index.php).
├── src                     # The application itself, grouped by the role each class plays.
├── templates               # Twig templates.
├── tests                   # The test suite.
└── translations            # The `.xlf` translation files.
```

Within `src`, classes are grouped by what they do rather than by feature: `Controller`, `Service`, `Entity`,
`Repository`, `Form`, `Twig`, and so on. Each of those is then split by domain - `Database` for the ledger of what the
association decided and who its members are, `Decision` for the projection of it that the site and the API read,
`Report` for the machinery that keeps that projection level with the ledger, `Checker` for the consistency checks,
`Activity`, `Career`, `Education`, `Frontpage` and `Photo` for the website's own features, `User` for accounts and the
API, and `Application` for what is shared.

### Testing Stripe Behaviour
The Stripe API needs the following additional configuration:

* Create a restricted key on https://dashboard.stripe.com/test/apikeys and set it in the `STRIPE_SECRET_KEY` environment variable (check `.env.local.dist` for which permissions to grant it)
* Copy the publishable key from https://dashboard.stripe.com/test/apikeys and set it in the `STRIPE_PUBLISHABLE_KEY` environment variable
* Copy the webhook signing secret from the output of `make stripewebhooksecret`
* Create a product with a one-off price on https://dashboard.stripe.com/test/products?active=true and copy its price ID to `STRIPE_MEMBERSHIP_PRICE_ID`

Tip: to reduce the wait for a checkout session to expire, run `docker compose exec stripe stripe checkout sessions expire cs_test_fromcheckoutsessionstable`. Cash payment is then enabled and the retry email is sent.

Note: the links in the e-mails do not resolve in the development setup. Replace the host with `http://localhost/` to follow them.

### Using the API
To experiment with the API, import the `openapi.yaml` file into your favourite REST client. That file is generated
from the application by `make openapi` and is never edited by hand; a running deployment serves the same document at
`GET /api/docs.json`.

A running deployment also serves Swagger UI at [`/api-docs`](http://localhost/api-docs), where you can paste a token
and try the endpoints.

Every endpoint is read with the token of an API principal, created under **Users → API principals** in the
administration, and is limited to the permissions that principal was given. Collections are paged: `?page=` and
`?itemsPerPage=` (100 by default, 500 at most), with the totals in the response's `meta`.

Everything except the oldest member endpoints needs the contract version, as either
`Accept: application/vnd.gewis.gewisdb+json;version=5.0.0` or `X-Api-Version: 5.0.0`.

Alternatively, you can use PowerShell, for example:

```powershell
((Invoke-WebRequest -Uri http://localhost/api/organFunctions -Headers @{"Authorization" = "Bearer APITOKEN"; "Accept" = "application/vnd.gewis.gewisdb+json;version=5.0.0"}).Content | ConvertFrom-Json).data | Format-List
```

## License
This software is licensed under the GNU General Public License v3.0 (GPL-3.0), see [LICENSE](./LICENSE.txt).
