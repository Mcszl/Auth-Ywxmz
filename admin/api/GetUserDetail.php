<?php
/**
 * 获取用户详情 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 获取指定用户的详细信息
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
    
    // 获取要查询的用户UUID
    $userUuid = isset($_GET['uuid']) ? trim($_GET['uuid']) : '';
    
    if (empty($userUuid)) {
        jsonResponse(false, null, '缺少用户UUID参数', 400);
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
    
    $isSuperAdmin = ($admin['id'] == 1);
    
    // 查询用户详细信息
    $stmt = $pdo->prepare("
        SELECT 
            id,
            uuid,
            username,
            nickname,
            phone,
            email,
            avatar,
            user_type,
            gender,
            birth_date,
            status,
            register_ip,
            last_login_at,
            last_login_ip,
            created_at,
            updated_at
        FROM users.user
        WHERE uuid = :uuid
    ");
    
    $stmt->execute([':uuid' => $userUuid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 权限控制：普通管理员不能查看其他管理员的详情
    if (!$isSuperAdmin && in_array($user['user_type'], ['admin', 'siteadmin'])) {
        jsonResponse(false, null, '无权查看管理员信息', 403);
    }
    
    // 查询用户的授权应用数量
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT app_id) as count
        FROM users.openid
        WHERE user_uuid = :uuid
        AND status = 1
    ");
    $stmt->execute([':uuid' => $userUuid]);
    $authorizedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // 构建返回数据（管理员可以看到完整信息）
    // 将gender数字转换为字符串
    $genderMap = [0 => '', 1 => 'male', 2 => 'female', 3 => 'other'];
    $genderValue = isset($user['gender']) ? intval($user['gender']) : 0;
    $genderString = $genderMap[$genderValue] ?? '';
    
    // 判断是否可以编辑和删除
    $isUserId1 = ($user['id'] == 1);
    $isTargetAdmin = in_array($user['user_type'], ['admin', 'siteadmin']);
    
    $userData = [
        'id' => $user['id'],
        'uuid' => $user['uuid'],
        'username' => $user['username'],
        'nickname' => $user['nickname'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'avatar' => $user['avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png', // 如果为空则使用默认头像
        'user_type' => $user['user_type'],
        'gender' => $genderString,
        'birth_date' => $user['birth_date'],
        'status' => $user['status'],
        'register_ip' => $user['register_ip'],
        'last_login_at' => $user['last_login_at'],
        'last_login_ip' => $user['last_login_ip'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'authorized_count' => $authorizedCount,
        'permissions' => [
            'can_edit' => $isSuperAdmin || !$isTargetAdmin,
            'can_delete' => !$isUserId1 && ($isSuperAdmin || !$isTargetAdmin),
            'can_set_admin' => $isSuperAdmin,
            'is_super_admin' => $isSuperAdmin,
            'is_user_id_1' => $isUserId1
        ]
    ];
    
    // 记录日志
    $logger->info('admin', '管理员查看用户详情', [
        'admin' => $admin['username'],
        'target_user' => $user['username'],
        'target_uuid' => $userUuid
    ]);
    
    // 返回结果
    jsonResponse(true, $userData, '获取成功');
    
} catch (PDOException $e) {
    error_log('获取用户详情失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取用户详情失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取用户详情失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取用户详情失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
