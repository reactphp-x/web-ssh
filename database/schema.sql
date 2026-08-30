-- Web SSH platform schema (SQLite, no users table; platform auth via HTTP Basic Auth)

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS host_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    parent_id INTEGER NULL,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS hosts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    address TEXT NOT NULL,
    port INTEGER NOT NULL DEFAULT 22,
    username TEXT NOT NULL,
    auth_type TEXT NOT NULL,
    private_key_source TEXT NOT NULL DEFAULT 'path',
    encrypted_secret TEXT NOT NULL,
    encrypted_passphrase TEXT NULL,
    jump_host_id INTEGER NULL,
    group_id INTEGER NULL,
    tags TEXT NOT NULL DEFAULT '',
    remark TEXT NULL,
    created_by TEXT NOT NULL DEFAULT '',
    last_connected_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name),
    FOREIGN KEY (group_id) REFERENCES host_groups (id) ON DELETE SET NULL,
    FOREIGN KEY (jump_host_id) REFERENCES hosts (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_hosts_group_id ON hosts (group_id);
CREATE INDEX IF NOT EXISTS idx_hosts_address ON hosts (address);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    host_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    error_message TEXT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NULL,
    duration INTEGER NULL,
    recording_url TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_username ON sessions (username);
CREATE INDEX IF NOT EXISTS idx_sessions_host_id ON sessions (host_id);
CREATE INDEX IF NOT EXISTS idx_sessions_start_time ON sessions (start_time);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    resource TEXT NOT NULL,
    resource_id INTEGER NULL,
    detail TEXT NULL,
    ip TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_username ON audit_logs (username);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_resource ON audit_logs (resource, resource_id);

CREATE TABLE IF NOT EXISTS two_factor_auth (
    username TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    encrypted_secret TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS two_factor_pending (
    username TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    encrypted_secret TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS two_factor_sessions (
    token TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_two_factor_sessions_username ON two_factor_sessions (username);
CREATE INDEX IF NOT EXISTS idx_two_factor_sessions_expires_at ON two_factor_sessions (expires_at);

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO host_groups (id, name, created_by)
VALUES (1, '默认分组', 'system');
