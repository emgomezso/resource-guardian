<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using correct database schema
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

// Create cron job using direct database insertion with correct schema
try {
    $scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    
    // Verify script exists
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }
    
    // Get database adapter
    $db = pm_Bootstrap::getDbAdapter();
    
    // Remove existing task if any
    try {
        $db->delete('ScheduledTasks', "description = 'Resource Guardian - System Monitoring'");
    } catch (Exception $e) {
        // Continue if no task exists
    }
    
    // Get service node ID (usually 1)
    $serviceNodeId = 1;
    
    // Insert new task with correct column names
    $db->insert('ScheduledTasks', array(
        'hash' => md5('resource-guardian-monitor-' . time()),
        'serviceNodeId' => $serviceNodeId,
        'sysUserId' => null,
        'sysUserLogin' => 'root',
        'isActive' => 1,
        'type' => 'exec',
        'phpHandlerId' => null,
        'command' => '/usr/bin/php',
        'arguments' => $scriptPath,
        'description' => 'Resource Guardian - System Monitoring',
        'notify' => 'errors',
        'emailType' => 'owner',
        'email' => null,
        'minute' => '*',
        'hour' => '*',
        'dayOfMonth' => '*',
        'month' => '*',
        'dayOfWeek' => '*',
        'period' => 0
    ));
    
    pm_Log::info('Cron job created successfully: ' . $scriptPath);
    echo "✓ Cron job created successfully\n";
    echo "  Script: {$scriptPath}\n";
    echo "  Schedule: Every minute (* * * * *)\n";
    
} catch (Exception $e) {
    $errorMsg = 'Resource Guardian Cron Error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    error_log($errorMsg);
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nResource Guardian installed successfully!\n";
echo "Logs: {$logDir}/monitor.log\n";