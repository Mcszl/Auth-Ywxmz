<?php
/**
 * 修改邮箱
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
    $verifyInfo = $_SESSION['change_email_verify'];

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $newEmail = $input['new_email'] ?? '';
    $oldVerifyCode = $input['old_verify_code'] ?? '';
    $newEmailCode = $input['new_email_code'] ?? '';

    // 验证参数
    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, '新邮箱格式错误');
    }

    if (empty($oldVerifyCode) || !preg_match('/^\d{6}$/', $oldVerifyCode)) {
        jsonResponse(false, null, '旧验证码格式错误');
    }

    if (empty($newEmailCode) || !preg_match('/^\d{6}$/', $newEmailCode)) {
        jsonResponse(false, null, '新邮箱验证码格式错误');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);

    // 开始事务
    $pdo->beginTransaction();

    try {
        // 获取用户信息
        $stmt = $pdo->prepare("
            SELECT uuid, username, email, phone
            FROM users.user
            WHERE uuid = :uuid
            FOR UPDATE
        ");
        $stmt->execute(['uuid' => $userUuid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('用户不存在');
        }

        // 检查新邮箱是否与当前邮箱相同
        if ($user['email'] === $newEmail) {
            throw new Exception('新邮箱不能与当前邮箱相同');
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
            throw new Exception('该邮箱已被其他用户使用');
        }

        // 验证第一步的验证码（旧手机号/邮箱）
        $method = $verifyInfo['method'];
        $target = $verifyInfo['target'];
        $codeId = $verifyInfo['code_id'];

        if ($method === 'phone') {
            // 验证短信验证码
            $stmt = $pdo->prepare("
                SELECT sms_id, code, status
                FROM sms.code
                WHERE sms_id = :code_id
                  AND phone = :target
                  AND purpose IN ('change_email', '修改邮箱')
                  AND status = 3
            ");
            $stmt->execute([
                'code_id' => $codeId,
                'target' => $target
            ]);
        } else {
            // 验证邮件验证码
            $stmt = $pdo->prepare("
                SELECT code_id, code, status
                FROM email.code
                WHERE code_id = :code_id
                  AND email = :target
                  AND purpose IN ('change_email', '修改邮箱')
                  AND status = 3
            ");
            $stmt->execute([
                'code_id' => $codeId,
                'target' => $target
            ]);
        }

        $oldCodeRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldCodeRecord) {
            throw new Exception('验证信息已失效，请重新验证');
        }

        if ($oldCodeRecord['code'] !== $oldVerifyCode) {
            throw new Exception('旧验证码错误');
        }

        // 验证新邮箱的验证码
        $stmt = $pdo->prepare("
            SELECT code_id, code, status, expires_at
            FROM email.code
            WHERE email = :email
              AND purpose IN ('change_email', '修改邮箱')
              AND status = 1
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['email' => $newEmail]);
        $newCodeRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$newCodeRecord) {
            throw new Exception('新邮箱验证码不存在或已过期');
        }

        if ($newCodeRecord['code'] !== $newEmailCode) {
            throw new Exception('新邮箱验证码错误');
        }

        // 更新用户邮箱
        $stmt = $pdo->prepare("
            UPDATE users.user
            SET email = :new_email, updated_at = NOW()
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'new_email' => $newEmail,
            'uuid' => $userUuid
        ]);

        // 更新旧验证码状态为二次核验成功（状态4）
        if ($method === 'phone') {
            $stmt = $pdo->prepare("
                UPDATE sms.code
                SET status = 4,
                    updated_at = NOW()
                WHERE sms_id = :code_id
            ");
        } else {
            $stmt = $pdo->prepare("
                UPDATE email.code
                SET status = 4
                WHERE code_id = :code_id
            ");
        }
        $stmt->execute(['code_id' => $codeId]);

        // 更新新邮箱验证码状态为已使用（状态0）
        $stmt = $pdo->prepare("
            UPDATE email.code
            SET status = 0, verify_count = verify_count + 1, last_verify_at = NOW()
            WHERE code_id = :code_id
        ");
        $stmt->execute(['code_id' => $newCodeRecord['code_id']]);

        // 提交事务
        $pdo->commit();

        // 清除 session 中的验证信息
        unset($_SESSION['change_email_verify']);

        // 记录日志
        $logger->log('change_email', 'email_changed', $userUuid, [
            'old_email' => $user['email'],
            'new_email' => $newEmail,
            'verify_method' => $method
        ]);

        jsonResponse(true, [
            'new_email' => $newEmail
        ], '邮箱修改成功');

    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('修改邮箱失败: ' . $e->getMessage());
    jsonResponse(false, null, $e->getMessage());
}
