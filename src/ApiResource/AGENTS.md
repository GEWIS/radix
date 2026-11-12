# API

Applies to `src/ApiResource/`, `src/State/`, `src/Serializer/Api/`, `src/Service/Api/` and `src/Controller/Api/`.

Everything under `^/api` is stateless, read with `Authorization: Bearer <token>`, and answers the same envelope:

```json
{"status": "success", "data": …, "meta": {"page": 1, "itemsPerPage": 100, "totalItems": 4213, "totalPages": 43}}
{"status": "forbidden", "error": {"type": "…", "exception": "…"}}
```

- `meta` is present on every collection and nowhere else; `/api/docs.json` is the one path exempt, because an OpenAPI
  document has to stay one.
- **The envelope, status codes, wire names and version negotiation are a contract with other GEWIS applications.** A
  field name change changes someone else's input. `openapi.yaml` publishes it and is **generated**: run `make openapi`
  after touching a resource, never edit it by hand. `ApiDocumentationTest` fails on drift.

## Where things live

- `src/ApiResource/<Domain>/`: plain `final readonly` DTOs with `#[ApiResource]`; property declaration order is the JSON
  key order; the directory is excluded from the container.
- `src/State/<Domain>/`: providers that build them from the projection; `src/State/Api/` holds the shared plumbing
  (`EnvelopeProcessor`, `CollectionPagination`).
- `src/Serializer/Api/`: serializer contexts, including the permission-driven groups of the member payload.
- `src/Service/Api/`: the decorator that completes the generated document.
- `src/Controller/Api/ApiController` keeps the endpoints that are not resources: `/health`, the two function lists,
  the example error endpoint, and the catch-all that makes an unknown `/api` path answer `error-router-no-match`. **That
  catch-all MUST stay the last method in the class.**
- Both the API and the website read the projection, never the ledger directly.

## Rules

- Every operation carries `security:` and `securityMessage:`; the attribute is an `ApiPermissions` wire value decided by
  `App\Security\Api\ApiPermissionVoter`.
- Every collection is paged (default 100, maximum 500), and a page without a deterministic `ORDER BY` is a bug. A
  collection provider that returns a plain array instead of a paginator silently drops `meta`.
- Nothing a request asks for is worth a 400: a page below 1 is clamped, one past the end is an empty page, and query
  parameters are read through `App\Util\Application\QueryValue`.
- A permission that names people is enforced twice: as the operation gate, and as a row filter for deleted members
  (`ApiPermissions::MembersDeleted`) applied in the query rather than to the page, or the totals stop matching.
- **Versioning.** Everything added when the API moved onto API Platform requires a contract version, declared as
  `Accept: application/vnd.gewis.gewisdb+json;version=5.0.0` or `X-Api-Version: 5.0.0` (the second exists because
  Swagger UI cannot send an `Accept` parameter). A new operation declares
  `extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT]`; `App\State\Api\MinimumVersionProvider` enforces it,
  after the permission check and before the read. The member endpoints that predate the versioned contract answer
  without one; that list is `ApiOpenApiFactory::UNVERSIONED_PATHS` and it does not grow.
- `GET /api/members/{lidnr}` answers **204 with no body** for a member that does not exist *or that the principal may not
  see*. It is the legacy contract, not an oversight, which is why `MemberProvider` returns a `Response`.
- Swagger UI answers on `/api-docs`, outside `^/api`, because a browser cannot send an `Authorization` header on the
  first navigation. It is public, since `openapi.yaml` is committed to a public repository.
