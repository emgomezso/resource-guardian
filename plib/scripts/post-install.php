<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job using correct database schema
 */

pm_Context::init('resource-guardian');

$varDir = pm_Context::getVarDir();
$logDir = $varDir . '/logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
    @chown($logDir, 'psaadm');
    @chgrp($logDir, 'psaadm');
}

try {
    $scriptPath = pm_Context::getPlibDir() . 'scripts/cron-monitor.php';
    if (!file_exists($scriptPath)) {
        throw new Exception("Monitor script not found at: {$scriptPath}");
    }

    $db = pm_Bootstrap::getDbAdapter();

    // Eliminar tarea previa si existe
    try {
        $db->delete('ScheduledTasks', "description = 'Resource Guardian - System Monitoring'");
    } catch (Exception $e) {
        // Ignorar si no existe
    }

    // Obtener el handler PHP activo desde la API de Plesk
    $phpHandler = null;
    try {
        $phpHandler = pm_Php::getInstance()->getHandlerId();
    } catch (Exception $e) {
        pm_Log::warn("Cannot determine PHP handler ID, defaulting to null");
    }

    // Insertar nueva tarea programada
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
        'notify' => 'none', // no notificar
        'emailType' => 'owner',
        'email' => null,
        'minute' => '*',
        'hour' => '*',
        'dayOfMonth' => '*',
        'month' => '*',
        'dayOfWeek' => '*',
        'period' => 0
    ));

    pm_Log::info('PHP cron job created successfully: ' . $scriptPath);
    echo "✓ PHP cron job created successfully\n";
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