<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using Plesk API
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

// Create cron job using Plesk API
try {
    $scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    
    // Verify script exists
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }
    
    // Remove existing task if any
    try {
        $existingTasks = pm_ScheduledTask::getAllByDescription('Resource Guardian - System Monitoring');
        foreach ($existingTasks as $task) {
            pm_ScheduledTask::remove($task->getId());
        }
    } catch (Exception $e) {
        // Continue if no task exists
        pm_Log::debug('No existing task to remove: ' . $e->getMessage());
    }
    
    // Create new scheduled task using Plesk API
    $fullCommand = '/opt/plesk/php/8.3/bin/php ' . escapeshellarg($scriptPath);
    
    $task = new pm_ScheduledTask();
    $task->setDescription('Resource Guardian - System Monitoring');
    $task->setCommand($fullCommand);
    $task->setSchedule([
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'weekday' => '*'
    ]);
    $task->setEnabled(true);
    
    // Save the task
    $taskId = $task->create();
    
    pm_Log::info('Cron job created successfully with ID: ' . $taskId);
    echo "✓ Cron job created successfully\n";
    echo "  Task ID: {$taskId}\n";
    echo "  Script: {$scriptPath}\n";
    echo "  Schedule: Every minute (* * * * *)\n";
    
} catch (Exception $e) {
    $errorMsg = 'Resource Guardian Cron Error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    error_log($errorMsg);
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}