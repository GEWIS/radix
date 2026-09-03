# @gewis/radix-client

TypeScript client for the GEWIS API, generated from the OpenAPI document radix publishes.

Nothing here is written by hand. `openapi.yaml` in the root of this repository is generated from the application and
kept in step with it by `ApiDocumentationTest`, and this package is generated from that file, so the client can never
describe an API the application does not serve.

## Install

```
npm install @gewis/radix-client
```

## Use

Every path is read with a bearer token belonging to an API principal, and what that principal may read is decided per
permission. Everything except the oldest member endpoints also needs the contract version, which this package states
as `RADIX_API_VERSION` so it does not have to be written out by hand.

```ts
import { ActivityApi, Configuration, RADIX_API_VERSION } from '@gewis/radix-client';

const api = new ActivityApi(
  new Configuration({
    basePath: 'https://gewis.nl',
    accessToken: process.env.RADIX_API_TOKEN,
  }),
);

const { data } = await api.apiActivities({ xApiVersion: RADIX_API_VERSION, past: true });

for (const activity of data.data ?? []) {
  console.log(activity.name?.en, activity.category);
}

console.log(data.meta?.totalItems);
```

Every field is optional in these types. The document declares no `required` on its resource schemas, so the
generator marks all of them `?`, and a consumer with `strict` on has to guard each access. That is looseness in the
document rather than in the client, and it is worth fixing there.

Responses carry the envelope the API answers with, so the payload is `data.data` and a collection also has
`data.meta` with the page. A failure is an axios error whose body is one of `ResponseError`, `ResponseErrorForbidden`
or `ResponseErrorNotFound`, each pinned to the one `status` it carries, so `status` tells them apart.

Fields the register stores as an enum are enums here as well, rather than strings: `ActivityCategoryEnum`,
`BodyTypeEnum`, `MembershipTypeEnum`, `BoardFunctionEnum` and `OrganFunctionEnum`. Both the CommonJS and the ES module
build are shipped, and the document itself is included as `@gewis/radix-client/openapi.json`.

## Versions

A release of radix publishes the client under the same number, so `@gewis/radix-client@5.3.2` is the client of radix
`v5.3.2`. Pushes to `main` publish a snapshot under the `dev` tag, numbered after the release it is built on and how
far past it that commit is, because which release comes next is not decided at that point.

```
npm install @gewis/radix-client        # the newest release
npm install @gewis/radix-client@dev    # the newest snapshot
```

That number says which release the client was generated from. It is not what goes on the wire. The wire value is
the contract, which this package states as `RADIX_API_VERSION`: the newest version any endpoint in this client
requires, so every one of them answers it. It moves only when the contract does.

Each endpoint publishes its own bound in the document as `x-gewis-version-minimum`, alongside
`x-gewis-version-maximum` for one kept alive only until its consumers have moved, so a client that wants to reason
about them can read `@gewis/radix-client/openapi.json` rather than this constant.

## Working on it

The generator needs Java; nothing else needs a running radix, because the document it reads is committed.

```
pnpm install --frozen-lockfile --ignore-scripts
pnpm run generate    # openapi.yaml -> generated/
pnpm run build       # generated/ -> dist/
pnpm run smoke       # loads both builds the way a consumer does
```

`--ignore-scripts` because the generator's only install script prints a donation banner, and the jar it actually
runs is fetched on the first `generate`. Without it `pnpm install` stops on a build it was never going to need.

`make client` in the root of the repository does all of this.
