<?php
/**
 * 获取邮件日志列表 API
 * 支持日期筛选、邮箱筛选、用途筛选、状态筛选、渠道筛选、分页等功能
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
    
    // 获取查询参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? min(100, max(10, intval($_GET['page_size']))) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 日期筛选（默认今天）
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // 邮箱筛选
    $email = $_GET['email'] ?? '';
    
    // 用途筛选
    $purpose = $_GET['purpose'] ?? '';
    
    // 状态筛选
    $status = $_GET['status'] ?? '';
    
    // 渠道筛选
    $channel = $_GET['channel'] ?? '';
    
    // 关键词搜索（搜索验证码ID）
    $keyword = $_GET['keyword'] ?? '';
    
    // 构建查询条件
    $conditions = [];
    $params = [];
    
    // 日期范围条件
    if (!empty($startDate)) {
        $conditions[] = "created_at >= :start_date::date";
        $params['start_date'] = $startDate;
    }
    
    if (!empty($endDate)) {
        $conditions[] = "created_at < (:end_date::date + INTERVAL '1 day')";
        $params['end_date'] = $endDate;
    }
    
    // 邮箱条件
    if (!empty($email)) {
        $conditions[] = "email = :email";
        $params['email'] = $email;
    }
    
    // 用途条件
    if (!empty($purpose)) {
        $conditions[] = "purpose = :purpose";
        $params['purpose'] = $purpose;
    }
    
    // 状态条件
    if ($status !== '') {
        $conditions[] = "status = :status";
        $params['status'] = intval($status);
    }
    
    // 渠道条件
    if (!empty($channel)) {
        $conditions[] = "channel = :channel";
        $params['channel'] = $channel;
    }
    
    // 关键词搜索
    if (!empty($keyword)) {
        $conditions[] = "code_id ILIKE :keyword";
        $params['keyword'] = '%' . $keyword . '%';
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM email.code
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue(":$key", $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    
    // 查询邮件日志列表
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
        $whereClause
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    // 格式化时间和数据
    foreach ($logs as &$log) {
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
        
        // 脱敏处理验证码（仅显示长度）
        $log['code_length'] = strlen($log['code']);
        unset($log['code']); // 不返回真实验证码
    }
    
    // 获取统计信息
    $statsSql = "
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as used_count,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as valid_count,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as expired_count,
            SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as first_verify_count,
            SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as second_verify_count,
            COUNT(DISTINCT email) as unique_email_count,
            COUNT(DISTINCT channel) as unique_channel_count
        FROM email.code
        $whereClause
    ";
    
    $statsStmt = $pdo->prepare($statsSql);
    foreach ($params as $key => $value) {
        $statsStmt->bindValue(":$key", $value);
    }
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    // 获取用途分布
    $purposeDistSql = "
        SELECT 
            purpose,
            COUNT(*) as count
        FROM email.code
        $whereClause
        GROUP BY purpose
        ORDER BY count DESC
    ";
    
    $purposeDistStmt = $pdo->prepare($purposeDistSql);
    foreach ($params as $key => $value) {
        $purposeDistStmt->bindValue(":$key", $value);
    }
    $purposeDistStmt->execute();
    $purposeDistribution = $purposeDistStmt->fetchAll();
    
    // 获取渠道分布
    $channelDistSql = "
        SELECT 
            channel,
            COUNT(*) as count
        FROM email.code
        $whereClause
        GROUP BY channel
        ORDER BY count DESC
    ";
    
    $channelDistStmt = $pdo->prepare($channelDistSql);
    foreach ($params as $key => $value) {
        $channelDistStmt->bindValue(":$key", $value);
    }
    $channelDistStmt->execute();
    $channelDistribution = $channelDistStmt->fetchAll();
    
    jsonResponse(true, [
        'logs' => $logs,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => intval($total),
            'total_pages' => ceil($total / $pageSize)
        ],
        'stats' => [
            'total_count' => intval($stats['total_count']),
            'used_count' => intval($stats['used_count']),
            'valid_count' => intval($stats['valid_count']),
            'expired_count' => intval($stats['expired_count']),
            'first_verify_count' => intval($stats['first_verify_count']),
            'second_verify_count' => intval($stats['second_verify_count']),
            'unique_email_count' => intval($stats['unique_email_count']),
            'unique_channel_count' => intval($stats['unique_channel_count'])
        ],
        'purpose_distribution' => $purposeDistribution,
        'channel_distribution' => $channelDistribution
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log("获取邮件日志失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志失败', 500);
} catch (Exception $e) {
    error_log("获取邮件日志错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
