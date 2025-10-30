<?php
/**
 * Resource Guardian - Settings Controller
 * Handles configuration management
 */

class SettingsController extends pm_Controller_Action
{
    private $dbPath;
    
    public function init()
    {
        parent::init();
        $this->dbPath = pm_Context::getVarDir() . '/db/metrics.db';
        $this->view->pageTitle = 'Settings';
    }
    
    /**
     * Display and handle settings form
     */
    public function indexAction()
    {
        $form = $this->getConfigForm();
        
        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            try {
                $this->saveConfig($form->getValues());
                
                $this->_status->addMessage('info', 'Settings saved successfully');
                $this->_helper->json([
                    'status' => 'success',
                    'message' => 'Settings saved successfully'
                ]);
                return;
                
            } catch (Exception $e) {
                $this->_status->addMessage('error', 'Failed to save settings: ' . $e->getMessage());
            }
        }
        
        $this->view->form = $form;
    }
    
    /**
     * Get configuration as JSON
     */
    public function configJsonAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        
        try {
            $config = $this->getConfig();
            $this->_helper->json($config);
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create configuration form
     */
    private function getConfigForm()
    {
        $config = $this->getConfig();
        
        $form = new pm_Form_Simple();
        
        // CPU Thresholds
        $form->addElement('text', 'cpu_warning_threshold', [
            'label' => 'CPU Warning Threshold (%)',
            'value' => $config['cpu_warning_threshold'] ?? 70,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 1, 'max' => 100]]
            ],
            'description' => 'Trigger warning alert when CPU usage exceeds this percentage'
        ]);
        
        $form->addElement('text', 'cpu_critical_threshold', [
            'label' => 'CPU Critical Threshold (%)',
            'value' => $config['cpu_critical_threshold'] ?? 85,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 1, 'max' => 100]]
            ],
            'description' => 'Trigger critical alert when CPU usage exceeds this percentage'
        ]);
        
        // RAM Thresholds
        $form->addElement('text', 'ram_warning_threshold', [
            'label' => 'RAM Warning Threshold (%)',
            'value' => $config['ram_warning_threshold'] ?? 75,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 1, 'max' => 100]]
            ],
            'description' => 'Trigger warning alert when RAM usage exceeds this percentage'
        ]);
        
        $form->addElement('text', 'ram_critical_threshold', [
            'label' => 'RAM Critical Threshold (%)',
            'value' => $config['ram_critical_threshold'] ?? 90,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 1, 'max' => 100]]
            ],
            'description' => 'Trigger critical alert when RAM usage exceeds this percentage'
        ]);
        
        // Monitoring Interval
        $form->addElement('text', 'monitoring_interval', [
            'label' => 'Monitoring Interval (seconds)',
            'value' => $config['monitoring_interval'] ?? 60,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 30, 'max' => 300]]
            ],
            'description' => 'How often to collect metrics (30-300 seconds)'
        ]);
        
        // Email Settings
        $form->addElement('text', 'alert_email', [
            'label' => 'Alert Email Address',
            'value' => $config['alert_email'] ?? '',
            'validators' => [
                ['EmailAddress', true]
            ],
            'description' => 'Email address to receive alert notifications'
        ]);
        
        $form->addElement('checkbox', 'enable_email_alerts', [
            'label' => 'Enable Email Alerts',
            'value' => $config['enable_email_alerts'] ?? 1,
            'description' => 'Send alerts via email'
        ]);
        
        // Webhook Settings
        $form->addElement('text', 'webhook_url', [
            'label' => 'Webhook URL',
            'value' => $config['webhook_url'] ?? '',
            'description' => 'HTTP endpoint for webhook notifications (Slack, Discord, etc.)'
        ]);
        
        $form->addElement('checkbox', 'enable_webhook_alerts', [
            'label' => 'Enable Webhook Alerts',
            'value' => $config['enable_webhook_alerts'] ?? 0,
            'description' => 'Send alerts to webhook URL'
        ]);
        
        // Alert Cooldown
        $form->addElement('text', 'alert_cooldown', [
            'label' => 'Alert Cooldown (seconds)',
            'value' => $config['alert_cooldown'] ?? 300,
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Digits', true],
                ['Between', true, ['min' => 60, 'max' => 3600]]
            ],
            'description' => 'Minimum time between duplicate alerts (60-3600 seconds)'
        ]);
        
        $form->addControlButtons([
            'cancelLink' => pm_Context::getActionUrl('index', 'index'),
        ]);
        
        return $form;
    }
    
    /**
     * Get current configuration from database
     */
    private function getConfig()
    {
        $db = new SQLite3($this->dbPath);
        $result = $db->query("SELECT key, value FROM config");
        
        $config = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $config[$row['key']] = $row['value'];
        }
        
        $db->close();
        return $config;
    }
    
    /**
     * Save configuration to database
     */
    private function saveConfig($values)
    {
        $db = new SQLite3($this->dbPath);
        
        foreach ($values as $key => $value) {
            // Skip form control buttons
            if (in_array($key, ['send', 'cancel'])) {
                continue;
            }
            
            $stmt = $db->prepare("INSERT OR REPLACE INTO config (key, value) VALUES (:key, :value)");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);
            $stmt->execute();
        }
        
        $db->close();
    }
    
    /**
     * Test email configuration
     */
    public function testEmailAction()
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
            $config = $this->getConfig();
            $email = $config['alert_email'] ?? '';
            
            if (empty($email)) {
                throw new Exception('No email address configured');
            }
            
            $subject = 'Resource Guardian - Test Alert';
            $message = "This is a test alert from Resource Guardian.\n\n";
            $message .= "If you receive this email, your alert configuration is working correctly.\n\n";
            $message .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
            
            $headers = "From: noreply@" . $_SERVER['SERVER_NAME'];
            
            if (mail($email, $subject, $message, $headers)) {
                $this->_helper->json([
                    'status' => 'success',
                    'message' => 'Test email sent successfully to ' . $email
                ]);
            } else {
                throw new Exception('Failed to send email');
            }
            
        } catch (Exception $e) {
            $this->_helper->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}