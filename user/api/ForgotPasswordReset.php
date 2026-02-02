<?php
/**
 * 忘记密码 - 重置密码
 * 
 * 功能说明：
 * - 用户重置密码
 * - 需要先通过验证码验证
 * - 验证 session 中的信息
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

    // 检查 session 验证信息
    if (!isset($_SESSION['forgot_password_verified']) || !$_SESSION['forgot_password_verified']) {
        jsonResponse(false, null, '请先完成身份验证', 401);
    }

    if (!isset($_SESSION['forgot_password_expires']) || $_SESSION['forgot_password_expires'] < time()) {
        // 清除 session
        unset($_SESSION['forgot_password_verified']);
        unset($_SESSION['forgot_password_account']);
        unset($_SESSION['forgot_password_account_type']);
        unset($_SESSION['forgot_password_user_uuid']);
        unset($_SESSION['forgot_password_code_id']);
        unset($_SESSION['forgot_password_code']);
        unset($_SESSION['forgot_password_lot_number']);
        unset($_SESSION['forgot_password_expires']);
        
        jsonResponse(false, null, '验证信息已过期，请重新验证', 401);
    }

    $account = $_SESSION['forgot_password_account'];
    $accountType = $_SESSION['forgot_password_account_type'];
    $userUuid = $_SESSION['forgot_password_user_uuid'];
    $codeId = $_SESSION['forgot_password_code_id'];
    $sessionCode = $_SESSION['forgot_password_code'] ?? ''; // 从 session 获取验证码
    $lotNumber = $_SESSION['forgot_password_lot_number'] ?? ''; // 从 session 获取 lot_number

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $inputAccount = $input['account'] ?? '';
    $inputAccountType = $input['account_type'] ?? '';
    $inputCode = $input['code'] ?? ''; // 用户输入的验证码
    $newPassword = $input['new_password'] ?? '';

    // 验证参数
    if (empty($newPassword)) {
        jsonResponse(false, null, '请输入新密码');
    }

    if (strlen($newPassword) < 8 || strlen($newPassword) > 20) {
        jsonResponse(false, null, '密码长度为8-20位');
    }

    // 验证账号是否匹配
    if ($inputAccount !== $account || $inputAccountType !== $accountType) {
        jsonResponse(false, null, '账号信息不匹配');
    }

    // 验证验证码是否匹配
    if (empty($inputCode) || $inputCode !== $sessionCode) {
        jsonResponse(false, null, '验证码错误或已失效');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    $captchaService = new CaptchaService($pdo);

    // 再次验证人机验证（使用 lot_number 进行二次验证）
    if (!empty($lotNumber)) {
        $captchaConfig = $captchaService->getCaptchaConfig('reset_password');
        if ($captchaConfig) {
            $captchaResult = $captchaService->verifySecondTime(
                $lotNumber,
                $account,
                $captchaConfig['provider'],
                'reset_password_final',
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            );
            
            if (!$captchaResult['success']) {
                error_log('重置密码 - 人机验证二次验证失败: ' . json_encode($captchaResult));
                jsonResponse(false, null, '人机验证已失效，请重新验证');
            }
            
            error_log('重置密码 - 人机验证二次验证成功');
        }
    }

    // 开始事务
    $pdo->beginTransaction();

    try {
        // 更新用户密码
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("
            UPDATE users.user
            SET password_hash = :password_hash,
                updated_at = NOW()
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'password_hash' => $passwordHash,
            'uuid' => $userUuid
        ]);

        // 将该用户的所有有效 token 标记为失效（安全措施：密码重置后强制重新登录）
        
        // 1. 标记所有有效的 login_token 为强制关闭（status = 3）
        $stmt = $pdo->prepare("
            UPDATE tokens.login_token
            SET status = 3,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);
        
        // 2. 标记所有有效的 access_token 为用户退出登录（status = 2）
        $stmt = $pdo->prepare("
            UPDATE tokens.access_token
            SET status = 2,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);
        
        // 3. 标记所有有效的 refresh_token 为用户退出登录（status = 2）
        $stmt = $pdo->prepare("
            UPDATE tokens.refresh_token
            SET status = 2,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);

        // 更新验证码状态为已使用（状态0）
        if ($accountType === 'phone') {
            $stmt = $pdo->prepare("
                UPDATE sms.code
                SET status = 0,
                    updated_at = NOW()
                WHERE sms_id = :code_id
            ");
        } else {
            $stmt = $pdo->prepare("
                UPDATE email.code
                SET status = 0, verify_count = verify_count + 1, last_verify_at = NOW()
                WHERE code_id = :code_id
            ");
        }
        $stmt->execute(['code_id' => $codeId]);

        // 提交事务
        $pdo->commit();

        // 清除 session
        unset($_SESSION['forgot_password_verified']);
        unset($_SESSION['forgot_password_account']);
        unset($_SESSION['forgot_password_account_type']);
        unset($_SESSION['forgot_password_user_uuid']);
        unset($_SESSION['forgot_password_code_id']);
        unset($_SESSION['forgot_password_code']);
        unset($_SESSION['forgot_password_lot_number']);
        unset($_SESSION['forgot_password_expires']);

        $logger->log('forgot_password', 'password_reset_success', $userUuid, [
            'account' => $account,
            'account_type' => $accountType,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        jsonResponse(true, null, '密码重置成功');

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
