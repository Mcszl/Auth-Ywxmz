<?php
/**
 * 获取用户列表 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 支持分页查询
 * 3. 支持搜索（用户名、昵称、手机号、邮箱）
 * 4. 支持筛选（用户类型、状态）
 * 5. 支持排序
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
    
    // 检查是否登录
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
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 获取查询参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $userType = isset($_GET['user_type']) ? trim($_GET['user_type']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $orderBy = isset($_GET['order_by']) ? trim($_GET['order_by']) : 'created_at';
    $orderDir = isset($_GET['order_dir']) && strtoupper($_GET['order_dir']) === 'ASC' ? 'ASC' : 'DESC';
    
    // 计算偏移量
    $offset = ($page - 1) * $pageSize;
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    // 搜索条件
    if (!empty($search)) {
        $where[] = "(username ILIKE :search OR nickname ILIKE :search OR phone ILIKE :search OR email ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // 用户类型筛选
    if (!empty($userType) && in_array($userType, ['user', 'admin', 'siteadmin'])) {
        $where[] = "user_type = :user_type";
        $params[':user_type'] = $userType;
    }
    
    // 状态筛选
    if ($status !== '') {
        $where[] = "status = :status";
        $params[':status'] = intval($status);
    }
    
    // 组合 WHERE 子句
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 验证排序字段
    $allowedOrderBy = ['created_at', 'updated_at', 'last_login_at', 'username', 'status'];
    if (!in_array($orderBy, $allowedOrderBy)) {
        $orderBy = 'created_at';
    }
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM users.user $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询用户列表
    $sql = "
        SELECT 
            uuid,
            username,
            nickname,
            phone,
            email,
            avatar,
            user_type,
            gender,
            status,
            register_ip,
            last_login_at,
            last_login_ip,
            created_at,
            updated_at
        FROM users.user
        $whereClause
        ORDER BY $orderBy $orderDir
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
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理用户数据（隐藏敏感信息）
    $userList = array_map(function($user) {
        return [
            'uuid' => $user['uuid'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'phone' => $user['phone'] ? substr($user['phone'], 0, 3) . '****' . substr($user['phone'], -4) : null,
            'email' => $user['email'] ? preg_replace('/(?<=.{2}).(?=.*@)/', '*', $user['email']) : null,
            'avatar' => $user['avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png', // 如果为空则使用默认头像
            'user_type' => $user['user_type'],
            'gender' => $user['gender'],
            'status' => $user['status'],
            'register_ip' => $user['register_ip'],
            'last_login_at' => $user['last_login_at'],
            'last_login_ip' => $user['last_login_ip'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ];
    }, $users);
    
    // 计算分页信息
    $totalPages = ceil($total / $pageSize);
    
    // 记录日志
    $logger->info('admin', '管理员获取用户列表', [
        'admin' => $admin['username'],
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'search' => $search,
        'user_type' => $userType,
        'status' => $status
    ]);
    
    // 返回结果
    jsonResponse(true, [
        'users' => $userList,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages
        ]
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('获取用户列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取用户列表失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取用户列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取用户列表失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
