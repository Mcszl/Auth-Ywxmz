<?php
/**
 * 封禁/解封用户 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 封禁或解封指定用户
 */

header('Content-Type: application/json; charset=utf-8');

// 启动会话
session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

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

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查是否登录
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $adminUuid = $_SESSION['user_uuid'];
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, '无效的请求数据', 400);
    }
    
    $userUuid = isset($input['uuid']) ? trim($input['uuid']) : '';
    $action = isset($input['action']) ? trim($input['action']) : ''; // 'ban' 或 'unban'
    
    if (empty($userUuid)) {
        jsonResponse(false, null, '缺少用户UUID参数', 400);
    }
    
    if (!in_array($action, ['ban', 'unban'])) {
        jsonResponse(false, null, '无效的操作类型', 400);
    }
    
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 检查目标用户是否存在
    $stmt = $pdo->prepare("
        SELECT username, status
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute([':uuid' => $userUuid]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 不能封禁自己
    if ($userUuid === $adminUuid) {
        jsonResponse(false, null, '不能封禁自己', 400);
    }
    
    // 执行封禁或解封
    $newStatus = ($action === 'ban') ? 0 : 1;
    $actionText = ($action === 'ban') ? '封禁' : '解封';
    
    // 检查当前状态
    if ($action === 'ban' && $targetUser['status'] == 0) {
        jsonResponse(false, null, '该用户已被封禁', 400);
    }
    
    if ($action === 'unban' && $targetUser['status'] != 0) {
        jsonResponse(false, null, '该用户未被封禁', 400);
    }
    
    // 更新用户状态
    $stmt = $pdo->prepare("
        UPDATE users.user 
        SET status = :status, updated_at = CURRENT_TIMESTAMP
        WHERE uuid = :uuid
    ");
    $stmt->execute([
        ':status' => $newStatus,
        ':uuid' => $userUuid
    ]);
    
    // 记录日志
    $logger->info('admin', "管理员{$actionText}用户", [
        'admin' => $admin['username'],
        'target_user' => $targetUser['username'],
        'target_uuid' => $userUuid,
        'action' => $actionText
    ]);
    
    // 返回成功
    jsonResponse(true, null, "{$actionText}成功");
    
} catch (PDOException $e) {
    error_log('封禁/解封用户失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '封禁/解封用户失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('封禁/解封用户失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '封禁/解封用户失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
