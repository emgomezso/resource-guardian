<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job
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

// Create cron job
try {
    $scriptPath = pm_Context::getPlibDir() . '/scripts/cron-monitor.php';
    
    // Verify script exists
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }
    
    // Remove existing task using database query
    try {
        $db = pm_Bootstrap::getDbAdapter();
        $db->delete('ScheduledTasks', "description = 'Resource Guardian - System Monitoring'");
        pm_Log::info('Removed existing cron task if any');
    } catch (Exception $e) {
        // Continue if no task exists
    }
    
    // Create new task
    $task = new pm_Scheduler_Task();
    $task->setCmd('/usr/bin/php')
         ->setArgs(array($scriptPath))
         ->setDescription('Resource Guardian - System Monitoring')
         ->setOwner('root')
         ->setSchedule('* * * * *');  // Every minute
    
    $task->save();
    
    pm_Log::info('Cron job created successfully: ' . $scriptPath);
    echo "✓ Cron job created successfully\n";
    echo "  Script: {$scriptPath}\n";
    echo "  Schedule: Every minute\n";
    
} catch (Exception $e) {
    $errorMsg = 'Resource Guardian Cron Error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    error_log($errorMsg);
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nResource Guardian installed successfully!\n";
echo "Logs: {$logDir}/monitor.log\n";