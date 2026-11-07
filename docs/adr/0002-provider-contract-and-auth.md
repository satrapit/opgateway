# ADR 0002: Provider contract and credential separation

Status: Proposed (2026-11-07).

Decision: version-pin an official verified OpenAPI artifact once the current download, rights and checksum have been reviewed. Gateway client credentials must be distinct from Openprovider reseller credentials. Do not forward inbound Authorization headers to Openprovider; instead supply server-held upstream Bearer tokens. Any public contract difference must be documented and tested before enablement.

Open question: compatibility behavior of `/v1/auth/login` for existing clients and upstream operation-specific ownership guarantees.
