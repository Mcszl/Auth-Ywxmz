<?php
/**
 * 验证密码重置验证码
 * 
 * 功能说明：
 * - 验证用户输入的验证码是否正确
 * - 验证通过后返回临时令牌
 * - 临时令牌用于后续的密码修改
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
        $targetType = 'phone';
    } else {
        if (empty($user['email'])) {
            jsonResponse(false, null, '您还未绑定邮箱');
        }
        $target = $user['email'];
        $targetType = 'email';
    }

    // 查询验证码
    if ($method === 'phone') {
        $stmt = $pdo->prepare("
            SELECT sms_id as code_id, code, expires_at, status
            FROM sms.code
            WHERE phone = :target
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND status IN (1, 3)
            ORDER BY created_at DESC
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT code_id, code, expires_at, status
            FROM email.code
            WHERE email = :target
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND status IN (1, 3)
            ORDER BY created_at DESC
            LIMIT 1
        ");
    }
    
    $stmt->execute(['target' => $target]);
    $verificationCode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificationCode) {
        $logger->log('password_reset', 'code_not_found', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码不存在或已过期');
    }

    // 检查验证码是否已使用
    if ($verificationCode['status'] == 0) {
        $logger->log('password_reset', 'code_already_used', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码已使用，请重新获取');
    }

    // 检查验证码是否过期
    if (strtotime($verificationCode['expires_at']) < time() || $verificationCode['status'] == 2) {
        $logger->log('password_reset', 'code_expired', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码已过期，请重新获取');
    }

    // 验证验证码
    if ($verificationCode['code'] !== $code) {
        $logger->log('password_reset', 'code_incorrect', $userUuid, [
            'method' => $method,
            'target' => $target
        ]);
        jsonResponse(false, null, '验证码错误');
    }

    // 更新验证码状态为一次核验成功
    if ($method === 'phone') {
        $stmt = $pdo->prepare("
            UPDATE sms.code 
            SET status = 3, verify_count = verify_count + 1, last_verify_at = NOW()
            WHERE sms_id = :code_id
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE email.code 
            SET status = 3, verify_count = verify_count + 1, last_verify_at = NOW()
            WHERE code_id = :code_id
        ");
    }
    $stmt->execute(['code_id' => $verificationCode['code_id']]);

    // 生成临时令牌（用于后续的密码修改）
    $token = bin2hex(random_bytes(32));
    
    // 将令牌和验证码信息保存到 session
    $_SESSION['password_reset_token'] = $token;
    $_SESSION['password_reset_code_id'] = $verificationCode['code_id']; // 统一使用 code_id（短信表已通过别名转换）
    $_SESSION['password_reset_method'] = $method;
    $_SESSION['password_reset_target'] = $target;
    $_SESSION['password_reset_code'] = $code; // 保存验证码，用于最终验证
    $_SESSION['password_reset_expires'] = time() + 600; // 10分钟有效期

    $logger->log('password_reset', 'code_verified', $userUuid, [
        'method' => $method,
        'target' => $target
    ]);

    jsonResponse(true, [
        'token' => $token,
        'method' => $method
    ], '验证成功');

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
