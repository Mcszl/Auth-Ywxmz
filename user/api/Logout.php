<?php
/**
 * 用户退出登录 API
 * 清除用户 Session
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
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if ($pdo) {
        // 设置时区为北京时间
        $pdo->exec("SET timezone = 'Asia/Shanghai'");
        
        // 确保 Schema 存在
        ensureSchemaExists($pdo);
        
        // 创建系统日志实例
        $logger = new SystemLogger($pdo);
        
        // 记录退出登录日志
        if (isset($_SESSION['user_uuid'])) {
            $logger->info('user', '用户退出登录', [
                'user_uuid' => $_SESSION['user_uuid']
            ]);
        }
    }
    
    // 清除所有 session 数据
    $_SESSION = array();
    
    // 删除 session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // 销毁 session
    session_destroy();
    
    // 返回成功响应
    jsonResponse(true, null, '退出登录成功');
    
} catch (Exception $e) {
    error_log("退出登录错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
