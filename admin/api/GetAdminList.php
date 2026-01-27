<?php
/**
 * 获取管理员列表 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 获取所有管理员用户列表
 * 3. 支持分页、搜索、排序
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
    
    // 获取分页参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 获取搜索参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // 构建查询条件
    $whereConditions = ["user_type IN ('admin', 'siteadmin')"];
    $params = [];
    
    // 搜索条件
    if (!empty($search)) {
        $whereConditions[] = "(username ILIKE :search OR nickname ILIKE :search OR phone ILIKE :search OR email ILIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM users.user WHERE {$whereClause}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询管理员列表
    $sql = "
        SELECT 
            uuid,
            username,
            nickname,
            phone,
            email,
            avatar,
            user_type,
            status,
            last_login_at,
            created_at
        FROM users.user
        WHERE {$whereClause}
        ORDER BY 
            CASE 
                WHEN user_type = 'siteadmin' THEN 1
                WHEN user_type = 'admin' THEN 2
                ELSE 3
            END,
            created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理管理员数据，添加默认头像
    $admins = array_map(function($admin) {
        $admin['avatar'] = $admin['avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png';
        return $admin;
    }, $admins);
    
    // 构建返回数据
    $result = [
        'admins' => $admins,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => intval($total),
            'total_pages' => ceil($total / $pageSize),
            'has_prev' => $page > 1,
            'has_next' => $page < ceil($total / $pageSize)
        ]
    ];
    
    // 记录日志
    $logger->info('admin', '管理员查看管理员列表', [
        'admin' => $admin['username'],
        'page' => $page,
        'search' => $search
    ]);
    
    // 返回结果
    jsonResponse(true, $result, '获取成功');
    
} catch (PDOException $e) {
    error_log('获取管理员列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取管理员列表失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取管理员列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取管理员列表失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
