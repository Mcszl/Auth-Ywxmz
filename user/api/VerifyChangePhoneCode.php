<?php
/**
 * 验证修改手机号验证码（验证旧手机号/邮箱）
 * 
 * 功能说明：
 * - 验证用户输入的验证码是否正确
 * - 验证通过后将信息保存到 session
 * - Session 有效期 10 分钟
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function jsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '请求方法错误', 405);
    }

    // 检查用户登录状态
    if (!isset($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '请先登录', 401);
    }

    $userUuid = $_SESSION['user_uuid'];

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $method = $input['method'] ?? ''; // phone 或 email
    $code = $input['code'] ?? '';

    // 验证参数
    if (empty($method) || !in_array($method, ['phone', 'email'])) {
        jsonResponse(false, null, '验证方式参数错误');
    }

    if (empty($code)) {
        jsonResponse(false, null, '请输入验证码');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);

    // 获取用户信息
    $stmt = $pdo->prepare("
        SELECT uuid, username, phone, email
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute(['uuid' => $userUuid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, '用户不存在');
    }

    // 确定验证目标
    if ($method === 'phone') {
        if (empty($user['phone'])) {
            jsonResponse(false, null, '您还未绑定手机号');
        }
        $target = $user['phone'];
    } else {
        if (empty($user['email'])) {
            jsonResponse(false, null, '您还未绑定邮箱');
        }
        $target = $user['email'];
    }

    // 查询验证码
    if ($method === 'phone') {
        $stmt = $pdo->prepare("
            SELECT sms_id as code_id, code, expires_at, status
            FROM sms.code
            WHERE phone = :target
              AND purpose IN ('change_phone', '修改手机号')
              AND status = 1
            ORDER BY created_at DESC
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT code_id, code, expires_at, status
            FROM email.code
            WHERE email = :target
              AND purpose IN ('change_phone', '修改手机号')
              AND status = 1
            ORDER BY created_at DESC
            LIMIT 1
        ");
    }
    
    $stmt->execute(['target' => $target]);
    $verificationCode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificationCode) {
        $logger->log('change_phone', 'old_code_not_found', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码不存在或已过期');
    }

    // 检查验证码是否过期
    if (strtotime($verificationCode['expires_at']) < time()) {
        $logger->log('change_phone', 'old_code_expired', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码已过期，请重新获取');
    }

    // 验证验证码
    if ($verificationCode['code'] !== $code) {
        $logger->log('change_phone', 'old_code_incorrect', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码错误');
    }

    // 将验证信息保存到 session
    $_SESSION['change_phone_old_verified'] = true;
    $_SESSION['change_phone_old_method'] = $method;
    $_SESSION['change_phone_old_target'] = $target;
    $_SESSION['change_phone_old_code'] = $code;
    $_SESSION['change_phone_expires'] = time() + 600; // 10分钟有效期

    $logger->log('change_phone', 'old_code_verified', $userUuid, [
        'method' => $method,
        'target' => $target
    ]);

    jsonResponse(true, [
        'method' => $method
    ], '验证成功');

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
