# Satellite snapshot contract v4 (`schema_version = 4.0.0`)

v4 is selected only from the last persisted describe response when all three conditions are true:

- `snapshot_schema_versions` is a well-formed unique list containing `4.0.0`;
- `product_content_languages` is a non-empty, unique, canonically sorted list;
- `product_documents` is exactly `{ "version": "1.0.0" }`, with no extra keys.

Any missing or malformed document capability falls back through the existing v3, v2 and v1 rules.
Versions 1–3 retain their frozen rows, manifests and file sets.

The v4 product is structurally v3 plus the required `documents` list. Every item contains the stable
core document id, title, position, content-addressed snapshot filename, SHA-256, byte size, original
filename and nullable `language`/`type`. The latter two values are emitted only when source
provenance has a valid HTTP(S) URL, exact match basis, and its digest and size match the attached bytes. Manual or
unvalidated metadata is represented by JSON `null`. Source URLs and provenance never enter the wire
contract.

Each unique PDF is stored once as `document-<sha256>.pdf`. Its manifest entry has
`role: product_document`, `kind: application/pdf`, and `rows: 0`. Document entries follow the frozen
NDJSON order and are sorted by filename. The producer checks media identity, MIME, `%PDF-` signature,
size and digest through bounded streams before atomically publishing the artifact.

Producer limits are 20 MiB per PDF, 8 attached documents per product, 512 unique PDFs and 2 GiB of
unique PDF bytes per snapshot. Exceeding a limit fails the new snapshot and removes its directory;
the previous published snapshot remains available.
