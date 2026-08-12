# Satellite snapshot contract — v2 (`schema_version = 2.0.0`)

v2 is negotiated additively through the persisted describe capability
`capabilities.snapshot_schema_versions`. The producer selects v2 only when the list is well-formed,
has no duplicates, and contains the exact string `2.0.0`; every other shape remains on the frozen v1
producer. `description.schema_version` remains the major-1 describe ceremony version.

The disk layout, row wrapper, canonical hashing, file order, and price/stock behavior are identical
to v1. A v2 category contains structural fields, actual raw
translations of active languages, an optional strict Okay source identity, and an optional integrity-
checked public image descriptor. Missing translation rows are never fabricated through fallback.
`null` translation fields are omitted; explicit empty strings remain present.

Product source identity is a separately advertised additive capability. It is enabled only when the
persisted describe selected snapshot v2 and contains the exact object
`product_source_identity: {namespace: "okay", instance: "<slug>", entities: ["product", "variant"]}`.
Malformed, partial, duplicate, wrong-namespace, or truthy non-object values fail closed. While the
capability is enabled, every product and variant carries `source_identity`: a strict positive legacy
id parsed from `okay:product:<id>` / `okay:variant:<id>`, or JSON `null` only for a core-new/manual
entity whose `external_id` is actually null. A malformed non-null identity fails the snapshot before
publication; it is never downgraded to a legacy SKU fallback. The row `external_id`, SKU, ordering,
and hash algorithm are unchanged.

`source_identity` remains optional in `product.schema.json` for compatibility with v2 artifacts that
predate this separately negotiated capability. Consumers may bind by it only when the exact capability
was advertised; when advertised, the producer always emits both nullable keys.

Category slug collision ownership is per category, not per locale. Every distinct non-empty slug that
actually exists on a category participates in collision detection against other categories, while the
same Okay slug repeated in RU and UK on that one category is one owner. The redirect registry tracks
the actual slug of the channel default-language row; if it is absent, the first actual slug in language-
code order is used. No legacy flat/fallback slug is consulted.

Category images remain language-neutral. `image` is emitted only when the media row carries the exact
lowercase SHA-256 saved at upload, valid image MIME/size metadata, and a safe absolute public URL. An
internal, signed, relative, malformed, or private literal URL produces `image: null`; category text is
still emitted. Descriptor URLs and hashes are snapshot data and are never copied to logs or operator
errors.

Schemas are documentation artifacts, as in v1. `category.schema.json` and `manifest.schema.json` are
recursively closed allow-lists; `golden/categories.ndjson` pins canonical key ordering and hashing.
