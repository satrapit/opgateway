# Changelog

## [Unreleased]

- Add private browser route dispatcher for sign-in, admin landing and sign-out.
- Add strict URL-encoded form decoding and bounded request stream handling.
- Add unit tests rejecting duplicate parameters and malformed Content-Length.
- Keep public entrypoint unchanged; no admin endpoints activated.
- No new schema, manual SQL import or hosting CLI requirements.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
