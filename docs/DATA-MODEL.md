# eCRM data model

All relationships use UUIDv7 IDs. Display numbers are never relational keys.

Persistent collections: customers, addresses, contacts, leads, activities, products, product_units, locations, inventory_movements, workshop_jobs, quotations, invoices, payments, payment_batches, payment_allocations, documents, users and audit_events.

Financial and inventory history is append-only. Corrections create reversal/correction transactions. Finalized invoices store immutable financial snapshots.
