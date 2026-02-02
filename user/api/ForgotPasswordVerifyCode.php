<?php
/**
 * 忘记密码 - 验证验证码
 * 
 * 功能说明：
 * - 验证用户输入的验证码是否正确
 * - 需要再次验证人机验证
 * - 验证通过后将信息保存到 session
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
require_once __DIR__ . '/../../captcha/CaptchaService.php';

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

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $account = $input['account'] ?? '';
    $accountType = $input['account_type'] ?? ''; // phone 或 email
    $code = $input['code'] ?? '';
    $captchaToken = $input['captcha_token'] ?? '';
    $lotNumber = $input['lot_number'] ?? ''; // 第一步发送验证码时返回的 lot_number

    // 验证参数
    if (empty($account)) {
        jsonResponse(false, null, '请输入手机号或邮箱');
    }

    if (empty($accountType) || !in_array($accountType, ['phone', 'email'])) {
        jsonResponse(false, null, '账号类型参数错误');
    }

    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        jsonResponse(false, null, '验证码格式错误');
    }

    // 如果有 lot_number，使用二次验证；否则需要完整的人机验证
    if (empty($lotNumber) && empty($captchaToken)) {
        jsonResponse(false, null, '请完成人机验证');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    $captchaService = new CaptchaService($pdo);

    // 验证人机验证
    $captchaConfig = $captchaService->getCaptchaConfig('reset_password');
    if (!$captchaConfig) {
        error_log('验证身份 - 获取人机验证配置失败: scene=reset_password');
        jsonResponse(false, null, '人机验证服务暂时不可用（配置未找到）');
    }
    
    error_log('验证身份 - 人机验证配置: ' . json_encode($captchaConfig));
    
    // 如果有 lot_number，使用二次验证；否则使用完整验证
    if (!empty($lotNumber)) {
        // 极验二次验证：使用 verifySecondTime 方法
        error_log('验证身份 - 使用二次验证，lot_number: ' . $lotNumber);
        
        $captchaResult = $captchaService->verifySecondTime(
            $lotNumber,
            $account,
            $captchaConfig['provider'],
            'reset_password',
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );
        error_log('验证身份 - 二次验证结果: ' . json_encode($captchaResult));
    } else {
        // 完整的人机验证
        error_log('验证身份 - 使用完整验证，接收到的验证数据: ' . json_encode($captchaToken));
        
        $captchaResult = $captchaService->verifyCaptcha($captchaConfig, $captchaToken, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        error_log('验证身份 - 完整验证结果: ' . json_encode($captchaResult));
    }
    
    if (!$captchaResult['success']) {
        jsonResponse(false, null, $captchaResult['message'] ?? '人机验证失败');
    }

    // 查询用户
    if ($accountType === 'phone') {
        $stmt = $pdo->prepare("
            SELECT uuid, username, phone, email
            FROM users.user
            WHERE phone = :account
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT uuid, username, phone, email
            FROM users.user
            WHERE email = :account
        ");
    }
    $stmt->execute(['account' => $account]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, '该账号不存在');
    }

    // 查询验证码
    if ($accountType === 'phone') {
        $stmt = $pdo->prepare("
            SELECT sms_id as code_id, code, expires_at, status
            FROM sms.code
            WHERE phone = :account
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND status = 1
            ORDER BY created_at DESC
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT code_id, code, expires_at, status
            FROM email.code
            WHERE email = :account
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND status = 1
            ORDER BY created_at DESC
            LIMIT 1
        ");
    }
    $stmt->execute(['account' => $account]);
    $verificationCode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificationCode) {
        $logger->log('forgot_password', 'code_not_found', $user['uuid'], [
            'account' => $account,
            'account_type' => $accountType
        ]);
        jsonResponse(false, null, '验证码不存在或已过期');
    }

    // 检查验证码是否过期
    if (strtotime($verificationCode['expires_at']) < time()) {
        $logger->log('forgot_password', 'code_expired', $user['uuid'], [
            'account' => $account,
            'account_type' => $accountType
        ]);
        jsonResponse(false, null, '验证码已过期，请重新获取');
    }

    // 验证验证码
    if ($verificationCode['code'] !== $code) {
        $logger->log('forgot_password', 'code_incorrect', $user['uuid'], [
            'account' => $account,
            'account_type' => $accountType
        ]);
        jsonResponse(false, null, '验证码错误');
    }

    // 更新验证码状态为已验证（状态3）
    if ($accountType === 'phone') {
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
    $stmt->execute(['code_id' => $verificationCode['code_id']]);

    // 将验证信息保存到 session（用于后续重置密码时验证）
    $_SESSION['forgot_password_verified'] = true;
    $_SESSION['forgot_password_account'] = $account;
    $_SESSION['forgot_password_account_type'] = $accountType;
    $_SESSION['forgot_password_user_uuid'] = $user['uuid'];
    $_SESSION['forgot_password_code_id'] = $verificationCode['code_id'];
    $_SESSION['forgot_password_code'] = $code; // 保存验证码，用于重置密码时再次验证
    $_SESSION['forgot_password_lot_number'] = $lotNumber; // 保存 lot_number，用于重置密码时再次验证人机验证
    $_SESSION['forgot_password_expires'] = time() + 600; // 10分钟有效期

    $logger->log('forgot_password', 'code_verified', $user['uuid'], [
        'account' => $account,
        'account_type' => $accountType
    ]);

    jsonResponse(true, [
        'account_type' => $accountType
    ], '验证成功');

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
