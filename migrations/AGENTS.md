# Migrations

- Migrations are per entity manager: `migrations/database` (the PostgreSQL ledger) and `migrations/web` (MariaDB).
- They run on container start; `make migrate` runs both by hand.
- Each set is a file in `config/packages/migrations/` naming its own connection, so every command passes `--configuration=config/packages/migrations/{default,web}.yaml` and **never `--em`**.
- The PostgreSQL connections `SET ROLE` (`src/Doctrine/Middleware/`) to ensure that anything created by the migrations is properly owned.
- A ledger schema change needs its projection counterpart (see `src/Entity/AGENTS.md`).
- Tests: locally `make test-prepare` rebuilds both schemas from the mapping; CI builds them from the migrations (`.github/workflows/unit-tests.yml`), so a migration that disagrees with the mapping shows up in CI.
