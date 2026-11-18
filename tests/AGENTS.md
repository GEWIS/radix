# Testing

PHPUnit runs inside the `app` container against isolated copies of both databases on real PostgreSQL, because both are production and a substitute like SQLite cannot reproduce the custom DQL functions (`RAND`, `YEAR`), `EditLockService`'s advisory locks, the UUID columns, or what the ledger asks of PostgreSQL.

## Commands

```
make test-prepare      # (re)build both schemas from the mapping and load the seed; once, and after schema/fixture changes
make test              # the ordinary suite; excludes Browser/
make test-browser      # the Panther e2e suite; re-seeds when the run ends
make test-coverage     # the ordinary suite's coverage of src/, as CI measures it
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

## Coverage (`tests/Coverage/`)

The total is measured over the whole of `src/` from the ordinary suite's Clover report: PHPUnit analyses every file in the source filter, so a class no test loads counts as uncovered. The browser suite is not part of it, because it runs in a server process of its own. Whatever a browser test drives should already be covered by a unit or integration test.

- CI records the summary on `main` and, on a pull request, annotates the files whose coverage fell and prints the comparison in the step summary. A drop does not fail the build; `tests/Coverage/check.php --fail-on-drop` enables that once the normal movement is known. A missing baseline (the first run, or an artifact past 30 days) is reported and skipped.
- Line coverage is not a standard by itself: a test that asserts nothing raises it. The suite above, and the browser test required for user-facing behaviour, remain the standard.
- Reproduce CI's number with `make test-coverage`. Both use PCOV, so the per-file HTML report is `php -d memory_limit=-1 bin/phpunit --coverage-html ./coverage` inside the container.
