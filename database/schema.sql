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

CREATE TABLE IF NOT EXISTS ai_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    active_segment_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_ai_sessions_username ON ai_sessions (username);
CREATE INDEX IF NOT EXISTS idx_ai_sessions_status ON ai_sessions (status);
CREATE INDEX IF NOT EXISTS idx_ai_sessions_created_at ON ai_sessions (created_at);

CREATE TABLE IF NOT EXISTS ai_session_segments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ai_session_id INTEGER NOT NULL,
    host_id INTEGER NOT NULL,
    session_id INTEGER NULL,
    live_key TEXT NOT NULL,
    order_index INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TEXT NULL,
    FOREIGN KEY (ai_session_id) REFERENCES ai_sessions (id) ON DELETE CASCADE,
    FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_ai_session_segments_ai_session_id ON ai_session_segments (ai_session_id);
CREATE INDEX IF NOT EXISTS idx_ai_session_segments_host_id ON ai_session_segments (host_id);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    host_id INTEGER NOT NULL,
    session_type TEXT NOT NULL DEFAULT 'terminal',
    ai_session_id INTEGER NULL,
    status TEXT NOT NULL,
    error_message TEXT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NULL,
    duration INTEGER NULL,
    recording_url TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE CASCADE,
    FOREIGN KEY (ai_session_id) REFERENCES ai_sessions (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_username ON sessions (username);
CREATE INDEX IF NOT EXISTS idx_sessions_host_id ON sessions (host_id);
CREATE INDEX IF NOT EXISTS idx_sessions_start_time ON sessions (start_time);
CREATE INDEX IF NOT EXISTS idx_sessions_ai_session_id ON sessions (ai_session_id);
CREATE INDEX IF NOT EXISTS idx_sessions_session_type ON sessions (session_type);

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

CREATE TABLE IF NOT EXISTS ai_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    config_json TEXT NOT NULL DEFAULT '{}',
    encrypted_secrets TEXT NOT NULL DEFAULT '',
    is_selected INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by TEXT NOT NULL DEFAULT '',
    UNIQUE (name)
);

CREATE INDEX IF NOT EXISTS idx_ai_profiles_selected ON ai_profiles (is_selected);

CREATE TABLE IF NOT EXISTS command_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    scope_type TEXT NOT NULL DEFAULT 'global',
    scope_id INTEGER NULL,
    priority INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    rules_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_command_policies_scope ON command_policies (scope_type, scope_id);
CREATE INDEX IF NOT EXISTS idx_command_policies_enabled ON command_policies (enabled);

CREATE TABLE IF NOT EXISTS command_executions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    host_id INTEGER NULL,
    command TEXT NOT NULL,
    decision TEXT NOT NULL,
    matched_rule TEXT NULL,
    inspection_json TEXT NOT NULL DEFAULT '{}',
    session_id INTEGER NULL,
    ai_session_id INTEGER NULL,
    exit_code INTEGER NULL,
    timed_out INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE SET NULL,
    FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE SET NULL,
    FOREIGN KEY (ai_session_id) REFERENCES ai_sessions (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_command_executions_username ON command_executions (username);
CREATE INDEX IF NOT EXISTS idx_command_executions_host_id ON command_executions (host_id);
CREATE INDEX IF NOT EXISTS idx_command_executions_created_at ON command_executions (created_at);
