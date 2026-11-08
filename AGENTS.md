# AGENTS.md

Operating manual for AI coding agents working in this repository. RFC 2119 keywords (MUST, MUST NOT, SHOULD, MAY)
are normative. Humans should read `README.md` first; `AI_POLICY.md` governs how contributions are made.

## What this project is

radix is one Symfony application serving two things that belong to the same association (GEWIS):

- **The register** — meetings, decisions, members, memberships, bodies and mailing lists: the authoritative record of
  what GEWIS decided and who its members are, written by the board and read by every other GEWIS system through the
  API.
- **The website** — activities, photos, course documents, custom pages, the careers portal, and the public face of the
  decisions above.

They used to be two applications (GEWISDB, GEWISWEB) joined by a sync script. Here they are one codebase, one
container image, one deployment: two entity managers in the same kernel, with no sync command, no second
repository and no separate report database.

## Two databases

Two connections, and the distinction governs anything that touches data:

- **`default`** — PostgreSQL, the **ledger**. The editable source of truth: members, prospective members,
  memberships, meetings, decisions, bodies, mailing lists. Entities in `src/Entity/Database`, migrations in
  `migrations/database`.
- **`web`** — MariaDB, everything else. The website's own data (activities, photos, companies, pages, users,
  sessions) *and* the **projection** of the ledger that the site and the API read: `src/Entity/Decision`, with the
  other domains owning the rest. Migrations in `migrations/web`.

- The projection is not a cache to bypass, and not a copy to let drift. `App\Service\Report` writes
  it; `App\EventListener\Report\DatabaseUpdateListener` and `DatabaseDeletionListener` keep it level with the ledger
  as the ledger is written; `app:decision:generate` rebuilds it from the ledger — run it after a bulk change.
  `App\EventListener\Report\BulkLoadListener` stands the listeners down while fixtures load.
- A change to a ledger entity MUST come with the matching change on the projection side (`src/Entity/Decision`,
  `src/Service/Report`). Change one without the other and the website quietly renders an association that no
  longer exists.
- A query MUST NOT span both connections. DQL, joins and associations stay inside one entity manager; ask the other
  for what you need and match up in PHP.
- The default manager is the ledger's — usually the wrong one. Inject the manager or repository you want; do not
  let `ManagerRegistry` hand you the default.
- Migrations are per entity manager and run on container start; `make migrate` runs both by hand. Each set is a file
  in `config/packages/migrations/` naming its own connection, so every command passes
  `--configuration=config/packages/migrations/{default,web}.yaml` and never `--em`.
- The PostgreSQL connections `SET ROLE` to a least-privileged role (`src/Doctrine/Middleware/`); anything needing
  owner rights fails as a permission error — that is not a configuration bug.

## Stack

`composer.json` is the authority on versions — do not quote them from here.

- PHP 8.5.
- Symfony 8.1 across the board (framework, security, messenger, scheduler, asset-mapper, ux-live-component,
  workflow).
- Doctrine ORM 3 with attribute mapping over the two entity managers, patched at install for sub-decision joins
  (`composer.json` `extra.patches`).
- FrankenPHP worker mode behind Caddy; worker-mode safety is non-negotiable — `make igor`, see Gates.
- API Platform 5 (`config/packages/api_platform.yaml`, all stateless, JSON only) serves almost all of `^/api`,
  alongside the hand-written endpoints in `src/Controller/Report`.
- Symfony Messenger (RabbitMQ in dev, in-memory in test) and Scheduler — see Messaging & scheduling.
- Symfony Workflow drives the revision/approval lifecycle — see Revision workflow.
- Twig + Stimulus (TypeScript, compiled by `sensiolabs/typescript-bundle`/SWC) + Live Components; Bootstrap, Font
  Awesome, Sass, asset-mapper. No npm; third-party JS is vendored under `assets/vendor/`.
- Altcha (self-hosted proof-of-work captcha on public forms), Stripe (membership fees), Mailman and Listmonk (lists
  and newsletters), Mercure (SSE), Mailer (MailPit in dev), Notifier, Matomo, `scheb/2fa`.
- Docker via `docker compose`; most `make` targets shell into the `app` container.

## Layout

Classes are grouped by the role they play, then by domain. The entity domains are **Activity**, **Application**,
**Career**, **Database** (the ledger), **Decision** (the projection — all its entities are in `src/Entity/Decision`,
there are no `Report` entities), **Education**, **Frontpage**, **Photo** and **User**. `Report` and `Checker` are
machinery without entities: `Report` maintains and serves the projection, `Checker` enforces consistency over the
ledger, `Application` is what is shared. A member, a meeting or an organ is an entity *in* a domain, never a domain
of its own; there is no `Api` domain, because the API's parts belong to what they serve.

```
src/
  ApiResource/      API Platform resources, by domain; plain DTOs, excluded from the container
  Command/          #[AsCommand]-tagged console commands
  CommonMark/       Markdown rendering extensions
  Controller/       feature controllers
  DataFixtures/     the development seed, by concern
  Doctrine/         Doctrine infrastructure: custom types, DQL functions, connection middleware
  Entity/           Doctrine entities with attribute mapping; domains may add Enums/ and Traits/ subfolders
  EventListener/    #[AsEventListener] listeners (incl. workflow guards and the projection listeners)
  Exception/        domain exceptions
  Form/             Symfony Form types
  Kernel.php
  Message/          Messenger message classes
  MessageHandler/   #[AsMessageHandler] handlers
  Monolog/          Monolog infrastructure: processors adding context to every record
  OpenApi/          decorators completing the generated OpenAPI document
  Repository/       Doctrine repositories
  Scheduler/        Symfony Scheduler providers (flat)
  Security/         user checkers, voters, remember-me handler, API authentication
  Serializer/       serializer context builders and normalizers
  Service/          domain services
  State/            API Platform state providers and processors, by domain
  Twig/             Extensions/ for custom Twig extensions; Components/<Namespace>/ for Live components
  Util/             small stateless helpers
  Validator/        custom constraint validators
  ViewModel/        immutable read models for templates, mirrors the domain structure
  Workflow/         revision-workflow plumbing: marking store, RevisionCloner implementations + registry
config/             framework + per-bundle config under packages/, routes under routes/
migrations/         database/ (the ledger) and web/ (everything else)
templates/          Twig templates; components/<Namespace>/ for Live components,
                    partials/application/ for stateless includes
translations/       .xlf files for en/nl
assets/             TypeScript Stimulus controllers, Sass, vendored assets
tests/              unit tests, mostly mirroring src/; Browser/ is the Panther e2e suite (see Testing)
```

When adding to a domain, follow the sibling pattern — do not invent a new home. Prefer passing a `ViewModel` to a
template over entities or loose arrays when the view needs derived or aggregated data (final readonly classes, no
behaviour beyond accessors).

## Routing & locale

The application is bilingual (`en`, `nl`):

- `localised_routes` in `config/routes.yaml` attribute-scans `src/Controller/` and applies the `/en` and `/nl`
  prefixes; controllers use locale-agnostic `#[Route]` paths.
- **The register is part of the administration and answers under `/{_locale}/admin`.** `src/Controller/Database`
  carries its paths below that prefix, so its routes keep a single name each (`member_index`, not
  `member_index.en`). `/{_locale}/database` still answers, permanently redirecting to wherever each page moved to
  (`ApplicationController::legacyRegister`).
- **`page_route`** (custom-pages catch-all) and **`catch_all`** (404 fallback) are defined in `config/routes.yaml`
  rather than as attributes — order matters. A locale-prefixed route has to be declared **above `page_route`**,
  whose `/{_locale}/{category}/{subCategory}/{name}` otherwise reads three segments of it as a missing page. Don't
  reorder this file lightly.
- **There is no session-stored locale.** A request's language is its `_locale`; `App\Service\Application\LocalePreference`
  answers for the handful of addresses that have no room to say it. E-mail is written in English and links to
  `/en/…` explicitly.
- **Live-component routes are locale-prefixed** (`config/routes/ux_live_component.yaml` → `/{_locale}/_components`);
  without the segment, re-renders come back in the framework default.
- Addresses that do not carry a locale — the sign-up and renewal links that sit in mailboxes and on posters, the
  checkout return addresses, `/user/token/{app}` (external applications are configured with it), plus `image_serve`,
  the public JWKS document, the Stripe webhook and `/health` — answer through
  `App\Controller\Application\LocaleRedirectController`, which forwards to the page proper. The redirect is a 302,
  not a 301: where it lands depends on `Accept-Language`, so it must not be remembered.

## Twig components & partials

- **`templates/components/<Namespace>/`** holds Twig / Live component templates, each paired with a backing class in
  `src/Twig/Components/<Namespace>/`. Names use `:` separators, set explicitly via
  `#[AsLiveComponent(name: ..., template: ...)]` so renames stay obvious; the namespace mirrors `src/Controller/`.
- **`templates/partials/application/`** holds stateless `{% include %}` fragments — sidebars, pagination, sort
  headers. Anything reused by multiple components belongs here; files use kebab-case without a leading underscore.

Render components from a regular template with `{{ component('User:Admin:UsersOverview') }}`. Live-component action
handlers re-render on the server, so locale-dependent output depends on the locale-prefixed route above.

## Frontend assets

- Stimulus controllers live in `assets/controllers/<domain>/` as `*_controller.ts`. **Write new controllers in
  TypeScript, never plain JS.** The single exception is `assets/controllers/csrf_protection_controller.js`, which is
  recipe-managed by Symfony Flex; leave it as JS so recipe updates apply cleanly.
- **SWC strips types but does not type-check** — there is no `tsc` or eslint gate, so type errors surface at runtime.
  Verify changed behaviour in the browser (see Testing).

## Security & authorization

The firewalls are in `config/packages/security.yaml`. `^/api` is stateless; the interactive firewalls carry
`form_login`, login throttling, two-factor authentication and a `UserChecker`.

There are three kinds of user, and any new authorization logic MUST reason about which one it has — never assume
`$this->getUser()` is a member:

- `App\Entity\User\User` — website members, keyed by `lidnr` (membership number); roles derived from member type,
  explicit `UserRole` rows, and self-assigned role.
- `App\Entity\User\CompanyUser` — corporate users, keyed by company id; always `ROLE_COMPANY_USER`.
- `App\Entity\Database\User\ApiPrincipal` — the principals and tokens for machine access, on the ledger.

**The register has no accounts of its own.** It is reached with a member's own `User`, and who may read or change it
is decided by roles granted for as long as somebody holds the office, derived in `User::getRoles()` from board
installations rather than written against an account: `UserRoles::DatabaseAdmin` (`ROLE_DATABASE_ADMIN`) follows a
serving secretary; `UserRoles::DatabaseReadOnly` (`ROLE_DATABASE_READ_ONLY`) a secretary who has been relieved but
whose year is not yet discharged; and `UserRoles::DatabaseMemberReadOnly` the current board. When MFA enforcement
is on, an in-scope user who has not enrolled has their protective roles stripped in `getRoles()`, so every
`#[IsGranted]` and `access_control` check fails until they enrol — `MfaEnforcementListener` converts the exception
into the redirect.

`ROLE_DATABASE_ADMIN` and `ROLE_DATABASE_READ_ONLY` are also withheld from a request that did not arrive from one of
the subnets in `REGISTER_IP_RANGES`:
`App\Security\Database\RegisterNetworkRoleHierarchy` filters them out of the reachable role set, so every check
naming them fails at once and the register's menu links disappear with them. An empty list restricts nothing —
what dev and tests run. The check refuses an address belonging to a trusted proxy, so getting
`SYMFONY_TRUSTED_PROXIES` wrong shuts the register rather than opening it.

`App\Security\User\UserChecker` blocks login for deleted, hidden or expired members and members without an email.
Authorization beyond roles lives in voters under `src/Security/`.

**Sudo** is a time-bounded grant: `App\Security\User\SudoVoter` gates the `SUDO` attribute and
`App\Security\User\SudoMode` keeps it in Valkey, scoped to the firewall, account and session it was given on. It has
two expirations: an idle TTL (1800 s, `app.sudo_idle_ttl`) extended by every guarded write, so a long edit is not
interrupted by a prompt — a GET does not extend it — and an absolute TTL (7200 s, `app.sudo_absolute_ttl`) measured
from the confirmation that never extends.

- Whole areas are behind that grant rather than lists of actions within them.
  `App\EventListener\User\SudoEnforcementListener` requires it on every address `App\Security\User\SudoArea` names —
  `/{_locale}/{admin, user/settings, user/security, company/security}` — so a new controller in any of them needs no
  `#[IsGranted(SudoVoter::ATTRIBUTE)]`.
- Deliberately outside the area: signing in, resetting a password and confirming sudo answer under `/user` and
  `/company` but outside `/security` — so somebody without a grant can still go and get one. The live components an
  admin page is built from, which answer under `/{_locale}/_components` and carry the attribute themselves. And
  `/user/cosmetics`, the festive-effects switch, which every page `fetch`es.
- Two places keep the attribute, because the rule is about the object, not the URL: the review screens in
  `AbstractRevisionReviewController` (may *this* reader approve *this* revision) and the administrative actions on
  `ProspectiveMemberController`.
- `App\EventListener\User\SudoGrantOnLoginListener` grants the permission on fresh sign-in, so the just-typed
  password is not asked again on the way in. It follows the passport credentials, not the login — a remember-me
  cookie presents nothing to check — and do not reach for `InteractiveLoginEvent`: Symfony calls the remember-me
  authenticator interactive as well.
- **An XHR endpoint inside a guarded area answers a lapsed grant with the confirmation page, not its JSON** —
  nothing the asking script can act on. Check this before putting a new `fetch` target inside one of these areas.

Remember-me is a **custom** integration (`App\Security\User\PersistentSignatureRememberMeHandler`, persisted via the
`Session` entity). The cookies, lifetimes and per-firewall handlers differ deliberately — read
`config/packages/security.yaml` and `config/packages/session.yaml` before touching.

## Logging

Two pipelines; the reader decides which one a message belongs in.

- **Ordinary logging** is `LoggerInterface` on the default channel. In production a `fingers_crossed` handler
  buffers `debug` and `info` until the same request fails; `warning` and above pass through (`passthru_level`) —
  anything that must survive a successful request MUST be at least a `warning`.
- **Security logging** is `App\Service\User\SecurityEventLogger` — the only way to record that something happened to
  an account: a sign-in, a session torn down, a second factor turned off, an administrator acting as somebody else.
  Call `record()` with a case of `App\Entity\User\Enums\SecurityEventType` — the address, browser, signed-in user
  and request are gathered there, so two places reporting the same event cannot describe it differently. Never use
  the plain logger for these, and pass a case, not a sentence: the administration filters on the cases and a data
  export names them.
- It writes twice. The `security` monolog channel is never buffered, so its copy survives a request that later failed
  and rolled back. `App\Entity\User\SecurityLog` is the queryable copy, read by `/admin/users/security-log` and the
  member data export. Rows are personal data: keep the columns to what recognises an event, flat
  and scalar — never a token, a cookie or a session identifier. `app:user:prune-security-log` enforces the retention
  period.
- Every record carries the context the processors in `config/packages/monolog.yaml` add
  (`App\Monolog\Processor\RequestIdProcessor` among them), so a visit's lines line up against the row written beside
  them.

## API

Everything under `^/api` is stateless, is read with `Authorization: Bearer <token>`, and answers the same envelope:

```json
{"status": "success", "data": …, "meta": {"page": 1, "itemsPerPage": 100, "totalItems": 4213, "totalPages": 43}}
{"status": "forbidden", "error": {"type": "…", "exception": "…"}}
```

- `meta` is present on every collection and nowhere else; `/api/docs.json` is the one path exempt, because an
  OpenAPI document has to stay one.
- **The envelope, the status codes, the wire names and the version negotiation are a contract with other GEWIS
  applications** — a field name change changes someone else's input. `openapi.yaml` publishes it and is
  **generated**: run `make openapi` after touching a resource, never edit it by hand; `ApiDocumentationTest` fails
  on drift.
- Almost everything is an **API Platform resource**:
  - `src/ApiResource/<Domain>/` — plain `final readonly` DTOs with `#[ApiResource]`; property declaration order is
    the JSON key order; the directory is excluded from the container.
  - `src/State/<Domain>/` — the providers that build them from the projection; `src/State/Api/` the shared plumbing
    (`EnvelopeProcessor`, `CollectionPagination`).
  - `src/Serializer/Api/` — the serializer contexts, including the permission-driven groups of the member payload;
    `src/OpenApi/` — the decorator that completes the generated document.
- Every operation carries `security:` and `securityMessage:`; the attribute is an `ApiPermissions` wire value and
  `App\Security\Api\ApiPermissionVoter` decides it.
- Every collection is paged (default 100, maximum 500), and a page without a deterministic `ORDER BY` is a bug. A
  collection provider that returns a plain array instead of a paginator silently drops `meta`.
- Nothing a request asks for is worth a 400: a page below 1 is clamped, one past the end is an empty page, and
  query parameters are read through `App\Util\Application\QueryValue`.
- A permission that names people is enforced twice — as the operation gate, and as a row filter for deleted members
  (`ApiPermissions::MembersDeleted`) applied in the query rather than to the page, or the totals stop matching.
- **Everything added when the API moved onto API Platform requires a contract version**, declared as
  `Accept: application/vnd.gewis.gewisdb+json;version=5.0.0` or as `X-Api-Version: 5.0.0` — the second exists
  because Swagger UI cannot send an `Accept` parameter. A new operation
  declares `extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT]` and
  `App\State\Api\MinimumVersionProvider` enforces it, after the permission check and before the read. The member
  endpoints that predate the versioned contract keep answering without one; that list is
  `ApiOpenApiFactory::UNVERSIONED_PATHS` and it does not grow.
- Swagger UI answers on **`/api-docs`**, outside `^/api` — a browser cannot send an `Authorization` header on the
  first navigation. It is public, because `openapi.yaml` is committed to a public repository.
- `src/Controller/Report/ApiController` keeps the endpoints that are not resources: `/health`, the two function
  lists, the example error endpoint, and the catch-all that makes an unknown `/api` path answer
  `error-router-no-match` — that catch-all MUST stay the last method in the class.
- `GET /api/members/{lidnr}` answers **204 with no body** for a member that does not exist, *or that the principal
  may not see* — an empty dataset rather than a missing resource. It is the legacy contract, not an oversight —
  which is why `MemberProvider` returns a `Response`.
- Both surfaces read the projection, never the ledger directly.

## Dependency injection

Pure autowire / autoconfigure from `src/`, with scalar bindings in `config/services.yaml`. There are **no factory
classes** — constructor property promotion does all the wiring, and `readonly` where it holds. If autowire cannot
resolve a dependency, define the service explicitly in `config/services.yaml`; do not add a factory.

## Coding style

- `declare(strict_types=1);` in every file, immediately after the opening `<?php`.
- Native PHP type hints on parameters, return types and properties wherever a parent signature allows.
- Yoda-style comparisons: `null === $x`.
- Multi-clause `if` conditions, one clause per line.
- Attribute-based wiring throughout; `#[Override]` on inherited methods.
- Doctrine attribute mapping with short-name imports: `use Doctrine\ORM\Mapping\Entity;` and write `#[Entity]` —
  **not** the `ORM\` alias form.
- Comments explain decisions and non-obvious mechanics in full sentences, not what the code already says.
- Follow `GEWISPHPCodingStandards` (the rule set in `phpcs.xml.dist`); `make lint` is authoritative,
  `make lint-fix` autofixes a subset.

## Testing

The suite runs with PHPUnit inside the `app` container, against isolated copies of both databases — real PostgreSQL
and MariaDB, because both are production and a substitute like SQLite cannot reproduce the custom DQL functions
(`RAND`, `YEAR`), `EditLockService`'s named locks, the UUID and `uca1400` columns, or what the ledger asks of
PostgreSQL.

- Integration tests boot the kernel and grab the managers (`tests/Integration/DatabaseTestCase`) — **query the seeded
  fixtures, don't hand-build entity graphs.**
- `dama/doctrine-test-bundle` wraps each test in a transaction and rolls it back, so one schema and one seed serve
  the whole run and tests share the seed without leaking writes.
- Locally, `make test-prepare` (re)builds both schemas from the mapping and loads the seed; run it once, and again
  after any schema or fixture change. CI builds the test databases from the migrations instead
  (`.github/workflows/unit-tests.yml`).
- Pure domain logic (mappers, voters, guard listeners, enums) stays a DB-less `TestCase`.

### Browser tests

`tests/Browser/` drives a real Chromium against a real server (`symfony/panther`, extending `BrowserTestCase`).
They cover what a page does once its JavaScript has run — an editor surviving a cache restore, a lock released on
navigation, a live component still responding — none of which a test that only renders a template can see.

- **A change to interactive or user-facing behaviour MUST be covered by a browser test.** A unit or kernel test
  alone is not sufficient: it renders the template and stops. Browser (e2e) tests are the default for user-facing
  behaviour; DB-less and kernel tests are for logic a browser test cannot reach cheaply.
- They are a testsuite of their own, excluded from `make test`, because each one starts Chromium and a web server.
  Run them with `make test-browser`; CI runs the suite after the ordinary one.
- The server the browser drives has a connection of its own, outside the transaction every other test is rolled back
  with: read the seeded fixtures rather than building data, treat anything a test writes as permanent, and sign in
  through the real form — the kernel-level sign-in helpers authenticate in this process and reach none of the
  server. `make test-browser` re-seeds when the run ends; run the target, not a bare `bin/phpunit`.

### Gates

- `make lint` (after `make lint-fix`) and `make phpstan` MUST pass for any change. `make lint-twig` whenever
  templates changed; `make igor` for any non-trivial change.
- Baselines are for legacy debt, not new code: when a new error hits a baseline, fix it rather than extending the
  baseline. The type-coverage percentages are pinned just under where the codebase stands, so they can only go up.
  `make phpstan-pr` regenerates the baseline against `main` for a PR.
- **Validate what you changed.** Non-trivial work needs a smoke run: exercise the changed path and observe the
  result; passing tests alone are not proof. For a bug, reproduce before the fix, confirm after, and keep a
  regression test.
- CSRF is **stateless** (double-submit cookie plus Origin/Referer checks), so a hand-crafted `curl` POST fails by
  design. **Do not "verify" forms, login or sign-up flows with curl** — a 4xx proves nothing. Validate interactive
  flows through the browser suite, or in a real browser against the seeded local stack (login in Local development
  workflow; outbound mail in MailPit).
- **The checker.** `make sf c=check:database` runs the consistency checks from the Articles of Association and
  Internal Regulations. Worth running after anything that changes decisions or installations: it is the only thing
  that catches data the regulations forbid.

## Translatable strings

`make translations` extracts into `translations/{messages,validators}.{en,nl}.xlf` with the project's expected flags
(`--sort=asc --no-fill --force --clean`); never hand-roll `bin/console translation:extract`. `--clean` removes
entries no longer referenced in source, which is safe for the `validators` domain because Symfony falls back to the
vendor files.

**Extraction alone is not enough.** `--no-fill` leaves new entries with an empty `<target/>` in *both* locales, and
an empty target is used *as* the translation, so the interface renders blank rather than falling back. Find them
with `grep -rn -e '<target/>' -e '<target></target>' translations/` and fill every one: `en` targets get the source
text verbatim, `nl` targets your best Dutch — and list the Dutch you wrote in the final report so a human can
review it.

Three extraction traps:

- `t()` and `TranslatableMessage` are extracted by their **literal** argument. An enum that builds a label must put
  the constructor *inside* each match arm:
  ```php
  return match ($this) {
      self::Chair => new TranslatableMessage('Voorzitter'),
  };
  ```
  Not `new TranslatableMessage(match ($this) { ... })` — the extractor cannot see through that, and `--clean` then
  deletes every translation for those labels.
- A bare string handed to the form theme for it to translate (a `help:` on a `form_row()` call) is invisible to the
  extractor, so `--clean` deletes its translation while the template still uses it. Write `help: t('…')` instead.
- **A validation message has to be written where the extractor can see it.** It reads `t('…')`,
  `TranslatableMessage`, and the message arguments of a constraint that is written out; it does not read a literal
  handed to `$context->buildViolation('…')`, a literal passed on to a helper, or the default of a `$message`
  property on a constraint class. So: hand `buildViolation()` the key as `t('…', [], 'validators')->getMessage()`,
  give a helper that raises errors a `TranslatableMessage` rather than a string, and put a constraint's messages in
  the attribute that applies them rather than in a property default. In form types, wrap user-facing labels and
  `invalid_message` strings with `t()` (the extractor does not recurse into `RepeatedType`'s `first_options` /
  `second_options`), and don't call `$translator->trans()` at form-build time — it locks the locale before render.

## Local development workflow

- `make start` — build the images and start the stack; creates `.env.local` from `.env.local.dist` if it is missing.
- `make seed` — load the fixtures (seeds both connections: the ledger first, then the web database), rebuild the
  projection, and prepare Mailman and Listmonk. Seeded login: member number `8000`, password `gewiswebgewis`; the
  register is reached with the same account, `8000` holding both `ROLE_ADMIN` and `ROLE_DATABASE_ADMIN`, `8001`
  only the former, `8002` only the latter. (Migrations already run — they fire one-shot on container start.)
- `make bash` — shell into the FrankenPHP `app` container; `make exec cmd="…"`, `make sf c=…`,
  `make composer c='…'` — single commands in it. `make cc` clears the cache and restarts the worker.
  `make stop` / `make logs` — as named.
- **Where a variable lives.** `.env` is committed and holds almost nothing — only `APP_ENV`. Docker compose reads it
  too, so a value there would silently satisfy the `${VAR:?…}` guards in `compose.yaml` that are supposed to catch a
  missing production variable, and `composer dump-env prod` would bake it into the production image as a fallback.
  So: **`config/services.yaml`, as `env(NAME): default`**, for a constant with a sane fallback rather than something
  a deployment sets; **`.env.local`** for the whole of development (both Symfony and compose read it, so the
  containers without a dotenv chain of their own — the databases, pgadmin, Matomo, the Stripe CLI — are configured
  from the same file); **`.env.test`** for the test suite, which does not read `.env.local`; **the orchestrator's
  stack environment** for production.
- Hot reload covers almost everything in dev: FrankenPHP reloads the workers on source changes, and the dev
  entrypoint watches `assets/` to rebuild Sass and the asset map. Reach for `make cc` or a container restart only if
  something genuinely will not budge. `vendor/` sits in the bind mount, so the IDE indexes the same directory the
  container installs into.

## Messaging & scheduling

Messenger is backed by RabbitMQ in dev, in-memory in test (`config/packages/messenger.yaml`). Message classes go in
`src/Message/`; handlers in `src/MessageHandler/` with `#[AsMessageHandler]`. Recurring work runs through
`src/Scheduler/` (Symfony Scheduler), which is also where the register's periodic jobs — mailing list
synchronisation, expiry sweeps, renewal reminders — are declared.

## File storage

Uploaded and generated files live under `data/`, never under `public/`. `StorageNamespace` maps each domain onto its
directory and carries the rules with it — whether the namespace is scoped (photos per album, company assets per
company, meeting documents per meeting), whether serving a file needs an authenticated signed request, which MIME
types it accepts and how large a file may be. `FileStorage` is the only writer, over a Flysystem adapter rooted at
`data/` (in-memory under test). Public images are served through `ImageUrlBuilder` as `/img/{variant}/{path}`;
nothing is linked to by its path on disk.

**A web request never encodes an image.** Serving reads the pre-generated variant cache only; a miss on an existing
original queues one `GenerateImageVariantMessage` and answers 503 with `Retry-After`. The variants exist because
uploads queue them (`ProcessImageVariantsMessage`) and because `app:image:pregenerate` queues the rest — that command
only dispatches, so a backfill is encoded by the `messenger-images` workers rather than by whichever container the
command was run from, and `IMAGE_WORKER_REPLICAS` is what paces it. Run it after clearing the variant cache, and
with `--force` after changing the variant set or the encoding. Synchronous encode-on-miss is what once saturated the
production host; do not reintroduce it.

## Doctrine caching

- The projection's `Member` is the only entity in Doctrine's second-level cache (`config/packages/doctrine.yaml`,
  region `member_region`). It is read almost everywhere and changes only when the projection is written, which makes
  by-ID loads (`find()`, lazy associations) worth serving from cache. DQL queries that select Members still hit the
  database unless explicitly marked cacheable.
- Deliberately **not** cached: `User`, because MFA enrolment toggles must take effect on the next request, and
  `UserRole`, because admin grants and revocations need to be visible immediately.
- Anything that writes a cached entity outside the ORM MUST evict its region by hand — Doctrine cannot see a raw
  DBAL write. If you add caching to another entity, check how it is written before choosing a strategy.

## Revision workflow

Activities, companies, vacancies, body pages and polls are created and edited through a revision-based approval
workflow. Read this before touching any of those domains.

- **Contracts** in `src/Entity/Application/`: `RevisableInterface` (the stable aggregate — `Activity`,
  `Career\Company`, `Career\Vacancy`, `Decision\OrganInformation`, `Frontpage\Poll`: identity, revision chain,
  `markRevisionLive()`), `RevisionInterface` / `AbstractRevision` (a per-revision snapshot of all editable content),
  and `Enums/RevisionStatus`.
- **Lifecycle** (`config/packages/workflow.yaml`, state machine `revision`): draft → submitted → in-review →
  { approved | changes-requested | rejected → closed }. The marking store is custom
  (`App\Workflow\RevisionStatusMarkingStore`, bridging the status enum).
- **Non-draft revisions are immutable.** Only a `draft` revision may be edited; approval promotes it to the live
  revision. To change approved content, spawn a new draft via the cloner registry and run it through the workflow —
  never mutate a submitted or approved `*Revision` row directly.
- **Guards and side effects** are event listeners, not workflow config: `RevisionGuardListener` delegates to
  `App\Security\Application\RevisionVoter` (organ/creator/company scoping); `SpawnNextDraftListener` clones a new
  draft after changes-requested; `PromoteLiveRevisionListener` and `MigrateSignupsOnApprovalListener` run on
  approval; `NotifyOnRevisionSubmissionListener` tells the reviewers. A domain may add its own guard on top
  (`Activity/PastActivityGuardListener`, `Frontpage/PollRevisionGuardListener`); they are additive, so all must
  pass.
- **Per-domain registries** tagged by interface, so a new revisable domain adds its own answers instead of editing a
  shared `match`: `RevisionDescriberRegistry` (what the review screen shows),
  `RevisionNotificationRegistry` (which notification a submission raises and who it goes to), and
  `RevisionCommentRepositoryInterface` (the review discussion). Review screens extend
  `Controller/Application/AbstractRevisionReviewController` and share `templates/partials/application/review-*`.
- **Cloning**: each revisable domain that can be edited implements `App\Workflow\RevisionClonerInterface` (deep
  copy, bump revision number, link `previousRevision`), routed through `RevisionClonerRegistry` — a cloner, never
  bespoke copy logic. A poll deliberately has none: it is written and submitted in one request, `request_changes`
  is withheld by its guard, and asking the question again writes a new revision from scratch.
- **Edit locking**: `Entity/Application/EditLock` + `Service/Application/EditLockService` give one user at a time
  exclusive editing (90-second ping TTL, serialised via MariaDB `GET_LOCK`, reviewers can force-take). The
  `application/edit_lock_controller.ts` Stimulus controller does the pinging.
- **Activity sign-ups** are versioned with the revision: each `ActivityRevision` owns clones of its `SignupList`s,
  matched across revisions by `lineageId`; on approval `Service/Activity/SignupListMigrator` carries live sign-ups
  over (and guards block approval if migration would lose data). All sign-up writes go through
  `Service/Activity/SignupManager` — members sign up directly, external guests get double-opt-in email verification
  plus Altcha.

## AI contribution

This project follows [AI_POLICY.md](./AI_POLICY.md). For an agent working here, that means:

- **MUST NOT write or post prose in the contributor's voice** — PR descriptions, issue bodies, review replies, or
  other GitHub comments. When opening a PR, fill in the title and checklist structure only and leave the
  description to the contributor; if they ask for help, give notes in chat that they rewrite themselves.
- **MUST NOT tick the "I understand every change in this PR and can explain it" checkbox** in the PR template, or
  any other confirmation of the policy. Only the contributor can confirm they followed it.
- **Keep the contributor able to explain the change.** After making changes, say what changed and why, and call out
  the non-obvious decisions, so the contributor can review and own them.

## Commits

Conventional-commit prefixes (`feat:`, `fix:`, `chore:`, …), and they MUST be signed.
