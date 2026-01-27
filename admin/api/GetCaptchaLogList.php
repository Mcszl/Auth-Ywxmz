<?php
/**
 * 获取人机验证日志列表 API
 * 支持日期筛选、场景筛选、分页等功能
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
    
    // 场景筛选
    $scene = $_GET['scene'] ?? '';
    
    // 提供商筛选
    $provider = $_GET['provider'] ?? '';
    
    // 验证结果筛选
    $verifySuccess = isset($_GET['verify_success']) && $_GET['verify_success'] !== '' && $_GET['verify_success'] !== 'undefined' 
        ? $_GET['verify_success'] 
        : null;
    
    // IP 筛选
    $clientIp = $_GET['client_ip'] ?? '';
    
    // 手机号筛选
    $phone = $_GET['phone'] ?? '';
    
    // 邮箱筛选
    $email = $_GET['email'] ?? '';
    
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
    
    // 场景条件
    if (!empty($scene)) {
        $conditions[] = "scene = :scene";
        $params['scene'] = $scene;
    }
    
    // 提供商条件
    if (!empty($provider)) {
        $conditions[] = "provider = :provider";
        $params['provider'] = $provider;
    }
    
    // 验证结果条件
    if ($verifySuccess !== null && $verifySuccess !== '') {
        $conditions[] = "verify_success = :verify_success";
        // 将字符串 "1" 或 "0" 转换为布尔值
        $params['verify_success'] = ($verifySuccess === '1' || $verifySuccess === 'true' || $verifySuccess === true);
        
        // 调试日志
        error_log("verify_success 参数: " . $verifySuccess . " => " . ($params['verify_success'] ? 'TRUE' : 'FALSE'));
    }
    
    // IP 条件
    if (!empty($clientIp)) {
        $conditions[] = "client_ip = :client_ip";
        $params['client_ip'] = $clientIp;
    }
    
    // 手机号条件
    if (!empty($phone)) {
        $conditions[] = "phone = :phone";
        $params['phone'] = $phone;
    }
    
    // 邮箱条件
    if (!empty($email)) {
        $conditions[] = "email = :email";
        $params['email'] = $email;
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM site_configs.captcha_verify_log
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    // 绑定参数（包括布尔类型）
    foreach ($params as $key => $value) {
        if ($key === 'verify_success') {
            $countStmt->bindValue(":$key", $value, PDO::PARAM_BOOL);
        } else {
            $countStmt->bindValue(":$key", $value);
        }
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    
    // 查询日志列表
    $sql = "
        SELECT 
            id,
            config_id,
            scene,
            provider,
            lot_number,
            verify_success,
            error_message,
            client_ip,
            phone,
            email,
            user_id,
            created_at,
            expires_at
        FROM site_configs.captcha_verify_log
        $whereClause
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        // 如果是 verify_success 参数，使用 PDO::PARAM_BOOL
        if ($key === 'verify_success') {
            $stmt->bindValue(":$key", $value, PDO::PARAM_BOOL);
        } else {
            $stmt->bindValue(":$key", $value);
        }
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    // 格式化时间
    foreach ($logs as &$log) {
        $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
        if ($log['expires_at']) {
            $log['expires_at'] = date('Y-m-d H:i:s', strtotime($log['expires_at']));
        }
    }
    
    // 获取统计信息
    $statsSql = "
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN verify_success = TRUE THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN verify_success = FALSE THEN 1 ELSE 0 END) as fail_count,
            COUNT(DISTINCT client_ip) as unique_ip_count,
            COUNT(DISTINCT scene) as scene_count
        FROM site_configs.captcha_verify_log
        $whereClause
    ";
    
    $statsStmt = $pdo->prepare($statsSql);
    // 绑定参数
    foreach ($params as $key => $value) {
        if ($key === 'verify_success') {
            $statsStmt->bindValue(":$key", $value, PDO::PARAM_BOOL);
        } else {
            $statsStmt->bindValue(":$key", $value);
        }
    }
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    // 获取场景分布
    $sceneDistSql = "
        SELECT 
            scene,
            COUNT(*) as count,
            SUM(CASE WHEN verify_success = TRUE THEN 1 ELSE 0 END) as success_count
        FROM site_configs.captcha_verify_log
        $whereClause
        GROUP BY scene
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $sceneDistStmt = $pdo->prepare($sceneDistSql);
    // 绑定参数
    foreach ($params as $key => $value) {
        if ($key === 'verify_success') {
            $sceneDistStmt->bindValue(":$key", $value, PDO::PARAM_BOOL);
        } else {
            $sceneDistStmt->bindValue(":$key", $value);
        }
    }
    $sceneDistStmt->execute();
    $sceneDistribution = $sceneDistStmt->fetchAll();
    
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
            'success_count' => intval($stats['success_count']),
            'fail_count' => intval($stats['fail_count']),
            'success_rate' => $stats['total_count'] > 0 
                ? round($stats['success_count'] / $stats['total_count'] * 100, 2) 
                : 0,
            'unique_ip_count' => intval($stats['unique_ip_count']),
            'scene_count' => intval($stats['scene_count'])
        ],
        'scene_distribution' => $sceneDistribution
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log("获取人机验证日志失败: " . $e->getMessage());
    jsonResponse(false, null, '获取日志失败', 500);
} catch (Exception $e) {
    error_log("获取人机验证日志错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
