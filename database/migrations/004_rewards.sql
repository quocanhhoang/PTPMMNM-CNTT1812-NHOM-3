ALTER TABLE users
    ADD COLUMN IF NOT EXISTS coins BIGINT NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'users_coins_nonnegative' AND conrelid = 'users'::regclass
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT users_coins_nonnegative CHECK (coins >= 0);
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS reward_ledger (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount BIGINT NOT NULL CHECK (amount <> 0),
    reason VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS reward_ledger_user_created_idx
    ON reward_ledger(user_id, created_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS reward_claims (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    period_type VARCHAR(12) NOT NULL CHECK (period_type IN ('day', 'week', 'month')),
    period_start DATE NOT NULL,
    mission_key VARCHAR(40) NOT NULL,
    coins_awarded INTEGER NOT NULL CHECK (coins_awarded > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, period_type, period_start, mission_key)
);

CREATE INDEX IF NOT EXISTS reward_claims_user_created_idx
    ON reward_claims(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS online_daily (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    activity_day DATE NOT NULL,
    online_seconds INTEGER NOT NULL DEFAULT 0 CHECK (online_seconds >= 0),
    last_heartbeat TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, activity_day)
);

CREATE TABLE IF NOT EXISTS storage_boosts (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    quota_bytes BIGINT NOT NULL CHECK (quota_bytes > 0),
    starts_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    source VARCHAR(40) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (expires_at > starts_at)
);

CREATE INDEX IF NOT EXISTS storage_boosts_user_expiry_idx
    ON storage_boosts(user_id, expires_at);
