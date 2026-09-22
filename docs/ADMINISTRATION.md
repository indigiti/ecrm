# Administration and Security — v0.6

Status: implementation complete.

## Authentication and Sessions

- First-run creation of the initial administrator
- Filesystem-backed user accounts stored under persistent private runtime storage
- Password hashes use PHP's current PASSWORD_DEFAULT algorithm
- Minimum password length: 12 characters
- Secure, HttpOnly, SameSite=Strict session cookie
- Session cookie is scoped to the eCRM application path
- Session state is stored in the private persistent `sessions/` directory
- Login regenerates the session ID
- All authenticated state-changing API requests require a per-session CSRF token
- Inactive users cannot authenticate
- Password hashes are never returned through API responses

## Roles

Available roles:

- Admin
- Manager
- Sales
- Accounts
- User
- Read Only

Administration endpoints require Admin where appropriate. Company settings updates require Admin or Manager. Read Only users are blocked from state-changing API operations. The last active administrator cannot be disabled or demoted.

## Audit Attribution

The authenticated user's UUID, name, email and role are attached automatically to subsequent append-only audit events. Existing hash-chain verification remains intact.

## Company Settings and Branding

Persistent private company configuration supports:

- Company and legal name
- Address and contact details
- GSTIN and PAN
- Bank and UPI details
- Quote, invoice and payment prefixes
- Financial-year start month
- Currency and default tax
- Quotation and invoice terms
- Footer text
- Company logo
- Signature image

Logo and signature files remain in private document storage and are served only through authenticated content routes.

## Documents

- Private persistent storage outside the public web root
- 25 MB upload limit
- Server-side MIME detection and allow-listing
- Opaque UUID filenames
- SHA-256 checksum recorded on upload
- Entity type / entity ID links
- Category and notes
- Authenticated download and inline content routes
- Archive-with-reason instead of silent deletion
- Search indexing and deterministic search rebuild
- Integrity verification for private file existence, checksum and linked entity references

## Backup and Verification

The System settings tab provides **Backup Now** and **Verify** controls for Admin and Manager roles.

Each snapshot:

- is written under persistent private `backups/`
- stages into a temporary directory and is atomically published
- includes business data, search indexes, uploads, audit records, users, company configuration and job state
- excludes sessions, locks and the backup directory itself
- records every copied file's byte size and SHA-256 checksum in `MANIFEST.json`
- must pass the application's logical integrity verifier before publication
- can be re-verified later to detect missing, changed or corrupted files
- records backup creation and verification in the audit ledger

Restore is intentionally not exposed as an in-app one-click operation in this release. DigiOps code rollback remains separate from business-data recovery, preventing a deployment rollback from silently rewinding live CRM data.

## Deployment Persistence

DigiOps release metadata preserves:

- data/
- indexes/
- uploads/
- audit/
- users/
- config/
- jobs/
- locks/
- backups/
- sessions/

The health endpoint verifies all persistent runtime directories are readable and writable.

## CI

The release workflow validates CRM, Sales, Finance, Inventory, Workshop, Reports and Administration before frontend build, release packaging and artifact upload.
