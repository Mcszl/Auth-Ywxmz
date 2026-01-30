<?php
/**
 * PostgreSQL 数据库配置文件
 * 一碗小米周授权登录平台
 */

// 数据库配置
define('DB_HOST', 'ningbo.postgresql.ywxmz.com');           // 数据库主机地址
define('DB_PORT', '5432');                // 数据库端口
define('DB_NAME', 'auth');       // 数据库名称
define('DB_USER', 'auth');            // 数据库用户名
define('DB_PASSWORD', 'wKmewESBnaw7DLr6');   // 数据库密码
define('DB_CHARSET', 'UTF8');             // 字符集

// Schema 配置
define('DB_SCHEMA', 'site_configs');      // Schema 名称

// 连接选项
define('DB_TIMEOUT', 30);                 // 连接超时时间（秒）
define('DB_PERSISTENT', false);           // 是否使用持久连接

/**
 * 获取数据库连接
 * @return PDO|false
 */
function getDBConnection() {
    try {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => DB_TIMEOUT,
            PDO::ATTR_PERSISTENT => DB_PERSISTENT
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        
        // 设置时区为北京时间
        $pdo->exec("SET timezone = 'Asia/Shanghai'");
        
        // 设置默认 schema
        $pdo->exec("SET search_path TO " . DB_SCHEMA . ", public");
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("数据库连接失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 检查并创建 Schema
 * @param PDO $pdo
 * @return bool
 */
function ensureSchemaExists($pdo) {
    try {
        // 需要创建的 Schema 列表
        $schemas = [DB_SCHEMA, 'sms', 'users', 'logs', 'checks'];
        
        foreach ($schemas as $schema) {
            // 检查 schema 是否存在
            $stmt = $pdo->prepare("
                SELECT schema_name 
                FROM information_schema.schemata 
                WHERE schema_name = :schema
            ");
            $stmt->execute(['schema' => $schema]);
            
            if (!$stmt->fetch()) {
                // Schema 不存在，创建它
                $pdo->exec("CREATE SCHEMA IF NOT EXISTS " . $schema);
                error_log("Schema '" . $schema . "' 已创建");
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Schema 检查/创建失败: " . $e->getMessage());
        return false;
    }
}
