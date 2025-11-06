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
$scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    
    // Verify script exists
if (!file_exists($scriptPath)) {
    throw new Exception("Monitor script not found at: {$scriptPath}");
}
    
    // Get database adapter
$db = pm_Bootstrap::getDbAdapter();
       
    // Get service node ID (usually 1)
$serviceNodeId = 1;
    
    // Insert new task with correct column names
$db->insert('ScheduledTasks', array(
    'hash' => md5('resource-guardian-php-' . time()),
    'serviceNodeId' => 1,
    'sysUserId' => null,
    'sysUserLogin' => 'root',
    'isActive' => 1,
    'type' => 'php',
    'phpHandlerId' => $phpHandler,
    'command' => $scriptPath,
    'arguments' => '',
    'description' => 'Resource Guardian - System Monitoring',
    'minute' => '*',
    'hour' => '*',
    'dayOfMonth' => '*',
    'month' => '*',
    'dayOfWeek' => '*',
    'period' => 0
));