<?php
/**
 * 获取用户信息 API
 * 用于用户中心页面显示用户详细信息
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
    
    // 从 session 获取用户 UUID
    $uuid = $_SESSION['user_uuid'] ?? '';
    
    // 如果 session 中没有，尝试从参数获取（兼容旧版本）
    if (empty($uuid)) {
        $uuid = $_GET['uuid'] ?? '';
    }
    
    // 验证必填参数
    if (empty($uuid)) {
        jsonResponse(false, null, '未登录或缺少用户信息', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 查询用户信息
    try {
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
            LIMIT 1
        ");
        $stmt->execute(['uuid' => $uuid]);
        
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, null, '用户不存在', 404);
        }
        
        // 查询已授权网站数量
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT app_id) as count
            FROM users.openid
            WHERE user_uuid = :uuid
            AND status = 1
        ");
        $stmt->execute(['uuid' => $uuid]);
        $authorizedCount = $stmt->fetch()['count'] ?? 0;
        
        // 查询待审核的头像
        $stmt = $pdo->prepare("
            SELECT new_avatar, submitted_at
            FROM checks.avatar_check
            WHERE user_uuid = :uuid
            AND status = 0
            ORDER BY submitted_at DESC
            LIMIT 1
        ");
        $stmt->execute(['uuid' => $uuid]);
        $pendingAvatar = $stmt->fetch();
        
        // 查询待审核的昵称
        $stmt = $pdo->prepare("
            SELECT new_nickname, apply_time
            FROM checks.nickname_check
            WHERE user_uuid = :uuid
            AND status = 0
            ORDER BY apply_time DESC
            LIMIT 1
        ");
        $stmt->execute(['uuid' => $uuid]);
        $pendingNickname = $stmt->fetch();
        
        // 构建返回数据
        $userData = [
            'uuid' => $user['uuid'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'phone' => $user['phone'],
            'email' => $user['email'],
            'avatar' => $user['avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png', // 如果为空则使用默认头像
            'user_type' => $user['user_type'],
            'gender' => $user['gender'],
            'birth_date' => $user['birth_date'],
            'status' => $user['status'],
            'register_ip' => $user['register_ip'],
            'last_login_at' => $user['last_login_at'],
            'last_login_ip' => $user['last_login_ip'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
            'authorized_count' => $authorizedCount,
            // 审核状态信息
            'pending_avatar' => $pendingAvatar ? [
                'avatar' => $pendingAvatar['new_avatar'],
                'submitted_at' => $pendingAvatar['submitted_at']
            ] : null,
            'pending_nickname' => $pendingNickname ? [
                'nickname' => $pendingNickname['new_nickname'],
                'submitted_at' => $pendingNickname['apply_time']
            ] : null
        ];
        
        $logger->info('user', '获取用户信息成功', [
            'uuid' => $uuid
        ]);
        
        // 返回用户信息
        jsonResponse(true, $userData, '获取成功');
        
    } catch (PDOException $e) {
        $logger->error('user', '查询用户信息失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '服务器错误', 500);
    }
    
} catch (Exception $e) {
    error_log("获取用户信息错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
