
<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using correct Plesk database schema
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

// Create cron job using Plesk database
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
        $existingTasks = $db->fetchAll(
            "SELECT id FROM ScheduledTasks WHERE description LIKE '%Resource Guardian%'"
        );
        
        foreach ($existingTasks as $task) {
            $db->delete('ScheduledTasks', "id = " . $task['id']);
            pm_Log::debug('Removed existing task ID: ' . $task['id']);
        }
    } catch (Exception $e) {
        pm_Log::debug('No existing tasks to remove: ' . $e->getMessage());
    }
    
    // Get service node ID (usually 1)
    $serviceNodeId = 1;
    
    // Prepare the command
    $command = '/opt/plesk/php/8.3/bin/php ' . $scriptPath;
    
    // Insert new task with ALL required fields
    $taskData = array(
        'hash' => md5('resource-guardian-monitor-' . time()),
        'serviceNodeId' => $serviceNodeId,
        'sysUserId' => null,
        'sysUserLogin' => 'root',
        'isActive' => 1,
        'type' => 'exec',
        'phpHandlerId' => null,
        'command' => $command,
        'arguments' => '',
        'description' => 'Resource Guardian - System Monitoring',
        'notify' => 'ignore',
        'emailType' => 'owner',
        'email' => null,
        'minute' => '*',
        'hour' => '*',
        'dayOfMonth' => '*',
        'month' => '*',
        'dayOfWeek' => '*',
        'period' => 0
    );
    
    $db->insert('ScheduledTasks', $taskData);
    $taskId = $db->lastInsertId();
    
    // Verify the task was created
    $verifyTask = $db->fetchRow(
        "SELECT id, description, command FROM ScheduledTasks WHERE id = ?",
        array($taskId)
    );
    
    if (!$verifyTask) {
        throw new Exception("Task was created but cannot be verified");
    }
    
    pm_Log::info('Cron job created successfully with ID: ' . $taskId);
    echo "✓ Cron job created successfully\n";
    echo "  Task ID: {$taskId}\n";
    echo "  Description: {$verifyTask['description']}\n";
    echo "  Command: {$verifyTask['command']}\n";
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