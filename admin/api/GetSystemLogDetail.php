<?php
/**
 * 获取系统日志详情 API
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
        jsonResponse(false, null, '缺少日志ID参数', 400);
    }
    
    // 查询日志详情
    $sql = "
        SELECT 
            id,
            log_level,
            log_type,
            message,
            context,
            stack_trace,
            request_method,
            request_uri,
            request_params,
            client_ip,
            user_agent,
            user_id,
            session_id,
            created_at,
            created_by
        FROM logs.system_logs
        WHERE id = :id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $logId, PDO::PARAM_INT);
    $stmt->execute();
    
    $log = $stmt->fetch();
    
    if (!$log) {
        jsonResponse(false, null, '日志不存在', 404);
    }
    
    // 格式化时间
    $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
    
    // 解析 JSON 字段
    if ($log['context']) {
        $log['context'] = json_decode($log['context'], true);
    }
    if ($log['request_params']) {
        $log['request_params'] = json_decode($log['request_params'], true);
    }
    
    jsonResponse(true, $log, '获取成功');
    
} catch (PDOException $e) {
    error_log("获取系统日志详情失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志详情失败', 500);
} catch (Exception $e) {
    error_log("获取系统日志详情错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
