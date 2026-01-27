<?php
/**
 * 修改头像 API
 * 用户修改自己的头像，如果开启审核则提交审核
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../checks/AvatarCheckService.php';
require_once __DIR__ . '/../../storage/StorageService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

/**
 * 验证头像URL格式
 */
function validateAvatarUrl($url) {
    // 检查是否为有效的URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // 检查是否为HTTPS
    if (strpos($url, 'https://') !== 0) {
        return false;
    }
    
    // 检查文件扩展名
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions)) {
        return false;
    }
    
    return true;
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 从 session 获取用户 UUID
    $uuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($uuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $avatarUrl = trim($input['avatar'] ?? '');
    
    // 验证头像URL
    if (empty($avatarUrl)) {
        jsonResponse(false, null, '头像URL不能为空');
    }
    
    if (!validateAvatarUrl($avatarUrl)) {
        jsonResponse(false, null, '头像URL格式不正确，必须是HTTPS链接且为图片格式');
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
    
    // 查询用户当前头像
    $stmt = $pdo->prepare("SELECT avatar FROM users.user WHERE uuid = :uuid");
    $stmt->execute(['uuid' => $uuid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 检查头像是否与当前相同
    if ($user['avatar'] === $avatarUrl) {
        jsonResponse(false, null, '新头像与当前头像相同');
    }
    
    // 创建头像审核服务实例
    $avatarCheckService = new AvatarCheckService($pdo);
    
    // 检查头像审核
    $checkResult = $avatarCheckService->checkAvatar($avatarUrl, $uuid, $user['avatar']);
    
    if (!$checkResult['success']) {
        $logger->warning('user', '头像审核失败', [
            'uuid' => $uuid,
            'avatar' => $avatarUrl,
            'reason' => $checkResult['message']
        ]);
        jsonResponse(false, null, $checkResult['message']);
    }
    
    // 如果需要人工审核
    if ($checkResult['need_manual_review']) {
        $logger->info('user', '头像提交人工审核', [
            'uuid' => $uuid,
            'old_avatar' => $user['avatar'],
            'new_avatar' => $avatarUrl
        ]);
        jsonResponse(true, [
            'need_review' => true
        ], '头像已提交审核，请等待管理员审核');
    }
    
    // 直接更新头像
    try {
        $stmt = $pdo->prepare("
            UPDATE users.user 
            SET avatar = :avatar, updated_at = CURRENT_TIMESTAMP 
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'avatar' => $avatarUrl,
            'uuid' => $uuid
        ]);
        
        $logger->info('user', '修改头像成功', [
            'uuid' => $uuid,
            'old_avatar' => $user['avatar'],
            'new_avatar' => $avatarUrl
        ]);
        
        jsonResponse(true, [
            'need_review' => false,
            'avatar' => $avatarUrl
        ], '头像修改成功');
        
    } catch (PDOException $e) {
        $logger->error('user', '修改头像失败', [
            'uuid' => $uuid,
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '修改头像失败', 500);
    }
    
} catch (Exception $e) {
    error_log("修改头像错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
