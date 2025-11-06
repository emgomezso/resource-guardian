<?php
/**
 * Post-uninstallation script for Resource Guardian
 * Removes cron job and optionally database
 */

// Remove cron job
try {
    $task = pm_Scheduler_Task::getByDescription('Resource Guardian - System Monitoring');
    $task->delete();
    pm_Log::info('Cron job removed successfully');
} catch (Exception $e) {
    pm_Log::warn('Cron job not found or already removed: ' . $e->getMessage());
}

echo "Resource Guardian uninstalled successfully!\n";