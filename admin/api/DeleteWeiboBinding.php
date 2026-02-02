<?php
/**
 * 删除微博绑定记录
 * 
 * 功能说明：
 * - 永久删除微博绑定记录
 * - 此操作不可恢复
 * 
 * 请求方式：POST
 * 
 * 请求参数：
 * - id: 微博绑定记录ID（必填）
 * 
 * 返回数据：
 * {
 *   "success": true,
 *   "data": null,
 *   "message": "删除成功"
 * }
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 开启会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 返回 JSON 响应
 */
function jsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
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
    
    // 验证参数
    if (!isset($input['id']) || empty($input['id'])) {
        jsonResponse(false, null, '缺少必要参数：id', 400);
    }
    
    $bindingId = intval($input['id']);
    
    // 查询绑定记录
    $stmt = $pdo->prepare("
        SELECT id, uid, user_uuid, weibo_nickname 
        FROM auth.weibo_user_info 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $bindingId]);
    $binding = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$binding) {
        jsonResponse(false, null, '绑定记录不存在', 404);
    }
    
    // 开启事务
    $pdo->beginTransaction();
    
    try {
        // 删除绑定记录
        $deleteStmt = $pdo->prepare("
            DELETE FROM auth.weibo_user_info 
            WHERE id = :id
        ");
        $deleteStmt->execute(['id' => $bindingId]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录日志
        error_log(sprintf(
            '管理员删除微博绑定记录 - 管理员UUID: %d, 微博UID: %s, 微博昵称: %s, 用户UUID: %s',
            $_SESSION['user_uuid'],
            $binding['uid'],
            $binding['weibo_nickname'] ?? 'NULL',
            $binding['user_uuid'] ?? 'NULL'
        ));
        
        jsonResponse(true, null, '删除成功');
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
