<?php
/**
 * 网站配置 API
 * 一碗小米周授权登录平台
 */

require_once __DIR__ . '/../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');

// 允许跨域请求（根据需要配置）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * 返回 JSON 响应
 */
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 获取网站配置
 */
function getSiteConfig($pdo, $id = null) {
    try {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM site_config WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $config = $stmt->fetch();
            
            if (!$config) {
                jsonResponse(false, null, '配置不存在', 404);
            }
            
            return $config;
        } else {
            // 获取最新的配置
            $stmt = $pdo->query("SELECT * FROM site_config ORDER BY created_at DESC LIMIT 1");
            $config = $stmt->fetch();
            
            if (!$config) {
                jsonResponse(false, null, '未找到配置', 404);
            }
            
            return $config;
        }
    } catch (PDOException $e) {
        error_log("获取配置失败: " . $e->getMessage());
        jsonResponse(false, null, '获取配置失败', 500);
    }
}

/**
 * 创建网站配置
 */
function createSiteConfig($pdo, $data) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO site_config (
                site_name, site_url, site_protocol, app_id, secret_key, status,
                permissions, callback_urls, callback_mode,
                enable_register, enable_phone_register, enable_email_register,
                enable_login, enable_password_login, enable_email_code_login, enable_phone_code_login,
                enable_third_party_login, enable_qq_login, enable_wechat_login, 
                enable_weibo_login, enable_github_login, enable_google_login,
                description
            ) VALUES (
                :site_name, :site_url, :site_protocol, :app_id, :secret_key, :status,
                :permissions, :callback_urls, :callback_mode,
                :enable_register, :enable_phone_register, :enable_email_register,
                :enable_login, :enable_password_login, :enable_email_code_login, :enable_phone_code_login,
                :enable_third_party_login, :enable_qq_login, :enable_wechat_login,
                :enable_weibo_login, :enable_github_login, :enable_google_login,
                :description
            ) RETURNING id
        ");
        
        // 生成 APP_ID 和 SECRET_KEY
        $appId = $data['app_id'] ?? 'APP_' . str_pad(rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $secretKey = $data['secret_key'] ?? md5(uniqid(rand(), true));
        
        // 处理数组字段
        $permissions = isset($data['permissions']) && is_array($data['permissions']) 
            ? '{' . implode(',', $data['permissions']) . '}' 
            : '{user.basic,user.email}';
            
        $callbackUrls = isset($data['callback_urls']) && is_array($data['callback_urls']) 
            ? '{' . implode(',', $data['callback_urls']) . '}' 
            : '{}';
        
        $stmt->execute([
            'site_name' => $data['site_name'] ?? '',
            'site_url' => $data['site_url'] ?? '',
            'site_protocol' => $data['site_protocol'] ?? 'https',
            'app_id' => $appId,
            'secret_key' => $secretKey,
            'status' => $data['status'] ?? 1,
            'permissions' => $permissions,
            'callback_urls' => $callbackUrls,
            'callback_mode' => $data['callback_mode'] ?? 'strict',
            'enable_register' => $data['enable_register'] ?? true,
            'enable_phone_register' => $data['enable_phone_register'] ?? true,
            'enable_email_register' => $data['enable_email_register'] ?? true,
            'enable_login' => $data['enable_login'] ?? true,
            'enable_password_login' => $data['enable_password_login'] ?? true,
            'enable_email_code_login' => $data['enable_email_code_login'] ?? false,
            'enable_phone_code_login' => $data['enable_phone_code_login'] ?? false,
            'enable_third_party_login' => $data['enable_third_party_login'] ?? false,
            'enable_qq_login' => $data['enable_qq_login'] ?? false,
            'enable_wechat_login' => $data['enable_wechat_login'] ?? false,
            'enable_weibo_login' => $data['enable_weibo_login'] ?? false,
            'enable_github_login' => $data['enable_github_login'] ?? false,
            'enable_google_login' => $data['enable_google_login'] ?? false,
            'description' => $data['description'] ?? ''
        ]);
        
        $result = $stmt->fetch();
        return $result['id'];
    } catch (PDOException $e) {
        error_log("创建配置失败: " . $e->getMessage());
        jsonResponse(false, null, '创建配置失败: ' . $e->getMessage(), 500);
    }
}

/**
 * 更新网站配置
 */
function updateSiteConfig($pdo, $id, $data) {
    try {
        $stmt = $pdo->prepare("
            UPDATE site_config SET
                site_name = :site_name,
                site_url = :site_url,
                site_protocol = :site_protocol,
                status = :status,
                permissions = :permissions,
                callback_urls = :callback_urls,
                callback_mode = :callback_mode,
                enable_register = :enable_register,
                enable_phone_register = :enable_phone_register,
                enable_email_register = :enable_email_register,
                enable_login = :enable_login,
                enable_password_login = :enable_password_login,
                enable_email_code_login = :enable_email_code_login,
                enable_phone_code_login = :enable_phone_code_login,
                enable_third_party_login = :enable_third_party_login,
                enable_qq_login = :enable_qq_login,
                enable_wechat_login = :enable_wechat_login,
                enable_weibo_login = :enable_weibo_login,
                enable_github_login = :enable_github_login,
                enable_google_login = :enable_google_login,
                description = :description
            WHERE id = :id
        ");
        
        // 处理数组字段
        $permissions = isset($data['permissions']) && is_array($data['permissions']) 
            ? '{' . implode(',', $data['permissions']) . '}' 
            : null;
            
        $callbackUrls = isset($data['callback_urls']) && is_array($data['callback_urls']) 
            ? '{' . implode(',', $data['callback_urls']) . '}' 
            : null;
        
        // 获取当前配置
        $current = getSiteConfig($pdo, $id);
        
        $stmt->execute([
            'id' => $id,
            'site_name' => $data['site_name'] ?? $current['site_name'],
            'site_url' => $data['site_url'] ?? $current['site_url'],
            'site_protocol' => $data['site_protocol'] ?? $current['site_protocol'],
            'status' => $data['status'] ?? $current['status'],
            'permissions' => $permissions ?? $current['permissions'],
            'callback_urls' => $callbackUrls ?? $current['callback_urls'],
            'callback_mode' => $data['callback_mode'] ?? $current['callback_mode'],
            'enable_register' => $data['enable_register'] ?? $current['enable_register'],
            'enable_phone_register' => $data['enable_phone_register'] ?? $current['enable_phone_register'],
            'enable_email_register' => $data['enable_email_register'] ?? $current['enable_email_register'],
            'enable_login' => $data['enable_login'] ?? $current['enable_login'],
            'enable_password_login' => $data['enable_password_login'] ?? $current['enable_password_login'],
            'enable_email_code_login' => $data['enable_email_code_login'] ?? $current['enable_email_code_login'],
            'enable_phone_code_login' => $data['enable_phone_code_login'] ?? $current['enable_phone_code_login'],
            'enable_third_party_login' => $data['enable_third_party_login'] ?? $current['enable_third_party_login'],
            'enable_qq_login' => $data['enable_qq_login'] ?? $current['enable_qq_login'],
            'enable_wechat_login' => $data['enable_wechat_login'] ?? $current['enable_wechat_login'],
            'enable_weibo_login' => $data['enable_weibo_login'] ?? $current['enable_weibo_login'],
            'enable_github_login' => $data['enable_github_login'] ?? $current['enable_github_login'],
            'enable_google_login' => $data['enable_google_login'] ?? $current['enable_google_login'],
            'description' => $data['description'] ?? $current['description']
        ]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("更新配置失败: " . $e->getMessage());
        jsonResponse(false, null, '更新配置失败: ' . $e->getMessage(), 500);
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 获取请求方法
    $method = $_SERVER['REQUEST_METHOD'];
    
    // 获取请求数据
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 路由处理
    switch ($method) {
        case 'GET':
            // 获取配置
            $id = $_GET['id'] ?? null;
            $config = getSiteConfig($pdo, $id);
            jsonResponse(true, $config, '获取成功');
            break;
            
        case 'POST':
            // 创建配置
            if (!$data) {
                jsonResponse(false, null, '无效的请求数据', 400);
            }
            $id = createSiteConfig($pdo, $data);
            jsonResponse(true, ['id' => $id], '创建成功', 201);
            break;
            
        case 'PUT':
            // 更新配置
            $id = $_GET['id'] ?? null;
            if (!$id) {
                jsonResponse(false, null, '缺少配置ID', 400);
            }
            if (!$data) {
                jsonResponse(false, null, '无效的请求数据', 400);
            }
            $success = updateSiteConfig($pdo, $id, $data);
            if ($success) {
                jsonResponse(true, null, '更新成功');
            } else {
                jsonResponse(false, null, '更新失败或配置不存在', 404);
            }
            break;
            
        default:
            jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
} catch (Exception $e) {
    error_log("API 错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
