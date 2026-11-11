# Routing & locale

The application is bilingual (`en`, `nl`).

- `localised_routes` in `config/routes.yaml` attribute-scans `src/Controller/` and applies the `/en` and `/nl` prefixes; controllers use locale-agnostic `#[Route]` paths.
- **The register answers under `/{_locale}/admin`.** `src/Controller/Database` carries its paths below that prefix, so its routes keep a single name each (`member_index`, not `member_index.en`). `/{_locale}/database` still answers, permanently redirecting to wherever each page moved (`ApplicationController::legacyRegister`).
- **`page_route`** (custom-pages catch-all) and **`catch_all`** (404 fallback) are defined in `config/routes.yaml`, not as attributes. Order matters: a locale-prefixed route has to be declared **above `page_route`**, whose `/{_locale}/{category}/{subCategory}/{name}` otherwise reads three segments of it as a missing page. Don't reorder this file lightly.
- **There is no session-stored locale.** A request's language is its `_locale`; `App\Service\Application\LocalePreference` answers for the handful of addresses that have no room to say it. E-mail is written in English and links to `/en/…` explicitly.
- **Live-component routes are locale-prefixed** (`config/routes/ux_live_component.yaml` → `/{_locale}/_components`); without the segment, re-renders come back in the framework default.
- Addresses that carry no locale (sign-up and renewal links on posters and in mailboxes, checkout return addresses, `/user/token/{app}`, `image_serve`, the public JWKS document, the Stripe webhook and `/health`) answer through `App\Controller\Application\LocaleRedirectController`. The redirect is a **302**, not a 301: where it lands depends on `Accept-Language`, so it must not be remembered.
- New controllers under `/{_locale}/{admin, user/settings, user/security, company/security}` are automatically behind sudo; see `src/Security/AGENTS.md`.
