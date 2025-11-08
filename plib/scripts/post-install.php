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
$dbDir = $varDir . '/db';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
// Always ensure correct permissions
chmod($logDir, 0755);
chown($logDir, 'psaadm');
chgrp($logDir, 'psaadm');

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}
// Always ensure correct permissions
chmod($dbDir, 0755);
chown($dbDir, 'psaadm');
chgrp($dbDir, 'psaadm');

// Initialize database
try {
    $dbPath = $dbDir . '/metrics.db';
    $sqlFile = pm_Context::getPlibDir() . 'resources/database.sql';
    
    // Verify SQL file exists
    if (!file_exists($sqlFile)) {
        throw new Exception("Database SQL file not found at: {$sqlFile}");
    }
    
    // Remove old database if exists
    if (file_exists($dbPath)) {
        unlink($dbPath);
        pm_Log::debug('Removed old database');
    }
    
    // Create new database
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    
    // Read and execute SQL file
    $sql = file_get_contents($sqlFile);
    
    // Execute the entire SQL file at once
    // SQLite3::exec() can handle multiple statements separated by semicolons
    $result = @$db->exec($sql);
    
    if ($result === false) {
        $error = $db->lastErrorMsg();
        pm_Log::err("SQL execution error: " . $error);
        throw new Exception("Failed to execute SQL: " . $error);
    }
    
    // Set proper permissions
    chmod($dbPath, 0644);
    chown($dbPath, 'psaadm');
    chgrp($dbPath, 'psaadm');
    
    // Also fix any existing log files
    $logFile = $logDir . '/cron.log';
    if (file_exists($logFile)) {
        chmod($logFile, 0644);
        chown($logFile, 'psaadm');
        chgrp($logFile, 'psaadm');
    }
    
    // Verify database
    $checkResult = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = [];
    while ($row = $checkResult->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }
    
    $db->close();
    
    pm_Log::info('Database initialized successfully with tables: ' . implode(', ', $tables));
    echo "✓ Database initialized successfully\n";
    echo "  Database: {$dbPath}\n";
    echo "  Tables: " . implode(', ', $tables) . "\n";
    
} catch (Exception $e) {
    $errorMsg = 'Database initialization error: ' . $e->getMessage();
    pm_Log::err($errorMsg);
    echo "✗ Warning: " . $errorMsg . "\n";
    // Don't exit - continue with cron setup
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