/**
 * Resource Guardian - Dashboard JavaScript
 * Handles real-time updates, charts, and interactions
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        refreshInterval: 30000,      // 30 seconds
        chartRefreshInterval: 300000, // 5 minutes
        // Use relative path from current page
        baseUrl: window.location.pathname.replace(/\/[^\/]*$/, '/'),
        thresholds: {
            cpu: { warning: 70, critical: 85 },
            ram: { warning: 75, critical: 90 }
        }
    };

    // Global chart instance
    let metricsChart = null;

    /**
     * Initialize dashboard on page load
     */
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Resource Guardian: Initializing dashboard...');
        console.log('Base URL:', CONFIG.baseUrl);
        
        initializeChart();
        loadMetrics();
        updateStatusIndicators();
        startAutoRefresh();
        attachEventListeners();
    });

    /**
     * Initialize Chart.js chart
     */
    function initializeChart() {
        const canvas = document.getElementById('metricsChart');
        if (!canvas) {
            console.warn('Chart canvas not found');
            return;
        }

        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }

        const ctx = canvas.getContext('2d');
        
        metricsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'CPU Usage (%)',
                        data: [],
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'RAM Usage (%)',
                        data: [],
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toFixed(2) + '%';
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Time'
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Usage (%)'
                        },
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });

        console.log('Chart initialized successfully');
    }

    /**
     * Load historical metrics data
     */
    function loadMetrics(hours = 24) {
        const url = CONFIG.baseUrl + 'metrics-json?hours=' + hours;
        console.log('Loading metrics from:', url);
        
        fetch(url)
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Metrics loaded:', data);
                if (data.status === 'error') {
                    throw new Error(data.message);
                }
                if (Array.isArray(data)) {
                    console.log('Processing', data.length, 'records');
                    updateChart(data);
                } else {
                    console.warn('Unexpected data format:', data);
                }
            })
            .catch(error => {
                console.error('Error loading metrics:', error);
                showError('Failed to load metrics data: ' + error.message);
            });
    }

    /**
     * Update chart with new data
     */
    function updateChart(metrics) {
        if (!metricsChart) {
            console.warn('Cannot update chart: chart not initialized');
            return;
        }

        if (!metrics || metrics.length === 0) {
            console.warn('No metrics data to display');
            return;
        }

        const labels = [];
        const cpuData = [];
        const ramData = [];

        // Process metrics
        metrics.forEach(metric => {
            const date = new Date(metric.timestamp * 1000);
            labels.push(formatTime(date));
            cpuData.push(parseFloat(metric.cpu_usage));
            ramData.push(parseFloat(metric.ram_usage));
        });

        // Update chart data
        metricsChart.data.labels = labels;
        metricsChart.data.datasets[0].data = cpuData;
        metricsChart.data.datasets[1].data = ramData;
        metricsChart.update('none'); // Update without animation for performance

        console.log('Chart updated with ' + metrics.length + ' data points');
    }

    /**
     * Update current metrics display
     */
    function updateCurrentMetrics() {
        const url = CONFIG.baseUrl + 'current-json';
        console.log('Updating current metrics from:', url);
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Current metrics response:', data);
                if (data.status === 'error') {
                    throw new Error(data.message);
                }
                if (data.status === 'success' || data.timestamp) {
                    // Update display values
                    updateElement('cpu-value', data.cpu_usage.toFixed(1) + '%');
                    updateElement('ram-value', data.ram_usage.toFixed(1) + '%');
                    updateElement('mysql-value', data.mysql_connections);

                    // Update status indicators
                    updateStatusIndicator('cpu', data.cpu_usage);
                    updateStatusIndicator('ram', data.ram_usage);

                    console.log('Current metrics updated successfully');
                }
            })
            .catch(error => {
                console.error('Error updating current metrics:', error);
            });
    }

    /**
     * Update status indicator based on value
     */
    function updateStatusIndicator(type, value) {
        const statusEl = document.getElementById(type + '-status');
        if (!statusEl) return;

        let status = 'normal';
        const thresholds = CONFIG.thresholds[type];

        if (thresholds) {
            if (value >= thresholds.critical) {
                status = 'critical';
            } else if (value >= thresholds.warning) {
                status = 'warning';
            }
        }

        statusEl.className = 'metric-status status-' + status;
        statusEl.textContent = status.toUpperCase();
    }

    /**
     * Update status indicators from current page values
     */
    function updateStatusIndicators() {
        const cpuEl = document.getElementById('cpu-value');
        const ramEl = document.getElementById('ram-value');

        if (cpuEl) {
            const cpuValue = parseFloat(cpuEl.textContent);
            if (!isNaN(cpuValue)) {
                updateStatusIndicator('cpu', cpuValue);
            }
        }

        if (ramEl) {
            const ramValue = parseFloat(ramEl.textContent);
            if (!isNaN(ramValue)) {
                updateStatusIndicator('ram', ramValue);
            }
        }
    }

    /**
     * Start auto-refresh timers
     */
    function startAutoRefresh() {
        // Refresh current metrics every 30 seconds
        setInterval(function() {
            updateCurrentMetrics();
        }, CONFIG.refreshInterval);

        // Refresh chart every 5 minutes
        setInterval(function() {
            loadMetrics();
        }, CONFIG.chartRefreshInterval);

        console.log('Auto-refresh started');
    }

    /**
     * Attach event listeners
     */
    function attachEventListeners() {
        // Time range selector
        const timeRangeSelect = document.getElementById('time-range');
        if (timeRangeSelect) {
            timeRangeSelect.addEventListener('change', function() {
                const hours = parseInt(this.value);
                loadMetrics(hours);
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function(e) {
                e.preventDefault();
                updateCurrentMetrics();
                loadMetrics();
                showSuccess('Data refreshed');
            });
        }

        // Clear alerts button
        const clearAlertsBtn = document.getElementById('clear-alerts-btn');
        if (clearAlertsBtn) {
            clearAlertsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to clear all alerts?')) {
                    clearAlerts();
                }
            });
        }
    }

    /**
     * Clear all alerts
     */
    function clearAlerts() {
        const url = CONFIG.baseUrl + 'alerts/clear';
        
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showSuccess('All alerts cleared');
                // Reload page to update alerts display
                setTimeout(() => location.reload(), 1000);
            } else {
                showError(data.message || 'Failed to clear alerts');
            }
        })
        .catch(error => {
            console.error('Error clearing alerts:', error);
            showError('Failed to clear alerts');
        });
    }

    /**
     * Helper: Update element text content
     */
    function updateElement(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    }

    /**
     * Helper: Format timestamp for display
     */
    function formatTime(date) {
        const hours = date.getHours().toString().padStart(2, '0');
        const minutes = date.getMinutes().toString().padStart(2, '0');
        return hours + ':' + minutes;
    }

    /**
     * Helper: Show success message
     */
    function showSuccess(message) {
        showNotification(message, 'success');
    }

    /**
     * Helper: Show error message
     */
    function showError(message) {
        showNotification(message, 'error');
    }

    /**
     * Helper: Show notification
     */
    function showNotification(message, type) {
        // Check if Plesk notification system exists
        if (typeof Jsw !== 'undefined' && Jsw.notificationSuccess) {
            if (type === 'success') {
                Jsw.notificationSuccess(message);
            } else {
                Jsw.notificationError(message);
            }
        } else {
            // Fallback to console
            console.log('[' + type.toUpperCase() + '] ' + message);
            
            // Or create simple notification
            const notification = document.createElement('div');
            notification.className = 'notification notification-' + type;
            notification.textContent = message;
            notification.style.cssText = 'position:fixed;top:20px;right:20px;padding:15px 20px;background:' + 
                (type === 'success' ? '#4CAF50' : '#F44336') + ';color:white;border-radius:4px;z-index:9999;box-shadow:0 2px 5px rgba(0,0,0,0.2);';
            document.body.appendChild(notification);
            
            setTimeout(() => notification.remove(), 3000);
        }
    }

    /**
     * Export functions for external use
     */
    window.ResourceGuardian = {
        loadMetrics: loadMetrics,
        updateCurrentMetrics: updateCurrentMetrics,
        updateChart: updateChart
    };

})();