# Testing

PHPUnit runs inside the `app` container against isolated copies of both databases: real PostgreSQL and MariaDB, because both are production and a substitute like SQLite cannot reproduce the custom DQL functions (`RAND`, `YEAR`), `EditLockService`'s named locks, the UUID and `uca1400` columns, or what the ledger asks of PostgreSQL.

## Commands

```
make test-prepare      # (re)build both schemas from the mapping and load the seed; once, and after schema/fixture changes
make test              # the ordinary suite; excludes Browser/
make test-browser      # the Panther e2e suite; re-seeds when the run ends
```

Run a single test through the container, e.g. `make exec cmd="bin/phpunit --filter SomeTest"` or a path. For the browser suite always use the make target, never a bare `bin/phpunit`.

## Ordinary tests

- Integration tests boot the kernel and grab the managers (`tests/Integration/DatabaseTestCase`). **Query the seeded fixtures; don't hand-build entity graphs.**
- `dama/doctrine-test-bundle` wraps each test in a transaction and rolls it back, so one schema and one seed serve the whole run and tests share the seed without leaking writes.
- Pure domain logic (mappers, voters, guard listeners, enums) stays a DB-less `TestCase`.
- CI builds the test databases from migrations instead of the mapping.
- `tests/` mostly mirrors `src/`.

## Browser tests (`tests/Browser/`)

A real Chromium against a real server (`symfony/panther`, extending `BrowserTestCase`). They cover what a page does once its JavaScript has run: an editor surviving a cache restore, a lock released on navigation, a live component still responding. A test that only renders a template cannot see any of that.

- **A change to interactive or user-facing behaviour MUST be covered by a browser test.** A unit or kernel test alone is not sufficient. Browser tests are the default for user-facing behaviour; DB-less and kernel tests are for logic a browser test cannot reach cheaply.
- They are a testsuite of their own because each one starts Chromium and a web server. CI runs them after the ordinary suite.
- The server the browser drives has a connection of its own, outside the transaction every other test is rolled back with. Read the seeded fixtures rather than building data, treat anything a test writes as permanent, and sign in through the real form: the kernel-level sign-in helpers authenticate in this process and reach none of the server.
- Do not verify forms, login or sign-up with `curl` (stateless CSRF); use this suite.
