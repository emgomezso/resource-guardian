<?php
/**
 * Post-installation script for Resource Guardian
 * Sets up cron job
 */

// Create cron job for monitoring
try {
    $scriptPath = pm_Context::getPlibDir() . '/scripts/cron-monitor.php';
    
    // Create cron task - runs every minute
    $task = new pm_Scheduler_Task();
    $task->setCmd('/usr/bin/php')
         ->setArgs(['-f', $scriptPath])
         ->setDescription('Resource Guardian - System Monitoring')
         ->setOwner('root')
         ->setSchedule('* * * * *'); // Every minute
    
    // Remove existing task if any
    try {
        pm_Scheduler_Task::getByDescription('Resource Guardian - System Monitoring')->delete();
    } catch (Exception $e) {
        // Task doesn't exist, continue
    }
    
    // Save the new task
    $task->save();
    
    pm_Log::info('Cron job created successfully');
    
} catch (Exception $e) {
    pm_Log::err('Failed to create cron job: ' . $e->getMessage());
    throw $e;
}

// Output success message
echo "Resource Guardian installed successfully!\n";
echo "Monitoring task scheduled to run every minute.\n";