<?php
/**
 * 解绑 QQ API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 将 QQ 绑定状态设置为已解绑
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
    
    // 检查管理员权限
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取 POST 数据
    $input = json_decode(file_get_contents('php://input'), true);
    $bindingId = isset($input['id']) ? intval($input['id']) : 0;
    
    if ($bindingId <= 0) {
        jsonResponse(false, null, '缺少绑定ID参数', 400);
    }
    
    // 查询绑定信息
    $stmt = $pdo->prepare("
        SELECT id, openid, user_uuid, qq_nickname, bind_status
        FROM auth.qq_user_info
        WHERE id = :id
    ");
    $stmt->execute([':id' => $bindingId]);
    $binding = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$binding) {
        jsonResponse(false, null, '绑定信息不存在', 404);
    }
    
    if ($binding['bind_status'] == 0) {
        jsonResponse(false, null, '该绑定已经是解绑状态', 400);
    }
    
    // 更新绑定状态为已解绑
    $stmt = $pdo->prepare("
        UPDATE auth.qq_user_info
        SET bind_status = 0,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([':id' => $bindingId]);
    
    // 记录日志
    $logger->info('admin', '管理员解绑 QQ', [
        'admin' => $admin['username'],
        'binding_id' => $bindingId,
        'openid' => $binding['openid'],
        'user_uuid' => $binding['user_uuid'],
        'qq_nickname' => $binding['qq_nickname']
    ]);
    
    // 返回成功
    jsonResponse(true, null, '解绑成功');
    
} catch (PDOException $e) {
    error_log('解绑 QQ 失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '解绑 QQ 失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('解绑 QQ 失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '解绑 QQ 失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
