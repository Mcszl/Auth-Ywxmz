<?php
/**
 * 获取邮件日志详情 API
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
            code_id,
            email,
            code,
            status,
            purpose,
            validity_period,
            expires_at,
            channel,
            template_id,
            send_result,
            verify_count,
            last_verify_at,
            extra_info,
            client_ip,
            created_at,
            updated_at
        FROM email.code
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
    $log['updated_at'] = date('Y-m-d H:i:s', strtotime($log['updated_at']));
    $log['expires_at'] = date('Y-m-d H:i:s', strtotime($log['expires_at']));
    if ($log['last_verify_at']) {
        $log['last_verify_at'] = date('Y-m-d H:i:s', strtotime($log['last_verify_at']));
    }
    
    // 解析 JSON 字段
    if ($log['extra_info']) {
        $log['extra_info'] = json_decode($log['extra_info'], true);
    }
    
    // 脱敏处理邮箱（显示前3位和@后的域名）
    if ($log['email']) {
        $parts = explode('@', $log['email']);
        if (count($parts) == 2) {
            $log['email_masked'] = substr($parts[0], 0, 3) . '***@' . $parts[1];
        } else {
            $log['email_masked'] = substr($log['email'], 0, 3) . '***';
        }
    }
    
    jsonResponse(true, $log, '获取成功');
    
} catch (PDOException $e) {
    error_log("获取邮件日志详情失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志详情失败', 500);
} catch (Exception $e) {
    error_log("获取邮件日志详情错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
