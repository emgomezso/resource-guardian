<?php
/**
 * Resource Guardian - Alerts Controller
 * Handles alert history and management
 */

class AlertsController extends pm_Controller_Action
{
    private $dbPath;
    
    public function init()
    {
        parent::init();
        $this->dbPath = pm_Context::getVarDir() . '/db/metrics.db';
        $this->view->pageTitle = 'Alert History';
    }
    
    /**
     * Display alert history
     */
    public function indexAction()
    {
        try {
            $db = new SQLite3($this->dbPath);
            
            // Get all alerts (limited to 100)
            $result = $db->query("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT 100");
            $this->view->alerts = [];
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $this->view->alerts[] = $row;
            }
            
            // Get statistics
            $stats = $db->query("
                SELECT 
                    alert_type,
                    severity,
                    COUNT(*) as count
                FROM alerts
                GROUP BY alert_type, severity
            ");
            
            $this->view->alertStats = [];
            while ($row = $stats->fetchArray(SQLITE3_ASSOC)) {
                $this->view->alertStats[] = $row;
            }
            
            $db->close();
            
        } catch (Exception $e) {
            $this->view->error = 'Failed to load alerts: ' . $e->getMessage();
            $this->view->alerts = [];
            $this->view->alertStats = [];
        }
    }
    
    /**
     * Get alerts as JSON
     * Parameters: limit (default: 50), severity (optional), type (optional)
     */
    public function alertsJsonAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        try {
            $limit = max(1, min(500, intval($this->getParam('limit', 50))));
            $severity = $this->getParam('severity', null);
            $type = $this->getParam('type', null);
            
            $db = new SQLite3($this->dbPath);
            
            // Build query with filters
            $query = "SELECT * FROM alerts WHERE 1=1";
            $params = [];
            
            if ($severity) {
                $query .= " AND severity = :severity";
                $params[':severity'] = $severity;
            }
            
            if ($type) {
                $query .= " AND alert_type = :type";
                $params[':type'] = $type;
            }
            
            $query .= " ORDER BY timestamp DESC LIMIT :limit";
            
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, SQLITE3_TEXT);
            }
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            
            $alerts = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $alerts[] = [
                    'id' => (int)$row['id'],
                    'timestamp' => (int)$row['timestamp'],
                    'alert_type' => $row['alert_type'],
                    'severity' => $row['severity'],
                    'message' => $row['message'],
                    'metric_value' => (float)$row['metric_value'],
                    'threshold_value' => (float)$row['threshold_value'],
                    'resolved' => (int)$row['resolved'],
                    'resolved_at' => $row['resolved_at'] ? (int)$row['resolved_at'] : null
                ];
            }
            
            $db->close();
            
            $this->_helper->json($alerts);
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Clear all alerts
     */
    public function clearAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        if (!$this->getRequest()->isPost()) {
            $this->_helper->json([
                'status' => 'error',
                'message' => 'Only POST requests allowed'
            ]);
            return;
        }
        
        try {
            $db = new SQLite3($this->dbPath);
            $db->exec("DELETE FROM alerts");
            $db->close();
            
            $this->_helper->json([
                'status' => 'success',
                'message' => 'All alerts cleared successfully'
            ]);
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Resolve an alert
     */
    public function resolveAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        if (!$this->getRequest()->isPost()) {
            $this->_helper->json([
                'status' => 'error',
                'message' => 'Only POST requests allowed'
            ]);
            return;
        }
        
        try {
            $alertId = intval($this->getParam('id', 0));
            
            if ($alertId <= 0) {
                throw new Exception('Invalid alert ID');
            }
            
            $db = new SQLite3($this->dbPath);
            $stmt = $db->prepare("UPDATE alerts SET resolved = 1, resolved_at = :time WHERE id = :id");
            $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':id', $alertId, SQLITE3_INTEGER);
            $stmt->execute();
            $db->close();
            
            $this->_helper->json([
                'status' => 'success',
                'message' => 'Alert resolved successfully'
            ]);
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}