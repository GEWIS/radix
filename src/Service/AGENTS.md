# File storage and images

Uploaded and generated files live under `data/`, never under `public/`.

- `StorageNamespace` maps each domain onto its directory and carries the rules with it: whether the namespace is scoped (photos per album, company assets per company, meeting documents per meeting), whether serving a file needs an authenticated signed request, which MIME types it accepts and how large a file may be.
- `FileStorage` is the only writer, over a Flysystem adapter rooted at `data/` (in-memory under test).
- Public images are served through `ImageUrlBuilder` as `/img/{variant}/{path}`; nothing is linked to by its path on disk.

**A web request never encodes an image.** Serving reads the pre-generated variant cache only; a miss on an existing original queues one `GenerateImageVariantMessage` and answers 503 with `Retry-After`. Variants exist because uploads queue them (`ProcessImageVariantsMessage`) and because `app:image:pregenerate` queues the rest. That command only dispatches, so a backfill is encoded by the `messenger-images` workers, paced by `IMAGE_WORKER_REPLICAS`. Run it after clearing the variant cache, and with `--force` after changing the variant set or the encoding. Synchronous encode-on-miss once saturated the production host; do not reintroduce it.

See also `src/Entity/AGENTS.md` for how `Service/Decision` maintains the projection.
