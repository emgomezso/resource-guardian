#!/usr/bin/env php
<?php
/**
 * Resource Guardian - Monitoring Cron Script
 * Collects system metrics every minute
 */

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define paths
define('BASE_DIR', '/opt/psa/var/modules/resource-guardian');
define('DB_PATH', BASE_DIR . '/db/metrics.db');
define('LOG_PATH', BASE_DIR . '/logs/cron.log');

// Ensure log directory exists
if (!is_dir(dirname(LOG_PATH))) {
    @mkdir(dirname(LOG_PATH), 0755, true);
}

// Set error log to our log file
ini_set('error_log', LOG_PATH);

/**
 * Log message to file
 */
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message\n";
    error_log($logLine, 3, LOG_PATH);
    // Also output to stdout so Plesk can capture it
    echo $logLine;
}

try {
    logMessage("Starting Resource Guardian monitoring...");
    
    // Verify database exists
    if (!file_exists(DB_PATH)) {
        throw new Exception("Database not found at: " . DB_PATH);
    }

    // Connect to database
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(1000);

    // Collect CPU usage
    $load = sys_getloadavg();
    $cpuCount = 1;

    if (file_exists('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $cpuCount = count($matches[0]) ?: 1;
    }

    $cpuUsage = round(($load[0] / $cpuCount) * 100, 2);

    // Collect RAM usage
    $ramTotal = 0;
    $ramFree = 0;
    $ramUsage = 0;

    if (file_exists('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');

        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matchesTotal)) {
            $ramTotal = (int)$matchesTotal[1];
        }

        if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $matchesFree)) {
            $ramFree = (int)$matchesFree[1];
        } elseif (preg_match('/MemFree:\s+(\d+)/', $meminfo, $matchesFree)) {
            // Fallback to MemFree if MemAvailable not available
            $ramFree = (int)$matchesFree[1];
        }

        if ($ramTotal > 0) {
            $ramUsage = round((($ramTotal - $ramFree) / $ramTotal) * 100, 2);
        }
    }

    // Collect MySQL connections (optional, may fail if no MySQL access)
    $mysqlConnections = 0;
    $mysqlSlowQueries = 0;

    try {
        $mysqli = @new mysqli('localhost', 'root', '', 'information_schema');
        if (!$mysqli->connect_error) {
            $result = $mysqli->query("SHOW STATUS LIKE 'Threads_connected'");
            if ($result) {
                $row = $result->fetch_assoc();
                $mysqlConnections = (int)$row['Value'];
            }

            $result = $mysqli->query("SHOW STATUS LIKE 'Slow_queries'");
            if ($result) {
                $row = $result->fetch_assoc();
                $mysqlSlowQueries = (int)$row['Value'];
            }

            $mysqli->close();
        }
    } catch (Exception $e) {
        // MySQL monitoring is optional, don't fail if not available
        logMessage("MySQL monitoring skipped: " . $e->getMessage());
    }

    // Insert metrics into database
    $timestamp = time();

    $stmt = $db->prepare("
        INSERT INTO metrics 
        (timestamp, cpu_usage, ram_usage, ram_total, ram_free, io_read, io_write, mysql_connections, mysql_slow_queries) 
        VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)
    ");

    $stmt->bindValue(1, $timestamp, SQLITE3_INTEGER);
    $stmt->bindValue(2, $cpuUsage, SQLITE3_FLOAT);
    $stmt->bindValue(3, $ramUsage, SQLITE3_FLOAT);
    $stmt->bindValue(4, $ramTotal, SQLITE3_INTEGER);
    $stmt->bindValue(5, $ramFree, SQLITE3_INTEGER);
    $stmt->bindValue(6, $mysqlConnections, SQLITE3_INTEGER);
    $stmt->bindValue(7, $mysqlSlowQueries, SQLITE3_INTEGER);

    $result = $stmt->execute();

    if (!$result) {
        $errorMsg = $db->lastErrorMsg();
        throw new Exception("Failed to insert metrics. SQLite Error: " . $errorMsg);
    }

    // Log success
    $message = sprintf(
        "✓ Metrics collected - CPU: %.2f%%, RAM: %.2f%%, MySQL Connections: %d",
        $cpuUsage,
        $ramUsage,
        $mysqlConnections
    );
    logMessage($message);

    // Check thresholds and generate alerts
    $config = [];
    $configResult = $db->query("SELECT key, value FROM config");
    while ($row = $configResult->fetchArray(SQLITE3_ASSOC)) {
        $config[$row['key']] = $row['value'];
    }

    // Check CPU thresholds
    $cpuCritical = isset($config['cpu_critical_threshold']) ? (float)$config['cpu_critical_threshold'] : 85;
    $cpuWarning = isset($config['cpu_warning_threshold']) ? (float)$config['cpu_warning_threshold'] : 70;

    if ($cpuUsage >= $cpuCritical) {
        createAlert($db, 'cpu', 'critical', $cpuUsage, $cpuCritical);
    } elseif ($cpuUsage >= $cpuWarning) {
        createAlert($db, 'cpu', 'warning', $cpuUsage, $cpuWarning);
    }

    // Check RAM thresholds
    $ramCritical = isset($config['ram_critical_threshold']) ? (float)$config['ram_critical_threshold'] : 70;
    $ramWarning = isset($config['ram_warning_threshold']) ? (float)$config['ram_warning_threshold'] : 50;

    if ($ramUsage >= $ramCritical) {
        createAlert($db, 'ram', 'critical', $ramUsage, $ramCritical);
    } elseif ($ramUsage >= $ramWarning) {
        createAlert($db, 'ram', 'warning', $ramUsage, $ramWarning);
    }

    // Cleanup old data (older than 30 days)
    $cleanupTime = time() - (30 * 24 * 3600);
    $db->exec("DELETE FROM metrics WHERE timestamp < {$cleanupTime}");
    $db->exec("DELETE FROM alerts WHERE timestamp < {$cleanupTime}");

    $db->close();
    
    logMessage("Monitoring cycle completed successfully");
    exit(0);

} catch (Exception $e) {
    $errorMsg = "✗ Resource Guardian Error: " . $e->getMessage();
    logMessage($errorMsg);
    exit(1);
}

/**
 * Create alert in database
 */
function createAlert($db, $type, $severity, $value, $threshold) {
    // Check if similar alert exists in last 5 minutes (prevent spam)
    $recentTime = time() - 300;
    $check = $db->prepare("
        SELECT COUNT(*) as count 
        FROM alerts 
        WHERE alert_type = ? 
        AND severity = ? 
        AND timestamp > ?
    ");
    $check->bindValue(1, $type, SQLITE3_TEXT);
    $check->bindValue(2, $severity, SQLITE3_TEXT);
    $check->bindValue(3, $recentTime, SQLITE3_INTEGER);

    $result = $check->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row['count'] > 0) {
        // Alert already exists, skip
        return;
    }

    // Create new alert
    $message = ucfirst($type) . " usage $severity: {$value}% (threshold: {$threshold}%)";

    $stmt = $db->prepare("
        INSERT INTO alerts 
        (timestamp, alert_type, severity, message, metric_value, threshold_value) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->bindValue(1, time(), SQLITE3_INTEGER);
    $stmt->bindValue(2, $type, SQLITE3_TEXT);
    $stmt->bindValue(3, $severity, SQLITE3_TEXT);
    $stmt->bindValue(4, $message, SQLITE3_TEXT);
    $stmt->bindValue(5, $value, SQLITE3_FLOAT);
    $stmt->bindValue(6, $threshold, SQLITE3_FLOAT);

    $stmt->execute();

    logMessage("Alert created: $message");
    
    // Send email notification
    sendAlertEmail($db, $type, $severity, $message, $value, $threshold);
}

/**
 * Send alert email notification
 */
function sendAlertEmail($db, $type, $severity, $message, $value, $threshold) {
    // Get email configuration from database
    $config = [];
    $configResult = $db->query("SELECT key, value FROM config WHERE key IN ('alert_email', 'enable_email_alerts')");
    while ($row = $configResult->fetchArray(SQLITE3_ASSOC)) {
        $config[$row['key']] = $row['value'];
    }
    
    // Check if email alerts are enabled
    if (!isset($config['enable_email_alerts']) || $config['enable_email_alerts'] != '1') {
        logMessage("Email alerts disabled, skipping notification");
        return;
    }
    
    // Check if email is configured
    if (empty($config['alert_email'])) {
        logMessage("No alert email configured, skipping notification");
        return;
    }
    
    $to = $config['alert_email'];
    $hostname = gethostname();
    
    // Set email headers
    $headers = "From: Resource Guardian <noreply@{$hostname}>\r\n";
    $headers .= "Reply-To: noreply@{$hostname}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    // Email subject
    $subject = "[$severity] Resource Guardian Alert: $type on $hostname";
    
    // Email body (HTML)
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #f44336; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
            .header.warning { background: #ff9800; }
            .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 5px 5px; }
            .metric { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #f44336; }
            .metric.warning { border-left-color: #ff9800; }
            .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            h1 { margin: 0; font-size: 20px; }
            h2 { color: #333; font-size: 16px; }
            .value { font-size: 24px; font-weight: bold; color: #f44336; }
            .value.warning { color: #ff9800; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header " . ($severity == 'warning' ? 'warning' : '') . "'>
                <h1>Resource Guardian Alert</h1>
            </div>
            <div class='content'>
                <h2>Alert Details</h2>
                <div class='metric " . ($severity == 'warning' ? 'warning' : '') . "'>
                    <strong>Server:</strong> {$hostname}<br>
                    <strong>Resource:</strong> " . strtoupper($type) . "<br>
                    <strong>Severity:</strong> " . strtoupper($severity) . "<br>
                    <strong>Time:</strong> " . date('Y-m-d H:i:s') . "<br>
                    <br>
                    <div class='value " . ($severity == 'warning' ? 'warning' : '') . "'>{$value}%</div>
                    <small>Threshold: {$threshold}%</small>
                </div>
                <p><strong>Message:</strong><br>{$message}</p>
                <p>Please check your server's resource usage and take appropriate action if necessary.</p>
            </div>
            <div class='footer'>
                <p>This is an automated alert from Resource Guardian monitoring system.<br>
                Server: {$hostname} | Time: " . date('Y-m-d H:i:s') . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email
    if (mail($to, $subject, $body, $headers)) {
        logMessage("Alert email sent to: $to");
    } else {
        logMessage("Failed to send alert email to: $to");
    }
}