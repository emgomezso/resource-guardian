-- Resource Guardian Database Schema

-- Metrics table: stores system resource measurements
CREATE TABLE IF NOT EXISTS metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp INTEGER NOT NULL,              -- Unix timestamp
    cpu_usage REAL NOT NULL,                 -- CPU percentage (0-100)
    ram_usage REAL NOT NULL,                 -- RAM percentage (0-100)
    ram_total INTEGER NOT NULL,              -- Total RAM in KB
    ram_free INTEGER NOT NULL,               -- Free RAM in KB
    io_read REAL DEFAULT 0,                  -- Disk read in MB/s
    io_write REAL DEFAULT 0,                 -- Disk write in MB/s
    mysql_connections INTEGER DEFAULT 0,     -- Active MySQL connections
    mysql_slow_queries INTEGER DEFAULT 0     -- Cumulative slow queries
);

-- Index for fast time-based queries
CREATE INDEX IF NOT EXISTS idx_timestamp ON metrics(timestamp);

-- Alerts table: stores alert history
CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp INTEGER NOT NULL,              -- When alert was triggered
    alert_type TEXT NOT NULL,                -- Type: cpu, ram, io, mysql
    severity TEXT NOT NULL,                  -- Level: warning, critical
    message TEXT NOT NULL,                   -- Human-readable message
    metric_value REAL,                       -- Value that triggered alert
    threshold_value REAL,                    -- Threshold that was exceeded
    resolved INTEGER DEFAULT 0,              -- 0=active, 1=resolved
    resolved_at INTEGER                      -- When alert was resolved
);

-- Indexes for alert queries
CREATE INDEX IF NOT EXISTS idx_alert_timestamp ON alerts(timestamp);
CREATE INDEX IF NOT EXISTS idx_resolved ON alerts(resolved);
CREATE INDEX IF NOT EXISTS idx_alert_type ON alerts(alert_type);

-- Configuration table: stores settings
CREATE TABLE IF NOT EXISTS config (
    key TEXT PRIMARY KEY,                    -- Setting key
    value TEXT NOT NULL                      -- Setting value (stored as text)
);

-- Default configuration values
INSERT OR IGNORE INTO config (key, value) VALUES 
    ('cpu_warning_threshold', '70'),
    ('cpu_critical_threshold', '85'),
    ('ram_warning_threshold', '75'),
    ('ram_critical_threshold', '90'),
    ('io_warning_threshold', '80'),
    ('monitoring_interval', '60'),
    ('alert_email', ''),
    ('webhook_url', ''),
    ('enable_email_alerts', '1'),
    ('enable_webhook_alerts', '0'),
    ('alert_cooldown', '300');