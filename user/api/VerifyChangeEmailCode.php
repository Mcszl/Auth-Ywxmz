<?php
/**
 * 验证修改邮箱的旧手机号/邮箱验证码
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

    $userUuid = $_SESSION['user_uuid'];

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $method = $input['method'] ?? ''; // phone 或 email
    $code = $input['code'] ?? '';

    // 验证参数
    if (empty($method) || !in_array($method, ['phone', 'email'])) {
        jsonResponse(false, null, '验证方式参数错误');
    }

    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        jsonResponse(false, null, '验证码格式错误');
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

    // 验证验证码
    if ($method === 'phone') {
        // 验证短信验证码
        $stmt = $pdo->prepare("
            SELECT sms_id as code_id, phone as target, code, status, expires_at
            FROM sms.code
            WHERE phone = :target
              AND purpose IN ('change_email', '修改邮箱')
              AND status = 1
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['target' => $target]);
    } else {
        // 验证邮件验证码
        $stmt = $pdo->prepare("
            SELECT code_id, email as target, code, status, expires_at
            FROM email.code
            WHERE email = :target
              AND purpose IN ('change_email', '修改邮箱')
              AND status = 1
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['target' => $target]);
    }

    $codeRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$codeRecord) {
        jsonResponse(false, null, '验证码不存在或已过期');
    }

    if ($codeRecord['code'] !== $code) {
        jsonResponse(false, null, '验证码错误');
    }

    // 更新验证码状态为已使用（状态3：一次核验成功）
    if ($method === 'phone') {
        $stmt = $pdo->prepare("
            UPDATE sms.code
            SET status = 3,
                updated_at = NOW()
            WHERE sms_id = :code_id
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE email.code
            SET status = 3, verify_count = verify_count + 1, last_verify_at = NOW()
            WHERE code_id = :code_id
        ");
    }
    $stmt->execute(['code_id' => $codeRecord['code_id']]);

    // 保存验证信息到 session（用于后续提交时验证）
    $_SESSION['change_email_verify'] = [
        'method' => $method,
        'target' => $target,
        'code_id' => $codeRecord['code_id'],
        'verified_at' => time(),
        'expires_at' => time() + 600 // 10分钟有效期
    ];

    $logger->log('change_email', 'old_verify_success', $userUuid, [
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
