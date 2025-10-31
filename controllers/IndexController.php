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
        
        // Set page title
        $this->view->pageTitle = $this->lmsg('pageTitle', 'Resource Guardian Dashboard');
    }
    
    /**
     * Main dashboard view
     */
    public function indexAction()
    {
        try {
            $db = new SQLite3($this->dbPath);
            
            // Get current metrics
            $result = $db->query("SELECT * FROM metrics ORDER BY timestamp DESC LIMIT 1");
            $this->view->currentMetrics = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$this->view->currentMetrics) {
                // No data yet, set defaults
                $this->view->currentMetrics = [
                    'cpu_usage' => 0,
                    'ram_usage' => 0,
                    'mysql_connections' => 0,
                    'timestamp' => time()
                ];
            }
            
            // Get recent alerts (last 10)
            $result = $db->query("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT 10");
            $this->view->recentAlerts = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $this->view->recentAlerts[] = $row;
            }
            
            $db->close();
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => 'Failed to load dashboard: ' . $e->getMessage()
            ]);
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
            $stmt = $db->prepare("SELECT * FROM metrics WHERE timestamp >= :since ORDER BY timestamp ASC");
            $stmt->bindValue(':since', $since, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $metrics = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $metrics[] = [
                    'id' => $row['id'],
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
            $result = $db->query("SELECT * FROM metrics ORDER BY timestamp DESC LIMIT 1");
            $current = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();
            
            if ($current) {
                $this->_helper->json([
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
        $schemaPath = pm_Context::getModuleDir() . '/resources/database.sql';
        
        if (file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            $db->exec($schema);
        }
        
        $db->close();
    }
    
    /**
     * Helper method for localized messages
     */
    public static function lmsg($key, $default = '')
    {
        return parent::lmsg($key, $default);
    }
}