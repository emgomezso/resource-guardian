<?php
/**
 * Resource Guardian - Database Analyzer
 * Advanced MySQL/MariaDB monitoring and analysis
 */

namespace PleskExt\ResourceGuardian\Library;

class DatabaseAnalyzer
{
    private $db;
    private $mysqlConfig;
    
    /**
     * Constructor
     * @param string $dbPath Path to SQLite database
     * @param array $mysqlConfig MySQL connection configuration
     */
    public function __construct($dbPath, $mysqlConfig = [])
    {
        $this->db = new \SQLite3($dbPath);
        
        $this->mysqlConfig = array_merge([
            'host' => 'localhost',
            'user' => 'root',
            'password' => '',
            'database' => 'information_schema'
        ], $mysqlConfig);
    }
    
    /**
     * Destructor
     */
    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Perform comprehensive database analysis
     * @return array Analysis results
     */
    public function analyzeDatabase()
    {
        return [
            'connection_stats' => $this->getConnectionStats(),
            'slow_queries' => $this->getSlowQueries(),
            'long_running_queries' => $this->getLongRunningQueries(),
            'locked_tables' => $this->getLockedTables(),
            'table_locks' => $this->getTableLocks(),
            'innodb_status' => $this->getInnoDBStatus(),
            'performance_metrics' => $this->getPerformanceMetrics()
        ];
    }
    
    /**
     * Get MySQL connection
     * @return \mysqli|null MySQL connection or null on failure
     */
    private function getMysqlConnection()
    {
        try {
            $mysqli = @new \mysqli(
                $this->mysqlConfig['host'],
                $this->mysqlConfig['user'],
                $this->mysqlConfig['password'],
                $this->mysqlConfig['database']
            );
            
            if ($mysqli->connect_error) {
                error_log("DatabaseAnalyzer: Connection failed - " . $mysqli->connect_error);
                return null;
            }
            
            return $mysqli;
            
        } catch (\Exception $e) {
            error_log("DatabaseAnalyzer: Exception - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get connection statistics
     * @return array Connection stats
     */
    public function getConnectionStats()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $stats = [];
        
        $variables = [
            'max_connections',
            'max_used_connections',
            'threads_connected',
            'threads_running',
            'connections',
            'aborted_clients',
            'aborted_connects'
        ];
        
        foreach ($variables as $var) {
            $result = $mysqli->query("SHOW STATUS LIKE '{$var}'");
            if ($result) {
                $row = $result->fetch_assoc();
                $stats[$var] = $row['Value'];
            }
        }
        
        // Calculate connection usage percentage
        if (isset($stats['threads_connected']) && isset($stats['max_connections'])) {
            $stats['connection_usage_percent'] = round(
                ($stats['threads_connected'] / $stats['max_connections']) * 100,
                2
            );
        }
        
        $mysqli->close();
        return $stats;
    }
    
    /**
     * Get slow query statistics
     * @return array Slow query stats
     */
    public function getSlowQueries()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $result = $mysqli->query("SHOW STATUS LIKE 'Slow_queries'");
        $row = $result->fetch_assoc();
        
        $slowQueries = [
            'count' => (int)$row['Value'],
            'timestamp' => time()
        ];
        
        $mysqli->close();
        return $slowQueries;
    }
    
    /**
     * Get currently running long queries
     * @param int $minTime Minimum execution time in seconds
     * @return array Long running queries
     */
    public function getLongRunningQueries($minTime = 5)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $queries = [];
        
        $result = $mysqli->query("
            SELECT 
                ID,
                USER,
                HOST,
                DB,
                COMMAND,
                TIME,
                STATE,
                LEFT(INFO, 200) as QUERY_PREVIEW
            FROM information_schema.PROCESSLIST 
            WHERE COMMAND != 'Sleep' 
            AND TIME > {$minTime}
            ORDER BY TIME DESC
            LIMIT 20
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $queries[] = [
                    'id' => $row['ID'],
                    'user' => $row['USER'],
                    'host' => $row['HOST'],
                    'database' => $row['DB'],
                    'command' => $row['COMMAND'],
                    'time' => (int)$row['TIME'],
                    'state' => $row['STATE'],
                    'query_preview' => $row['QUERY_PREVIEW']
                ];
            }
        }
        
        $mysqli->close();
        return $queries;
    }
    
    /**
     * Get locked tables (InnoDB)
     * @return array Locked tables information
     */
    public function getLockedTables()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $locks = [];
        
        // Check if InnoDB lock tables exist
        $checkTable = $mysqli->query("
            SELECT COUNT(*) as count 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = 'information_schema' 
            AND TABLE_NAME = 'INNODB_LOCKS'
        ");
        
        if ($checkTable) {
            $row = $checkTable->fetch_assoc();
            if ($row['count'] > 0) {
                $result = $mysqli->query("
                    SELECT 
                        r.trx_id waiting_trx_id,
                        r.trx_mysql_thread_id waiting_thread,
                        LEFT(r.trx_query, 200) waiting_query,
                        b.trx_id blocking_trx_id,
                        b.trx_mysql_thread_id blocking_thread,
                        LEFT(b.trx_query, 200) blocking_query
                    FROM information_schema.INNODB_LOCK_WAITS w
                    INNER JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id
                    INNER JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id
                ");
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $locks[] = $row;
                    }
                }
            }
        }
        
        $mysqli->close();
        return $locks;
    }
    
    /**
     * Get table locks
     * @return array Table lock information
     */
    public function getTableLocks()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $locks = [];
        
        $result = $mysqli->query("SHOW OPEN TABLES WHERE In_use > 0");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $locks[] = [
                    'database' => $row['Database'],
                    'table' => $row['Table'],
                    'in_use' => (int)$row['In_use'],
                    'name_locked' => (int)$row['Name_locked']
                ];
            }
        }
        
        $mysqli->close();
        return $locks;
    }
    
    /**
     * Get InnoDB status information
     * @return array InnoDB status
     */
    public function getInnoDBStatus()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $status = [];
        
        $variables = [
            'innodb_buffer_pool_size',
            'innodb_buffer_pool_pages_total',
            'innodb_buffer_pool_pages_free',
            'innodb_buffer_pool_pages_dirty',
            'innodb_row_lock_waits',
            'innodb_row_lock_current_waits',
            'innodb_row_lock_time_avg'
        ];
        
        foreach ($variables as $var) {
            $result = $mysqli->query("SHOW STATUS LIKE '{$var}'");
            if ($result) {
                $row = $result->fetch_assoc();
                $status[$var] = $row['Value'];
            }
        }
        
        // Calculate buffer pool usage
        if (isset($status['innodb_buffer_pool_pages_total']) && 
            isset($status['innodb_buffer_pool_pages_free'])) {
            $total = (int)$status['innodb_buffer_pool_pages_total'];
            $free = (int)$status['innodb_buffer_pool_pages_free'];
            if ($total > 0) {
                $status['buffer_pool_usage_percent'] = round((($total - $free) / $total) * 100, 2);
            }
        }
        
        $mysqli->close();
        return $status;
    }
    
    /**
     * Get general performance metrics
     * @return array Performance metrics
     */
    public function getPerformanceMetrics()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $metrics = [];
        
        $variables = [
            'queries',
            'questions',
            'com_select',
            'com_insert',
            'com_update',
            'com_delete',
            'bytes_received',
            'bytes_sent',
            'uptime'
        ];
        
        foreach ($variables as $var) {
            $result = $mysqli->query("SHOW STATUS LIKE '{$var}'");
            if ($result) {
                $row = $result->fetch_assoc();
                $metrics[$var] = $row['Value'];
            }
        }
        
        // Calculate queries per second
        if (isset($metrics['queries']) && isset($metrics['uptime']) && $metrics['uptime'] > 0) {
            $metrics['queries_per_second'] = round($metrics['queries'] / $metrics['uptime'], 2);
        }
        
        $mysqli->close();
        return $metrics;
    }
    
    /**
     * Kill a MySQL query by ID
     * @param int $queryId Query ID to kill
     * @return bool Success status
     */
    public function killQuery($queryId)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return false;
        }
        
        $queryId = (int)$queryId;
        $result = $mysqli->query("KILL {$queryId}");
        
        $mysqli->close();
        return $result !== false;
    }
    
    /**
     * Get database size information
     * @return array Database sizes
     */
    public function getDatabaseSizes()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $sizes = [];
        
        $result = $mysqli->query("
            SELECT 
                table_schema as 'database',
                SUM(data_length + index_length) as 'size_bytes',
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as 'size_mb'
            FROM information_schema.TABLES 
            GROUP BY table_schema
            ORDER BY size_bytes DESC
        ");
        
        if ($result) {
            while ($row = $while ($row = $result->fetch_assoc())) {
                $sizes[] = [
                    'database' => $row['database'],
                    'size_bytes' => (int)$row['size_bytes'],
                    'size_mb' => (float)$row['size_mb']
                ];
            }
        }
        
        $mysqli->close();
        return $sizes;
    }
    
    /**
     * Get table sizes for a specific database
     * @param string $database Database name
     * @return array Table sizes
     */
    public function getTableSizes($database)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $database = $mysqli->real_escape_string($database);
        $tables = [];
        
        $result = $mysqli->query("
            SELECT 
                table_name as 'table',
                table_rows as 'rows',
                data_length as 'data_size',
                index_length as 'index_size',
                ROUND((data_length + index_length) / 1024 / 1024, 2) as 'total_size_mb'
            FROM information_schema.TABLES 
            WHERE table_schema = '{$database}'
            ORDER BY (data_length + index_length) DESC
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tables[] = [
                    'table' => $row['table'],
                    'rows' => (int)$row['rows'],
                    'data_size' => (int)$row['data_size'],
                    'index_size' => (int)$row['index_size'],
                    'total_size_mb' => (float)$row['total_size_mb']
                ];
            }
        }
        
        $mysqli->close();
        return $tables;
    }
    
    /**
     * Optimize a table
     * @param string $database Database name
     * @param string $table Table name
     * @return bool Success status
     */
    public function optimizeTable($database, $table)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return false;
        }
        
        $database = $mysqli->real_escape_string($database);
        $table = $mysqli->real_escape_string($table);
        
        $result = $mysqli->query("OPTIMIZE TABLE `{$database}`.`{$table}`");
        
        $mysqli->close();
        return $result !== false;
    }
    
    /**
     * Analyze a table
     * @param string $database Database name
     * @param string $table Table name
     * @return bool Success status
     */
    public function analyzeTable($database, $table)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return false;
        }
        
        $database = $mysqli->real_escape_string($database);
        $table = $mysqli->real_escape_string($table);
        
        $result = $mysqli->query("ANALYZE TABLE `{$database}`.`{$table}`");
        
        $mysqli->close();
        return $result !== false;
    }
    
    /**
     * Get query execution plan
     * @param string $query SQL query to explain
     * @return array Execution plan
     */
    public function explainQuery($query)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $query = $mysqli->real_escape_string($query);
        $plan = [];
        
        $result = $mysqli->query("EXPLAIN {$query}");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $plan[] = $row;
            }
        }
        
        $mysqli->close();
        return $plan;
    }
    
    /**
     * Get MySQL variables
     * @param array $variableNames Specific variables to retrieve (empty for all)
     * @return array MySQL variables
     */
    public function getMysqlVariables($variableNames = [])
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $variables = [];
        
        if (empty($variableNames)) {
            $result = $mysqli->query("SHOW VARIABLES");
        } else {
            $whereClause = "Variable_name IN ('" . implode("','", array_map([$mysqli, 'real_escape_string'], $variableNames)) . "')";
            $result = $mysqli->query("SHOW VARIABLES WHERE {$whereClause}");
        }
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $variables[$row['Variable_name']] = $row['Value'];
            }
        }
        
        $mysqli->close();
        return $variables;
    }
    
    /**
     * Check MySQL replication status
     * @return array Replication status
     */
    public function getReplicationStatus()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $status = [];
        
        // Check slave status
        $result = $mysqli->query("SHOW SLAVE STATUS");
        if ($result && $result->num_rows > 0) {
            $status['slave'] = $result->fetch_assoc();
        }
        
        // Check master status
        $result = $mysqli->query("SHOW MASTER STATUS");
        if ($result && $result->num_rows > 0) {
            $status['master'] = $result->fetch_assoc();
        }
        
        $mysqli->close();
        return $status;
    }
    
    /**
     * Get index usage statistics
     * @param string $database Database name
     * @return array Index usage stats
     */
    public function getIndexUsage($database)
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $database = $mysqli->real_escape_string($database);
        $indexes = [];
        
        $result = $mysqli->query("
            SELECT 
                TABLE_NAME as 'table',
                INDEX_NAME as 'index',
                NON_UNIQUE as 'non_unique',
                SEQ_IN_INDEX as 'sequence',
                COLUMN_NAME as 'column',
                CARDINALITY as 'cardinality',
                INDEX_TYPE as 'type'
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = '{$database}'
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $indexes[] = [
                    'table' => $row['table'],
                    'index' => $row['index'],
                    'non_unique' => (int)$row['non_unique'],
                    'sequence' => (int)$row['sequence'],
                    'column' => $row['column'],
                    'cardinality' => (int)$row['cardinality'],
                    'type' => $row['type']
                ];
            }
        }
        
        $mysqli->close();
        return $indexes;
    }
    
    /**
     * Get query cache statistics
     * @return array Query cache stats
     */
    public function getQueryCacheStats()
    {
        $mysqli = $this->getMysqlConnection();
        if (!$mysqli) {
            return [];
        }
        
        $stats = [];
        
        $variables = [
            'query_cache_size',
            'query_cache_limit',
            'query_cache_min_res_unit',
            'qcache_free_blocks',
            'qcache_free_memory',
            'qcache_hits',
            'qcache_inserts',
            'qcache_lowmem_prunes',
            'qcache_not_cached',
            'qcache_queries_in_cache',
            'qcache_total_blocks'
        ];
        
        foreach ($variables as $var) {
            $result = $mysqli->query("SHOW STATUS LIKE '{$var}'");
            if ($result) {
                $row = $result->fetch_assoc();
                if ($row) {
                    $stats[$var] = $row['Value'];
                }
            }
        }
        
        // Calculate hit rate
        if (isset($stats['qcache_hits']) && isset($stats['qcache_inserts'])) {
            $hits = (int)$stats['qcache_hits'];
            $inserts = (int)$stats['qcache_inserts'];
            $total = $hits + $inserts;
            if ($total > 0) {
                $stats['hit_rate_percent'] = round(($hits / $total) * 100, 2);
            }
        }
        
        $mysqli->close();
        return $stats;
    }
    
    /**
     * Get recommendations for database optimization
     * @return array Recommendations
     */
    public function getOptimizationRecommendations()
    {
        $recommendations = [];
        
        // Check connection usage
        $connStats = $this->getConnectionStats();
        if (isset($connStats['connection_usage_percent']) && $connStats['connection_usage_percent'] > 80) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'connections',
                'message' => 'Connection pool usage is high (' . $connStats['connection_usage_percent'] . '%)',
                'recommendation' => 'Consider increasing max_connections or optimizing connection pooling'
            ];
        }
        
        // Check slow queries
        $slowQueries = $this->getSlowQueries();
        if (isset($slowQueries['count']) && $slowQueries['count'] > 100) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'performance',
                'message' => 'High number of slow queries detected (' . $slowQueries['count'] . ')',
                'recommendation' => 'Review and optimize slow queries, add indexes where needed'
            ];
        }
        
        // Check buffer pool usage
        $innodbStatus = $this->getInnoDBStatus();
        if (isset($innodbStatus['buffer_pool_usage_percent']) && $innodbStatus['buffer_pool_usage_percent'] > 90) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'memory',
                'message' => 'InnoDB buffer pool usage is high (' . $innodbStatus['buffer_pool_usage_percent'] . '%)',
                'recommendation' => 'Consider increasing innodb_buffer_pool_size'
            ];
        }
        
        // Check for table locks
        $locks = $this->getLockedTables();
        if (count($locks) > 0) {
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'locks',
                'message' => 'Active table locks detected (' . count($locks) . ')',
                'recommendation' => 'Review long-running transactions and optimize queries'
            ];
        }
        
        return $recommendations;
    }
}