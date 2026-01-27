<?php
/**
 * 重置应用密钥 API
 * 管理员重置应用的密钥
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
    
    // 生成新密钥
    $newSecretKey = generateSecretKey();
    
    // 更新密钥
    $stmt = $pdo->prepare("UPDATE site_config SET secret_key = :secret_key WHERE app_id = :app_id");
    $stmt->execute([
        'secret_key' => $newSecretKey,
        'app_id' => $appId
    ]);
    
    // 记录操作日志
    $logger->info('admin', '重置应用密钥', [
        'admin_uuid' => $adminUuid,
        'app_id' => $appId,
        'site_name' => $app['site_name']
    ]);
    
    jsonResponse(true, [
        'secret_key' => $newSecretKey
    ], '密钥已重置，请及时通知应用开发者更新密钥');
    
} catch (PDOException $e) {
    error_log("重置密钥失败: " . $e->getMessage());
    jsonResponse(false, null, '重置密钥失败', 500);
} catch (Exception $e) {
    error_log("重置密钥错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
