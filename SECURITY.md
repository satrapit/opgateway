# Security policy

This repository is in foundation stage and is not production ready. Please report vulnerabilities privately to the repository maintainers through GitHub private vulnerability reporting if enabled; do not open public issues containing exploit details or secrets.

Never share real upstream credentials, access tokens, customer data or request bodies with bug reports. Redact before submitting diagnostic records.

The unreleased self-service branch stores only password hashes for gateway
login; decryptable vendor credentials and SMTP passwords use private
AES-256-GCM envelopes with independently protected APP_KEY. Do not publish
credentials or diagnostic details in issues. Do not enable the upstream
write gateway without transaction, scope and idempotency review.
