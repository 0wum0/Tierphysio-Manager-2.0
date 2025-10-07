<?php
namespace TierphysioManager;

use PDO;
use PDOException;

/**
 * Database Connection Manager
 * Handles all database operations using PDO
 */
class Database {
    private static $instance = null;
    private $connection;
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset;
    
    /**
     * Private constructor - Singleton pattern
     */
    private function __construct() {
        // Check if config file exists
        if (!file_exists(__DIR__ . '/config.php')) {
            // Redirect to installer if not configured
            if (!strpos($_SERVER['REQUEST_URI'], 'installer') !== false) {
                header('Location: /installer/');
                exit;
            }
            return;
        }
        
        require_once __DIR__ . '/config.php';
        
        $this->host = DB_HOST;
        $this->dbname = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
        $this->charset = DB_CHARSET;
        
        $this->connect();
    }
    
    /**
     * Get database instance
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Connect to database
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            // Log error instead of displaying
            error_log('Database Connection Error: ' . $e->getMessage());
            
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('Database Connection Failed: ' . $e->getMessage());
            } else {
                die('Ein Datenbankfehler ist aufgetreten. Bitte kontaktieren Sie den Administrator.');
            }
        }
    }
    
    /**
     * Get PDO connection
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Prepare and execute a query
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query Error: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw $e;
        }
    }
    
    /**
     * Insert data
     * @param string $table
     * @param array $data
     * @return int Last insert ID
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update data
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int Affected rows
     */
    public function update($table, $data, $where) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :{$key}";
        }
        $set = implode(', ', $set);
        
        $whereClause = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "{$key} = :where_{$key}";
            $data["where_{$key}"] = $value;
        }
        $whereClause = implode(' AND ', $whereClause);
        
        $sql = "UPDATE {$table} SET {$set} WHERE {$whereClause}";
        $stmt = $this->query($sql, $data);
        
        return $stmt->rowCount();
    }
    
    /**
     * Delete data
     * @param string $table
     * @param array $where
     * @return int Affected rows
     */
    public function delete($table, $where) {
        $whereClause = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "{$key} = :{$key}";
        }
        $whereClause = implode(' AND ', $whereClause);
        
        $sql = "DELETE FROM {$table} WHERE {$whereClause}";
        $stmt = $this->query($sql, $where);
        
        return $stmt->rowCount();
    }
    
    /**
     * Select data
     * @param string $table
     * @param array $where
     * @param array $columns
     * @param string $orderBy
     * @param int $limit
     * @return array
     */
    public function select($table, $where = [], $columns = ['*'], $orderBy = '', $limit = null) {
        $columnList = implode(', ', $columns);
        $sql = "SELECT {$columnList} FROM {$table}";
        
        if (!empty($where)) {
            $whereClause = [];
            foreach ($where as $key => $value) {
                $whereClause[] = "{$key} = :{$key}";
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClause);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }
        
        $stmt = $this->query($sql, $where);
        return $stmt->fetchAll();
    }
    
    /**
     * Get single row
     * @param string $table
     * @param array $where
     * @param array $columns
     * @return array|null
     */
    public function selectOne($table, $where = [], $columns = ['*']) {
        $result = $this->select($table, $where, $columns, '', 1);
        return !empty($result) ? $result[0] : null;
    }
    
    /**
     * Count rows
     * @param string $table
     * @param array $where
     * @return int
     */
    public function count($table, $where = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        
        if (!empty($where)) {
            $whereClause = [];
            foreach ($where as $key => $value) {
                $whereClause[] = "{$key} = :{$key}";
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClause);
        }
        
        $stmt = $this->query($sql, $where);
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Check if table exists
     * @param string $table
     * @return bool
     */
    public function tableExists($table) {
        try {
            $sql = "SHOW TABLES LIKE :table";
            $stmt = $this->query($sql, ['table' => $table]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}