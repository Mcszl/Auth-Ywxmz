<?php
/**
 * 安装处理器
 */
class InstallHandler {
    private $configDir;
    private $sqlDir;
    
    public function __construct() {
        $this->configDir = __DIR__ . '/../config';
        $this->sqlDir = __DIR__ . '/../sql';
    }
    
    public function handle($action, $data) {
        switch ($action) {
            case 'check_environment':
                return $this->checkEnvironment();
            case 'test_database':
                return $this->testDatabase($data);
            case 'save_database_config':
                return $this->saveDatabaseConfig($data);
            case 'check_sql_files':
                return $this->checkSqlFiles();
            case 'install_database':
                return $this->installDatabase();
            case 'create_admin':
                return $this->createAdmin($data);
            case 'finish_install':
                return $this->finishInstall();
            default:
                return ['success' => false, 'message' => '未知操作'];
        }
    }
    
    // 检查环境
    private function checkEnvironment() {
        $checks = [];
        
        // 检查PHP版本
        $checks['php_version'] = [
            'name' => 'PHP版本',
            'required' => '7.4+',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '7.4.0', '>=')
        ];
        
        // 检查PDO扩展
        $checks['pdo'] = [
            'name' => 'PDO扩展',
            'required' => '已安装',
            'current' => extension_loaded('pdo') ? '已安装' : '未安装',
            'status' => extension_loaded('pdo')
        ];
        
        // 检查PDO PostgreSQL驱动
        $checks['pdo_pgsql'] = [
            'name' => 'PDO PostgreSQL驱动',
            'required' => '已安装',
            'current' => extension_loaded('pdo_pgsql') ? '已安装' : '未安装',
            'status' => extension_loaded('pdo_pgsql')
        ];
        
        // 检查Redis扩展
        $checks['redis'] = [
            'name' => 'Redis扩展',
            'required' => '已安装',
            'current' => extension_loaded('redis') ? '已安装' : '未安装',
            'status' => extension_loaded('redis')
        ];
        
        // 检查config目录
        $checks['config_dir'] = [
            'name' => 'config目录',
            'required' => '可写',
            'current' => is_writable($this->configDir) ? '可写' : '不可写',
            'status' => is_writable($this->configDir)
        ];
        
        // 检查是否已有配置文件
        $pgConfigExists = file_exists($this->configDir . '/postgresql.config.php');
        $redisConfigExists = file_exists($this->configDir . '/redis.config.php');
        
        $allPassed = true;
        foreach ($checks as $check) {
            if (!$check['status']) {
                $allPassed = false;
                break;
            }
        }
        
        return [
            'success' => true,
            'checks' => $checks,
            'all_passed' => $allPassed,
            'config_exists' => $pgConfigExists && $redisConfigExists,
            'pg_config_exists' => $pgConfigExists,
            'redis_config_exists' => $redisConfigExists
        ];
    }
    
    // 测试数据库连接
    private function testDatabase($data) {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                $data['db_host'],
                $data['db_port'],
                $data['db_name']
            );
            
            $pdo = new PDO($dsn, $data['db_user'], $data['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            // 测试Redis连接
            if (!empty($data['redis_host'])) {
                if (!class_exists('Redis')) {
                    return ['success' => false, 'message' => 'Redis扩展未安装'];
                }
                // 动态实例化Redis类以避免静态分析错误
                $redisClass = 'Redis';
                $redis = new $redisClass();
                $connected = $redis->connect($data['redis_host'], $data['redis_port'], 5);
                if (!$connected) {
                    return ['success' => false, 'message' => 'Redis连接失败'];
                }
                if (!empty($data['redis_password'])) {
                    $redis->auth($data['redis_password']);
                }
                $redis->ping();
            }
            
            return ['success' => true, 'message' => '数据库连接成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '连接失败：' . $e->getMessage()];
        }
    }
    
    // 保存数据库配置
    private function saveDatabaseConfig($data) {
        try {
            // 生成PostgreSQL配置文件
            $pgConfig = $this->generatePgConfig($data);
            file_put_contents($this->configDir . '/postgresql.config.php', $pgConfig);
            
            // 生成Redis配置文件
            $redisConfig = $this->generateRedisConfig($data);
            // 替换占位符
            $redisConfig = str_replace('REDIS_HOST_VALUE', $data['redis_host'], $redisConfig);
            $redisConfig = str_replace('REDIS_PORT_VALUE', $data['redis_port'], $redisConfig);
            $redisConfig = str_replace('REDIS_PASSWORD_VALUE', !empty($data['redis_password']) ? $data['redis_password'] : '', $redisConfig);
            file_put_contents($this->configDir . '/redis.config.php', $redisConfig);
            
            return ['success' => true, 'message' => '配置文件已保存'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '保存失败：' . $e->getMessage()];
        }
    }
    
    // 检查SQL文件
    private function checkSqlFiles() {
        $requiredFiles = [
            'users.sql', 'user_openid.sql', 'user_center_config.sql',
            'site.sql', 'tokens.sql', 'access_token.sql', 'authority.sql',
            'third_party_login_config.sql', 'qq_user_info.sql', 'wechat_user_info.sql',
            'weibo_user_info.sql', 'github_user_info.sql', 'google_user_info.sql',
            'sms.sql', 'sms_limit.sql', 'email_code.sql', 'email_config.sql',
            'captcha_config.sql', 'captcha_log_optimization.sql',
            'storage_config.sql', 'system_logs.sql',
            'avatar_check.sql', 'avatar_check_migration.sql', 'nickname_check.sql'
        ];
        
        $missing = [];
        foreach ($requiredFiles as $file) {
            if (!file_exists($this->sqlDir . '/' . $file)) {
                $missing[] = $file;
            }
        }
        
        if (!empty($missing)) {
            return [
                'success' => false,
                'message' => '缺少SQL文件，请下载完整安装包',
                'missing_files' => $missing
            ];
        }
        
        return [
            'success' => true,
            'message' => 'SQL文件检查通过',
            'total_files' => count($requiredFiles)
        ];
    }
    
    // 安装数据库
    private function installDatabase() {
        try {
            require_once $this->configDir . '/postgresql.config.php';
            $pdo = getDBConnection();
            if (!$pdo) {
                return ['success' => false, 'message' => '数据库连接失败'];
            }
            
            // 获取站点配置
            $siteProtocol = $_POST['site_protocol'] ?? 'https';
            $siteDomain = $_POST['site_domain'] ?? 'auth.example.com';
            $siteName = $_POST['site_name'] ?? '一碗小米周开放平台';
            $siteUrl = $siteProtocol . '://' . $siteDomain;
            $callbackUrl = $siteUrl . '/user/callback';
            
            // 生成统一的 APP_ID
            $defaultAppId = 'DEFAULT_LOGIN_APP';
            $userCenterAppId = 'DEFAULT_USER_CENTER';
            
            // 创建必要的schema
            $schemas = ['site_configs', 'users', 'auth', 'tokens', 'sms', 'logs', 'checks', 'storage'];
            foreach ($schemas as $schema) {
                $pdo->exec("CREATE SCHEMA IF NOT EXISTS $schema");
            }
            
            // 执行SQL文件（除了 site.sql 和 user_center_config.sql）
            $sqlFiles = [
                'users.sql', 'user_openid.sql',
                'tokens.sql', 'access_token.sql', 'authority.sql',
                'third_party_login_config.sql', 'qq_user_info.sql', 'wechat_user_info.sql',
                'weibo_user_info.sql', 'github_user_info.sql', 'google_user_info.sql',
                'sms.sql', 'sms_limit.sql', 'email_code.sql', 'email_config.sql',
                'captcha_config.sql', 'captcha_log_optimization.sql',
                'storage_config.sql', 'system_logs.sql',
                'avatar_check.sql', 'avatar_check_migration.sql', 'nickname_check.sql'
            ];
            
            $executed = [];
            foreach ($sqlFiles as $file) {
                $sql = file_get_contents($this->sqlDir . '/' . $file);
                $pdo->exec($sql);
                $executed[] = $file;
            }
            
            // 执行 site.sql（默认数据已注释）
            $siteSql = file_get_contents($this->sqlDir . '/site.sql');
            $pdo->exec($siteSql);
            $executed[] = 'site.sql';
            
            // 插入自定义的默认应用配置
            $this->insertDefaultApps($pdo, $defaultAppId, $userCenterAppId, $siteName, $siteUrl, $siteProtocol, $callbackUrl);
            
            // 执行 user_center_config.sql（默认数据已注释）
            $userCenterSql = file_get_contents($this->sqlDir . '/user_center_config.sql');
            $pdo->exec($userCenterSql);
            $executed[] = 'user_center_config.sql';
            
            // 插入用户中心配置
            $this->insertUserCenterConfig($pdo, $userCenterAppId, $callbackUrl);
            
            return [
                'success' => true,
                'message' => '数据表安装成功',
                'executed_files' => $executed
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '安装失败：' . $e->getMessage()];
        }
    }
    
    // 插入默认应用配置
    private function insertDefaultApps($pdo, $defaultAppId, $userCenterAppId, $siteName, $siteUrl, $siteProtocol, $callbackUrl) {
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.site_config (
                site_name,
                site_url,
                site_protocol,
                app_id,
                secret_key,
                status,
                permissions,
                callback_urls,
                callback_mode,
                enable_register,
                enable_phone_register,
                enable_email_register,
                enable_login,
                enable_password_login,
                enable_email_code_login,
                enable_phone_code_login,
                enable_third_party_login,
                enable_qq_login,
                enable_wechat_login,
                enable_weibo_login,
                enable_github_login,
                enable_google_login,
                description,
                created_at,
                updated_at
            ) VALUES (
                :site_name1,
                :site_url1,
                :site_protocol1,
                :app_id1,
                :secret_key1,
                1,
                ARRAY['user.basic', 'user.email', 'user.phone'],
                ARRAY[:callback_url1],
                'strict',
                true, true, true, true, true, false, false,
                true, true, true, true, true, true,
                '默认登录应用',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            ), (
                '用户中心',
                :site_url2,
                :site_protocol2,
                :app_id2,
                :secret_key2,
                1,
                ARRAY['user.basic', 'user.email', 'user.phone', 'user.profile'],
                ARRAY[:callback_url2],
                'strict',
                false, false, false, true, true, true, true,
                false, false, false, false, false, false,
                '默认用户中心应用',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        
        $stmt->execute([
            'site_name1' => $siteName,
            'site_url1' => $siteUrl,
            'site_protocol1' => $siteProtocol,
            'app_id1' => $defaultAppId,
            'secret_key1' => bin2hex(random_bytes(32)),
            'callback_url1' => $callbackUrl,
            'site_url2' => $siteUrl,
            'site_protocol2' => $siteProtocol,
            'app_id2' => $userCenterAppId,
            'secret_key2' => bin2hex(random_bytes(32)),
            'callback_url2' => $callbackUrl
        ]);
    }
    
    // 插入用户中心配置
    private function insertUserCenterConfig($pdo, $userCenterAppId, $callbackUrl) {
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.user_center_config (
                app_id,
                callback_url,
                permissions,
                status,
                created_at,
                updated_at
            ) VALUES (
                :app_id,
                :callback_url,
                'user.basic',
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        
        $stmt->execute([
            'app_id' => $userCenterAppId,
            'callback_url' => $callbackUrl
        ]);
    }
    
    // 创建管理员账号
    private function createAdmin($data) {
        try {
            require_once $this->configDir . '/postgresql.config.php';
            $pdo = getDBConnection();
            if (!$pdo) {
                return ['success' => false, 'message' => '数据库连接失败'];
            }
            
            $pdo->exec("SET timezone = 'Asia/Shanghai'");
            
            // 密码加密
            $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
            
            // 判断是邮箱还是手机号
            $isEmail = filter_var($data['account'], FILTER_VALIDATE_EMAIL);
            
            // 插入用户表（uuid 会自动生成）
            $stmt = $pdo->prepare("
                INSERT INTO users.user (
                    username, nickname, password_hash, email, phone, status, user_type, created_at
                ) VALUES (
                    :username, :nickname, :password_hash, :email, :phone, 1, 'admin', CURRENT_TIMESTAMP
                )
                RETURNING id, uuid
            ");
            
            $stmt->execute([
                'username' => $data['account'],
                'nickname' => '管理员',
                'password_hash' => $passwordHash,
                'email' => $isEmail ? $data['account'] : null,
                'phone' => !$isEmail ? $data['account'] : null
            ]);
            
            // 获取插入的用户ID
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $userId = $user['id'];
            
            // 插入用户资料表
            $stmt = $pdo->prepare("
                INSERT INTO users.user_profile (
                    user_id, real_name, bio, created_at
                ) VALUES (
                    :user_id, :real_name, '系统管理员', CURRENT_TIMESTAMP
                )
            ");
            
            $stmt->execute([
                'user_id' => $userId,
                'real_name' => '管理员'
            ]);
            
            // 创建默认应用（已在 installDatabase 中处理）
            
            return ['success' => true, 'message' => '管理员账号创建成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '创建失败：' . $e->getMessage()];
        }
    }
    
    // 完成安装
    private function finishInstall() {
        try {
            // 创建安装锁文件
            file_put_contents(__DIR__ . '/../config/install.lock', date('Y-m-d H:i:s'));
            
            return ['success' => true, 'message' => '安装完成'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '完成失败：' . $e->getMessage()];
        }
    }
    
    // 生成PostgreSQL配置文件
    private function generatePgConfig($data) {
        return <<<PHP
<?php
/**
 * PostgreSQL 数据库配置文件
 * 一碗小米周开放平台
 */

// 数据库配置
define('DB_HOST', '{$data['db_host']}');
define('DB_PORT', '{$data['db_port']}');
define('DB_NAME', '{$data['db_name']}');
define('DB_USER', '{$data['db_user']}');
define('DB_PASSWORD', '{$data['db_password']}');
define('DB_CHARSET', 'UTF8');

// Schema 配置
define('DB_SCHEMA', 'site_configs');

// 连接选项
define('DB_TIMEOUT', 30);
define('DB_PERSISTENT', false);

/**
 * 获取数据库连接
 * @return PDO|false
 */
function getDBConnection() {
    try {
        \$dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        \$options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => DB_TIMEOUT,
            PDO::ATTR_PERSISTENT => DB_PERSISTENT
        ];
        
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASSWORD, \$options);
        
        // 设置时区为北京时间
        \$pdo->exec("SET timezone = 'Asia/Shanghai'");
        
        // 设置默认 schema
        \$pdo->exec("SET search_path TO " . DB_SCHEMA . ", public");
        
        return \$pdo;
    } catch (PDOException \$e) {
        error_log("数据库连接失败: " . \$e->getMessage());
        return false;
    }
}

/**
 * 检查并创建 Schema
 * @param PDO \$pdo
 * @return bool
 */
function ensureSchemaExists(\$pdo) {
    try {
        // 需要创建的 Schema 列表
        \$schemas = [DB_SCHEMA, 'sms', 'users', 'logs', 'checks'];
        
        foreach (\$schemas as \$schema) {
            // 检查 schema 是否存在
            \$stmt = \$pdo->prepare("
                SELECT schema_name 
                FROM information_schema.schemata 
                WHERE schema_name = :schema
            ");
            \$stmt->execute(['schema' => \$schema]);
            
            if (!\$stmt->fetch()) {
                // Schema 不存在，创建它
                \$pdo->exec("CREATE SCHEMA IF NOT EXISTS " . \$schema);
                error_log("Schema '" . \$schema . "' 已创建");
            }
        }
        
        return true;
    } catch (PDOException \$e) {
        error_log("Schema 检查/创建失败: " . \$e->getMessage());
        return false;
    }
}

PHP;
    }
    
    // 生成Redis配置文件
    private function generateRedisConfig($data) {
        $password = !empty($data['redis_password']) ? $data['redis_password'] : '';
        return <<<'PHP'
<?php
/**
 * Redis 配置文件
 * 一碗小米周开放平台
 */

// Redis 配置
define('REDIS_HOST', 'REDIS_HOST_VALUE');
define('REDIS_PORT', REDIS_PORT_VALUE);
define('REDIS_PASSWORD', 'REDIS_PASSWORD_VALUE');
define('REDIS_DATABASE', 0);
define('REDIS_TIMEOUT', 5);

// Redis Key 前缀
define('REDIS_PREFIX', 'auth:sms:');

// Key 命名规则
define('REDIS_KEY_RATE_LIMIT', REDIS_PREFIX . 'rate_limit:');
define('REDIS_KEY_WHITELIST', REDIS_PREFIX . 'whitelist');
define('REDIS_KEY_BLACKLIST', REDIS_PREFIX . 'blacklist');
define('REDIS_KEY_SEND_COUNT', REDIS_PREFIX . 'send_count:');

/**
 * 获取 Redis 连接
 * @return Redis|false
 */
function getRedisConnection() {
    try {
        if (!class_exists('Redis')) {
            error_log("Redis 扩展未安装");
            return false;
        }
        
        $redis = new Redis();
        
        // 连接 Redis
        $connected = $redis->connect(REDIS_HOST, REDIS_PORT, REDIS_TIMEOUT);
        
        if (!$connected) {
            error_log("Redis 连接失败");
            return false;
        }
        
        // 设置密码（如果有）
        if (!empty(REDIS_PASSWORD)) {
            $redis->auth(REDIS_PASSWORD);
        }
        
        // 选择数据库
        $redis->select(REDIS_DATABASE);
        
        return $redis;
        
    } catch (Exception $e) {
        error_log("Redis 连接错误: " . $e->getMessage());
        return false;
    }
}

/**
 * 检查 Redis 是否可用
 * @return bool
 */
function isRedisAvailable() {
    $redis = getRedisConnection();
    if (!$redis) {
        return false;
    }
    
    try {
        $redis->ping();
        return true;
    } catch (Exception $e) {
        error_log("Redis 不可用: " . $e->getMessage());
        return false;
    }
}

PHP;
    }
}
