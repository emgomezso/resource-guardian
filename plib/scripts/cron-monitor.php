#!/usr/bin/env php
<?php
/**
 * Resource Guardian - Monitoring Cron Script
 * Collects system metrics every minute
 */

// Set error handling
error_reporting(E_ALL);
file_put_contents('/tmp/rg_cron_user.log', 'User: ' . get_current_user() . ', UID: ' . getmyuid() . "\n", FILE_APPEND);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define paths
define('BASE_DIR', dirname(dirname(__FILE__)));
//define('BASE_DIR', '/usr/local/psa/var/modules/resource-guardian');
define('DB_PATH', BASE_DIR . '/db/metrics.db');

/**
 * Log message to file
 */
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
}

try {
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
        logMessage("MySQL monitoring failed: " . $e->getMessage());
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
        // CORRECCIÓN: Captura el error de la base de datos y lánzalo
        $errorMsg = $db->lastErrorMsg();
        throw new Exception("Failed to insert metrics. SQLite Error: " . $errorMsg);
    }
    
    // Log success
    $message = sprintf(
        "Metrics collected - CPU: %.2f%%, RAM: %.2f%%, MySQL Connections: %d",
        $cpuUsage,
        $ramUsage,
        $mysqlConnections
    );
    logMessage($message);
    echo "$message\n";
    
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
    $ramCritical = isset($config['ram_critical_threshold']) ? (float)$config['ram_critical_threshold'] : 90;
    $ramWarning = isset($config['ram_warning_threshold']) ? (float)$config['ram_warning_threshold'] : 75;
    
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
    exit(0);
    
} catch (Exception $e) {
    $errorMsg = "Resource Guardian Error: " . $e->getMessage();
    logMessage($errorMsg);
    echo "$errorMsg\n";
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
}