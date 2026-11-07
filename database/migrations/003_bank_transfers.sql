CREATE TABLE rent_bank_accounts (
    contract_id TEXT PRIMARY KEY REFERENCES contracts(id),
    company_id TEXT NOT NULL REFERENCES companies(id),
    recipient_id TEXT NOT NULL REFERENCES users(id),
    beneficiary TEXT NOT NULL,
    iban TEXT NOT NULL
);
CREATE TABLE bank_transfer_proofs (
    id TEXT PRIMARY KEY,
    company_id TEXT NOT NULL REFERENCES companies(id),
    charge_id TEXT REFERENCES charges(id),
    plan_id TEXT REFERENCES plans(id),
    months INTEGER,
    amount_cents INTEGER NOT NULL,
    beneficiary TEXT NOT NULL,
    iban TEXT NOT NULL,
    recipient_id TEXT REFERENCES users(id),
    author_id TEXT NOT NULL REFERENCES users(id),
    reviewer_id TEXT REFERENCES users(id),
    mime_type TEXT NOT NULL,
    file_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'PENDING',
    bank_reference_key TEXT UNIQUE,
    paid_until TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX bank_proofs_company_charge ON bank_transfer_proofs(company_id,charge_id,status);
