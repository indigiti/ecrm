# Phase 3 — Sales

Status: active implementation, with Product Master, Quotations and Invoices operational.

## Implemented

- Product Master with UUIDv7 identity and `PRD-` business codes
- SKU, category, brand, model, unit, HSN/SAC and GST metadata
- Purchase, selling and MRP values stored as integer paise
- Tax and discount percentages stored as basis points
- Quotation numbering by year
- Quotation customer/address snapshots
- Multi-line quotation calculation
- CGST/SGST and IGST calculation modes
- Discounts, round-off and exact grand totals
- Quotation states: Draft, Sent, Viewed, Revised, Approved, Rejected, Expired
- Approved quotations are financially immutable
- Approved quotation → invoice conversion without retyping
- Conversion is idempotent: one live invoice per quotation
- Standalone invoices without quotations
- Invoice numbering by year
- Invoice customer/address snapshots
- Draft invoice editing
- Issued invoice financial snapshot immutability
- Explicit invoice void reason and audit event
- Product, quotation and invoice global search
- Sales data included in deterministic search rebuilds
- Sales relationship and arithmetic integrity verification
- Customer 360 quotation/invoice history
- Product, Quotation and Invoice UI workspaces
- Dynamic line-item editor with Product Master price/GST autofill
- Print-ready quotation and invoice views; browser print can save as PDF

## Financial invariants

All monetary values inside stored Sales records are integer paise. Percentage values are basis points. Internal relationships use UUIDv7 IDs only. Product/customer master changes never rewrite approved quotations or issued invoices.

An issued invoice can change operational state later through the Finance engine, but its financial snapshot must not be recalculated from mutable master records.

## Phase 4 boundary

Payments, allocations, customer statements, receivables ageing and invoice paid/partially-paid state transitions belong to the Finance engine. Phase 4 must allocate independent payment transactions to invoice UUIDs without modifying historical invoice line snapshots.
