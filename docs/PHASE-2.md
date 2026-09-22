# Phase 2 — CRM

Status: implementation complete; release verification required on the current main commit before Phase 3 starts.

Implemented:

- Customer master with UUIDv7 identity and sequential display number
- Customer create/edit and Customer 360 workspace
- Multiple contacts per customer with inline editing
- Multiple typed addresses per customer with inline editing
- Address geocode state, durable geocoding queue and provider worker
- Stale-geocode protection using version tokens
- Customer location map for resolved addresses
- Leads with pipeline stages, detail editing and lead-to-customer conversion
- Converted-lead consistency rules
- Activities, tasks and follow-ups
- Overdue, due-today, upcoming and unscheduled attention buckets
- Global indexed search across CRM records
- Deterministic search index rebuild command
- Non-destructive CRM integrity verification
- Relationship, UUIDv7, business-number and geocode-state verification
- Hash-chain verification for audit events
- Dashboard CRM counts and attention indicators
- Atomic file-backed persistence
- DigiOps-compatible public/private release packaging
- PHP, Python, smoke, integrity and frontend release checks in CI

Operational private commands:

- `python3 workers/geocode.py`
- `php tools/apply-geocodes.php`
- `php tools/rebuild-search.php`
- `php tools/verify-integrity.php`

Phase 3 starts only after the current Phase 2 release workflow is green.
