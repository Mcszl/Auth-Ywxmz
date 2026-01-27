<?php
/**
 * 创建授权应用 API
 * 管理员创建新的授权应用
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

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
 * 生成唯一的应用ID
 */
function generateAppId() {
    return 'APP_' . strtoupper(bin2hex(random_bytes(8)));
}

/**
 * 生成密钥
 */
function generateSecretKey() {
    return bin2hex(random_bytes(32)); // 64位十六进制字符串
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 从 session 获取管理员 UUID
    $adminUuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($adminUuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 验证必填字段
    $siteName = trim($input['site_name'] ?? '');
    $siteUrl = trim($input['site_url'] ?? '');
    
    if (empty($siteName)) {
        jsonResponse(false, null, '应用名称不能为空');
    }
    
    if (empty($siteUrl)) {
        jsonResponse(false, null, '网站地址不能为空');
    }
    
    // 验证URL格式
    if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        jsonResponse(false, null, '网站地址格式不正确');
    }
    
    // 获取可选字段
    $siteProtocol = $input['site_protocol'] ?? 'https';
    $appIconUrl = trim($input['app_icon_url'] ?? '');
    $description = trim($input['description'] ?? '');
    $permissions = $input['permissions'] ?? ['user.basic'];
    $callbackUrls = $input['callback_urls'] ?? [];
    $callbackMode = $input['callback_mode'] ?? 'strict';
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 检查应用名称是否已存在
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM site_config WHERE site_name = :site_name");
    $stmt->execute(['site_name' => $siteName]);
    if ($stmt->fetch()['count'] > 0) {
        jsonResponse(false, null, '应用名称已存在');
    }
    
    // 生成应用ID和密钥
    $appId = generateAppId();
    $secretKey = generateSecretKey();
    
    // 确保应用ID唯一
    $maxAttempts = 10;
    $attempts = 0;
    while ($attempts < $maxAttempts) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM site_config WHERE app_id = :app_id");
        $stmt->execute(['app_id' => $appId]);
        if ($stmt->fetch()['count'] == 0) {
            break;
        }
        $appId = generateAppId();
        $attempts++;
    }
    
    if ($attempts >= $maxAttempts) {
        jsonResponse(false, null, '生成应用ID失败，请重试', 500);
    }
    
    // 转换数组为PostgreSQL数组格式
    $permissionsArray = '{' . implode(',', $permissions) . '}';
    $callbackUrlsArray = '{' . implode(',', $callbackUrls) . '}';
    
    // 插入应用记录
    $stmt = $pdo->prepare("
        INSERT INTO site_config (
            site_name,
            site_url,
            site_protocol,
            app_id,
            secret_key,
            app_icon_url,
            status,
            permissions,
            callback_urls,
            callback_mode,
            description,
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
            enable_google_login
        ) VALUES (
            :site_name,
            :site_url,
            :site_protocol,
            :app_id,
            :secret_key,
            :app_icon_url,
            1,
            :permissions,
            :callback_urls,
            :callback_mode,
            :description,
            TRUE, TRUE, TRUE, TRUE, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE
        )
    ");
    
    $stmt->execute([
        'site_name' => $siteName,
        'site_url' => $siteUrl,
        'site_protocol' => $siteProtocol,
        'app_id' => $appId,
        'secret_key' => $secretKey,
        'app_icon_url' => $appIconUrl,
        'permissions' => $permissionsArray,
        'callback_urls' => $callbackUrlsArray,
        'callback_mode' => $callbackMode,
        'description' => $description
    ]);
    
    // 记录操作日志
    $logger->info('admin', '创建授权应用', [
        'admin_uuid' => $adminUuid,
        'app_id' => $appId,
        'site_name' => $siteName,
        'site_url' => $siteUrl
    ]);
    
    jsonResponse(true, [
        'app_id' => $appId,
        'secret_key' => $secretKey
    ], '应用创建成功');
    
} catch (PDOException $e) {
    error_log("创建应用失败: " . $e->getMessage());
    jsonResponse(false, null, '创建应用失败', 500);
} catch (Exception $e) {
    error_log("创建应用错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
