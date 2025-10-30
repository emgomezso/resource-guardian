<?php
/**
 * Resource Guardian - Notifier
 * Handles alert notifications via email and webhooks
 */

namespace PleskExt\ResourceGuardian\Library;

class Notifier
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
     * Destructor
     */
    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Send alert notification
     * @param array $alert Alert data
     * @param array $config Configuration settings
     * @return bool Success status
     */
    public function sendAlert($alert, $config)
    {
        $success = true;
        
        // Send email notification
        if (isset($config['enable_email_alerts']) && $config['enable_email_alerts'] == '1') {
            if (!empty($config['alert_email'])) {
                $emailSent = $this->sendEmail($alert, $config['alert_email']);
                $success = $success && $emailSent;
                
                if ($emailSent) {
                    error_log("Notifier: Email alert sent to {$config['alert_email']}");
                } else {
                    error_log("Notifier: Failed to send email alert");
                }
            }
        }
        
        // Send webhook notification
        if (isset($config['enable_webhook_alerts']) && $config['enable_webhook_alerts'] == '1') {
            if (!empty($config['webhook_url'])) {
                $webhookSent = $this->sendWebhook($alert, $config['webhook_url']);
                $success = $success && $webhookSent;
                
                if ($webhookSent) {
                    error_log("Notifier: Webhook alert sent to {$config['webhook_url']}");
                } else {
                    error_log("Notifier: Failed to send webhook alert");
                }
            }
        }
        
        return $success;
    }
    
    /**
     * Send email notification
     * @param array $alert Alert data
     * @param string $email Recipient email address
     * @return bool Success status
     */
    private function sendEmail($alert, $email)
    {
        try {
            // Validate email address
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                error_log("Notifier: Invalid email address: {$email}");
                return false;
            }
            
            // Build email subject
            $subject = $this->buildEmailSubject($alert);
            
            // Build email body
            $message = $this->buildEmailBody($alert);
            
            // Build headers
            $headers = $this->buildEmailHeaders();
            
            // Send email
            $sent = mail($email, $subject, $message, $headers);
            
            if (!$sent) {
                error_log("Notifier: mail() function returned false");
            }
            
            return $sent;
            
        } catch (\Exception $e) {
            error_log("Notifier: Email error - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build email subject
     * @param array $alert Alert data
     * @return string Email subject
     */
    private function buildEmailSubject($alert)
    {
        $severity = strtoupper($alert['severity']);
        $type = strtoupper($alert['alert_type']);
        
        return "[Resource Guardian] {$severity} Alert: {$type}";
    }
    
    /**
     * Build email body
     * @param array $alert Alert data
     * @return string Email body
     */
    private function buildEmailBody($alert)
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? gethostname();
        $timestamp = date('Y-m-d H:i:s', $alert['timestamp']);
        
        $body = "Resource Guardian Alert\n";
        $body .= str_repeat("=", 50) . "\n\n";
        
        $body .= "Server: {$serverName}\n";
        $body .= "Time: {$timestamp}\n\n";
        
        $body .= "Alert Details:\n";
        $body .= str_repeat("-", 50) . "\n";
        $body .= "Type: " . ucfirst($alert['alert_type']) . "\n";
        $body .= "Severity: " . ucfirst($alert['severity']) . "\n";
        $body .= "Message: {$alert['message']}\n\n";
        
        if (isset($alert['metric_value']) && isset($alert['threshold_value'])) {
            $body .= "Current Value: " . number_format($alert['metric_value'], 2) . "%\n";
            $body .= "Threshold: " . number_format($alert['threshold_value'], 2) . "%\n\n";
        }
        
        $body .= str_repeat("=", 50) . "\n\n";
        
        // Add recommendations based on alert type
        $body .= "Recommended Actions:\n";
        $body .= $this->getRecommendations($alert['alert_type'], $alert['severity']);
        
        $body .= "\n\n";
        $body .= "This is an automated alert from Resource Guardian.\n";
        $body .= "Please do not reply to this email.\n";
        
        return $body;
    }
    
    /**
     * Build email headers
     * @return string Email headers
     */
    private function buildEmailHeaders()
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        $headers = [];
        $headers[] = "From: Resource Guardian <noreply@{$serverName}>";
        $headers[] = "Reply-To: noreply@{$serverName}";
        $headers[] = "X-Mailer: Resource Guardian/1.0";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        
        return implode("\r\n", $headers);
    }
    
    /**
     * Get recommendations based on alert type
     * @param string $type Alert type
     * @param string $severity Alert severity
     * @return string Recommendations text
     */
    private function getRecommendations($type, $severity)
    {
        $recommendations = [
            'cpu' => [
                'warning' => "1. Check running processes with 'top' or 'htop'\n" .
                           "2. Identify resource-intensive applications\n" .
                           "3. Consider optimizing code or queries\n" .
                           "4. Monitor for unusual activity",
                'critical' => "IMMEDIATE ACTION REQUIRED:\n" .
                            "1. SSH into server and run 'top' to identify processes\n" .
                            "2. Consider killing non-essential processes\n" .
                            "3. Restart services if necessary (php-fpm, apache, nginx)\n" .
                            "4. Check for malware or unauthorized access\n" .
                            "5. Consider upgrading server resources"
            ],
            'ram' => [
                'warning' => "1. Check memory usage with 'free -h'\n" .
                           "2. Review application memory leaks\n" .
                           "3. Clear caches (WordPress, Opcache, etc.)\n" .
                           "4. Restart PHP-FPM if needed",
                'critical' => "IMMEDIATE ACTION REQUIRED:\n" .
                            "1. Restart PHP-FPM: systemctl restart php7.4-fpm\n" .
                            "2. Clear all caches\n" .
                            "3. Check for memory leaks in applications\n" .
                            "4. Consider adding swap space temporarily\n" .
                            "5. Plan server RAM upgrade"
            ],
            'io' => [
                'warning' => "1. Check disk I/O with 'iostat'\n" .
                           "2. Review database queries\n" .
                           "3. Optimize file operations\n" .
                           "4. Consider SSD upgrade",
                'critical' => "IMMEDIATE ACTION REQUIRED:\n" .
                            "1. Identify I/O intensive processes\n" .
                            "2. Stop non-essential disk operations\n" .
                            "3. Check disk health\n" .
                            "4. Consider moving to faster storage"
            ],
            'mysql' => [
                'warning' => "1. Check slow query log\n" .
                           "2. Optimize database tables\n" .
                           "3. Review connection pool settings\n" .
                           "4. Add indexes where needed",
                'critical' => "IMMEDIATE ACTION REQUIRED:\n" .
                            "1. Kill long-running queries\n" .
                            "2. Restart MySQL if unresponsive\n" .
                            "3. Check for table locks\n" .
                            "4. Review and optimize slow queries"
            ]
        ];
        
        if (isset($recommendations[$type][$severity])) {
            return $recommendations[$type][$severity];
        }
        
        return "Please investigate the issue and take appropriate action.";
    }
    
    /**
     * Send webhook notification
     * @param array $alert Alert data
     * @param string $webhookUrl Webhook URL
     * @return bool Success status
     */
    private function sendWebhook($alert, $webhookUrl)
    {
        try {
            // Validate URL
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                error_log("Notifier: Invalid webhook URL: {$webhookUrl}");
                return false;
            }
            
            // Build payload
            $payload = $this->buildWebhookPayload($alert);
            
            // Detect webhook type and format accordingly
            $formattedPayload = $this->formatWebhookPayload($webhookUrl, $payload);
            
            // Send webhook
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($formattedPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log("Notifier: Webhook curl error - {$curlError}");
                return false;
            }
            
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log("Notifier: Webhook returned HTTP {$httpCode}");
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Notifier: Webhook error - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build webhook payload
     * @param array $alert Alert data
     * @return array Payload data
     */
    private function buildWebhookPayload($alert)
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? gethostname();
        
        return [
            'server' => $serverName,
            'timestamp' => $alert['timestamp'],
            'datetime' => date('Y-m-d H:i:s', $alert['timestamp']),
            'alert_type' => $alert['alert_type'],
            'severity' => $alert['severity'],
            'message' => $alert['message'],
            'metric_value' => $alert['metric_value'] ?? null,
            'threshold_value' => $alert['threshold_value'] ?? null
        ];
    }
    
    /**
     * Format webhook payload for specific platforms
     * @param string $url Webhook URL
     * @param array $payload Base payload
     * @return array Formatted payload
     */
    private function formatWebhookPayload($url, $payload)
    {
        // Detect Slack
        if (strpos($url, 'hooks.slack.com') !== false) {
            return $this->formatSlackPayload($payload);
        }
        
        // Detect Discord
        if (strpos($url, 'discord.com/api/webhooks') !== false) {
            return $this->formatDiscordPayload($payload);
        }
        
        // Detect Microsoft Teams
        if (strpos($url, 'office.com') !== false || strpos($url, 'webhook.office.com') !== false) {
            return $this->formatTeamsPayload($payload);
        }
        
        // Default: generic JSON payload
        return $payload;
    }
    
    /**
     * Format payload for Slack
     * @param array $payload Base payload
     * @return array Slack-formatted payload
     */
    private function formatSlackPayload($payload)
    {
        $color = $payload['severity'] === 'critical' ? 'danger' : 'warning';
        $emoji = $payload['severity'] === 'critical' ? ':rotating_light:' : ':warning:';
        
        return [
            'username' => 'Resource Guardian',
            'icon_emoji' => ':shield:',
            'attachments' => [
                [
                    'color' => $color,
                    'title' => "{$emoji} " . ucfirst($payload['severity']) . " Alert",
                    'text' => $payload['message'],
                    'fields' => [
                        [
                            'title' => 'Server',
                            'value' => $payload['server'],
                            'short' => true
                        ],
                        [
                            'title' => 'Time',
                            'value' => $payload['datetime'],
                            'short' => true
                        ],
                        [
                            'title' => 'Type',
                            'value' => ucfirst($payload['alert_type']),
                            'short' => true
                        ],
                        [
                            'title' => 'Value',
                            'value' => number_format($payload['metric_value'], 2) . '%',
                            'short' => true
                        ]
                    ],
                    'footer' => 'Resource Guardian',
                    'ts' => $payload['timestamp']
                ]
            ]
        ];
    }
    
    /**
     * Format payload for Discord
     * @param array $payload Base payload
     * @return array Discord-formatted payload
     */
    private function formatDiscordPayload($payload)
    {
        $color = $payload['severity'] === 'critical' ? 15158332 : 16776960; // Red or Yellow
        
        return [
            'username' => 'Resource Guardian',
            'embeds' => [
                [
                    'title' => ucfirst($payload['severity']) . ' Alert: ' . ucfirst($payload['alert_type']),
                    'description' => $payload['message'],
                    'color' => $color,
                    'fields' => [
                        [
                            'name' => 'Server',
                            'value' => $payload['server'],
                            'inline' => true
                        ],
                        [
                            'name' => 'Time',
                            'value' => $payload['datetime'],
                            'inline' => true
                        ],
                        [
                            'name' => 'Current Value',
                            'value' => number_format($payload['metric_value'], 2) . '%',
                            'inline' => true
                        ],
                        [
                            'name' => 'Threshold',
                            'value' => number_format($payload['threshold_value'], 2) . '%',
                            'inline' => true
                        ]
                    ],
                    'footer' => [
                        'text' => 'Resource Guardian'
                    ],
                    'timestamp' => date('c', $payload['timestamp'])
                ]
            ]
        ];
    }
    
    /**
     * Format payload for Microsoft Teams
     * @param array $payload Base payload
     * @return array Teams-formatted payload
     */
    private function formatTeamsPayload($payload)
    {
        $color = $payload['severity'] === 'critical' ? 'FF0000' : 'FFA500';
        
        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $payload['message'],
            'themeColor' => $color,
            'title' => 'Resource Guardian Alert',
            'sections' => [
                [
                    'activityTitle' => ucfirst($payload['severity']) . ' - ' . ucfirst($payload['alert_type']),
                    'activitySubtitle' => $payload['server'],
                    'activityImage' => 'https://via.placeholder.com/64/FF0000/FFFFFF?text=!',
                    'facts' => [
                        [
                            'name' => 'Time',
                            'value' => $payload['datetime']
                        ],
                        [
                            'name' => 'Message',
                            'value' => $payload['message']
                        ],
                        [
                            'name' => 'Current Value',
                            'value' => number_format($payload['metric_value'], 2) . '%'
                        ],
                        [
                            'name' => 'Threshold',
                            'value' => number_format($payload['threshold_value'], 2) . '%'
                        ]
                    ],
                    'markdown' => true
                ]
            ]
        ];
    }
    
    /**
     * Test email configuration
     * @param string $email Email address to test
     * @return bool Success status
     */
    public function testEmail($email)
    {
        $testAlert = [
            'timestamp' => time(),
            'alert_type' => 'test',
            'severity' => 'info',
            'message' => 'This is a test alert from Resource Guardian',
            'metric_value' => 0,
            'threshold_value' => 0
        ];
        
        return $this->sendEmail($testAlert, $email);
    }
    
    /**
     * Test webhook configuration
     * @param string $webhookUrl Webhook URL to test
     * @return bool Success status
     */
    public function testWebhook($webhookUrl)
    {
        $testAlert = [
            'timestamp' => time(),
            'alert_type' => 'test',
            'severity' => 'info',
            'message' => 'This is a test alert from Resource Guardian',
            'metric_value' => 0,
            'threshold_value' => 0
        ];
        
        return $this->sendWebhook($testAlert, $webhookUrl);
    }
}