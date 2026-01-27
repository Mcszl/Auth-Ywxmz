<?php
/**
 * 获取授权应用详情 API
 * 管理员查看应用的完整配置信息
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
    
    // 获取请求参数
    $appId = trim($_GET['app_id'] ?? '');
    
    if (empty($appId)) {
        jsonResponse(false, null, '应用ID不能为空');
    }
    
    // 查询应用详情
    $stmt = $pdo->prepare("
        SELECT 
            sc.*,
            COUNT(DISTINCT o.user_uuid) as authorized_users
        FROM site_config sc
        LEFT JOIN users.openid o ON sc.app_id = o.app_id AND o.status = 1
        WHERE sc.app_id = :app_id
        GROUP BY sc.id
    ");
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch();
    
    if (!$app) {
        jsonResponse(false, null, '应用不存在', 404);
    }
    
    // 处理PostgreSQL数组类型
    $permissions = $app['permissions'];
    if (is_string($permissions)) {
        // PostgreSQL数组格式转换：{value1,value2} -> ['value1', 'value2']
        $permissions = trim($permissions, '{}');
        $permissions = $permissions ? explode(',', $permissions) : [];
    } elseif (!is_array($permissions)) {
        $permissions = [];
    }
    
    $callbackUrls = $app['callback_urls'];
    if (is_string($callbackUrls)) {
        $callbackUrls = trim($callbackUrls, '{}');
        $callbackUrls = $callbackUrls ? explode(',', $callbackUrls) : [];
    } elseif (!is_array($callbackUrls)) {
        $callbackUrls = [];
    }
    
    // 格式化响应数据
    $response = [
        'id' => $app['id'],
        'app_id' => $app['app_id'],
        'secret_key' => $app['secret_key'],
        'site_name' => $app['site_name'],
        'site_url' => $app['site_url'],
        'site_protocol' => $app['site_protocol'],
        'app_icon_url' => $app['app_icon_url'] ?: 'https://via.placeholder.com/64',
        'status' => $app['status'],
        'status_text' => getStatusText($app['status']),
        'permissions' => $permissions,
        'callback_urls' => $callbackUrls,
        'callback_mode' => $app['callback_mode'],
        'enable_register' => (bool)$app['enable_register'],
        'enable_phone_register' => (bool)$app['enable_phone_register'],
        'enable_email_register' => (bool)$app['enable_email_register'],
        'enable_login' => (bool)$app['enable_login'],
        'enable_password_login' => (bool)$app['enable_password_login'],
        'enable_email_code_login' => (bool)$app['enable_email_code_login'],
        'enable_phone_code_login' => (bool)$app['enable_phone_code_login'],
        'enable_third_party_login' => (bool)$app['enable_third_party_login'],
        'enable_qq_login' => (bool)$app['enable_qq_login'],
        'enable_wechat_login' => (bool)$app['enable_wechat_login'],
        'enable_weibo_login' => (bool)$app['enable_weibo_login'],
        'enable_github_login' => (bool)$app['enable_github_login'],
        'enable_google_login' => (bool)$app['enable_google_login'],
        'description' => $app['description'],
        'authorized_users' => intval($app['authorized_users']),
        'created_at' => $app['created_at'],
        'updated_at' => $app['updated_at']
    ];
    
    jsonResponse(true, $response, '获取成功');
    
} catch (Exception $e) {
    error_log("获取应用详情失败: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}

/**
 * 获取状态文本
 */
function getStatusText($status) {
    $statusMap = [
        0 => '封禁',
        1 => '正常',
        2 => '待审核'
    ];
    return $statusMap[$status] ?? '未知';
}
