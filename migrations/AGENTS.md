# Migrations

- Migrations are per entity manager: `migrations/database` (the PostgreSQL ledger) and `migrations/application`
  (the PostgreSQL website and projection).
- They run on container start; `make migrate` runs all sets by hand.
- Each set is a file in `config/packages/migrations/` naming its own connection, so every command passes
  `--configuration=config/packages/migrations/{database,application}.yaml` and **never `--em`**.
- The PostgreSQL connections `SET ROLE` (`src/Doctrine/Middleware/`) to ensure that anything created by the
  migrations is properly owned.
- A ledger schema change needs its projection counterpart (see `src/Entity/AGENTS.md`).
- The application set's initial migration also creates the `messenger_messages` table (no entity maps it; the
  messenger schema listener injects it into the application schema only, because that is where its transport
  points) and the `unaccent` extension; its drift comparison excludes that table.
- Tests: locally `make test-prepare` rebuilds the ledger schema from the mapping and runs the application set from
  its migrations; CI builds both from the migrations (`.github/workflows/unit-tests.yml`), so a migration that
  disagrees with the mapping shows up in CI.
