# Entities, the projection and caching

Read the root `AGENTS.md` first; this adds the detail for `src/Entity/`, `src/Service/Decision` and the Decision listeners.

## The projection

The projection is not a cache to bypass, and not a copy to let drift.

- `App\Service\Decision` writes it.
- `App\EventListener\Decision\DatabaseUpdateListener` and `DatabaseDeletionListener` keep it level with the ledger as the ledger is written.
- `app:decision:generate` rebuilds it from the ledger. Run it after a bulk change.
- `App\EventListener\Decision\BulkLoadListener` stands the listeners down while fixtures load.
- A change to a ledger entity (`src/Entity/Database`) MUST come with the matching change in `src/Entity/Decision` and `src/Service/Decision`.
- `make sf c=check:database` runs the consistency checks from the Articles of Association and Internal Regulations. Run it after anything that changes decisions or installations; it is the only thing that catches data the regulations forbid.

## Doctrine second-level cache

- The projection's `Member` is the only entity in the second-level cache (`config/packages/doctrine.yaml`, region `member_region`). It is read almost everywhere and changes only when the projection is written, so by-ID loads (`find()`, lazy associations) are served from cache. DQL queries that select Members still hit the database unless explicitly marked cacheable.
- Deliberately **not** cached: `User`, because MFA enrolment toggles must take effect on the next request, and `UserRole`, because admin grants and revocations must be visible immediately.
- Anything that writes a cached entity outside the ORM MUST evict its region by hand; Doctrine cannot see a raw DBAL write. If you add caching to another entity, check how it is written before choosing a strategy.

## Mapping

Attribute mapping with short-name imports (`use Doctrine\ORM\Mapping\Entity;`, `#[Entity]`). Domains may add `Enums/` and `Traits/` subfolders. Enums that build translatable labels have an extraction trap: see `translations/AGENTS.md`.
