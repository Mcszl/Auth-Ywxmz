<?php
/**
 * 获取微信绑定列表 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 获取所有微信绑定信息
 * 3. 支持分页和搜索
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
    
    // 验证表是否存在
    $checkTableSql = "
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'auth' 
            AND table_name = 'wechat_user_info'
        ) as table_exists
    ";
    $stmt = $pdo->query($checkTableSql);
    $tableCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tableCheck['table_exists']) {
        $logger->error('admin', '微信用户信息表不存在', [
            'admin' => $admin['username']
        ]);
        jsonResponse(false, null, '数据库表 auth.wechat_user_info 不存在，请先执行 SQL 脚本创建表', 500);
    }
    
    // 检查是否有数据
    $countCheckSql = "SELECT COUNT(*) as total FROM auth.wechat_user_info";
    $stmt = $pdo->query($countCheckSql);
    $dataCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $logger->info('admin', '微信绑定表状态检查', [
        'admin' => $admin['username'],
        'table_exists' => true,
        'total_records' => $dataCheck['total']
    ]);
    
    // 如果没有数据，直接返回空列表
    if ($dataCheck['total'] == 0) {
        jsonResponse(true, [
            'list' => [],
            'pagination' => [
                'total' => 0,
                'page' => 1,
                'page_size' => 20,
                'total_pages' => 0
            ]
        ], '暂无微信绑定数据');
    }
    
    // 获取分页参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 获取搜索参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $bindStatus = isset($_GET['bind_status']) ? intval($_GET['bind_status']) : -1; // -1 表示全部
    
    // 构建查询条件
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(w.openid LIKE :search OR w.wechat_nickname LIKE :search OR u.username LIKE :search OR u.nickname LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if ($bindStatus >= 0) {
        $whereConditions[] = "w.bind_status = :bind_status";
        $params[':bind_status'] = $bindStatus;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM auth.wechat_user_info w
        LEFT JOIN users.user u ON w.user_uuid = u.uuid
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询列表数据
    $listSql = "
        SELECT 
            w.id,
            w.openid,
            w.user_uuid,
            w.wechat_nickname,
            w.wechat_avatar,
            w.wechat_gender,
            w.wechat_country,
            w.wechat_province,
            w.wechat_city,
            w.unionid,
            w.bind_status,
            w.last_login_at,
            w.created_at,
            w.updated_at,
            u.username,
            u.nickname as user_nickname,
            u.avatar as user_avatar,
            u.user_type,
            u.status as user_status
        FROM auth.wechat_user_info w
        LEFT JOIN users.user u ON w.user_uuid = u.uuid
        $whereClause
        ORDER BY w.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($listSql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $bindings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $formattedBindings = [];
    foreach ($bindings as $binding) {
        // 性别文本
        $genderText = ['未知', '男', '女'][$binding['wechat_gender']] ?? '未知';
        
        // 地区信息
        $location = [];
        if ($binding['wechat_country']) $location[] = $binding['wechat_country'];
        if ($binding['wechat_province']) $location[] = $binding['wechat_province'];
        if ($binding['wechat_city']) $location[] = $binding['wechat_city'];
        $locationText = !empty($location) ? implode(' ', $location) : null;
        
        $formattedBindings[] = [
            'id' => $binding['id'],
            'openid' => $binding['openid'],
            'user_uuid' => $binding['user_uuid'],
            'wechat_nickname' => $binding['wechat_nickname'],
            'wechat_avatar' => $binding['wechat_avatar'],
            'wechat_gender' => $binding['wechat_gender'],
            'wechat_gender_text' => $genderText,
            'wechat_location' => $locationText,
            'unionid' => $binding['unionid'],
            'bind_status' => $binding['bind_status'],
            'bind_status_text' => $binding['bind_status'] == 1 ? '已绑定' : '已解绑',
            'last_login_at' => $binding['last_login_at'],
            'created_at' => $binding['created_at'],
            'updated_at' => $binding['updated_at'],
            'user' => $binding['user_uuid'] ? [
                'uuid' => $binding['user_uuid'],
                'username' => $binding['username'],
                'nickname' => $binding['user_nickname'],
                'avatar' => $binding['user_avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png',
                'user_type' => $binding['user_type'],
                'status' => $binding['user_status']
            ] : null
        ];
    }
    
    // 记录日志
    $logger->info('admin', '管理员查看微信绑定列表', [
        'admin' => $admin['username'],
        'page' => $page,
        'page_size' => $pageSize,
        'search' => $search
    ]);
    
    // 返回结果
    jsonResponse(true, [
        'list' => $formattedBindings,
        'pagination' => [
            'total' => intval($total),
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize)
        ]
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('获取微信绑定列表失败: ' . $e->getMessage());
    error_log('SQL Error Code: ' . $e->getCode());
    error_log('SQL State: ' . ($e->errorInfo[0] ?? 'unknown'));
    error_log('Driver Error Code: ' . ($e->errorInfo[1] ?? 'unknown'));
    error_log('Driver Error Message: ' . ($e->errorInfo[2] ?? 'unknown'));
    
    if (isset($logger)) {
        $logger->error('admin', '获取微信绑定列表失败：数据库错误', [
            'error' => $e->getMessage(),
            'sql_state' => $e->getCode(),
            'error_info' => $e->errorInfo
        ]);
    }
    
    // 开发环境返回详细错误，生产环境返回通用错误
    $isDev = (isset($_SERVER['SERVER_NAME']) && 
              (strpos($_SERVER['SERVER_NAME'], 'localhost') !== false || 
               strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false ||
               strpos($_SERVER['SERVER_NAME'], 'dev.') !== false));
    
    $errorMessage = $isDev 
        ? '数据库错误: ' . $e->getMessage() . ' (SQLSTATE: ' . ($e->errorInfo[0] ?? 'unknown') . ')'
        : '系统错误，请稍后重试';
    
    jsonResponse(false, [
        'debug' => $isDev ? [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'sqlstate' => $e->errorInfo[0] ?? null,
            'driver_code' => $e->errorInfo[1] ?? null,
            'driver_message' => $e->errorInfo[2] ?? null
        ] : null
    ], $errorMessage, 500);
} catch (Exception $e) {
    error_log('获取微信绑定列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取微信绑定列表失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
