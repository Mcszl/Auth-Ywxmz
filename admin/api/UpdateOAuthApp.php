<?php
/**
 * 更新授权应用 API
 * 管理员更新应用配置（不包括app_id）
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
    
    $appId = trim($input['app_id'] ?? '');
    
    if (empty($appId)) {
        jsonResponse(false, null, '应用ID不能为空');
    }
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 检查应用是否存在
    $stmt = $pdo->prepare("SELECT id, site_name FROM site_config WHERE app_id = :app_id");
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch();
    
    if (!$app) {
        jsonResponse(false, null, '应用不存在', 404);
    }
    
    // 构建更新字段
    $updateFields = [];
    $params = ['app_id' => $appId];
    
    // 基本信息
    if (isset($input['site_name'])) {
        $siteName = trim($input['site_name']);
        if (empty($siteName)) {
            jsonResponse(false, null, '应用名称不能为空');
        }
        
        // 检查名称是否与其他应用重复
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM site_config WHERE site_name = :site_name AND app_id != :app_id");
        $stmt->execute(['site_name' => $siteName, 'app_id' => $appId]);
        if ($stmt->fetch()['count'] > 0) {
            jsonResponse(false, null, '应用名称已被其他应用使用');
        }
        
        $updateFields[] = "site_name = :site_name";
        $params['site_name'] = $siteName;
    }
    
    if (isset($input['site_url'])) {
        $siteUrl = trim($input['site_url']);
        if (empty($siteUrl)) {
            jsonResponse(false, null, '网站地址不能为空');
        }
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(false, null, '网站地址格式不正确');
        }
        $updateFields[] = "site_url = :site_url";
        $params['site_url'] = $siteUrl;
    }
    
    if (isset($input['site_protocol'])) {
        $updateFields[] = "site_protocol = :site_protocol";
        $params['site_protocol'] = $input['site_protocol'];
    }
    
    if (isset($input['app_icon_url'])) {
        $updateFields[] = "app_icon_url = :app_icon_url";
        $params['app_icon_url'] = trim($input['app_icon_url']);
    }
    
    if (isset($input['description'])) {
        $updateFields[] = "description = :description";
        $params['description'] = trim($input['description']);
    }
    
    // 权限配置
    if (isset($input['permissions'])) {
        $permissions = is_array($input['permissions']) ? $input['permissions'] : [];
        $permissionsArray = '{' . implode(',', $permissions) . '}';
        $updateFields[] = "permissions = :permissions";
        $params['permissions'] = $permissionsArray;
    }
    
    // 回调配置
    if (isset($input['callback_urls'])) {
        $callbackUrls = is_array($input['callback_urls']) ? $input['callback_urls'] : [];
        $callbackUrlsArray = '{' . implode(',', $callbackUrls) . '}';
        $updateFields[] = "callback_urls = :callback_urls";
        $params['callback_urls'] = $callbackUrlsArray;
    }
    
    if (isset($input['callback_mode'])) {
        $updateFields[] = "callback_mode = :callback_mode";
        $params['callback_mode'] = $input['callback_mode'];
    }
    
    // 注册配置
    if (isset($input['enable_register'])) {
        $updateFields[] = "enable_register = :enable_register";
        $params['enable_register'] = $input['enable_register'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_phone_register'])) {
        $updateFields[] = "enable_phone_register = :enable_phone_register";
        $params['enable_phone_register'] = $input['enable_phone_register'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_email_register'])) {
        $updateFields[] = "enable_email_register = :enable_email_register";
        $params['enable_email_register'] = $input['enable_email_register'] ? 'TRUE' : 'FALSE';
    }
    
    // 登录配置
    if (isset($input['enable_login'])) {
        $updateFields[] = "enable_login = :enable_login";
        $params['enable_login'] = $input['enable_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_password_login'])) {
        $updateFields[] = "enable_password_login = :enable_password_login";
        $params['enable_password_login'] = $input['enable_password_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_email_code_login'])) {
        $updateFields[] = "enable_email_code_login = :enable_email_code_login";
        $params['enable_email_code_login'] = $input['enable_email_code_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_phone_code_login'])) {
        $updateFields[] = "enable_phone_code_login = :enable_phone_code_login";
        $params['enable_phone_code_login'] = $input['enable_phone_code_login'] ? 'TRUE' : 'FALSE';
    }
    
    // 第三方登录配置
    if (isset($input['enable_third_party_login'])) {
        $updateFields[] = "enable_third_party_login = :enable_third_party_login";
        $params['enable_third_party_login'] = $input['enable_third_party_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_qq_login'])) {
        $updateFields[] = "enable_qq_login = :enable_qq_login";
        $params['enable_qq_login'] = $input['enable_qq_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_wechat_login'])) {
        $updateFields[] = "enable_wechat_login = :enable_wechat_login";
        $params['enable_wechat_login'] = $input['enable_wechat_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_weibo_login'])) {
        $updateFields[] = "enable_weibo_login = :enable_weibo_login";
        $params['enable_weibo_login'] = $input['enable_weibo_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_github_login'])) {
        $updateFields[] = "enable_github_login = :enable_github_login";
        $params['enable_github_login'] = $input['enable_github_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (isset($input['enable_google_login'])) {
        $updateFields[] = "enable_google_login = :enable_google_login";
        $params['enable_google_login'] = $input['enable_google_login'] ? 'TRUE' : 'FALSE';
    }
    
    if (empty($updateFields)) {
        jsonResponse(false, null, '没有需要更新的字段');
    }
    
    // 执行更新
    $sql = "UPDATE site_config SET " . implode(', ', $updateFields) . " WHERE app_id = :app_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // 记录操作日志
    $logger->info('admin', '更新授权应用', [
        'admin_uuid' => $adminUuid,
        'app_id' => $appId,
        'site_name' => $app['site_name'],
        'updated_fields' => array_keys($params)
    ]);
    
    jsonResponse(true, null, '应用配置已更新');
    
} catch (PDOException $e) {
    error_log("更新应用失败: " . $e->getMessage());
    jsonResponse(false, null, '更新应用失败', 500);
} catch (Exception $e) {
    error_log("更新应用错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
