# Offline JSON Schema metaschemas

These are unmodified JSON documents from the official
[JSON Schema specification repository](https://github.com/json-schema-org/json-schema-spec).
The exact source commit, path, SHA-256 and Git blob ID of each file are recorded
in `manifest.json`. The snapshots use the maintained `draft-06`, `draft-07`,
`2019-09` and `2020-12` branches at the pinned commits, including published
errata, rather than early specification tags.

The SDK registers these documents by their official URI **in memory**. The URIs
are identifiers: runtime validation never fetches them. A separate empty
resolver handles the tool's own references, allowing references within the
provided document and embedded resources, and rejecting unresolved external
references. Do not register arbitrary file paths or network protocols here.

The metaschemas are redistributed under the upstream **BSD 3-Clause** option.
`LICENSE` preserves the upstream copyright, terms and dual-license text; its
pinned official source is recorded in `manifest.json`. The SDK's own MIT license
is unchanged.

Tests verify that every snapshot is a JSON object with the declared `$id`, and
that SHA-256 and Git blob hashes match its manifest. Runtime initialization also
checks SHA-256 and successful in-memory registration. These are integrity checks
for a pinned bundle, not an assertion that it is the latest upstream revision.
