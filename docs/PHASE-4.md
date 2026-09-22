# Phase 4 — Finance

Status: implementation complete; the current release head must pass the full CI pipeline before the next operational module begins.

## Implemented

- Independent payment transactions with UUIDv7 identity
- `PAY-YYYY-######` receipt numbers
- Payment methods: Cash, UPI, NEFT, RTGS, IMPS, Cheque, Card, Bank Transfer, Credit Note and Other
- Method-specific metadata and external references
- Payment batches with `PBAT-YYYY-######` numbers
- Optional customer-specific batches
- Batch close protection
- Many-to-many Payment ↔ Allocation ↔ Invoice relationships
- One receipt across multiple invoices
- One invoice across multiple receipts
- Persistent Finance lock for receipt creation, batch lifecycle, allocation/reversal and void checks
- Append-only allocation ledger
- Full allocation reversal with permanent reason
- Double-reversal protection
- Payment and invoice over-allocation protection
- Payment/invoice customer consistency checks
- Posted payment voiding only after all allocations are reversed
- Invoice voiding only after all allocations are reversed
- Invoice operational status derived from allocation balance:
  - Issued
  - Partially Paid
  - Paid
- Invoice financial line snapshots remain immutable
- Outstanding invoice engine
- Ageing buckets: Current, 1–30, 31–60, 61–90, 90+
- Customer statement with invoice debit, payment credit and running balance
- Unallocated customer credit tracking
- Dashboard collection, outstanding and overdue figures
- Payments / Receivables workspace
- Receipt detail and printable receipt view
- Allocation and reversal UI
- Outstanding and ageing views
- Payment Batch UI
- Customer 360 receivable and payment views
- Customer statement print/PDF view
- Payment and batch search indexing
- Deterministic Finance search rebuild
- Finance integrity verification covering references, signed allocations, reversals, ceilings, void states and invoice-status consistency

## Accounting invariants

Payments are independent immutable-value transactions. Invoice paid amounts are never stored as mutable fields. Balances are derived from the signed allocation ledger.

Corrections never delete allocation history. A correction appends a negative reversal linked to the original allocation.

A payment can have unallocated credit. That credit appears in the customer statement and remains available for later allocation.

Invoice operational payment status may change, but issued invoice customer, line, tax and total snapshots must never be recalculated from mutable masters.

All money is stored as integer paise. All entity relationships use UUIDv7 IDs.

## Concurrency

Finance mutations that can affect cross-record balance invariants share a persistent filesystem lock. Quotation-to-invoice conversion uses a separate cross-record lock to preserve idempotence under concurrent requests.

## Verification

`tests/phase4-finance.php` covers split receipts, multiple payments per invoice, allocation reversals, over-allocation rejection, payment/invoice void guards, ageing, customer statements, search rebuild and Finance integrity verification.
