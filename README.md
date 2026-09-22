# eCRM

Database-less CRM, sales, inventory, workshop and receivables application designed for DigiOps deployment.

## Architecture

- PHP 8.2+ application/API
- Vite + Tailwind frontend
- UUIDv7/GUID internal identifiers
- File-backed repositories with atomic writes and locking
- Append-only inventory, payment and audit ledgers
- Persistent runtime data lives outside deployable code
- DigiOps release artifact deployment

## Deployment contract

A production build emits:

```
release/
  public/
  private/
  RELEASE.json
```

DigiOps publishes public payload to `public_html/ecrm/` and deployable private code to `private_html/ecrm/`.
Persistent directories such as `data/`, `uploads/`, `audit/`, `users/`, `config/`, `jobs/`, `locks/`, and `backups/` must never be replaced by a release.

## Modules

CRM, Products, Inventory, Workshop, Sales, Payments, Reports, Documents, Users and Settings.
