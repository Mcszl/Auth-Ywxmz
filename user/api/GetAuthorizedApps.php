<?php
/**
 * 获取用户已授权应用列表 API
 * 返回当前用户已授权的所有应用信息
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 从 session 获取用户 UUID
    $uuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($uuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 查询用户已授权的应用（通过OpenID表）
    $stmt = $pdo->prepare("
        SELECT 
            o.app_id,
            o.created_at as authorized_at,
            sc.site_name as app_name,
            sc.app_icon_url as app_icon,
            sc.site_url as app_url,
            sc.description as app_description,
            sc.permissions as scope
        FROM users.openid o
        INNER JOIN site_configs.site_config sc ON o.app_id = sc.app_id
        WHERE o.user_uuid = :uuid AND o.status = 1
        ORDER BY o.created_at DESC
    ");
    $stmt->execute(['uuid' => $uuid]);
    $apps = $stmt->fetchAll();
    
    // 格式化数据
    $formattedApps = [];
    foreach ($apps as $app) {
        // 解析权限数组
        $permissions = $app['scope'];
        if (is_string($permissions)) {
            // PostgreSQL数组格式转换
            $permissions = trim($permissions, '{}');
            $permissions = $permissions ? explode(',', $permissions) : [];
        } elseif (!is_array($permissions)) {
            $permissions = [];
        }
        
        $formattedApps[] = [
            'app_id' => $app['app_id'],
            'app_name' => $app['app_name'],
            'app_icon' => $app['app_icon'] ?: 'https://via.placeholder.com/64',
            'app_url' => $app['app_url'],
            'app_description' => $app['app_description'],
            'scope' => implode(',', $permissions),
            'authorized_at' => $app['authorized_at'],
            'expires_at' => null, // OpenID不过期
            'is_expired' => false
        ];
    }
    
    jsonResponse(true, [
        'apps' => $formattedApps,
        'total' => count($formattedApps)
    ], '获取成功');
    
} catch (Exception $e) {
    error_log("获取已授权应用失败: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
