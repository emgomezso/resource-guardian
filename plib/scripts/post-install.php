<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using Plesk CLI
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

// Create cron job using Plesk CLI
try {
    $scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    
    // Verify script exists
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }
    
    $taskName = 'resource-guardian-monitor';
    $taskDescription = 'Resource Guardian - System Monitoring';
    
    // Remove existing task if any
    try {
        $checkCmd = '/usr/local/psa/bin/task --list 2>/dev/null | grep "' . $taskName . '"';
        $existingTask = shell_exec($checkCmd);
        
        if (!empty($existingTask)) {
            $removeCmd = '/usr/local/psa/bin/task --delete "' . $taskName . '" 2>&1';
            shell_exec($removeCmd);
            pm_Log::debug('Removed existing task: ' . $taskName);
        }
    } catch (Exception $e) {
        pm_Log::debug('No existing task to remove: ' . $e->getMessage());
    }
    
    // Create new scheduled task using Plesk CLI
    $command = '/opt/plesk/php/8.3/bin/php ' . escapeshellarg($scriptPath);
    
    $createCmd = sprintf(
        '/usr/local/psa/bin/task --create %s --command=%s --description=%s --schedule=%s 2>&1',
        escapeshellarg($taskName),
        escapeshellarg($command),
        escapeshellarg($taskDescription),
        escapeshellarg('* * * * *')
    );
    
    $output = shell_exec($createCmd);
    $exitCode = 0;
    
    // Check if command was successful
    if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
        throw new Exception("Failed to create task: " . $output);
    }
    
    // Verify task was created
    $verifyCmd = '/usr/local/psa/bin/task --list 2>&1 | grep "' . $taskName . '"';
    $verifyOutput = shell_exec($verifyCmd);
    
    if (empty($verifyOutput)) {
        throw new Exception("Task was not created successfully. CLI output: " . $output);
    }
    
    pm_Log::info('Cron job created successfully: ' . $taskName);
    echo "✓ Cron job created successfully\n";
    echo "  Task name: {$taskName}\n";
    echo "  Script: {$scriptPath}\n";
    echo "  Schedule: Every minute (* * * * *)\n";
    echo "  Command output: {$output}\n";
    
} catch (Exception $e) {
    $errorMsg = 'Resource Guardian Cron Error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    error_log($errorMsg);
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}