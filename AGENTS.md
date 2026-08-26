# AGENTS.md

Guidance for AI coding agents working in this repository. Humans should read `README.md` first.

## What this project is

radix is one Symfony application serving two things that belong to the same association:

- **The register** — meetings, decisions, members, memberships, bodies and mailing lists. The authoritative record of
  what GEWIS decided and who its members are, written by the board and read by every other GEWIS system.
- **The website** — activities, photos, course documents, custom pages, the careers portal, and the public face of the
  decisions above.

They used to be two applications, GEWISDB and GEWISWEB, that copied data between themselves over HTTP. Here they are
one codebase, one container image, one deployment. What used to cross the wire is now two entity managers in the same
kernel. There is no sync command, no second repository and no separate report database.

## Two databases

Two connections, and the distinction governs anything that touches data:

- **`default`** — **PostgreSQL**, the **ledger**. The editable source of truth: members, prospective members,
  memberships, meetings, decisions, bodies, mailing lists. Entities under `src/Entity/Database`, migrations in
  `migrations/database`.
- **`web`** — **MariaDB**, everything else. The website's own data — activities, photos, companies, pages, users,
  sessions — *and* the projection of the ledger that the site and the API read. The projection's entities are
  `src/Entity/Decision`; the other domains own the rest. Migrations in `migrations/web`.

The projection is not a cache you may bypass, and it is not a copy you may let drift. `App\Service\Report` writes it,
`App\EventListener\Report\DatabaseUpdateListener` and `DatabaseDeletionListener` keep it level with the ledger as the
ledger is written, and `app:decision:generate` rebuilds it from the ledger — that is what to run after a bulk change.
The listeners stand down while fixtures load (`BulkLoadListener`), for exactly that reason.

A change to a ledger entity therefore usually needs the matching change on the projection side and in the projection
services under `src/Service/Report`. Changing one and not the other is the easiest way to break this application: the
register keeps accepting writes and the website quietly renders a version of the association that no longer exists.

Rules that follow from having two connections:

- **No query spans both.** DQL, joins and associations stay inside one entity manager. Ask the other manager for what
  you need and match up in PHP.
- **Say which manager you mean.** The default manager is the ledger's, and for most of the codebase that is the wrong
  one. Inject the manager or repository you want rather than letting `ManagerRegistry` hand you the default.
- **Migrations are per entity manager.** They run on container start; `make migrate` runs both by hand. Each set is a
  file in `config/packages/migrations/` naming its own connection, so every command passes
  `--configuration=config/packages/migrations/{default,web}.yaml` and never `--em`. Without one a command finds no
  migrations at all.
- **The PostgreSQL connections `SET ROLE`** to a least-privileged role (`src/Doctrine/Middleware/`). Anything needing
  owner rights fails as a permission error, which reads like a configuration bug and is not one.

## Stack

`composer.json` is the authority on versions — do not quote them from here.

- **PHP 8.5** with `declare(strict_types=1)` required in every file.
- **Symfony 8** across the board (framework-bundle, security-bundle, messenger, scheduler, asset-mapper, stimulus,
  ux-live-component, workflow, …).
- **Doctrine ORM 3** with attribute mapping, over the two entity managers above. Patched at install for sub-decision
  joins — see `composer.json` `extra.patches`.
- **Doctrine Migrations** — `migrations/database` and `migrations/web`, generated from `migrations/template.tpl`.
- **FrankenPHP worker mode** behind Caddy. Worker-mode safety is non-negotiable here — see Things to be careful
  about, below.
- **API Platform 4** (`config/packages/api_platform.yaml`, all stateless, JSON only) serves almost all of `^/api`,
  alongside four hand-written endpoints in `src/Controller/Report`; see the API section.
- **Symfony Messenger** over RabbitMQ in dev, in-memory in test (`config/packages/messenger.yaml`). **Scheduler** in
  `src/Scheduler/`.
- **Symfony Workflow** drives the revision/approval lifecycle (`config/packages/workflow.yaml`) — see the Revision
  workflow section.
- **Twig + Stimulus + Live Components**, Bootstrap, Font Awesome, Sass via `symfonycasts/sass-bundle`, asset-mapper.
  Stimulus controllers are **TypeScript**, compiled by `sensiolabs/typescript-bundle` (SWC) — see Frontend assets.
- **Altcha** (`config/packages/altcha.yaml`) — self-hosted proof-of-work captcha on public forms (external activity
  sign-up, password-reset request, and the join form). `App\Service\Application\AltchaSolutionGuard` blocks replay of
  solved challenges.
- **Stripe** for membership fees on the join host; **Mailman** and **Listmonk** for mailing lists and newsletters.
- **Mercure** for SSE; **Mailer** with MailPit in dev; **Notifier**; **Matomo** analytics; **scheb/2fa** for MFA.
- Runs under Docker via `docker compose`; most `make` targets shell into the `app` container.

## Layout

Classes are grouped by the role they play, then by domain. The domains are **Activity**, **Application**, **Career**,
**Checker**, **Database**, **Decision**, **Education**, **Frontpage**, **Photo**, **Report** and **User**. `Database`
is the ledger, `Decision` is the projection of it, `Report` is the machinery that maintains that projection and serves
it, and `Application` is what is shared. Member, Meeting, Organ, MailingList and the rest are entities *in* a domain,
never domains of their own. There is no `Api` domain: the API is a delivery mechanism, and its parts belong to what
they serve.

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
  OpenApi/          decorators completing the generated OpenAPI document
  Repository/       Doctrine repositories
  Scheduler/        Symfony Scheduler providers (flat)
  Security/         user checkers, voters (SudoVoter, RevisionVoter), remember-me handler, API authentication
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
templates/          Twig templates, mirrors src/Controller; components/<Namespace>/ for Live components,
                    partials/application/ for stateless includes
translations/       .xlf files for en/nl
assets/             TypeScript Stimulus controllers, Sass, vendored assets
tests/              unit tests + Integration/ (see Static analysis & tests); bootstrap.php + object-manager.php
```

When adding to a domain, follow the sibling pattern — do not invent a new home. Prefer passing a `ViewModel` to a
template over handing it entities or loose arrays when the view needs derived or aggregated data (final readonly
classes, no behaviour beyond accessors).

## Routing & locale

The application is bilingual (`en`, `nl`) and most controller routes are prefixed from `config/routes.yaml`:

- The `localised_routes` resource attribute-scans `src/Controller/` and applies `/en` and `/nl` prefixes. Controllers
  themselves use `#[Route]` attributes with locale-agnostic paths.
- **The register is part of the administration and answers under `/{_locale}/admin`.** `src/Controller/Database`
  carries paths below that prefix, with the locale as a path segment rather than through the prefix map above, so its
  routes keep a single name each (`member_index`, not `member_index.en`). Sharing the prefix with `localised_routes`
  is not a conflict: two imports may contribute routes under one path as long as no two claim the same path and
  method. `/{_locale}/database` still answers, permanently redirecting to wherever each page moved to
  (`ApplicationController::legacyRegister`). The website's own URLs are unchanged by the merge, and must stay that
  way.
- **`page_route`** (custom-pages catch-all) and **`catch_all`** (404 fallback to `FrontpageController::notFound`) are
  defined in `config/routes.yaml` rather than as attributes — **order matters**. Attribute scanning that ran before the
  explicit YAML routes used to steal traffic; that bug bit voting committees. Don't reorder this file lightly.
- A few routes are deliberately *not* locale-prefixed, and are in `config/routes.yaml` for that reason: `image_serve`
  and `legacy_data` (assets and old bookmarks, not pages, and their slash-bearing `{path}` must be matched before
  `page_route` could grab it), `user_token`, and the public JWKS document.
- Other YAML route files under `config/routes/`: `api_platform.yaml`, `scheb_2fa.yaml`, `security.yaml`,
  `framework.yaml`, `nelmio_security.yaml`, `altcha.yaml`, `ux_live_component.yaml`, `ux_autocomplete.yaml`, and
  `web_profiler.yaml` (dev).
- `src/EventListener/Application/LocaleRedirectListener.php` redirects bare `/` to the user's preferred locale,
  falling back to `%kernel.default_locale%` (= `en`).
- `$defaultLocale` and `$supportedLocales` are auto-bound in `config/services.yaml` — services that need them can just
  declare the parameter.
- **Live-component routes are locale-prefixed** (`config/routes/ux_live_component.yaml` → `/{_locale}/_components`).
  Without the locale segment, action POSTs would have no `_locale` route attribute and re-renders would always come
  back in the framework default (`en`) — there is no `LocaleSubscriber` syncing the session locale to fall back on.

## Twig components & partials

Two template locations, deliberately distinct:

- **`templates/components/<Namespace>/`** holds Twig / Live component templates, each paired with a backing PHP class
  in `src/Twig/Components/<Namespace>/`. Component names use `:` separators (e.g. `User:Admin:UsersOverview`); set them
  explicitly via `#[AsLiveComponent(name: ..., template: ...)]` so renames stay obvious. The namespace structure should
  mirror `src/Controller/` (e.g. `User/Admin/`, not flat `Admin/`) so admin tooling stays grouped with its domain.
- **`templates/partials/application/`** holds stateless `{% include %}` fragments — sidebars, pagination, sort headers.
  Anything reused by multiple components belongs here, not co-located inside `templates/components/`. Files use
  kebab-case without a leading underscore (`pagination.html.twig`, `sort-header.html.twig`).

Render components from a regular template with `{{ component('User:Admin:UsersOverview') }}` (or
`<twig:User:Admin:UsersOverview />`). Live-component action handlers re-render the component on the server, so any
locale-dependent output (translations, date formatting) depends on the locale-prefixed route above.

## Frontend assets

- **Stimulus controllers are TypeScript.** They live in `assets/controllers/<domain>/` and are named `*_controller.ts`.
  Write new controllers in TypeScript — never plain JS. The single exception is
  `assets/controllers/csrf_protection_controller.js`, which is recipe-managed by Symfony Flex; leave it as JS so recipe
  updates apply cleanly.
- TS is compiled by `sensiolabs/typescript-bundle` via SWC (`.swcrc`, wired in `config/packages/asset_mapper.yaml`).
  **SWC strips types but does not type-check** — there is no `tsc` or eslint gate, so type errors surface at runtime.
  Be precise with DOM/Stimulus typings and verify behaviour in the browser (see Validating changes below).
- Sass lives in `assets/styles/`; third-party JS is vendored under `assets/vendor/` (asset-mapper, no `package.json`
  and no npm). In dev, the entrypoint watches `assets/` and recompiles.

## Security & users

The firewalls are in `config/packages/security.yaml`. The API surface (`^/api`) is stateless; the interactive
firewalls carry `form_login`, login throttling, two-factor authentication and a `UserChecker`.

The user entities are distinct on purpose, and any new authorization logic has to reason about which one it has —
never assume `$this->getUser()` is a member:

- `App\Entity\User\User` — website members, keyed by `lidnr` (membership number); roles derived from member type,
  explicit `UserRole` rows, and self-assigned role.
- `App\Entity\User\CompanyUser` — corporate users, keyed by company id; always `ROLE_COMPANY_USER`.
- `App\Entity\Database\User\ApiPrincipal` — the principals and tokens for machine access, on the ledger.

The register has no accounts of its own: it is reached with a member's own `User`, and who may read or change it is
decided by two roles granted for as long as somebody holds the office. `ROLE_DATABASE_ADMIN` follows a serving
secretary (installed, their installation date passed, not relieved) and `ROLE_DATABASE_READ_ONLY` a secretary who has
been relieved but whose year is not yet discharged. Both are derived in `User::getRoles()` from board installations
rather than written against an account, and both require MFA before they are granted at all.

Both are also withheld from a request that did not arrive from one of the subnets in `REGISTER_IP_RANGES`:
`App\Security\Database\RegisterNetworkRoleHierarchy` filters them out of the reachable role set, so every
`access_control` rule, `#[IsGranted]` and `is_granted()` naming them fails at once and the register's links disappear
from the menus with them. An empty list places the register nowhere and restricts nothing, which is what development
and the tests run with. The check refuses an address belonging to a trusted proxy, so getting `SYMFONY_TRUSTED_PROXIES`
wrong shuts the register rather than opening it.

`App\Security\User\UserChecker` blocks `User` login for deleted, hidden or expired members and members without an
email. Authorization beyond roles lives in voters under `src/Security/`; notably
`App\Security\User\SudoVoter` gates destructive actions behind a 30-minute time-bounded `SUDO` grant (see `SudoMode`).

Remember-me is a **custom** integration (`App\Security\User\PersistentSignatureRememberMeHandler`, persisted via the
`Session` entity). The cookies, lifetimes and per-firewall handlers differ deliberately — read
`config/packages/security.yaml` and `config/packages/session.yaml` before touching anything here.

## API

Everything under `^/api` is stateless, is read with `Authorization: Bearer <token>`, and answers the same envelope:

```json
{"status": "success", "data": …, "meta": {"page": 1, "itemsPerPage": 100, "totalItems": 4213, "totalPages": 43}}
{"status": "forbidden", "error": {"type": "…", "exception": "…"}}
```

`meta` is present on every collection and nowhere else, and `/api/docs.json` is the one path exempt from the
envelope because an OpenAPI document has to stay one. **The envelope, the status codes, the wire names and the
version negotiation are a contract with other GEWIS applications** — changing a field name changes someone else's
input. `openapi.yaml` publishes it and is **generated**: run `make openapi` after touching a resource, never edit it
by hand. `ApiDocumentationTest` fails on drift.

Almost everything is an **API Platform resource**:

- `src/ApiResource/<Domain>/` — plain `final readonly` DTOs with `#[ApiResource]`. Property declaration order is the
  JSON key order. The directory is excluded from the service container.
- `src/State/<Domain>/` — the `ProviderInterface` that builds them from the projection.
- `src/State/Api/` — the shared plumbing: `EnvelopeProcessor` (the envelope) and `CollectionPagination` (the page).
- `src/Serializer/Api/` — the serializer context, including the permission-driven groups of the member payload.
- `src/OpenApi/` — the decorator that completes the generated document with the endpoints that are not resources.
- `src/EventListener/Api/` — the charset header, and `UnexposedRouteListener`, which hides the routes API Platform
  mounts under `/api` for its own plumbing so no path there answers outside the contract.

Every operation carries `security:` and `securityMessage:`; the attribute is an `ApiPermissions` wire value and
`App\Security\Api\ApiPermissionVoter` decides it. Every collection is paged (default 100, maximum 500), and a page
without a deterministic `ORDER BY` is a bug. A collection provider that returns a plain array instead of a paginator
silently drops `meta`. Nothing a request asks for is worth refusing it over: a page below 1 is clamped, one past
the end is an empty page, and every query parameter is read through `App\Util\Application\QueryValue`, which
never answers 400.

A permission that names people is enforced twice — as the operation gate, and as a row filter for deleted members
(`ApiPermissions::MembersDeleted`) applied in the query rather than to the page, or the totals stop matching.

**Everything added when the API moved onto API Platform requires a contract version**, declared either as
`Accept: application/vnd.gewis.gewisdb+json;version=5.0.0` or as `X-Api-Version: 5.0.0` — the second exists because
OpenAPI requires tooling to ignore an `Accept` parameter, so Swagger UI cannot send the first. A new operation says
so with `extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT]` and
`App\State\Api\MinimumVersionProvider` enforces it, after the permission check and before the read. The member
endpoints that predate the versioned contract keep answering without one; that list is
`ApiOpenApiFactory::UNVERSIONED_PATHS` and it does not grow.

Swagger UI answers on **`/api-docs`**, deliberately outside `^/api`: a browser cannot send an `Authorization` header
on the first navigation, so behind the bearer wall the page could never render. It is public, because `openapi.yaml`
is committed to a public repository and describes the API without containing any of its data.

`src/Controller/Report/ApiController` keeps the four endpoints that are **not** resources, and that is a considered
escape hatch rather than a leftover: `/health` (no `data` key), the two function lists (their vendor `Accept` header
carries a `version` parameter that content negotiation answers 406 to before the version can be read), the two
examples, and the catch-all that makes an unknown `/api` path answer `error-router-no-match`. That catch-all must
stay the last method in the class.

`GET /api/members/{lidnr}` answers **204 with no body** for a member that does not exist. That is the legacy
contract, not an oversight, and it is why its provider returns a `Response`.

Both surfaces read the projection, never the ledger directly.

## Dependency injection

Pure autowire / autoconfigure from `src/`, with scalar bindings in `config/services.yaml` and exclusions for
`src/DependencyInjection/`, `src/Entity/`, `src/ApiResource/`, `src/ViewModel/` and
`src/Security/User/HandlerRegistry.php`. There are **no factory
classes** — constructor property promotion does all the wiring, and `readonly` where it holds. If autowire cannot
resolve a dependency, define the service explicitly in `config/services.yaml`; do not add a factory.

## Coding style

- `declare(strict_types=1);` immediately after the opening `<?php`.
- Constructor property promotion everywhere; many service-like classes are `final readonly`.
- Native PHP type hints on parameters, return types and properties wherever a parent signature allows.
- Yoda-style comparisons: `null === $x`, `true === $foo->getDeleted()`.
- Multi-clause `if` conditions split one clause per line:
  ```php
  if (
      $a
      && $b
  ) {
  ```
- Attribute-based wiring throughout: `#[Route]`, `#[AsEventListener]`, `#[AsCommand]`, `#[AsMessageHandler]`. Use
  `#[Override]` on inherited methods.
- Doctrine entities use attribute mapping, not annotations or XML. Import each attribute by its short name
  (`use Doctrine\ORM\Mapping\Entity;`, `use Doctrine\ORM\Mapping\Column;`) and write `#[Entity]`, `#[Column]` —
  **not** the `ORM\` alias form (`#[ORM\Entity]`, `#[ORM\Column]`).
- Comments explain decisions and non-obvious mechanics in full sentences, not what the code already says.
- Follow `GEWISPHPCodingStandards` (the rule set in `phpcs.xml.dist`). `make lint` is authoritative; `make lint-fix`
  autofixes a subset.
- Match the surrounding code when in doubt; consistency beats stylistic preference.

## Static analysis & tests

Run these inside the `app` container (the `make` targets handle that for you). **`make lint` (after `make lint-fix`)
and `make phpstan` must pass for any change** — they are not optional; do not claim work done while one of them fails.
Add `make lint-twig` whenever templates changed and `make igor` for any non-trivial change.

| Command | What it does |
|---|---|
| `make lint` / `make lint-fix` | PHPCS / PHPCBF against `GEWISPHPCodingStandards`. Run `lint-fix` first, then `lint` must be clean. |
| `make phpstan` | PHPStan at level 8, with a baseline and a type-coverage ratchet. Must pass. |
| `make lint-twig` | `lint:twig` over `templates/`. Run whenever you add or edit a template. |
| `make igor` | Validates the codebase for FrankenPHP worker-mode safety. **Run this for any non-trivial change.** |
| `make test` | PHPUnit under `APP_ENV=test`; `c=` passes options through. Must pass. |
| `make test-prepare` | (Re)builds the test schemas and loads the fixtures. Run once, and again after any schema or fixture change. |
| `make translations` | Extracts translatable strings into `translations/`. See below. |
| `make sf c=check:database` | The consistency checks from the Articles of Association and Internal Regulations. |

When a new error hits a baseline, fix it rather than extending the baseline. Baselines are for legacy debt, not new
code. The same goes for the type-coverage percentages: they are pinned just under where the codebase stands so they
can only go up.

The checker is worth running after anything that changes decisions or installations — it is the only thing that will
tell you that a seed or a decision path is producing data the regulations forbid.

**Tests.** Integration tests run against real PostgreSQL and MariaDB matching production, on isolated test databases.
A substitute like SQLite is deliberately not used: it cannot reproduce the custom DQL functions (`RAND`, `YEAR`),
`EditLockService`'s named locks, the UUID and `uca1400` columns, or what the ledger asks of PostgreSQL. The schemas
are built by `SchemaTool` (**no migrations**) and the full `DataFixtures` set is loaded once by `make test-prepare`;
`dama/doctrine-test-bundle` then wraps each test in a transaction and rolls it back, so tests share the seed yet never
leak writes. `DatabaseTestCase` boots the kernel and grabs the managers — **query the seeded fixtures, don't hand-build
entity graphs**. Pure domain logic (mappers, voters, guard listeners, enums) stays a DB-less `TestCase`.

## Translatable strings

`make translations` extracts into `translations/{messages,validators}.{en,nl}.xlf`. Never hand-roll
`bin/console translation:extract` — the Makefile target sets the project's expected flags (`--sort=asc --no-fill
--force --clean`). `--clean` removes entries no longer referenced in source, which is safe for the `validators` domain
because Symfony falls back to the vendor `validators.{en,nl}.xlf` for any key not in the project file.

**Extraction alone is not enough.** Because of `--no-fill`, new entries land with an empty `<target/>` in *both* the
`en` and `nl` files, and an empty target is used *as* the translation, so the interface renders blank rather than
falling back. Find them with

```sh
grep -rn -e '<target/>' -e '<target></target>' translations/
```

then fill every one: `en` targets get the source text verbatim, `nl` targets your best Dutch translation. Always list
the Dutch translations you wrote in your final report so a human can review them — never leave empty targets behind
silently.

Two extraction traps:

- `t()` and `TranslatableMessage` are extracted by their **literal** argument. An enum that builds a label must put the
  constructor *inside* each match arm:

  ```php
  return match ($this) {
      self::Chair => new TranslatableMessage('Voorzitter'),
  };
  ```

  Not `new TranslatableMessage(match ($this) { ... })` — the extractor cannot see through that, and `--clean` then
  deletes every translation for those labels.
- A bare string handed to the form theme for it to translate — a `help:` on a `form_row()` call — is invisible to
  the extractor, so `--clean` deletes its translation while the template still uses it. Write `help: t('…')`
  instead; Twig's `t()` is extracted and yields a `TranslatableMessage` the theme still translates. Put the
  parameters inside `t()` rather than in `help_translation_parameters`, because the `trans` filter refuses to take
  its own arguments alongside a `TranslatableMessage`.
- In form types, wrap user-facing labels and `invalid_message` strings with `t()`
  (`use function Symfony\Component\Translation\t;`). Symfony's PHP extractor does not recurse into `RepeatedType`'s
  `first_options` / `second_options`, so plain `'label' => 'My label'` strings nested there are silently skipped;
  `t('My label')` is always picked up. Don't eagerly call `$translator->trans()` at form-build time — it locks the
  locale before render and bypasses the form renderer's own translation pass.

## Local development workflow

- `make start` — build the images and start the stack. Creates `.env.local` from `.env.local.dist` if it is missing.
- `make seed` — load the fixtures (`app:fixtures:load` seeds both connections: the ledger first, then the web
  database), rebuild the projection, and prepare Mailman and Listmonk. Seeded login: member number `8000`, password
  `gewiswebgewis`; the register is reached with the same account, `8000` holding both `ROLE_ADMIN` and
  `ROLE_DATABASE_ADMIN` while `8001` holds only the former and `8002` only the latter. (Migrations have already run by
  the time you get here — they fire one-shot on container start.)
- `make bash` — shell into the FrankenPHP `app` container; `make exec cmd="..."` runs a single command in it.
- `make sf c=...` / `make composer c='...'` — the console and Composer inside the container.
- `make cc` — clear the cache and restart the worker.
- `make stop` / `make logs` — as named.

**Where a variable lives.** `.env` is committed and holds almost nothing — only `APP_ENV`. Docker compose reads it
too, so a value there would silently satisfy the `${VAR:?...}` guards in `compose.yaml` that are supposed to catch a
missing production variable, and `composer dump-env prod` would bake it into the production image as a fallback. So:

- **`config/services.yaml`, as `env(NAME): default`** — a constant with a sane fallback rather than something a
  deployment sets: the mail display names, the pinned Stripe and Mailman API versions, the watermark tag, the TU/e
  subnets. Changing one is a code change.
- **`.env.local`** — the whole of development, copied from `.env.local.dist` by `make start`. Both Symfony and compose
  read it, so the containers that have no dotenv chain of their own (the databases, pgadmin, Matomo, the Stripe CLI)
  are configured from the same file.
- **`.env.test`** — the test suite, which does not read `.env.local` at all. CI loads the same file into the job.
- **The orchestrator** — production, which is Portainer's stack environment for `compose.yaml`.

Hot reload covers almost everything in dev: FrankenPHP reloads the workers on source changes, and the dev entrypoint
(`docker/web/docker-entrypoint.sh`) watches `assets/` to rebuild Sass and the asset map. You should very rarely need
`make cc` or a container restart — reach for them only if something genuinely will not budge. `vendor/` lives in the
image rather than the bind mount; `make getvendordir` copies it out for the IDE to index.

Development mail is caught by MailPit; other locally exposed services are listed in `README.md`, with their ports in
`compose.override.yaml`.

### Validating changes in the browser

CSRF protection is **stateless** (`config/packages/csrf.yaml`) — validation relies on a double-submit cookie plus
Origin/Referer checks, with `assets/controllers/csrf_protection_controller.js` generating the token client-side. A
hand-crafted `curl` POST therefore fails CSRF by design. **Do not "verify" forms, login or sign-up flows with curl** —
a 4xx proves nothing about your change, and you cannot bypass the protection.

Validate interactive flows in a real browser with Playwright instead (use your own browser tooling — there is no
Playwright setup in this repository), against `http://localhost`. Seed first with `make seed`, then log in with one of
the seeded accounts above. Outbound mail is visible in MailPit, which is what the external sign-up verification,
password-reset and membership flows send through.

## Messaging & scheduling

Messenger is backed by RabbitMQ in dev, in-memory in test. Buses, transports and routing are in
`config/packages/messenger.yaml`. Message classes go in `src/Message/`; handlers in `src/MessageHandler/` with
`#[AsMessageHandler]`. Recurring work runs through `src/Scheduler/` (Symfony Scheduler), which is also where the
register's periodic jobs — mailing list synchronisation, expiry sweeps, renewal reminders — are declared.

## File storage

Uploaded and generated files live under `data/`, never under `public/`. `StorageNamespace` maps each domain onto its
directory and carries the rules with it — whether the namespace is scoped (photos per album, company assets per
company, meeting documents per meeting), whether serving a file needs an authenticated signed request, which MIME
types it accepts and how large a file may be. `FileStorage` is the only writer, over a Flysystem adapter rooted at
`data/` (in-memory under test). Public images are served through `ImageUrlBuilder` as `/img/{variant}/{path}`; nothing
is linked to by its path on disk.

**A web request never encodes an image.** Serving reads the pre-generated variant cache only; a miss on an existing
original queues one `GenerateImageVariantMessage` on the `images` transport (deduplicated through a shared-cache
marker) and answers 503 with `Retry-After` (`ImageVariantResponder`). The variants exist because uploads queue them
(`ProcessImageVariantsMessage`) and because `app:image:pregenerate` queues the rest
(`PregenerateImageVariantMessage`). That command only dispatches, so a backfill is encoded by the `messenger-images`
workers rather than by whichever container the command was run from, and `IMAGE_WORKER_REPLICAS` is what paces it.
Run it after clearing the variant cache, and with `--force` after changing the variant set or the encoding.
Synchronous encode-on-miss is what once saturated the production host; do not reintroduce it.

The application inherits one legacy pool: the flat content-addressed tree GEWISWEB wrote at `public/data`, holding
every photo, company and organ image, course document, page-embedded image and meeting document. `app:storage:migrate`
migrates all of it in one run — there is no second source, and GEWISDB never stored files. The run is journalled per
item and so is resumable, and `--source-dir` names the pool for the production layout, where it arrives as a populated
volume rather than at `public/data`. See the command's `--help`.

Its `--meetings` phase is the odd one out and the one to be careful with. Everything else is hardlinked into place by
`--files` and switched over by `--paths`, so those files survive the pool being removed; the flat meeting documents and
minutes are touched by neither, and are carried over only by `--meetings`, which rebuilds them into the
agenda-point/version model and *copies* their files. Until it has run, the pool is the only copy of every set of
minutes on the site. It skips per row what the new model already has — an earlier run's work, or the board's own — so
it can be run again at any time, and every run of the command ends by saying how many legacy rows still have no
counterpart.

## Doctrine caching

The projection's `Member` is the only entity in Doctrine's second-level cache (`config/packages/doctrine.yaml`, region
`member_region`). It is read almost everywhere — sign-up lists, photo tags, decision rendering — and changes only when
the projection is written, which makes by-ID loads (`find()`, lazy associations) worth serving from cache. DQL queries
that select Members still hit the database unless explicitly marked cacheable.

Deliberately **not** cached: `User`, because MFA enrolment toggles must take effect on the next request, and
`UserRole`, because admin grants and revocations need to be visible immediately.

Anything that writes a cached entity outside the ORM has to evict its region by hand — Doctrine cannot see a raw DBAL
write. If you add caching to another entity, check how it is written before choosing a strategy.

## Revision workflow

Activities, companies, vacancies, body pages and polls are created and edited through a revision-based approval
workflow. Read this before touching any of those domains.

- **Contracts** in `src/Entity/Application/`: `RevisableInterface` (the stable aggregate — `Activity`,
  `Career\Company`, `Career\Vacancy`, `Decision\OrganInformation`, `Frontpage\Poll`: identity, revision chain,
  `markRevisionLive()`), `RevisionInterface` / `AbstractRevision` (a MappedSuperclass snapshotting all editable content
  per revision), and `Enums/RevisionStatus`.
- **Lifecycle** (`config/packages/workflow.yaml`, state machine `revision`): draft → submitted → in-review →
  { approved | changes-requested | rejected → closed }. The marking store is custom
  (`App\Workflow\RevisionStatusMarkingStore`, bridging the status enum). Only a *draft* revision is mutable; approval
  promotes it to the live revision.
- **Guards and side effects** are event listeners, not workflow config: `RevisionGuardListener` delegates to
  `App\Security\Application\RevisionVoter` (organ/creator/company scoping); `SpawnNextDraftListener` clones a new draft
  after changes-requested; `PromoteLiveRevisionListener` and `MigrateSignupsOnApprovalListener` run on approval;
  `NotifyOnRevisionSubmissionListener` tells the reviewers. A domain may add its own guard on top
  (`Activity/PastActivityGuardListener`, `Frontpage/PollRevisionGuardListener`); they are additive, so all must pass.
- **Per-domain registries** tagged by interface, so a new revisable domain arrives with its own answers rather than
  editing a shared `match`: `RevisionDescriberRegistry` (what the review screen shows),
  `RevisionNotificationRegistry` (which notification a submission raises and who it goes to), and
  `RevisionCommentRepositoryInterface` (the review discussion). Review screens extend
  `Controller/Application/AbstractRevisionReviewController` and share `templates/partials/application/review-*`.
- **Cloning**: each domain implements `App\Workflow\RevisionClonerInterface` (deep copy, bump revision number, link
  `previousRevision`), routed through `RevisionClonerRegistry`. A revisable domain that can be edited adds a cloner,
  never bespoke copy logic. A poll deliberately has none: it is written and submitted in one request,
  `request_changes` is withheld by its guard, and asking the question again writes a new revision from scratch.
- **Edit locking**: `Entity/Application/EditLock` + `Service/Application/EditLockService` give one user at a time
  exclusive editing (90-second ping TTL, serialized via MariaDB `GET_LOCK`, reviewers can force-take). The
  `application/edit_lock_controller.ts` Stimulus controller does the pinging.
- **Activity sign-ups** are versioned with the revision: each `ActivityRevision` owns clones of its `SignupList`s,
  matched across revisions by `lineageId`; on approval `Service/Activity/SignupListMigrator` carries live sign-ups over
  (and guards block approval if migration would lose data). All sign-up writes go through
  `Service/Activity/SignupManager` — members sign up directly, external guests get double-opt-in email verification
  plus Altcha.

## Things to be careful about

- **The ledger and its projection move together.** A column added to `src/Entity/Database` that never reaches
  `src/Entity/Decision` and `src/Service/Report` is a silent divergence between what the association decided and what
  it is told it decided.
- **Decisions are the historical record.** They are amended by further decisions, never edited. Subdecision `sequence`
  is part of the primary key and is copied downstream, so subdecisions cannot be reordered. Don't "fix" past data by
  editing decision rows; model corrections as new decisions or sub-decisions.
- **Nothing joins across the two connections.** If a query needs the ledger and the website's data at once, it is two
  queries and a match in PHP.
- **The API is a contract.** Other GEWIS systems read it. The envelope, status codes and version negotiation are not
  yours to change casually.
- **FrankenPHP worker mode.** Avoid static state, lazy-singleton patterns, mutable globals and runtime container
  mutation — these survive across requests in worker mode and cause subtle leaks. Run `make igor` before claiming work
  done.
- **Route ordering.** `page_route` and `catch_all` in `config/routes.yaml` are sensitive to attribute-scan order.
  Don't reorder this file lightly — that is how the voting-committees 404 bug happened.
- **Several kinds of user.** Any new authorization logic must reason about `User` and `CompanyUser`, and about the
  roles a `User` holds only while in office. Don't assume `$this->getUser()` returns a member.
- **Custom remember-me.** The per-firewall handlers, cookie names and lifetimes are deliberate. Read `security.yaml`
  and `session.yaml` before touching them.
- **Non-draft revisions are immutable.** Only a `draft` revision may be edited; everything after submission is
  history. To change approved content, spawn a new draft via the cloner registry and run it through the workflow —
  never mutate a submitted or approved `*Revision` row directly.
- **CSRF can't be curl'd.** Stateless CSRF plus Origin checks mean scripted POSTs fail by design — validate flows in a
  real browser.
- **No factories.** Never introduce factory classes.
- **Build artifacts.** Treat `vendor/`, `var/`, `public/assets/` as read-only.
- **Commits.** Conventional-commit prefixes (`feat:`, `fix:`, `chore:`), and they must be signed.

## When you don't know

Read the nearest sibling: the existing controllers, services and listeners in the same domain folder are the canonical
reference, and the two halves of the application are expected to solve the same problem the same way — form themes,
page layout, Live Component shape, mail, dev tooling. If the question is genuinely unclear — especially anything
touching auth, routing order, or the boundary between the ledger and its projection — ask rather than guess: getting
the projection wrong corrupts what every other GEWIS system reads.
