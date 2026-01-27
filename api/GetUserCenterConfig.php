<?php
/**
 * 获取用户中心配置 API
 * 用于登录页面在没有传入 app_id 时自动加载用户中心配置
 */

require_once __DIR__ . '/../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 查询用户中心配置
    try {
        $stmt = $pdo->prepare("
            SELECT 
                app_id,
                callback_url,
                permissions,
                status
            FROM site_configs.user_center_config
            WHERE status = 1
            LIMIT 1
        ");
        $stmt->execute();
        
        $config = $stmt->fetch();
        
        if (!$config) {
            jsonResponse(false, null, '用户中心配置不存在', 404);
        }
        
        // 返回配置信息
        jsonResponse(true, [
            'app_id' => $config['app_id'],
            'callback_url' => $config['callback_url'],
            'permissions' => $config['permissions']
        ], '获取成功');
        
    } catch (PDOException $e) {
        error_log("查询用户中心配置失败: " . $e->getMessage());
        jsonResponse(false, null, '服务器错误', 500);
    }
    
} catch (Exception $e) {
    error_log("获取用户中心配置错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
