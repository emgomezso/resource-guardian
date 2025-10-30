<?php
/**
 * Resource Guardian - Alert Manager
 * Manages alert thresholds and notifications
 */

namespace PleskExt\ResourceGuardian\Library;

class AlertManager
{
    private $db;
    private $dbPath;
    private $notifier;
    
    /**
     * Constructor
     * @param string $dbPath Path to SQLite database
     */
    public function __construct($dbPath)
    {
        $this->dbPath = $dbPath;
        
        if (!file_exists($dbPath)) {
            throw new \Exception("Database not found at: {$dbPath}");
        }
        
        $this->db = new \SQLite3($dbPath);
        $this->notifier = new Notifier($dbPath);
    }
    
    /**
     * Destructor
     */
    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Check metrics against thresholds and create alerts
     * @param array $metrics Current metrics
     * @return array Created alerts
     */
    public function checkThresholds($metrics)
    {
        $config = $this->getConfig();
        $alerts = [];
        
        // Check CPU thresholds
        $cpuAlerts = $this->checkMetricThreshold(
            'cpu',
            $metrics['cpu_usage'],
            $config['cpu_warning_threshold'] ?? 70,
            $config['cpu_critical_threshold'] ?? 85
        );
        $alerts = array_merge($alerts, $cpuAlerts);
        
        // Check RAM thresholds
        $ramAlerts = $this->checkMetricThreshold(
            'ram',
            $metrics['ram_usage'],
            $config['ram_warning_threshold'] ?? 75,
            $config['ram_critical_threshold'] ?? 90
        );
        $alerts = array_merge($alerts, $ramAlerts);
        
        // Check I/O thresholds (if applicable)
        if (isset($config['io_warning_threshold'])) {
            $ioTotal = $metrics['io_read'] + $metrics['io_write'];
            if ($ioTotal > 0) {
                $ioAlerts = $this->checkMetricThreshold(
                    'io',
                    $ioTotal,
                    $config['io_warning_threshold'] ?? 80,
                    100 // No critical threshold for I/O
                );
                $alerts = array_merge($alerts, $ioAlerts);
            }
        }
        
        // Process all alerts
        foreach ($alerts as $alert) {
            $this->saveAlert($alert);
            $this->notifier->sendAlert($alert, $config);
        }
        
        return $alerts;
    }
    
    /**
     * Check a single metric against thresholds
     * @param string $type Metric type (cpu, ram, io)
     * @param float $value Current value
     * @param float $warningThreshold Warning threshold
     * @param float $criticalThreshold Critical threshold
     * @return array Array of alerts (0-1 alerts)
     */
    private function checkMetricThreshold($type, $value, $warningThreshold, $criticalThreshold)
    {
        $alerts = [];
        
        // Check if alert already exists recently (cooldown)
        $cooldown = $this->getConfig()['alert_cooldown'] ?? 300; // 5 minutes default
        
        if ($value >= $criticalThreshold) {
            if (!$this->recentAlertExists($type, 'critical', $cooldown)) {
                $alerts[] = $this->createAlert($type, 'critical', $value, $criticalThreshold);
            }
        } elseif ($value >= $warningThreshold) {
            if (!$this->recentAlertExists($type, 'warning', $cooldown)) {
                $alerts[] = $this->createAlert($type, 'warning', $value, $warningThreshold);
            }
        }
        
        return $alerts;
    }
    
    /**
     * Create an alert object
     * @param string $type Alert type
     * @param string $severity Severity level
     * @param float $value Current value
     * @param float $threshold Threshold value
     * @return array Alert data
     */
    private function createAlert($type, $severity, $value, $threshold)
    {
        $message = ucfirst($type) . " usage {$severity}: " . 
                   number_format($value, 2) . "% (threshold: {$threshold}%)";
        
        return [
            'timestamp' => time(),
            'alert_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'metric_value' => $value,
            'threshold_value' => $threshold
        ];
    }
    
    /**
     * Check if a similar alert exists recently
     * @param string $type Alert type
     * @param string $severity Severity level
     * @param int $cooldown Cooldown period in seconds
     * @return bool True if recent alert exists
     */
    private function recentAlertExists($type, $severity, $cooldown)
    {
        try {
            $since = time() - $cooldown;
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM alerts 
                WHERE alert_type = :type 
                AND severity = :severity 
                AND timestamp > :since
            ");
            
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':severity', $severity, SQLITE3_TEXT);
            $stmt->bindValue(':since', $since, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            return $row['count'] > 0;
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to check recent alerts - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save alert to database
     * @param array $alert Alert data
     * @return bool Success status
     */
    private function saveAlert($alert)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO alerts 
                (timestamp, alert_type, severity, message, metric_value, threshold_value) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bindValue(1, $alert['timestamp'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $alert['alert_type'], SQLITE3_TEXT);
            $stmt->bindValue(3, $alert['severity'], SQLITE3_TEXT);
            $stmt->bindValue(4, $alert['message'], SQLITE3_TEXT);
            $stmt->bindValue(5, $alert['metric_value'], SQLITE3_FLOAT);
            $stmt->bindValue(6, $alert['threshold_value'], SQLITE3_FLOAT);
            
            return $stmt->execute() !== false;
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to save alert - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent alerts
     * @param int $limit Maximum number of alerts
     * @return array Array of alerts
     */
    public function getRecentAlerts($limit = 50)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM alerts 
                ORDER BY timestamp DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            
            $alerts = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $alerts[] = $row;
            }
            
            return $alerts;
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to get recent alerts - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get configuration from database
     * @return array Configuration array
     */
    private function getConfig()
    {
        try {
            $result = $this->db->query("SELECT key, value FROM config");
            
            $config = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $config[$row['key']] = $row['value'];
            }
            
            return $config;
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to get config - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Resolve an alert
     * @param int $alertId Alert ID
     * @return bool Success status
     */
    public function resolveAlert($alertId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE alerts 
                SET resolved = 1, resolved_at = :time 
                WHERE id = :id
            ");
            
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':id', $alertId, SQLITE3_INTEGER);
            
            return $stmt->execute() !== false;
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to resolve alert - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean old alerts
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function cleanOldAlerts($days = 30)
    {
        try {
            $cutoff = time() - ($days * 24 * 3600);
            
            $this->db->exec("DELETE FROM alerts WHERE timestamp < {$cutoff}");
            
            return $this->db->changes();
            
        } catch (\Exception $e) {
            error_log("AlertManager: Failed to clean old alerts - " . $e->getMessage());
            return 0;
        }
    }
}