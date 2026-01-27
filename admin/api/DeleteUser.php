<?php
/**
 * 删除用户 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 删除指定用户
 * 3. 权限控制：
 *    - 不能删除ID为1的用户
 *    - 普通管理员不能删除其他管理员
 *    - 只有ID为1的管理员可以删除其他管理员
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
    
    $adminUuid = $_SESSION['user_uuid'];
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, '无效的请求数据', 400);
    }
    
    $userUuid = isset($input['uuid']) ? trim($input['uuid']) : '';
    
    if (empty($userUuid)) {
        jsonResponse(false, null, '缺少用户UUID参数', 400);
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
    
    $isSuperAdmin = ($admin['id'] == 1);
    
    // 检查目标用户是否存在
    $stmt = $pdo->prepare("
        SELECT id, username, user_type
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute([':uuid' => $userUuid]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 不能删除ID为1的用户
    if ($targetUser['id'] == 1) {
        jsonResponse(false, null, '不能删除超级管理员', 403);
    }
    
    // 不能删除自己
    if ($userUuid === $adminUuid) {
        jsonResponse(false, null, '不能删除自己', 400);
    }
    
    // 普通管理员不能删除其他管理员
    if (!$isSuperAdmin && in_array($targetUser['user_type'], ['admin', 'siteadmin'])) {
        jsonResponse(false, null, '无权删除管理员账户', 403);
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 删除用户的授权记录
        $stmt = $pdo->prepare("DELETE FROM users.openid WHERE user_uuid = :uuid");
        $stmt->execute([':uuid' => $userUuid]);
        
        // 删除用户
        $stmt = $pdo->prepare("DELETE FROM users.user WHERE uuid = :uuid");
        $stmt->execute([':uuid' => $userUuid]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录日志
        $logger->info('admin', '管理员删除用户', [
            'admin' => $admin['username'],
            'admin_id' => $admin['id'],
            'target_user' => $targetUser['username'],
            'target_uuid' => $userUuid,
            'target_type' => $targetUser['user_type']
        ]);
        
        // 返回成功
        jsonResponse(true, null, '删除成功');
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('删除用户失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除用户失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('删除用户失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除用户失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
