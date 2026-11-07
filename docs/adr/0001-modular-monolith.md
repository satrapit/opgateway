# ADR 0001: Modular monolith and fail-closed initialization

Status: Accepted for starter foundation (2026-11-07).

Context: PHP 8.3 / MySQL shared hosting without SSH, Redis, Supervisor or production Composer.

Decision: a single PHP application with explicit module boundaries and a public-only webroot. Deny all upstream calls until authentication, route policies, IP authorization, tenant isolation, and contract verification are implemented.

Consequences: small deployment footprint; manual release packaging. Future middleware and PSR abstractions may be added after a dependency review. No false claim of production readiness.
