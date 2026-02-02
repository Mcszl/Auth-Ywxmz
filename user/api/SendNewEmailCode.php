<?php
/**
 * 发送新邮箱验证码
 */

// 禁用 Deprecated 警告
error_reporting(E_ALL & ~E_DEPRECATED);

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

    // 检查是否已完成第一步验证
    if (!isset($_SESSION['change_email_verify'])) {
        jsonResponse(false, null, '请先完成身份验证');
    }

    // 检查第一步验证是否过期
    if ($_SESSION['change_email_verify']['expires_at'] < time()) {
        unset($_SESSION['change_email_verify']);
        jsonResponse(false, null, '验证信息已过期，请重新验证');
    }

    $userUuid = $_SESSION['user_uuid'];

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $newEmail = $input['new_email'] ?? '';

    // 验证新邮箱格式
    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, '邮箱格式错误');
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
        SELECT uuid, username, email
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute(['uuid' => $userUuid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, '用户不存在');
    }

    // 检查新邮箱是否与当前邮箱相同
    if ($user['email'] === $newEmail) {
        jsonResponse(false, null, '新邮箱不能与当前邮箱相同');
    }

    // 检查新邮箱是否已被其他用户使用
    $stmt = $pdo->prepare("
        SELECT uuid FROM users.user
        WHERE email = :email AND uuid != :uuid
    ");
    $stmt->execute([
        'email' => $newEmail,
        'uuid' => $userUuid
    ]);
    if ($stmt->fetch()) {
        jsonResponse(false, null, '该邮箱已被其他用户使用');
    }

    // 检查发送频率（1分钟内只能发送一次）
    $stmt = $pdo->prepare("
        SELECT created_at
        FROM email.code
        WHERE email = :email
          AND purpose IN ('change_email', '修改邮箱')
          AND created_at > NOW() - INTERVAL '1 minute'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['email' => $newEmail]);
    if ($stmt->fetch()) {
        jsonResponse(false, null, '发送过于频繁，请稍后再试');
    }

    // 生成6位数字验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 获取客户端 IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 生成验证码ID
    $codeId = 'newem_' . date('YmdHis') . '_' . substr(md5($newEmail . time()), 0, 8);

    // 保存验证码到数据库
    $stmt = $pdo->prepare("
        INSERT INTO email.code (
            code_id, email, code, status, purpose, 
            validity_period, expires_at, channel, client_ip, created_at
        ) VALUES (
            :code_id, :email, :code, 1, 'change_email',
            600, NOW() + INTERVAL '10 minutes', 'system', :client_ip, NOW()
        )
    ");
    $stmt->execute([
        'code_id' => $codeId,
        'email' => $newEmail,
        'code' => $code,
        'client_ip' => $clientIp
    ]);

    // 发送邮件验证码
    require_once __DIR__ . '/../../mail/MailService.php';
    $mailService = new MailService($pdo);
    $mailResult = $mailService->sendVerificationCode($newEmail, $user['username'], $code, 'change_email', 10);

    if (!$mailResult['success']) {
        $logger->log('change_email', 'new_email_send_failed', $userUuid, [
            'new_email' => $newEmail,
            'reason' => $mailResult['message']
        ]);
        jsonResponse(false, null, '邮件发送失败：' . $mailResult['message']);
    }

    $logger->log('change_email', 'new_email_sent', $userUuid, [
        'new_email' => $newEmail
    ]);

    jsonResponse(true, [
        'new_email' => substr($newEmail, 0, 3) . '****' . substr($newEmail, strpos($newEmail, '@'))
    ], '验证码已发送到新邮箱');

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
