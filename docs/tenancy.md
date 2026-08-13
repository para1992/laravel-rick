# Tenancy

Every Rick database query is scoped by `TenantContextBase`; there is no global
scan of package tables. Tenant IDs and business IDs are trimmed, valid UTF-8,
non-empty, and limited to 128 Unicode characters.

The default tenant is configured at `rick.tenant.default`. With
`rick.tenant.catalog = null`, operational `--all` means only that tenant. A
configured array is validated and deduplicated. SaaS applications should bind
their own `TenantCatalogBase` when tenants come from another data source.

Tenant context, transactions, repositories, outbox components, orchestration,
and the `Rick` entry point use Laravel scoped bindings. Queue workers and
Octane must reset scoped instances between units of work, as Laravel normally
does. The package Facade does not cache its resolved `Rick` instance.

Queue lock identifiers hash a length-prefixed `(tenant, entity ID)` tuple with
SHA-256. Plain tenant and business identifiers are never included in cache
lock keys.
