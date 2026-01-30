<?php
/**
 * 获取人机验证日志详情 API
 * 查看单条日志的完整信息
 */

session_start();
require_once __DIR__ . '/../../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');

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
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
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
    AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取日志ID
    $logId = $_GET['id'] ?? '';
    
    if (empty($logId)) {
        jsonResponse(false, null, '日志ID不能为空', 400);
    }
    
    // 查询日志详情
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            c.name as config_name,
            c.captcha_id,
            c.captcha_key
        FROM site_configs.captcha_verify_log l
        LEFT JOIN site_configs.captcha_config c ON l.config_id = c.id
        WHERE l.id = :id
    ");
    
    $stmt->execute(['id' => $logId]);
    $log = $stmt->fetch();
    
    if (!$log) {
        jsonResponse(false, null, '日志不存在', 404);
    }
    
    // 格式化时间
    $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
    if ($log['expires_at']) {
        $log['expires_at'] = date('Y-m-d H:i:s', strtotime($log['expires_at']));
    }
    
    // 解析 verify_result JSON
    if ($log['verify_result']) {
        $log['verify_result'] = json_decode($log['verify_result'], true);
    }
    
    jsonResponse(true, $log, '获取成功');
    
} catch (PDOException $e) {
    error_log("获取人机验证日志详情失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志详情失败', 500);
} catch (Exception $e) {
    error_log("获取人机验证日志详情错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
