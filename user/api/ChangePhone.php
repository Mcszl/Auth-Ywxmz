<?php
/**
 * 修改手机号
 * 
 * 功能说明：
 * - 用户修改绑定的手机号
 * - 需要先验证旧手机号或邮箱
 * - 然后验证新手机号
 * - 最后提交修改
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
    $newPhone = $input['new_phone'] ?? '';
    $oldVerifyCode = $input['old_verify_code'] ?? '';
    $newPhoneCode = $input['new_phone_code'] ?? '';

    // 验证参数
    if (empty($newPhone)) {
        jsonResponse(false, null, '请输入新手机号');
    }

    if (!preg_match('/^1[3-9]\d{9}$/', $newPhone)) {
        jsonResponse(false, null, '手机号格式不正确');
    }

    if (empty($oldVerifyCode)) {
        jsonResponse(false, null, '请输入旧手机号/邮箱验证码');
    }

    if (empty($newPhoneCode)) {
        jsonResponse(false, null, '请输入新手机号验证码');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);

    // 检查 session 中的验证信息
    if (!isset($_SESSION['change_phone_old_verified']) || 
        $_SESSION['change_phone_old_verified'] !== true) {
        jsonResponse(false, null, '请先验证当前手机号或邮箱');
    }

    if (!isset($_SESSION['change_phone_old_code']) || 
        $_SESSION['change_phone_old_code'] !== $oldVerifyCode) {
        jsonResponse(false, null, '旧手机号/邮箱验证码不正确');
    }

    // 检查新手机号是否已被其他用户使用
    $stmt = $pdo->prepare("
        SELECT uuid FROM users.user 
        WHERE phone = :phone AND uuid != :uuid
    ");
    $stmt->execute([
        'phone' => $newPhone,
        'uuid' => $userUuid
    ]);
    if ($stmt->fetch()) {
        jsonResponse(false, null, '该手机号已被其他用户使用');
    }

    // 验证新手机号验证码
    $stmt = $pdo->prepare("
        SELECT sms_id, code, expires_at, status
        FROM sms.code
        WHERE phone = :phone
          AND purpose IN ('change_phone', '修改手机号')
          AND status = 1
          AND code = :code
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        'phone' => $newPhone,
        'code' => $newPhoneCode
    ]);
    $newPhoneVerification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$newPhoneVerification) {
        $logger->log('change_phone', 'new_phone_code_invalid', $userUuid, [
            'new_phone' => $newPhone
        ]);
        jsonResponse(false, null, '新手机号验证码无效或已过期');
    }

    // 检查验证码是否过期
    if (strtotime($newPhoneVerification['expires_at']) < time()) {
        $logger->log('change_phone', 'new_phone_code_expired', $userUuid, [
            'new_phone' => $newPhone
        ]);
        jsonResponse(false, null, '新手机号验证码已过期');
    }

    // 开始事务
    $pdo->beginTransaction();

    try {
        // 更新用户手机号
        $stmt = $pdo->prepare("
            UPDATE users.user
            SET phone = :phone,
                updated_at = NOW()
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'phone' => $newPhone,
            'uuid' => $userUuid
        ]);

        // 标记新手机号验证码为已使用
        $stmt = $pdo->prepare("
            UPDATE sms.code
            SET status = 0,
                updated_at = NOW()
            WHERE sms_id = :sms_id
        ");
        $stmt->execute(['sms_id' => $newPhoneVerification['sms_id']]);

        // 提交事务
        $pdo->commit();

        $logger->log('change_phone', 'success', $userUuid, [
            'new_phone' => $newPhone
        ]);

        // 清除 session 中的临时数据
        unset($_SESSION['change_phone_old_verified']);
        unset($_SESSION['change_phone_old_method']);
        unset($_SESSION['change_phone_old_target']);
        unset($_SESSION['change_phone_old_code']);
        unset($_SESSION['change_phone_expires']);

        jsonResponse(true, null, '手机号修改成功');

    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
