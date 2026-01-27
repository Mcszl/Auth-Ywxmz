<?php
/**
 * 获取系统日志列表 API
 * 支持日期筛选、级别筛选、类型筛选、分页等功能
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
    
    // 日志级别筛选
    $logLevel = $_GET['log_level'] ?? '';
    
    // 日志类型筛选
    $logType = $_GET['log_type'] ?? '';
    
    // 模块筛选
    $module = $_GET['module'] ?? '';
    
    // 用户ID筛选
    $userId = $_GET['user_id'] ?? '';
    
    // IP筛选
    $clientIp = $_GET['client_ip'] ?? '';
    
    // 关键词搜索
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
    
    // 日志级别条件
    if (!empty($logLevel)) {
        $conditions[] = "LOWER(log_level) = LOWER(:log_level)";
        $params['log_level'] = $logLevel;
    }
    
    // 日志类型条件
    if (!empty($logType)) {
        $conditions[] = "log_type = :log_type";
        $params['log_type'] = $logType;
    }
    
    // 模块条件（从 context 中提取）
    if (!empty($module)) {
        $conditions[] = "context->>'module' = :module";
        $params['module'] = $module;
    }
    
    // 用户ID条件
    if (!empty($userId)) {
        $conditions[] = "user_id = :user_id";
        $params['user_id'] = intval($userId);
    }
    
    // IP条件
    if (!empty($clientIp)) {
        $conditions[] = "client_ip = :client_ip";
        $params['client_ip'] = $clientIp;
    }
    
    // 关键词搜索
    if (!empty($keyword)) {
        $conditions[] = "(message ILIKE :keyword OR context->>'username' ILIKE :keyword)";
        $params['keyword'] = '%' . $keyword . '%';
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM logs.system_logs
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue(":$key", $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    
    // 查询日志列表
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
        // 解析 JSON 字段
        if ($log['context']) {
            $log['context'] = json_decode($log['context'], true);
        }
        if ($log['request_params']) {
            $log['request_params'] = json_decode($log['request_params'], true);
        }
    }
    
    // 获取统计信息
    $statsSql = "
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN LOWER(log_level) = 'error' THEN 1 ELSE 0 END) as error_count,
            SUM(CASE WHEN LOWER(log_level) = 'critical' THEN 1 ELSE 0 END) as critical_count,
            SUM(CASE WHEN LOWER(log_level) = 'warning' THEN 1 ELSE 0 END) as warning_count,
            SUM(CASE WHEN LOWER(log_level) = 'info' THEN 1 ELSE 0 END) as info_count,
            COUNT(DISTINCT user_id) FILTER (WHERE user_id IS NOT NULL) as unique_user_count,
            COUNT(DISTINCT client_ip) FILTER (WHERE client_ip IS NOT NULL) as unique_ip_count
        FROM logs.system_logs
        $whereClause
    ";
    
    $statsStmt = $pdo->prepare($statsSql);
    foreach ($params as $key => $value) {
        $statsStmt->bindValue(":$key", $value);
    }
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    // 获取日志级别分布
    $levelDistSql = "
        SELECT 
            log_level,
            COUNT(*) as count
        FROM logs.system_logs
        $whereClause
        GROUP BY log_level
        ORDER BY count DESC
    ";
    
    $levelDistStmt = $pdo->prepare($levelDistSql);
    foreach ($params as $key => $value) {
        $levelDistStmt->bindValue(":$key", $value);
    }
    $levelDistStmt->execute();
    $levelDistribution = $levelDistStmt->fetchAll();
    
    // 获取日志类型分布
    $typeDistSql = "
        SELECT 
            log_type,
            COUNT(*) as count
        FROM logs.system_logs
        $whereClause
        GROUP BY log_type
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $typeDistStmt = $pdo->prepare($typeDistSql);
    foreach ($params as $key => $value) {
        $typeDistStmt->bindValue(":$key", $value);
    }
    $typeDistStmt->execute();
    $typeDistribution = $typeDistStmt->fetchAll();
    
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
            'error_count' => intval($stats['error_count']),
            'critical_count' => intval($stats['critical_count']),
            'warning_count' => intval($stats['warning_count']),
            'info_count' => intval($stats['info_count']),
            'unique_user_count' => intval($stats['unique_user_count']),
            'unique_ip_count' => intval($stats['unique_ip_count'])
        ],
        'level_distribution' => $levelDistribution,
        'type_distribution' => $typeDistribution
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log("获取系统日志失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志失败', 500);
} catch (Exception $e) {
    error_log("获取系统日志错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
