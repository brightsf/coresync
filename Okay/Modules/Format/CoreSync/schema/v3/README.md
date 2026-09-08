# Satellite snapshot contract — v3 (`schema_version = 3.0.0`)

The three JSON Schemas in this directory are byte-for-byte vendored from producer commit
`50543ef0652a22c94d06ab4a020cd90151c02fef` in b2bCRM. Their SHA-256 digests are pinned by
`ProductV3SchemaTest`; update them only from a separately accepted producer contract.

V3 preserves the v2 structural product/category fields and adds a required, sparse product
`translations` list plus manifest `product_content_languages`. Numeric Okay language IDs are local
implementation details and never cross the wire. Runtime validation additionally enforces sorted,
unique language membership and exact local `href_lang` resolution before any apply write.

The shared cross-repository fixture is mounted read-only at test runtime through
`SATELLITE_I18N_CONTRACT`; missing fixture/schema evidence is a failure, never a skipped test.
