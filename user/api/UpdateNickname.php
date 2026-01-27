<?php
/**
 * 修改昵称 API
 * 用户修改自己的昵称，如果开启审核则提交审核
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../checks/NicknameCheckService.php';

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
    $nickname = trim($input['nickname'] ?? '');
    
    // 验证昵称
    if (empty($nickname)) {
        jsonResponse(false, null, '昵称不能为空');
    }
    
    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
        jsonResponse(false, null, '昵称长度必须在2-20个字符之间');
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
    
    // 查询用户当前昵称
    $stmt = $pdo->prepare("SELECT nickname FROM users.user WHERE uuid = :uuid");
    $stmt->execute(['uuid' => $uuid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 检查昵称是否与当前相同
    if ($user['nickname'] === $nickname) {
        jsonResponse(false, null, '新昵称与当前昵称相同');
    }
    
    // 检查昵称是否已被其他用户使用
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users.user WHERE nickname = :nickname AND uuid != :uuid");
    $stmt->execute(['nickname' => $nickname, 'uuid' => $uuid]);
    if ($stmt->fetch()['count'] > 0) {
        jsonResponse(false, null, '该昵称已被使用');
    }
    
    // 创建昵称审核服务实例
    $nicknameCheckService = new NicknameCheckService($pdo);
    
    // 检查昵称审核
    $checkResult = $nicknameCheckService->checkNickname($nickname, $uuid);
    
    if (!$checkResult['success']) {
        $logger->warning('user', '昵称审核失败', [
            'uuid' => $uuid,
            'nickname' => $nickname,
            'reason' => $checkResult['message']
        ]);
        jsonResponse(false, null, $checkResult['message']);
    }
    
    // 如果需要人工审核
    if ($checkResult['need_manual_review']) {
        $logger->info('user', '昵称提交人工审核', [
            'uuid' => $uuid,
            'nickname' => $nickname
        ]);
        jsonResponse(true, [
            'need_review' => true
        ], '昵称已提交审核，请等待管理员审核');
    }
    
    // 直接更新昵称
    try {
        $stmt = $pdo->prepare("
            UPDATE users.user 
            SET nickname = :nickname, updated_at = CURRENT_TIMESTAMP 
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'nickname' => $nickname,
            'uuid' => $uuid
        ]);
        
        $logger->info('user', '修改昵称成功', [
            'uuid' => $uuid,
            'old_nickname' => $user['nickname'],
            'new_nickname' => $nickname
        ]);
        
        jsonResponse(true, [
            'need_review' => false,
            'nickname' => $nickname
        ], '昵称修改成功');
        
    } catch (PDOException $e) {
        $logger->error('user', '修改昵称失败', [
            'uuid' => $uuid,
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '修改昵称失败', 500);
    }
    
} catch (Exception $e) {
    error_log("修改昵称错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
