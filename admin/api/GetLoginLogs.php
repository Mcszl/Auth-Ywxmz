<?php
/**
 * 获取登录日志 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 支持分页查询
 * 3. 支持按用户名、IP、登录方式、Token状态、日期筛选
 * 4. 返回登录日志列表
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
    
    // 获取分页参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 获取筛选参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $loginMethod = isset($_GET['login_method']) ? trim($_GET['login_method']) : '';
    $tokenStatus = isset($_GET['token_status']) ? trim($_GET['token_status']) : '';
    $loginDate = isset($_GET['login_date']) ? trim($_GET['login_date']) : '';
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    // 搜索条件（用户名或IP）
    if (!empty($search)) {
        $where[] = "(t.username LIKE :search OR t.login_ip LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // 登录方式筛选
    if (!empty($loginMethod)) {
        $where[] = "t.login_method = :login_method";
        $params[':login_method'] = $loginMethod;
    }
    
    // Token状态筛选
    if ($tokenStatus !== '') {
        $where[] = "t.status = :token_status";
        $params[':token_status'] = intval($tokenStatus);
    }
    
    // 登录日期筛选
    if (!empty($loginDate)) {
        $where[] = "DATE(t.login_time) = :login_date";
        $params[':login_date'] = $loginDate;
    }
    
    // 组合WHERE子句
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total 
        FROM tokens.login_token t
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询登录日志列表
    $sql = "
        SELECT 
            t.id,
            t.token,
            t.user_id,
            t.user_uuid,
            t.username,
            t.app_id,
            t.status,
            t.login_method,
            t.login_ip,
            t.login_time,
            t.validity_period,
            t.expires_at,
            t.callback_url,
            t.permissions,
            t.used_at,
            t.used_ip,
            t.created_at,
            u.nickname,
            u.avatar
        FROM tokens.login_token t
        LEFT JOIN users.user u ON t.user_uuid = u.uuid
        $whereClause
        ORDER BY t.login_time DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    foreach ($logs as &$log) {
        // 格式化时间
        $log['login_time_formatted'] = date('Y-m-d H:i:s', strtotime($log['login_time']));
        $log['expires_at_formatted'] = date('Y-m-d H:i:s', strtotime($log['expires_at']));
        $log['used_at_formatted'] = $log['used_at'] ? date('Y-m-d H:i:s', strtotime($log['used_at'])) : null;
        
        // 格式化状态
        $statusMap = [
            0 => '已使用',
            1 => '正常',
            2 => '已过期',
            3 => '已关闭'
        ];
        $log['status_text'] = $statusMap[$log['status']] ?? '未知';
        
        // 格式化登录方式
        $methodMap = [
            'password' => '密码登录',
            'sms' => '短信验证码',
            'email' => '邮箱验证码',
            'wechat' => '微信登录',
            'qq' => 'QQ登录',
            'google' => 'Google登录',
            'github' => 'GitHub登录'
        ];
        $log['login_method_text'] = $methodMap[$log['login_method']] ?? $log['login_method'];
        
        // 隐藏敏感信息
        $log['token'] = substr($log['token'], 0, 10) . '...';
    }
    
    // 计算分页信息
    $totalPages = ceil($total / $pageSize);
    
    // 记录日志
    $logger->info('admin', '管理员查看登录日志', [
        'admin' => $admin['username'],
        'page' => $page,
        'search' => $search
    ]);
    
    // 返回结果
    jsonResponse(true, [
        'logs' => $logs,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $pageSize,
            'total' => intval($total),
            'total_pages' => $totalPages
        ]
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('获取登录日志失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取登录日志失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取登录日志失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取登录日志失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
