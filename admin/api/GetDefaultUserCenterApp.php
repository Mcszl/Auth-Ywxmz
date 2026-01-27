<?php
/**
 * 获取默认用户中心应用 API
 * 返回当前设置为默认的用户中心应用信息
 */

session_start();
require_once __DIR__ . '/../../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');

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
 * 检查管理员权限
 */
function checkAdminPermission($pdo) {
    if (!isset($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $userUuid = $_SESSION['user_uuid'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT user_type 
            FROM users.user 
            WHERE uuid = :uuid AND status = 1
        ");
        $stmt->execute(['uuid' => $userUuid]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, null, '用户不存在或已被禁用', 403);
        }
        
        if (!in_array($user['user_type'], ['admin', 'siteadmin'])) {
            jsonResponse(false, null, '权限不足', 403);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("检查管理员权限失败: " . $e->getMessage());
        jsonResponse(false, null, '服务器错误', 500);
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
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 检查管理员权限
    checkAdminPermission($pdo);
    
    // 查询当前默认应用
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.app_id,
                c.callback_url,
                c.permissions,
                c.status,
                s.site_name,
                s.site_url,
                s.site_protocol,
                s.app_icon_url
            FROM site_configs.user_center_config c
            LEFT JOIN site_config s ON c.app_id = s.app_id
            WHERE c.status = 1
            LIMIT 1
        ");
        $stmt->execute();
        
        $config = $stmt->fetch();
        
        if (!$config) {
            jsonResponse(true, null, '未设置默认应用');
        }
        
        jsonResponse(true, [
            'app_id' => $config['app_id'],
            'site_name' => $config['site_name'],
            'site_url' => $config['site_url'],
            'site_protocol' => $config['site_protocol'],
            'callback_url' => $config['callback_url'],
            'permissions' => $config['permissions'],
            'app_icon_url' => $config['app_icon_url']
        ], '获取成功');
        
    } catch (PDOException $e) {
        error_log("查询默认用户中心应用失败: " . $e->getMessage());
        jsonResponse(false, null, '服务器错误', 500);
    }
    
} catch (Exception $e) {
    error_log("获取默认用户中心应用错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
