CREATE TABLE paypal_intents (
    id TEXT PRIMARY KEY,
    company_id TEXT NOT NULL REFERENCES companies(id),
    charge_id TEXT REFERENCES charges(id),
    plan_id TEXT REFERENCES plans(id),
    app_key TEXT NOT NULL,
    recipient_id TEXT REFERENCES users(id),
    beneficiary TEXT,
    merchant_id TEXT,
    paypal_plan_id TEXT,
    amount_cents INTEGER NOT NULL,
    remote_id TEXT UNIQUE,
    approval_url TEXT,
    status TEXT NOT NULL DEFAULT 'CREATING',
    covered_until TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX paypal_intents_company_charge ON paypal_intents(company_id,charge_id,status);
CREATE TABLE paypal_subscription_payments (
    id TEXT PRIMARY KEY,
    intent_id TEXT NOT NULL REFERENCES paypal_intents(id),
    amount_cents INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
