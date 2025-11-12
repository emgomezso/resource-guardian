# Resource Guardian - Plesk Extension

Real-time server resource monitoring and alerting system for Plesk environments.

## Features

- **Real-time Monitoring**: CPU, RAM, Disk I/O, and MySQL metrics
- **Proactive Alerts**: Email and webhook notifications
- **Visual Dashboard**: Interactive charts and status cards
- **Configurable Thresholds**: Customize warning and critical levels
- **Alert History**: Track and analyze past incidents
- **Automatic Actions**: Optional automated responses to critical situations

## Requirements

- Plesk 18.0 or higher
- PHP 7.4+
- SQLite 3
- Mailutils
- Linux-based server

## Installation

1. Access SSH Terminal

2. Clone the repository:
```bash
git clone https://github.com/emgomezso/resource-guardian.git
```
3. Compress the cloned folder into a .zip file
```bash
zip -r resource-guardian.zip resource-guardian/ -x "*.git*"
```

4. Run installation script:
```bash
plesk bin extension --install /root/resource-guardian.zip
```

3. Access via Plesk:
   - Navigate to Extensions
   - Find "Resource Guardian"
   - Press the open button


## Troubleshooting

Error in the scheduled task in Plesk
If the scheduled task for using Resource Guardian fails, you will need to create the scheduled task manually.
The steps are as follows:

1. Go to Home -> Tools & Settings -> Scheduled Tasks

2. Click the Add Task button

3. In the task scheduling field, complete the following:
- **Task Type:** Run a command
- **Command:** /opt/plesk/php/8.3/bin/php /opt/psa/admin/plib/modules/resource-guardian/scripts/cron-monitor.php
- **Run:** Cron style / **(Box next to the cron style option):** ***** 
- **System User:** root
- **Description:** Resource Guardian - System Monitoring
- **Notify:** Do not notify

4. Click the OK button

## Configuration

Default thresholds:
- CPU Warning: 70%
- CPU Critical: 85%
- RAM Warning: 75%
- RAM Critical: 90%
- Monitoring Interval: 60 seconds

## License

GPL v3.0

## Authors

Team CL9
Advanced Database Topics Course
Professor: Manuel Espinoza Guerrero
Date: 24/10/2025