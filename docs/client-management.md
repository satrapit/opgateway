# API client and IP whitelist management — private services

## Current implementation

ClientManagementService operates only through internal PHP code. **No HTTP
routes, admin UI or public management APIs are enabled.** No MySQL migrations
or phpMyAdmin imports are necessary because the existing confirmed
`users`, `roles`, `user_roles`, `api_clients` and
`client_ip_whitelists` tables already provide the required columns.

- `create`: active, email-verified, approved owner creates a **disabled**
  client; no key is issued and access is denied.
- `activate`: only an already active, verified, approved administrator with
  role `admin` may enable a disabled client whose owner remains eligible.
- `disable`: only the eligible owning user can disable their client; an
  administrator must subsequently reapprove activation.
- `requestIp`: eligible owner adds a single exact IPv4 or IPv6 address to
  their client as **disabled**, never trusted immediately.
- `approveIp`: verified approved administrator enables a pending IP only
  when the associated client and owner are active.
- `revokeIp`: an eligible owner removes their own IP entry, ensuring no
  token with that address can use the client afterward.

A malformed address is rejected. Binary storage uses `inet_pton` with
exact-byte matching; CIDR ranges and proxy headers are not accepted.

## Security limits and next phases

All management methods constrain mutating SQL statements using the verified
actor's database ID. Callers must derive that ID from a future authenticated
session, **never from an untrusted request parameter**. API key issuance
is separately restricted by PdoApiCredentialStore. Runtime request admission
will require BOTH the key and the approved IP before authorization of
individual provider operations.

There is no administrator bootstrap, authenticated web session, request
audit trail, CSRF protection, user interface, or operation permission storage
yet. Do not wire management methods to routes until all applicable safeguards
are implemented. MySQL live integration has not been established.

## Hosting

The Mac is used only for Git operations. The server runs standard cPanel
Git Version Control and phpMyAdmin with no shell, Docker or CLI dependencies.
Code deploys do not modify `.htaccess` or the existing database.
