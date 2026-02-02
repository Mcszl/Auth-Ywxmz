<?php
/**
 * 删除微信绑定 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 删除指定的微信绑定记录
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
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || empty($input['id'])) {
        jsonResponse(false, null, '缺少必要参数', 400);
    }
    
    $bindingId = intval($input['id']);
    
    // 查询绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            w.id,
            w.openid,
            w.user_uuid,
            w.wechat_nickname,
            u.username
        FROM auth.wechat_user_info w
        LEFT JOIN users.user u ON w.user_uuid = u.uuid
        WHERE w.id = :id
    ");
    $stmt->execute(['id' => $bindingId]);
    $binding = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$binding) {
        jsonResponse(false, null, '绑定记录不存在', 404);
    }
    
    // 删除绑定记录
    $stmt = $pdo->prepare("
        DELETE FROM auth.wechat_user_info
        WHERE id = :id
    ");
    $stmt->execute(['id' => $bindingId]);
    
    // 记录日志
    $logger->info('admin', '管理员删除微信绑定记录', [
        'admin' => $admin['username'],
        'binding_id' => $bindingId,
        'openid' => $binding['openid'],
        'wechat_nickname' => $binding['wechat_nickname'],
        'user_uuid' => $binding['user_uuid'],
        'username' => $binding['username']
    ]);
    
    jsonResponse(true, null, '删除成功');
    
} catch (PDOException $e) {
    error_log('删除微信绑定失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除微信绑定失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('删除微信绑定失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除微信绑定失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
