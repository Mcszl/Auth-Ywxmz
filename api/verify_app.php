<?php
/**
 * 应用验证 API
 * 验证应用状态和配置信息
 */

require_once __DIR__ . '/../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
 * 验证回调域
 */
function validateCallbackUrl($callbackUrl, $allowedUrls, $mode) {
    if (empty($allowedUrls)) {
        return false;
    }
    
    foreach ($allowedUrls as $allowedUrl) {
        switch ($mode) {
            case 'strict':
                // 严格模式：完全匹配
                if ($callbackUrl === $allowedUrl) {
                    return true;
                }
                break;
                
            case 'moderate':
                // 中等模式：匹配到目录
                $allowedParsed = parse_url($allowedUrl);
                $callbackParsed = parse_url($callbackUrl);
                
                if ($allowedParsed['scheme'] === $callbackParsed['scheme'] &&
                    $allowedParsed['host'] === $callbackParsed['host']) {
                    
                    $allowedPath = rtrim($allowedParsed['path'] ?? '/', '/');
                    $callbackPath = rtrim($callbackParsed['path'] ?? '/', '/');
                    
                    if (strpos($callbackPath, $allowedPath) === 0) {
                        return true;
                    }
                }
                break;
                
            case 'loose':
                // 宽松模式：只匹配域名
                $allowedParsed = parse_url($allowedUrl);
                $callbackParsed = parse_url($callbackUrl);
                
                if ($allowedParsed['host'] === $callbackParsed['host']) {
                    return true;
                }
                break;
        }
    }
    
    return false;
}

/**
 * 获取权限信息
 */
function getPermissionInfo($pdo, $permissionCodes) {
    if (empty($permissionCodes)) {
        return [];
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));
        $stmt = $pdo->prepare("
            SELECT permission_code, permission_name, permission_description
            FROM authority
            WHERE permission_code IN ($placeholders) AND is_enabled = TRUE
        ");
        $stmt->execute($permissionCodes);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("获取权限信息失败: " . $e->getMessage());
        return [];
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查 PDO 扩展
    if (!extension_loaded('pdo_pgsql')) {
        error_log("PDO PostgreSQL 扩展未安装");
        jsonResponse(false, null, 'PDO PostgreSQL 扩展未安装', 500);
    }
    
    // 获取参数
    $appId = $_GET['app_id'] ?? '';
    $callbackUrl = $_GET['callback_url'] ?? '';
    $code = $_GET['code'] ?? '';
    $permissions = isset($_GET['permissions']) ? explode(',', $_GET['permissions']) : [];
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($callbackUrl)) {
        jsonResponse(false, null, '缺少 callback_url 参数', 400);
    }
    
    if (empty($permissions)) {
        jsonResponse(false, null, '缺少 permissions 参数', 400);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        // 获取更详细的错误信息
        $errorInfo = error_get_last();
        $errorMsg = $errorInfo ? $errorInfo['message'] : '未知错误';
        error_log("数据库连接失败详情: " . $errorMsg);
        jsonResponse(false, null, '数据库连接失败，请检查配置', 500);
    }
    
    ensureSchemaExists($pdo);
    
    // 查询应用配置
    $stmt = $pdo->prepare("SELECT * FROM site_config WHERE app_id = :app_id");
    $stmt->execute(['app_id' => $appId]);
    $config = $stmt->fetch();
    
    if (!$config) {
        jsonResponse(false, null, '应用不存在', 404);
    }
    
    // 验证应用状态
    switch ($config['status']) {
        case 0:
            jsonResponse(false, null, '应用已被封禁，请联系管理员', 403);
            break;
        case 2:
            jsonResponse(false, null, '应用正在审核中，请等待审核通过', 403);
            break;
        case 1:
            // 正常状态，继续处理
            break;
        default:
            jsonResponse(false, null, '应用状态异常', 500);
    }
    
    // 验证回调域
    $allowedCallbacks = $config['callback_urls'];
    if (is_string($allowedCallbacks)) {
        // 处理 PostgreSQL 数组格式
        $allowedCallbacks = trim($allowedCallbacks, '{}');
        $allowedCallbacks = $allowedCallbacks ? explode(',', $allowedCallbacks) : [];
    }
    
    if (!validateCallbackUrl($callbackUrl, $allowedCallbacks, $config['callback_mode'])) {
        jsonResponse(false, null, '回调地址未授权', 403);
    }
    
    // 验证权限
    $configPermissions = $config['permissions'];
    if (is_string($configPermissions)) {
        $configPermissions = trim($configPermissions, '{}');
        $configPermissions = $configPermissions ? explode(',', $configPermissions) : [];
    }
    
    // 检查请求的权限是否都在允许的权限列表中
    $invalidPermissions = array_diff($permissions, $configPermissions);
    if (!empty($invalidPermissions)) {
        jsonResponse(false, null, '请求的权限未授权: ' . implode(', ', $invalidPermissions), 403);
    }
    
    // 验证所有请求的权限都必须在应用配置中
    if (count($permissions) === 0) {
        jsonResponse(false, null, '必须至少请求一个权限', 400);
    }
    
    // 获取权限详细信息
    $permissionInfo = getPermissionInfo($pdo, $permissions);
    
    // 构建响应数据
    $responseData = [
        'app_info' => [
            'app_id' => $config['app_id'],
            'site_name' => $config['site_name'],
            'site_url' => $config['site_url'],
            'site_protocol' => $config['site_protocol'],
            'app_icon_url' => $config['app_icon_url'] ?? null
        ],
        'callback_info' => [
            'callback_url' => $callbackUrl,
            'code' => $code
        ],
        'permissions' => $permissionInfo,
        'login_config' => [
            'enable_login' => $config['enable_login'],
            'enable_password_login' => $config['enable_password_login'],
            'enable_email_code_login' => $config['enable_email_code_login'],
            'enable_phone_code_login' => $config['enable_phone_code_login'],
            'enable_third_party_login' => $config['enable_third_party_login'],
            'enable_qq_login' => $config['enable_qq_login'],
            'enable_wechat_login' => $config['enable_wechat_login'],
            'enable_weibo_login' => $config['enable_weibo_login'],
            'enable_github_login' => $config['enable_github_login'],
            'enable_google_login' => $config['enable_google_login']
        ],
        'register_config' => [
            'enable_register' => $config['enable_register'],
            'enable_phone_register' => $config['enable_phone_register'],
            'enable_email_register' => $config['enable_email_register']
        ]
    ];
    
    jsonResponse(true, $responseData, '验证成功');
    
} catch (Exception $e) {
    error_log("验证 API 错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
