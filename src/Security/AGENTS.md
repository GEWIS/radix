# Security & authorization

The firewalls are in `config/packages/security.yaml`. `^/api` is stateless; the interactive firewalls carry `form_login`, login throttling, two-factor authentication and a `UserChecker`.

## Three kinds of user

Any new authorization logic MUST reason about which one it has. Never assume `$this->getUser()` is a member.

- `App\Entity\User\User`: website members, keyed by `lidnr` (membership number); roles derived from member type, explicit `UserRole` rows, and self-assigned role.
- `App\Entity\User\CompanyUser`: corporate users, keyed by company id; always `ROLE_COMPANY_USER`.
- `App\Entity\Database\User\ApiPrincipal`: principals and tokens for machine access, on the ledger.

## The register has no accounts of its own

It is reached with a member's own `User`. Who may read or change it is decided by roles granted for as long as somebody is board member, derived in `User::getRoles()` from board installations rather than written against an account:

- `UserRoles::DatabaseAdmin` (`ROLE_DATABASE_ADMIN`) follows a serving secretary.
- `UserRoles::DatabaseReadOnly` (`ROLE_DATABASE_READ_ONLY`) a secretary who has been relieved but whose year is not yet discharged.
- `UserRoles::DatabaseMemberReadOnly` the current board.

When MFA enforcement is on, an in-scope user who has not enrolled has their protective roles stripped in `getRoles()`, so every `#[IsGranted]` and `access_control` check fails until they enrol; `MfaEnforcementListener` converts the exception into the redirect.

`ROLE_DATABASE_ADMIN` and `ROLE_DATABASE_READ_ONLY` are also withheld from a request that did not arrive from one of the subnets in `REGISTER_IP_RANGES`: `App\Security\Database\RegisterNetworkRoleHierarchy` filters them out of the reachable role set, so every check naming them fails and the register's menu links disappear. An empty list restricts nothing (what dev and tests run). The check refuses an address belonging to a trusted proxy, so getting `SYMFONY_TRUSTED_PROXIES` wrong shuts the register rather than opening it.

`App\Security\User\UserChecker` blocks login for deleted, hidden or expired members and members without an email. Authorization beyond roles lives in voters under `src/Security/`.

## Sudo

A time-bounded grant. `App\Security\User\SudoVoter` gates the `SUDO` attribute; `App\Security\User\SudoMode` keeps it in Valkey, scoped to the firewall, account and session it was given on. Two expirations: an idle TTL (1800 s, `app.sudo_idle_ttl`) extended by every guarded write (a GET does not extend it), and an absolute TTL (7200 s, `app.sudo_absolute_ttl`) measured from the confirmation, never extended.

- Whole areas are behind the grant, not lists of actions. `App\EventListener\User\SudoEnforcementListener` requires it on every address `App\Security\User\SudoArea` names (`/{_locale}/{admin, user/settings, user/security, company/security}`), so a new controller in any of them needs no `#[IsGranted(SudoVoter::ATTRIBUTE)]`.
- Deliberately **outside** the area: signing in, resetting a password and confirming sudo (under `/user` and `/company` but outside `/security`, so somebody without a grant can still get one); the live components an admin page is built from (under `/{_locale}/_components`, which carry the attribute themselves); and `/user/cosmetics`, which every page `fetch`es.
- Two places keep the attribute because the rule is about the object, not the URL: the review screens in `AbstractRevisionReviewController` and the administrative actions on `ProspectiveMemberController`.
- `App\EventListener\User\SudoGrantOnLoginListener` grants the permission on fresh sign-in so the just-typed password is not asked again. It follows the passport credentials, not the login: a remember-me cookie presents nothing to check. Do not reach for `InteractiveLoginEvent`; Symfony calls the remember-me authenticator interactive as well.
- **An XHR endpoint inside a guarded area answers a lapsed grant with the confirmation page, not its JSON**, which nothing the asking script can act on. Check this before putting a new `fetch` target inside one of these areas.

## Remember-me

A **custom** integration (`App\Security\User\PersistentSignatureRememberMeHandler`, persisted via the `Session` entity). Cookies, lifetimes and per-firewall handlers differ deliberately; read `config/packages/security.yaml` and `config/packages/session.yaml` before touching.

## Security logging

`App\Service\User\SecurityEventLogger` is the only way to record that something happened to an account (sign-in, a
session torn down, a second factor turned off, an administrator acting as somebody else).

- Call `record()` with a case of `App\Entity\User\Enums\SecurityEventType`. The address, browser, signed-in user and request are gathered there, so two places reporting the same event cannot describe it differently. Never use the plain logger for these, and pass a case, not a sentence: the administration filters on the cases and a data export names them.
- It writes twice. The `security` monolog channel is never buffered, so its copy survives a request that later failed and rolled back. `App\Entity\User\SecurityLog` is the queryable copy, read by `/admin/users/security-log` and the member data export.
- Rows are personal data: keep the columns to what recognises an event, flat and scalar. Never a token, a cookie or a session identifier. `app:user:prune-security-log` enforces retention.
- Every record carries the context the processors in `config/packages/monolog.yaml` add (`App\Monolog\Processor\RequestIdProcessor` among them).
