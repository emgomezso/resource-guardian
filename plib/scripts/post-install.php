<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using Plesk task-manager
 */

// CRÍTICO: Inicializar el contexto de Plesk
pm_Context::init('resource-guardian');

// Create directories if needed
$varDir = pm_Context::getVarDir();
$logDir = $varDir . '/logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
    @chown($logDir, 'psaadm');
    @chgrp($logDir, 'psaadm');
}

// Create cron job using system crontab
try {
    $scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    
    // Verify script exists
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }
    
    $cronLine = "* * * * * /opt/plesk/php/8.3/bin/php " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logDir . '/cron.log') . " 2>&1";
    $cronIdentifier = "# Resource Guardian Monitor";
    
    // Get current crontab
    $currentCrontab = shell_exec('crontab -l 2>/dev/null');
    
    // Remove existing Resource Guardian cron if present
    if ($currentCrontab) {
        $lines = explode("\n", $currentCrontab);
        $newLines = [];
        $skipNext = false;
        
        foreach ($lines as $line) {
            if (strpos($line, $cronIdentifier) !== false) {
                $skipNext = true;
                continue;
            }
            if ($skipNext && strpos($line, 'cron-monitor.php') !== false) {
                $skipNext = false;
                continue;
            }
            $skipNext = false;
            if (!empty(trim($line))) {
                $newLines[] = $line;
            }
        }
        $currentCrontab = implode("\n", $newLines);
    }
    
    // Add new cron job
    $newCrontab = trim($currentCrontab) . "\n" . $cronIdentifier . "\n" . $cronLine . "\n";
    
    // Write to temporary file
    $tmpFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tmpFile, $newCrontab);
    
    // Install new crontab
    $output = shell_exec("crontab " . escapeshellarg($tmpFile) . " 2>&1");
    unlink($tmpFile);
    
    // Verify installation
    $verifyOutput = shell_exec('crontab -l 2>&1 | grep "cron-monitor.php"');
    
    if (empty($verifyOutput)) {
        throw new Exception("Cron job was not installed. Output: " . $output);
    }
    
    pm_Log::info('Cron job created successfully in system crontab');
    echo "✓ Cron job created successfully\n";
    echo "  Script: {$scriptPath}\n";
    echo "  Schedule: Every minute (* * * * *)\n";
    echo "  Log file: {$logDir}/cron.log\n";
    
} catch (Exception $e) {
    $errorMsg = 'Resource Guardian Cron Error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    error_log($errorMsg);
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}