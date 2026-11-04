ALTER TABLE users ADD COLUMN email_verified_at TEXT;
ALTER TABLE memberships ADD COLUMN invitation_pending INTEGER NOT NULL DEFAULT 0;
CREATE INDEX idx_sessions_expiry ON sessions(expires_at);
CREATE INDEX idx_memberships_user ON memberships(user_id,active);
