# AGENTS.md

Operating manual for AI coding agents working in this repository. RFC 2119 keywords (MUST, MUST NOT, SHOULD, MAY) are normative. Humans should read `README.md` first; `AI_POLICY.md` governs how contributions are made. If this file and `README.md` disagree, say so in your report and follow this file for how to work, the README for what the project is.

**Nested `AGENTS.md` files** sit next to the code they describe (`src/Security/`, `src/Workflow/`, `src/ApiResource/`, `src/Controller/`, `src/Entity/`, `src/Service/`, `tests/`, `translations/`, `assets/`, `migrations/`). The nearest one wins. **Read the one for every directory you are about to change before changing it**; this file holds only what applies everywhere.

## What this project is

radix is one Symfony application serving two things that belong to the same association (GEWIS):

- **The register**: meetings, decisions, members, memberships, bodies and mailing lists. The authoritative record of what GEWIS decided and who its members are, written by the board and read by every other GEWIS system through the API.
- **The website**: activities, photos, course documents, custom pages, the careers portal, and the public face of the decisions above.

They used to be two applications (GEWISDB, GEWISWEB) joined by a sync script. Here they are one codebase, one container image, one deployment: two entity managers in the same kernel, no sync command, no second repository.

## Quick start

```
make start      # build images, start the stack; creates .env.local from .env.local.dist if missing
make seed       # load fixtures into both databases, rebuild the projection, prepare Mailman and Listmonk
```

- The app answers at `http://localhost:$APP_PORT` (`APP_PORT` defaults to 80). `GET /health` is the liveness check; Swagger UI is at `/api-docs`. Other services (MailPit, RabbitMQ, Mercure, databases) and their ports are in `compose.yaml`.
- Seeded login: member number `8000`, password `gewiswebgewis`. `8000` holds both `ROLE_ADMIN` and `ROLE_DATABASE_ADMIN`, `8001` only the former, `8002` only the latter. The register is reached with the same account.
- Migrations already ran: they fire one-shot on container start. `make migrate` runs both sets by hand.
- Outbound mail lands in MailPit.
- Hot reload covers almost everything: FrankenPHP reloads workers on source changes and the dev entrypoint watches `assets/`. Reach for `make cc` or a restart only if something genuinely will not budge.

Everyday commands: `make bash` (shell in the `app` container), `make exec cmd="…"`, `make sf c=…`, `make composer c='…'`, `make cc` (clear cache, restart worker), `make logs`, `make stop`. Most targets shell into the `app` container; run tools there, not on the host.

## Definition of done

Run these before reporting work complete. The condition column says when each is required.

| Command | When |
| --- | --- |
| `make lint-fix`, then `make lint` | Always |
| `make phpstan` | Always |
| `make lint-twig` | Templates changed |
| `make igor` | Any non-trivial change (worker-mode safety: state must not leak between requests) |
| `make test` | Always |
| `make test-prepare` first | Schema or fixtures changed (builds both test schemas and the seed) |
| `make test-browser` | Interactive or user-facing behaviour changed (and write the covering browser test) |
| `make openapi` | An API resource, operation or serializer group changed |
| `make translations`, then fill every empty `<target/>` | Any translatable string changed (see `translations/AGENTS.md`) |
| `make sf c=check:database` | Decisions or installations changed |

- **Browser tests are the default test for user-facing behaviour.** A change to a page, form, live component, Stimulus controller or anything else a person interacts with MUST be covered by a browser test in `tests/Browser/` (`make test-browser`), even if your change touches nothing under `tests/`. A unit or kernel test alone only renders the template and cannot see what happens once JavaScript has run. Reserve DB-less and kernel tests for logic a browser test cannot reach cheaply (mappers, voters, guard listeners, enums, query correctness). Read `tests/AGENTS.md` before writing either kind.
- **Smoke-run what you changed.** Exercise the changed path and observe the result; passing tests alone are not proof. For a bug, reproduce before the fix, confirm after, and keep a regression test.
- **Baselines are for legacy debt.** When a new error hits a baseline, fix it; do not extend the baseline. Type-coverage percentages are pinned just under where the codebase stands, so they only go up. `make phpstan-pr` regenerates the baseline against `main` for a PR.
- **Do not verify forms, login or sign-up flows with `curl`.** CSRF is stateless (double-submit cookie plus Origin/Referer checks), so a hand-crafted POST fails by design and a 4xx proves nothing. Use the browser suite, or a real browser against the seeded stack.
- **If you cannot run a gate** (no Docker, no Chromium, a tool unavailable), say which one and why in the final report. Never imply it passed.

## Two databases

Two connections; the distinction governs anything that touches data.

- **`default`**: PostgreSQL, the **ledger**. The editable source of truth: members, prospective members, memberships, meetings, decisions, bodies, mailing lists. Entities in `src/Entity/Database`, migrations in `migrations/database`.
- **`web`**: MariaDB, everything else. The website's own data *and* the **projection** of the ledger that the site and the API read (`src/Entity/Decision`). Migrations in `migrations/web`.

Rules that apply everywhere:

- A change to a ledger entity MUST come with the matching change on the projection side (`src/Entity/Decision`, `src/Service/Report`). Change one without the other and the website quietly renders an association that no longer exists.
- A query MUST NOT span both connections. DQL, joins and associations stay inside one entity manager; ask the other for what you need and match up in PHP.
- The default manager is the ledger's, usually the wrong one. Inject the manager or repository you want; do not let `ManagerRegistry` hand you the default.
- Both the website and the API read the projection, never the ledger directly.
- After a bulk change to the ledger, run `app:decision:generate` to rebuild the projection.

How the projection is maintained, migration commands and caching: `src/Entity/AGENTS.md` and `migrations/AGENTS.md`.

## Stack

`composer.json` is the authority on versions; do not quote them from here.

- PHP 8.5; Symfony 8.1 (framework, security, messenger, scheduler, asset-mapper, ux-live-component, workflow).
- Doctrine ORM 3, attribute mapping, two entity managers, patched at install for sub-decision joins (`composer.json` `extra.patches`).
- FrankenPHP worker mode behind Caddy. Worker-mode safety is non-negotiable: `make igor`.
- API Platform 5 serves almost all of `^/api` alongside hand-written endpoints in `src/Controller/Report`.
- Messenger (RabbitMQ in dev, in-memory in test) and Scheduler; Symfony Workflow for revisions.
- Twig + Stimulus (TypeScript via SWC) + Live Components; Bootstrap, Font Awesome, Sass, asset-mapper. No npm; third-party JS is vendored under `assets/vendor/`.
- Altcha (captcha on public forms), Stripe, Mailman and Listmonk, Mercure, Mailer (MailPit in dev), Notifier, Matomo, `scheb/2fa`.

## Layout

Classes are grouped by the role they play, then by domain. The entity domains are **Activity**, **Application**, **Career**, **Database** (the ledger), **Decision** (the projection; there are no `Report` entities), **Education**, **Frontpage**, **Photo** and **User**. `Report` and `Checker` are machinery without entities: `Report` maintains and serves the projection, `Checker` enforces consistency over the ledger, `Application` is what is shared. A member, a meeting or an organ is an entity *in* a domain, never a domain of its own; there is no `Api` domain.

```
src/
  ApiResource/      API Platform resources, by domain; plain DTOs, excluded from the container
  Command/          #[AsCommand] console commands
  CommonMark/       Markdown rendering extensions
  Controller/       feature controllers
  DataFixtures/     the development seed, by concern
  Doctrine/         custom types, DQL functions, connection middleware
  Entity/           Doctrine entities; domains may add Enums/ and Traits/
  EventListener/    #[AsEventListener] listeners (incl. workflow guards, projection listeners)
  Exception/        domain exceptions
  Form/             Symfony Form types
  Message/          Messenger messages          MessageHandler/  #[AsMessageHandler] handlers
  Monolog/          processors adding context to every record
  Repository/       Doctrine repositories       Scheduler/       Scheduler providers (flat)
  Security/         user checkers, voters, remember-me, API authentication
  Serializer/       serializer context builders and normalizers
  Service/          domain services             State/           API Platform providers and processors
  Twig/             Extensions/ and Components/<Namespace>/ (Live components)
  Util/             small stateless helpers     Validator/       custom constraint validators
  ViewModel/        immutable read models for templates, mirrors the domain structure
  Workflow/         revision-workflow plumbing
config/  migrations/  templates/  translations/  assets/  tests/
```

When adding to a domain, follow the sibling pattern; do not invent a new home. Prefer passing a `ViewModel` to a template over entities or loose arrays when the view needs derived or aggregated data (final readonly classes, no behaviour beyond accessors).

Twig: `templates/components/<Namespace>/` holds Live component templates paired with a class in `src/Twig/Components/<Namespace>/` (names use `:` separators, set explicitly in `#[AsLiveComponent]`; the namespace mirrors `src/Controller/`). `templates/partials/application/` holds stateless `{% include %}` fragments in kebab-case without a leading underscore. Render a component with `{{ component('User:Admin:UsersOverview') }}`.

## Coding style

- `declare(strict_types=1);` in every file, immediately after `<?php`.
- Native type hints on parameters, returns and properties wherever a parent signature allows.
- Yoda comparisons: `null === $x`. Multi-clause `if` conditions, one clause per line.
- Attribute-based wiring throughout; `#[Override]` on inherited methods.
- Doctrine mapping with short-name imports: `use Doctrine\ORM\Mapping\Entity;` and `#[Entity]`, **not** the `ORM\` alias.
- Comments explain decisions and non-obvious mechanics in full sentences, not what the code already says.
- Follow `GEWISPHPCodingStandards` (`phpcs.xml.dist`); `make lint` is authoritative, `make lint-fix` autofixes a subset.
- Dependency injection is pure autowire/autoconfigure from `src/`, with scalar bindings in `config/services.yaml`. **No factory classes**: constructor promotion does the wiring, `readonly` where it holds. If autowire cannot resolve something, define the service explicitly in `config/services.yaml`; do not add a factory.

## Logging

- **Ordinary logging** is `LoggerInterface` on the default channel. In production a `fingers_crossed` handler buffers `debug` and `info` until the same request fails; `warning` and above pass through. Anything that must survive a successful request MUST be at least a `warning`.
- **Account events** (sign-in, session torn down, second factor off, impersonation) never use the plain logger; see `src/Security/AGENTS.md`.

## Configuration variables

`.env` is committed and holds almost nothing (only `APP_ENV`): Docker compose reads it too, so a value there would silently satisfy the `${VAR:?…}` guards in `compose.yaml`, and `composer dump-env prod` would bake it into the production image. So:

- **`config/services.yaml`** as `env(NAME): default`, for a constant with a sane fallback that a deployment does not set.
- **`.env.local`** for the whole of development (Symfony and compose both read it).
- **`.env.test`** for the test suite, which does not read `.env.local`.
- **The orchestrator's stack environment** for production.

## Messaging & scheduling

Message classes in `src/Message/`, handlers in `src/MessageHandler/` with `#[AsMessageHandler]`. Recurring work runs through `src/Scheduler/`, which is also where the register's periodic jobs (mailing list synchronisation, expiry sweeps, renewal reminders) are declared.

## Writing

These rules apply to prose the agent writes: code comments and docblocks, user-facing strings (validation messages, flashes, template copy), etc. Commit messages and PRs are the contributor's to write (see AI contribution), but notes given in chat for them follow the same register. Write in this register from the first draft. Before writing a comment or a reply, check it against this list. Fixing a rule in one place means checking everything already written for the same problem.

- A comment states why a decision was made. If it has to explain what the code does, the code needs to change. Do not restate the obvious, and drop a comment whose reason is obvious from the code. Keep a comment to one or two lines.
- No issue references in comments. The GH-XXX belongs in the commit message, which the contributor writes; the comment  carries only the reason.
- No figurative speech, no complex language. Use plain technical English.
- Avoid anthropomorphic verbs and say what happens. Not "takes the grant with it" but "drops the grant"; not "pushes the expiry forward" but "extends the expiration"; not "holds the stamp still" but "prevents the timestamp from updating".
- Avoid the verb "hold" and its forms, except in the ordinary conditional sense ("if the condition holds").
- Do not describe code as an actor. Verbs such as "answers", "hands back" and "reads off" are the same problem; use "returns" and "reads from". Not "Takes the time rather than reading it, so the caller does not ask for it again" but "The time is a parameter, because the caller has already read it from the entity".
- State behaviour, not a story about a person. Not "somebody who had just typed their password was sent back to the prompt" but "forcing a user who just authenticated to re-enter their password".
- Use the vocabulary already in the codebase. If the method is `terminateSession()`, the word is "terminated", not "when the session it belongs to ends".
- Do not name a code shape with community jargon. Not "withers" for `withX()` methods; name the methods, or say "the methods that return a modified copy".

## AI contribution

This project follows [AI_POLICY.md](./AI_POLICY.md). For an agent working here:

- **MUST NOT write or post prose in the contributor's voice**: PR descriptions, issue bodies, review replies, or other GitHub comments. When opening a PR, fill in the title and checklist structure only and leave the description to the contributor; if they ask for help, give notes in chat that they rewrite themselves.
- **MUST NOT tick the "I understand every change in this PR and can explain it" checkbox** in the PR template, or any other confirmation of the policy. Only the contributor can confirm they followed it.
- **Keep the contributor able to explain the change.** After making changes, say what changed and why, and call out the non-obvious decisions, so the contributor can review and own them.

## Commits

Conventional-commit prefixes (`feat:`, `fix:`, `chore:`, …), with scopes (often a `<namespace>` or component).
