<?php
/**
 * 删除邮件配置 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 删除指定邮件配置
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
    
    $configId = isset($input['id']) ? intval($input['id']) : 0;
    
    if ($configId <= 0) {
        jsonResponse(false, null, '缺少配置ID参数', 400);
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
    
    // 检查配置是否存在
    $stmt = $pdo->prepare("SELECT id, config_name FROM site_configs.email_config WHERE id = :id");
    $stmt->execute([':id' => $configId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        jsonResponse(false, null, '配置不存在', 404);
    }
    
    // 删除配置
    $stmt = $pdo->prepare("DELETE FROM site_configs.email_config WHERE id = :id");
    $stmt->execute([':id' => $configId]);
    
    // 记录日志
    $logger->info('admin', '管理员删除邮件配置', [
        'admin' => $admin['username'],
        'config_id' => $configId,
        'config_name' => $config['config_name']
    ]);
    
    // 返回成功
    jsonResponse(true, null, '删除成功');
    
} catch (PDOException $e) {
    error_log('删除邮件配置失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除邮件配置失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('删除邮件配置失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '删除邮件配置失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
