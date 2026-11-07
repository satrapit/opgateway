# Upstream API compatibility — pending contract review

Verified against official Openprovider developer portal on 2026-11-07:

- Base URL: `https://api.openprovider.eu/v1`.
- Authentication: `POST /v1/auth/login` with reseller username/password, returns `data.token` (Bearer).
- Authorized requests use `Authorization: Bearer ...`.
- Responses may include `code`, `data`, and `desc` envelope. A nonzero code signals a business error.

References: https://developer.openprovider.com/get-started.html and https://developer.openprovider.com/api-keys.html.

**Important discrepancy:** the public legacy `openprovider/api-documentation` repository identifies the `v1beta` branch and older base path. Do not pin that legacy spec as a `v1` contract without checking the current downloadable official spec and its version and checksum. Specification download, license terms and endpoint coverage remain **unverified**.

## Authentication adapter design

A client must not receive the reseller token. The Gateway issues its own expiring scoped client credential/token, and maps it to a server-side upstream token. A compatible `/v1/auth/login` facade may be implemented only after careful validation; differences (credential format, expiry, error shape, ownership) must be documented. Until then, API compatibility remains a design goal, not a guarantee.

## Route approval

Generate explicit method/path policies from a verified version-pinned spec. Reject unknown routes. Separate `/gateway/*` control-plane paths from `/v1/*` upstream-compatible routes. Financially consequential or tenant-unsafe endpoints remain disabled.
