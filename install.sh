#!/bin/bash
# install.sh - Resource Guardian Installation Script

echo "====================================="
echo "Resource Guardian Installation"
echo "====================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: This script must be run as root"
    exit 1
fi

# Get current directory (where the module is located)
CURRENT_DIR="$(pwd)"
MODULE_NAME="resource-guardian"
PLESK_MODULE_DIR="/usr/local/psa/admin/plib/modules"
TARGET_DIR="$PLESK_MODULE_DIR/$MODULE_NAME"

# Verify we're in the correct directory
if [ ! -f "meta.xml" ]; then
    echo "Error: meta.xml not found. Are you in the resource-guardian directory?"
    exit 1
fi

# Create Plesk modules directory if it doesn't exist
echo "Setting up Plesk module structure..."
mkdir -p "$PLESK_MODULE_DIR"

# If current directory is not the target directory, create symlink
if [ "$CURRENT_DIR" != "$TARGET_DIR" ]; then
    # Remove existing symlink or directory if it exists
    if [ -L "$TARGET_DIR" ] || [ -d "$TARGET_DIR" ]; then
        echo "Removing existing installation at $TARGET_DIR..."
        rm -rf "$TARGET_DIR"
    fi
    
    # Create symlink
    echo "Creating symlink from $TARGET_DIR to $CURRENT_DIR..."
    ln -s "$CURRENT_DIR" "$TARGET_DIR"
    
    if [ $? -eq 0 ]; then
        echo "Symlink created successfully"
    else
        echo "Error: Failed to create symlink"
        exit 1
    fi
else
    echo "Module is already in the correct location"
fi

# Detect PHP path
if command -v php &> /dev/null; then
    PHP_BIN="php"
elif [ -f "/opt/plesk/php/8.3/bin/php" ]; then
    PHP_BIN="/opt/plesk/php/8.3/bin/php"
elif [ -f "/opt/plesk/php/8.2/bin/php" ]; then
    PHP_BIN="/opt/plesk/php/8.2/bin/php"
elif [ -f "/opt/plesk/php/8.1/bin/php" ]; then
    PHP_BIN="/opt/plesk/php/8.1/bin/php"
elif [ -f "/opt/plesk/php/7.4/bin/php" ]; then
    PHP_BIN="/opt/plesk/php/7.4/bin/php"
elif [ -f "/usr/bin/php" ]; then
    PHP_BIN="/usr/bin/php"
else
    echo "Error: PHP not found!"
    echo "Please install PHP or specify the path manually."
    exit 1
fi

echo "Using PHP: $PHP_BIN"
$PHP_BIN -v | head -1
echo ""

# Create database directory
echo "Creating database directory..."
mkdir -p src/var/db
chmod 755 src/var/db

# Verify database.sql exists
if [ ! -f "src/plib/resources/database.sql" ]; then
    echo "Error: database.sql not found at src/plib/resources/database.sql"
    exit 1
fi

# Initialize database
echo "Initializing database..."
$PHP_BIN -r "
try {
    \$db = new SQLite3('src/var/db/metrics.db');
    \$schema = file_get_contents('src/plib/resources/database.sql');
    \$db->exec(\$schema);
    \$db->close();
    echo 'Database initialized successfully\n';
} catch (Exception \$e) {
    echo 'Error: ' . \$e->getMessage() . '\n';
    exit(1);
}
"

if [ $? -ne 0 ]; then
    echo "Database initialization failed!"
    exit 1
fi

chmod 644 src/var/db/metrics.db

# Configure cron job with detected PHP path
echo "Configuring cron job..."
SCRIPT_PATH="$CURRENT_DIR/src/plib/scripts/cron-monitor.php"
CRON_CMD="* * * * * $PHP_BIN $SCRIPT_PATH >> /var/log/resource-guardian.log 2>&1"

if crontab -l 2>/dev/null | grep -q "cron-monitor.php"; then
    echo "Cron job already exists, removing old one..."
    crontab -l | grep -v "cron-monitor.php" | crontab -
fi

(crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -
if [ $? -eq 0 ]; then
    echo "Cron job added successfully"
else
    echo "Warning: Failed to add cron job"
fi

# Create log file
touch /var/log/resource-guardian.log
chmod 644 /var/log/resource-guardian.log

# Register extension with Plesk
echo ""
echo "Registering extension with Plesk..."
/usr/local/psa/bin/extension --register $MODULE_NAME

if [ $? -eq 0 ]; then
    echo "Extension registered successfully!"
else
    echo "Warning: Extension registration failed. You may need to register manually."
fi

echo ""
echo "====================================="
echo "Installation completed successfully!"
echo "====================================="
echo ""
echo "Module location: $CURRENT_DIR"
echo "Plesk symlink: $TARGET_DIR"
echo ""
echo "Next steps:"
echo "1. Test monitoring:"
echo "   $PHP_BIN $CURRENT_DIR/src/plib/scripts/cron-monitor.php"
echo ""
echo "2. View data:"
echo "   sqlite3 $CURRENT_DIR/src/var/db/metrics.db"
echo "   SELECT * FROM metrics;"
echo ""
echo "3. Access dashboard:"
echo "   Plesk → Extensions → Resource Guardian"
echo ""
echo "4. View logs:"
echo "   tail -f /var/log/resource-guardian.log"
echo ""