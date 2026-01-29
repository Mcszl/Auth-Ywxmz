<?php
/**
 * 获取统计数据 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 返回站点统计数据
 *    - 总用户数
 *    - 今日注册数
 *    - 今日登录数
 *    - 接入应用数
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
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查管理员权限
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $adminUuid = $_SESSION['user_uuid'];
    
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
    
    // 获取今天的日期范围（北京时间）
    $today = date('Y-m-d');
    $todayStart = $today . ' 00:00:00';
    $todayEnd = $today . ' 23:59:59';
    
    // 1. 总用户数
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users.user");
    $totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 2. 今日注册数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM users.user 
        WHERE created_at >= :start AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $todayStart,
        ':end' => $todayEnd
    ]);
    $todayRegister = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. 今日登录数（去重）
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT uuid) as total 
        FROM users.user 
        WHERE last_login_at >= :start AND last_login_at <= :end
    ");
    $stmt->execute([
        ':start' => $todayStart,
        ':end' => $todayEnd
    ]);
    $todayLogin = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 4. 接入应用数
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM site_configs.site_config");
    $totalApps = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 5. 额外统计数据
    // 活跃用户数（最近7天登录）
    $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM users.user 
        WHERE last_login_at >= :seven_days_ago
    ");
    $stmt->execute([':seven_days_ago' => $sevenDaysAgo]);
    $activeUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 6. 用户类型分布
    $stmt = $pdo->query("
        SELECT user_type, COUNT(*) as count 
        FROM users.user 
        GROUP BY user_type
    ");
    $userTypeDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. 用户状态分布
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM users.user 
        GROUP BY status
    ");
    $userStatusDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. 待审核头像数量
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM checks.avatar_check 
        WHERE status = 0
    ");
    $pendingAvatars = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 9. 待审核昵称数量
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM checks.nickname_check 
        WHERE status = 0
    ");
    $pendingNicknames = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 记录日志
    $logger->info('admin', '管理员获取统计数据', [
        'admin' => $admin['username']
    ]);
    
    // 返回结果
    jsonResponse(true, [
        'total_users' => intval($totalUsers),
        'today_register' => intval($todayRegister),
        'today_login' => intval($todayLogin),
        'total_apps' => intval($totalApps),
        'active_users' => intval($activeUsers),
        'user_type_distribution' => $userTypeDistribution,
        'user_status_distribution' => $userStatusDistribution,
        'pending_avatars' => intval($pendingAvatars),
        'pending_nicknames' => intval($pendingNicknames)
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('获取统计数据失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取统计数据失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取统计数据失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取统计数据失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
