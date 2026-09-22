# Operations, Workshop and Reports — v0.5

Status: implementation complete.

## Inventory and Locations

- Hierarchical Location Master with warehouse, zone, rack, workshop bay, showroom, vehicle, transit, customer site and temporary location types
- UUIDv7 relationships and `LOC-` display codes
- Append-only inventory movement ledger
- Fixed-precision inventory quantities at 0.001 units
- Monotonic ledger sequence for deterministic replay
- Receive, transfer, issue, return, sale, customer return, workshop in/out, adjustment, damaged, lost and scrap operations
- Corrections use explicit reversal movements; original movements are never edited
- Source-stock validation prevents negative balances
- Location deactivation is blocked while stock remains
- Product stock and location stock are derived from movement history
- Deterministic integrity replay identifies the exact movement that violates stock rules

## Serialized Units and QR

- Physical serialized units have their own UUIDv7 identity
- Opaque random QR token; raw internal IDs are not encoded in QR labels
- Unique serial number per Product Master
- Serialized products cannot move without a unit ID
- Unit location is derived from its movement chain
- Wrong-source and out-of-order reversal errors point to the specific serialized asset
- Browser QR rendering for printable labels
- Manual QR lookup everywhere
- Camera QR scanning when the browser supports BarcodeDetector and media capture
- Unit detail shows product, current location, operational state and complete movement history

## Workshop

- `WJOB-YYYY-` repair job numbering
- Company-owned serialized assets and customer-owned repair assets
- Workshop bay assignment
- Reported issue, diagnosis, work performed, parts notes and general notes
- Priorities: Low, Normal, High and Urgent
- Lifecycle: New → In Progress / Waiting Parts → Ready → Completed, with cancellation rules
- Company serialized asset check-in/out moves through the same Inventory ledger using Workshop In / Workshop Out
- Customer-owned assets do not enter company inventory
- Final workshop jobs are immutable repair-history records
- Integrity verification ties workshop jobs to the correct unit, bay and inventory movements

## Reports

Read-only report projections:

- Sales
- Collections
- Outstanding
- Ageing
- Invoice Register
- Payment Register
- Payment Mode Summary
- Tax Summary
- Quotation Conversion
- Customer-wise Sales
- Monthly Sales

Reports support customer and date filtering where applicable, print/browser PDF, and CSV export. Financial totals are derived from issued invoice snapshots, posted payments and payment allocations; report output is never stored as a second accounting source of truth.

## Verification

CI now validates CRM, Sales, Finance, Inventory, Workshop and Reports before Vite build, DigiOps release packaging and artifact upload.
