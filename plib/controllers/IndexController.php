<?php
/**
 * Resource Guardian - Index Controller
 * Handles main dashboard display and metrics API
 */

class IndexController extends pm_Controller_Action
{
    private $dbPath;
    
    public function init()
    {
        parent::init();
        
        // Set database path
        $this->dbPath = pm_Context::getVarDir() . '/db/metrics.db';
        
        // Initialize database if not exists
        if (!file_exists($this->dbPath)) {
            $this->initializeDatabase();
        }
        $this->view->pageTitle = 'Resource Guardian Dashboard';
    }
    
    /**
     * Main dashboard view
     */
    public function indexAction()
    {
        try {
            $db = new SQLite3($this->dbPath);
            $db->busyTimeout(5000);

            // Get current metrics
            $result = $db->query("SELECT * FROM metrics ORDER BY timestamp DESC LIMIT 1");
            
            if ($result === false) {
                throw new Exception("Failed to query metrics: " . $db->lastErrorMsg());
            }
            
            $currentMetrics = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$currentMetrics) {
                // No data yet, set defaults
                $currentMetrics = [
                    'cpu_usage' => 0,
                    'ram_usage' => 0,
                    'mysql_connections' => 0,
                    'timestamp' => time()
                ];
            }
            
            $this->view->currentMetrics = $currentMetrics;

            // Get recent alerts
            $result = $db->query("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT 10");
            
            if ($result === false) {
                throw new Exception("Failed to query alerts: " . $db->lastErrorMsg());
            }
            
            $recentAlerts = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $recentAlerts[] = $row;
            }
            
            $this->view->recentAlerts = $recentAlerts;
            
            // Get configuration
            $result = $db->query("SELECT key, value FROM config");
            $config = [];
            if ($result) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $config[$row['key']] = $row['value'];
                }
            }
            $this->view->config = $config;
            
            $db->close();
        
        } catch (Exception $e) {
            // Log error
            pm_Log::err('Resource Guardian Error: ' . $e->getMessage());
            
            // Set error message for view
            $this->view->errorMessage = 'Failed to load dashboard: ' . $e->getMessage();
            
            // Set empty data to prevent further errors
            $this->view->currentMetrics = [
                'cpu_usage' => 0,
                'ram_usage' => 0,
                'mysql_connections' => 0,
                'timestamp' => time()
            ];
            $this->view->recentAlerts = [];
            $this->view->config = [];
        }
    }
    
    /**
     * Get metrics data as JSON (for charts)
     * Parameters: hours (default: 24)
     */
    public function metricsJsonAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        try {
            $hours = $this->getParam('hours', 24);
            $hours = max(1, min(720, intval($hours))); // Limit between 1 and 720 hours (30 days)
            
            $since = time() - ($hours * 3600);
            
            $db = new SQLite3($this->dbPath);
            $db->busyTimeout(5000);
            
            $stmt = $db->prepare("SELECT * FROM metrics WHERE timestamp >= :since ORDER BY timestamp ASC");
            $stmt->bindValue(':since', $since, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result === false) {
                throw new Exception("Failed to query metrics: " . $db->lastErrorMsg());
            }
            
            $metrics = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $metrics[] = [
                    'id' => (int)$row['id'],
                    'timestamp' => (int)$row['timestamp'],
                    'cpu_usage' => (float)$row['cpu_usage'],
                    'ram_usage' => (float)$row['ram_usage'],
                    'ram_total' => (int)$row['ram_total'],
                    'ram_free' => (int)$row['ram_free'],
                    'io_read' => (float)$row['io_read'],
                    'io_write' => (float)$row['io_write'],
                    'mysql_connections' => (int)$row['mysql_connections'],
                    'mysql_slow_queries' => (int)$row['mysql_slow_queries']
                ];
            }
            
            $db->close();
            
            $this->_helper->json($metrics);
            
        } catch (Exception $e) {
            pm_Log::err('Resource Guardian Metrics JSON Error: ' . $e->getMessage());
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get current metrics as JSON
     */
    public function currentJsonAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        try {
            $db = new SQLite3($this->dbPath);
            $db->busyTimeout(5000);
            
            $result = $db->query("SELECT * FROM metrics ORDER BY timestamp DESC LIMIT 1");
            
            if ($result === false) {
                throw new Exception("Failed to query metrics: " . $db->lastErrorMsg());
            }
            
            $current = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();
            
            if ($current) {
                $this->_helper->json([
                    'status' => 'success',
                    'timestamp' => (int)$current['timestamp'],
                    'cpu_usage' => (float)$current['cpu_usage'],
                    'ram_usage' => (float)$current['ram_usage'],
                    'ram_total' => (int)$current['ram_total'],
                    'ram_free' => (int)$current['ram_free'],
                    'mysql_connections' => (int)$current['mysql_connections'],
                    'mysql_slow_queries' => (int)$current['mysql_slow_queries']
                ]);
            } else {
                $this->_helper->json([
                    'status' => 'error',
                    'message' => 'No data available'
                ]);
            }
            
        } catch (Exception $e) {
            pm_Log::err('Resource Guardian Current JSON Error: ' . $e->getMessage());
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Initialize database if it doesn't exist
     */
    private function initializeDatabase()
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $db = new SQLite3($this->dbPath);
        
        $rootPath = dirname(dirname(__FILE__));
        $schemaPath = $rootPath . '/resources/database.sql';
        
        if (file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            $db->exec($schema);
        }
        
        // Set proper permissions
        chmod($this->dbPath, 0644);
        @chown($this->dbPath, 'psaadm');
        @chgrp($this->dbPath, 'psaadm');
        
        $db->close();
    }
}