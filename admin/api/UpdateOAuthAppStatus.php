<?php
/**
 * 更新应用状态 API
 * 管理员启用、封禁或设置应用为待审核状态
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
    $status = isset($input['status']) ? intval($input['status']) : null;
    
    if (empty($appId)) {
        jsonResponse(false, null, '应用ID不能为空');
    }
    
    if ($status === null) {
        jsonResponse(false, null, '状态值不能为空');
    }
    
    // 验证状态值
    if (!in_array($status, [0, 1, 2])) {
        jsonResponse(false, null, '无效的状态值');
    }
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 检查应用是否存在
    $stmt = $pdo->prepare("SELECT id, site_name, status as old_status FROM site_config WHERE app_id = :app_id");
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch();
    
    if (!$app) {
        jsonResponse(false, null, '应用不存在', 404);
    }
    
    // 检查状态是否有变化
    if ($app['old_status'] == $status) {
        jsonResponse(true, null, '状态未发生变化');
    }
    
    // 更新状态
    $stmt = $pdo->prepare("UPDATE site_config SET status = :status WHERE app_id = :app_id");
    $stmt->execute([
        'status' => $status,
        'app_id' => $appId
    ]);
    
    // 记录操作日志
    $logger->info('admin', '更新应用状态', [
        'admin_uuid' => $adminUuid,
        'app_id' => $appId,
        'site_name' => $app['site_name'],
        'old_status' => $app['old_status'],
        'old_status_text' => getStatusText($app['old_status']),
        'new_status' => $status,
        'new_status_text' => getStatusText($status)
    ]);
    
    $message = '应用状态已更新为：' . getStatusText($status);
    jsonResponse(true, null, $message);
    
} catch (PDOException $e) {
    error_log("更新应用状态失败: " . $e->getMessage());
    jsonResponse(false, null, '更新应用状态失败', 500);
} catch (Exception $e) {
    error_log("更新应用状态错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
