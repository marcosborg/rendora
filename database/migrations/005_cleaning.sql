CREATE TABLE cleaning_plans (
    id TEXT PRIMARY KEY,
    company_id TEXT NOT NULL REFERENCES companies(id),
    property_id TEXT NOT NULL REFERENCES properties(id),
    assignee_id TEXT NOT NULL REFERENCES users(id),
    start_date TEXT NOT NULL,
    end_date TEXT,
    interval_days INTEGER NOT NULL,
    visit_time TEXT NOT NULL
);
CREATE INDEX cleaning_plans_company ON cleaning_plans(company_id, assignee_id);

CREATE TABLE cleaning_visits (
    id TEXT PRIMARY KEY,
    plan_id TEXT NOT NULL REFERENCES cleaning_plans(id),
    occurrence_date TEXT NOT NULL,
    scheduled_date TEXT NOT NULL,
    completed_at TEXT,
    completed_by TEXT REFERENCES users(id),
    UNIQUE(plan_id, occurrence_date)
);
CREATE INDEX cleaning_visits_agenda ON cleaning_visits(plan_id, scheduled_date);
