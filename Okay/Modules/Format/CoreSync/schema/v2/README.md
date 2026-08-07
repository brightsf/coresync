# CoreSync snapshot v2 consumer schema

`schema_version=2.0.0` keeps every v1 entity contract unchanged except categories. Category rows
carry structural fields, strict source identity, sparse translations keyed by `href_lang`, and an
optional integrity descriptor for one language-neutral image. `image: null` means no change.

The runtime consumer narrows `source_identity.id` to a positive decimal source id, requires the
configured `source_instance` to match exactly, and verifies image bytes, SHA-256, MIME and decoding
before atomically switching the local category image.
