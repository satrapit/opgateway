# Security policy

This repository contains a security-sensitive API gateway and an unreleased
self-service/SMTP/provider integration branch. Please use GitHub private
vulnerability reporting (if enabled) rather than publishing exploit details
or credentials in issues.

Gateway passwords are one-way hashed. SMTP and Openprovider credentials
are encrypted using an independently protected, private file key at
`storage/app-secret.key`; it is **not** saved in MySQL or Git and must
be backed up. The private `.env` contains only MySQL connection values.

Do not enable new mutating or paid upstream operations without reviewed
authorization, ownership, idempotency and versioned API compatibility.
Never put request bodies, passwords, access tokens or customer data in
issue reports or logs.
