<?php
/**
 * Resource Guardian - Monitor Engine
 * Core monitoring logic for collecting system metrics
 */

namespace PleskExt\ResourceGuardian\Library;

class MonitorEngine
{
    private $db;
    private $dbPath;
    
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
    }
    
    /**
     * Destructor - close database connection
     */
    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Collect all metrics and save to database
     * @return array Collected metrics
     */
    public function collectMetrics()
    {
        $metrics = [
            'timestamp' => time(),
            'cpu_usage' => $this->getCpuUsage(),
            'ram_usage' => $this->getRamUsage(),
            'ram_total' => $this->getRamTotal(),
            'ram_free' => $this->getRamFree(),
            'io_read' => $this->getIoRead(),
            'io_write' => $this->getIoWrite(),
            'mysql_connections' => $this->getMysqlConnections(),
            'mysql_slow_queries' => $this->getMysqlSlowQueries()
        ];
        
        $this->saveMetrics($metrics);
        
        return $metrics;
    }
    
    /**
     * Get current CPU usage percentage
     * @return float CPU usage (0-100)
     */
    private function getCpuUsage()
    {
        try {
            $load = sys_getloadavg();
            $cpuCount = $this->getCpuCount();
            
            // Calculate percentage based on load average and CPU count
            $usage = ($load[0] / $cpuCount) * 100;
            
            // Cap at 100%
            return round(min($usage, 100), 2);
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get CPU usage - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get number of CPU cores
     * @return int Number of CPU cores
     */
    private function getCpuCount()
    {
        static $cpuCount = null;
        
        if ($cpuCount !== null) {
            return $cpuCount;
        }
        
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cpuCount = count($matches[0]);
        }
        
        return $cpuCount ?: 1;
    }
    
    /**
     * Get RAM usage percentage
     * @return float RAM usage (0-100)
     */
    private function getRamUsage()
    {
        try {
            $total = $this->getRamTotal();
            $free = $this->getRamFree();
            
            if ($total == 0) {
                return 0;
            }
            
            $used = $total - $free;
            return round(($used / $total) * 100, 2);
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get RAM usage - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get total RAM in KB
     * @return int Total RAM in KB
     */
    private function getRamTotal()
    {
        static $ramTotal = null;
        
        if ($ramTotal !== null) {
            return $ramTotal;
        }
        
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches)) {
                $ramTotal = (int)$matches[1];
            }
        }
        
        return $ramTotal ?: 0;
    }
    
    /**
     * Get free/available RAM in KB
     * @return int Free RAM in KB
     */
    private function getRamFree()
    {
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            
            // Try MemAvailable first (more accurate)
            if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $matches)) {
                return (int)$matches[1];
            }
            
            // Fallback to MemFree
            if (preg_match('/MemFree:\s+(\d+)/', $meminfo, $matches)) {
                return (int)$matches[1];
            }
        }
        
        return 0;
    }
    
    /**
     * Get disk I/O read rate (MB/s)
     * @return float Read rate in MB/s
     */
    private function getIoRead()
    {
        // TODO: Implement disk I/O monitoring
        // Requires parsing /proc/diskstats or using iostat
        return 0;
    }
    
    /**
     * Get disk I/O write rate (MB/s)
     * @return float Write rate in MB/s
     */
    private function getIoWrite()
    {
        // TODO: Implement disk I/O monitoring
        return 0;
    }
    
    /**
     * Get current MySQL connection count
     * @return int Number of active connections
     */
    private function getMysqlConnections()
    {
        try {
            $mysqli = @new \mysqli('localhost', 'root', '', 'information_schema');
            
            if ($mysqli->connect_error) {
                return 0;
            }
            
            $result = $mysqli->query("SHOW STATUS LIKE 'Threads_connected'");
            if ($result) {
                $row = $result->fetch_assoc();
                $mysqli->close();
                return (int)$row['Value'];
            }
            
            $mysqli->close();
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get MySQL connections - " . $e->getMessage());
        }
        
        return 0;
    }
    
    /**
     * Get cumulative slow query count
     * @return int Number of slow queries
     */
    private function getMysqlSlowQueries()
    {
        try {
            $mysqli = @new \mysqli('localhost', 'root', '', 'information_schema');
            
            if ($mysqli->connect_error) {
                return 0;
            }
            
            $result = $mysqli->query("SHOW STATUS LIKE 'Slow_queries'");
            if ($result) {
                $row = $result->fetch_assoc();
                $mysqli->close();
                return (int)$row['Value'];
            }
            
            $mysqli->close();
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get slow queries - " . $e->getMessage());
        }
        
        return 0;
    }
    
    /**
     * Save metrics to database
     * @param array $metrics Metrics to save
     * @return bool Success status
     */
    private function saveMetrics($metrics)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO metrics 
                (timestamp, cpu_usage, ram_usage, ram_total, ram_free, 
                 io_read, io_write, mysql_connections, mysql_slow_queries) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bindValue(1, $metrics['timestamp'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $metrics['cpu_usage'], SQLITE3_FLOAT);
            $stmt->bindValue(3, $metrics['ram_usage'], SQLITE3_FLOAT);
            $stmt->bindValue(4, $metrics['ram_total'], SQLITE3_INTEGER);
            $stmt->bindValue(5, $metrics['ram_free'], SQLITE3_INTEGER);
            $stmt->bindValue(6, $metrics['io_read'], SQLITE3_FLOAT);
            $stmt->bindValue(7, $metrics['io_write'], SQLITE3_FLOAT);
            $stmt->bindValue(8, $metrics['mysql_connections'], SQLITE3_INTEGER);
            $stmt->bindValue(9, $metrics['mysql_slow_queries'], SQLITE3_INTEGER);
            
            return $stmt->execute() !== false;
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to save metrics - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent metrics from database
     * @param int $hours Number of hours to retrieve
     * @return array Array of metrics
     */
    public function getRecentMetrics($hours = 24)
    {
        try {
            $since = time() - ($hours * 3600);
            
            $stmt = $this->db->prepare("
                SELECT * FROM metrics 
                WHERE timestamp >= :since 
                ORDER BY timestamp ASC
            ");
            $stmt->bindValue(':since', $since, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            
            $metrics = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $metrics[] = $row;
            }
            
            return $metrics;
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get recent metrics - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get current (latest) metrics
     * @return array|null Latest metrics or null
     */
    public function getCurrentMetrics()
    {
        try {
            $result = $this->db->query("
                SELECT * FROM metrics 
                ORDER BY timestamp DESC 
                LIMIT 1
            ");
            
            return $result->fetchArray(SQLITE3_ASSOC);
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get current metrics - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get average metrics for a time period
     * @param int $hours Number of hours
     * @return array Average values
     */
    public function getAverageMetrics($hours = 24)
    {
        try {
            $since = time() - ($hours * 3600);
            
            $result = $this->db->query("
                SELECT 
                    AVG(cpu_usage) as avg_cpu,
                    AVG(ram_usage) as avg_ram,
                    MAX(cpu_usage) as max_cpu,
                    MAX(ram_usage) as max_ram,
                    MIN(cpu_usage) as min_cpu,
                    MIN(ram_usage) as min_ram
                FROM metrics 
                WHERE timestamp >= {$since}
            ");
            
            return $result->fetchArray(SQLITE3_ASSOC);
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to get average metrics - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean old metrics from database
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function cleanOldMetrics($days = 30)
    {
        try {
            $cutoff = time() - ($days * 24 * 3600);
            
            $this->db->exec("DELETE FROM metrics WHERE timestamp < {$cutoff}");
            
            return $this->db->changes();
            
        } catch (\Exception $e) {
            error_log("MonitorEngine: Failed to clean old metrics - " . $e->getMessage());
            return 0;
        }
    }
}