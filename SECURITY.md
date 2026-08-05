# Security policy

## Supported versions

Only the latest tagged release and the current `main` receive fixes. This is a
single-deployment research module; there are no maintained back-branches.

## Reporting a vulnerability

Please report privately through GitHub's
[private vulnerability reporting](https://github.com/fmadore/IwacSearch/security/advisories/new)
rather than opening a public issue. Expect an acknowledgement within a few
working days.

## What is security-relevant here

The module's job is to expose a public archive without leaking the parts of it
that are not public. Two controls carry that weight, and both are deliberately
hardcoded rather than configurable.

**The public scoped key.** `TypesenseSearchKeyProvider::mintPublicScopedKey()`
mints a 1-hour Typesense scoped key carrying both
`filter_by: is_public:=true` and `exclude_fields: ocr_text,toc_txt`. The first
keeps non-public records out of every response; the second keeps full OCR text
out of responses for the records that _are_ public. They are belt-and-braces:
either one failing alone should not disclose anything, and neither is driven by
site configuration. Anything that widens or bypasses that key is in scope.

**The admin API key.** It is read from `/run/secrets/typesense_api_key` by
`Service\TypesenseClientFactory`, which is the only reader. It must never reach
a response body, a template, a log line, or the browser. The browser only ever
receives a scoped key.

A page block's `locked_filters` are **not** a security boundary. They are
cosmetic client-side scoping for curated pages, and the module's own
documentation says so ([`src/Site/BlockLayout/IwacSearchBlock.php`](src/Site/BlockLayout/IwacSearchBlock.php)).
A report whose only claim is that `locked_filters` can be edited away in the
browser is expected behaviour, not a vulnerability — the scoped key is what
actually constrains the result set.

## Out of scope

- Findings in [Omeka S](https://github.com/omeka/omeka-s) itself, or in
  [Typesense](https://github.com/typesense/typesense) — report those upstream.
- Deployment concerns owned by the companion
  [IWAC-docker](https://github.com/fmadore/IWAC-docker) stack (TLS termination,
  the nginx `/search-api/` proxy, container secrets, backups).
- Missing hardening headers on `islam.zmo.de` that have no exploit path.
- Automated scanner output submitted without a working proof of concept.
