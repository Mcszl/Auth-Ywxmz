<?php
/**
 * 取消应用授权 API
 * 用户取消对某个应用的授权
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
    
    // 从 session 获取用户 UUID
    $uuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($uuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $appId = $input['app_id'] ?? '';
    
    if (empty($appId)) {
        jsonResponse(false, null, '应用ID不能为空');
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
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 查询应用信息
    $stmt = $pdo->prepare("
        SELECT site_name FROM site_configs.site_config 
        WHERE app_id = :app_id
    ");
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch();
    
    if (!$app) {
        jsonResponse(false, null, '应用不存在');
    }
    
    // 删除用户的OpenID记录（取消授权）
    $stmt = $pdo->prepare("
        DELETE FROM users.openid 
        WHERE user_uuid = :uuid AND app_id = :app_id
    ");
    $stmt->execute([
        'uuid' => $uuid,
        'app_id' => $appId
    ]);
    
    $deletedCount = $stmt->rowCount();
    
    if ($deletedCount > 0) {
        $logger->info('user', '取消应用授权', [
            'uuid' => $uuid,
            'app_id' => $appId,
            'app_name' => $app['site_name']
        ]);
        
        jsonResponse(true, null, '已取消授权');
    } else {
        jsonResponse(false, null, '未找到授权记录');
    }
    
} catch (Exception $e) {
    error_log("取消授权失败: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
