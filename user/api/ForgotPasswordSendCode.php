<?php
/**
 * 忘记密码 - 发送验证码
 * 
 * 功能说明：
 * - 用户忘记密码时发送验证码
 * - 支持手机号和邮箱两种方式
 * - 需要通过人机验证
 * - 验证码有效期 10 分钟
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
require_once __DIR__ . '/../../sms/RateLimitService.php';
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
    $captchaToken = $input['captcha_token'] ?? '';

    // 验证参数
    if (empty($account)) {
        jsonResponse(false, null, '请输入手机号或邮箱');
    }

    if (empty($accountType) || !in_array($accountType, ['phone', 'email'])) {
        jsonResponse(false, null, '账号类型参数错误');
    }

    if (empty($captchaToken)) {
        jsonResponse(false, null, '请完成人机验证');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    $rateLimitService = new RateLimitService($pdo);
    $captchaService = new CaptchaService($pdo);

    // 验证人机验证
    $captchaConfig = $captchaService->getCaptchaConfig('reset_password');
    if (!$captchaConfig) {
        error_log('忘记密码 - 获取人机验证配置失败: scene=reset_password');
        jsonResponse(false, null, '人机验证服务暂时不可用（配置未找到）');
    }
    
    error_log('忘记密码 - 人机验证配置: ' . json_encode($captchaConfig));
    error_log('忘记密码 - 配置provider: ' . ($captchaConfig['provider'] ?? 'null'));
    error_log('忘记密码 - 接收到的验证数据: ' . json_encode($captchaToken));
    error_log('忘记密码 - 验证数据类型: ' . gettype($captchaToken));
    
    // 获取客户端 IP（提前定义，用于验证和日志）
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $captchaResult = $captchaService->verifyCaptcha($captchaConfig, $captchaToken, $clientIp);
    error_log('忘记密码 - 人机验证结果: ' . json_encode($captchaResult));
    
    if (!$captchaResult['success']) {
        jsonResponse(false, null, $captchaResult['message'] ?? '人机验证失败');
    }
    
    // 保存验证日志（用于后续二次验证）
    $captchaService->saveVerifyLog(
        $captchaConfig,
        'reset_password',
        $captchaToken,
        true,
        $clientIp,
        $account,
        $captchaResult
    );

    // 查询用户是否存在
    if ($accountType === 'phone') {
        $stmt = $pdo->prepare("
            SELECT uuid, username, phone, email, status
            FROM users.user
            WHERE phone = :account
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT uuid, username, phone, email, status
            FROM users.user
            WHERE email = :account
        ");
    }
    $stmt->execute(['account' => $account]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, '该账号不存在');
    }

    if ($user['status'] != 1) {
        jsonResponse(false, null, '账户状态异常，无法重置密码');
    }

    // 如果是手机号方式，使用 RateLimitService 检查频率限制
    if ($accountType === 'phone') {
        // 获取短信配置以获取 template_id
        $stmt = $pdo->prepare("
            SELECT template_id FROM site_configs.sms_config 
            WHERE purpose IN ('password_reset', 'reset_password', '重置密码')
            AND is_enabled = TRUE 
            AND status = 1
            AND daily_sent_count < daily_limit
            ORDER BY priority ASC
            LIMIT 1
        ");
        $stmt->execute();
        $smsConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$smsConfig) {
            error_log("未找到密码重置短信配置");
            jsonResponse(false, null, '短信服务暂时不可用');
        }
        
        $templateId = $smsConfig['template_id'];
        
        // 使用 RateLimitService 检查频率限制
        $rateLimitResult = $rateLimitService->checkRateLimit($account, $clientIp, $templateId, 'password_reset');
        
        if (!$rateLimitResult['allowed']) {
            $logger->log('forgot_password', 'rate_limit_triggered', $user['uuid'], [
                'phone' => $account,
                'limit_name' => $rateLimitResult['reason'] ?? '未知限制',
                'client_ip' => $clientIp
            ]);
            
            $message = '发送过于频繁';
            if (isset($rateLimitResult['retry_after'])) {
                $message .= '，请 ' . $rateLimitResult['retry_after'] . ' 秒后再试';
            }
            jsonResponse(false, null, $message);
        }
    } else {
        // 邮箱方式使用简单频率检查（1分钟内只能发送一次）
        $stmt = $pdo->prepare("
            SELECT created_at
            FROM email.code
            WHERE email = :account
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND created_at > NOW() - INTERVAL '1 minute'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['account' => $account]);
        $recentCode = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($recentCode) {
            jsonResponse(false, null, '发送过于频繁，请稍后再试');
        }
    }

    // 生成6位数字验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 保存验证码到数据库
    if ($accountType === 'phone') {
        // 生成短信ID
        $smsId = 'fpwd_' . date('YmdHis') . '_' . substr(md5($account . time()), 0, 8);
        
        $stmt = $pdo->prepare("
            INSERT INTO sms.code (
                sms_id, phone, code, status, purpose, 
                validity_period, expires_at, channel, client_ip, created_at
            ) VALUES (
                :sms_id, :phone, :code, 1, 'password_reset',
                600, NOW() + INTERVAL '10 minutes', 'system', :client_ip, NOW()
            )
        ");
        $stmt->execute([
            'sms_id' => $smsId,
            'phone' => $account,
            'code' => $code,
            'client_ip' => $clientIp
        ]);
    } else {
        // 生成验证码ID
        $codeId = 'fpwd_' . date('YmdHis') . '_' . substr(md5($account . time()), 0, 8);
        
        $stmt = $pdo->prepare("
            INSERT INTO email.code (
                code_id, email, code, status, purpose, 
                validity_period, expires_at, channel, client_ip, created_at
            ) VALUES (
                :code_id, :email, :code, 1, 'password_reset',
                600, NOW() + INTERVAL '10 minutes', 'system', :client_ip, NOW()
            )
        ");
        $stmt->execute([
            'code_id' => $codeId,
            'email' => $account,
            'code' => $code,
            'client_ip' => $clientIp
        ]);
    }

    // 发送验证码
    if ($accountType === 'phone') {
        // 发送短信验证码
        require_once __DIR__ . '/../../sms/SmsService.php';
        $smsService = new SmsService($pdo);
        $smsResult = $smsService->sendVerificationCode($account, 'password_reset', 600, $clientIp);
        
        if (!$smsResult['success']) {
            $logger->log('forgot_password', 'sms_send_failed', $user['uuid'], [
                'phone' => $account,
                'reason' => $smsResult['message']
            ]);
            jsonResponse(false, null, '短信发送失败：' . $smsResult['message']);
        }

        $logger->log('forgot_password', 'sms_sent', $user['uuid'], [
            'phone' => $account
        ]);

        // 记录发送（增加频率限制计数）
        $rateLimitService->recordSend($account, $clientIp, $templateId, 'password_reset');

        // 返回数据，包含 lot_number（用于二次验证）
        $responseData = [
            'code_id' => $smsId,
            'account_type' => 'phone',
            'masked_account' => substr($account, 0, 3) . '****' . substr($account, -4)
        ];
        
        // 如果有极验的 lot_number，也返回给前端
        if (isset($captchaResult['lot_number'])) {
            $responseData['lot_number'] = $captchaResult['lot_number'];
        }

        jsonResponse(true, $responseData, '验证码已发送到您的手机');

    } else {
        // 发送邮件验证码
        require_once __DIR__ . '/../../mail/MailService.php';
        $mailService = new MailService($pdo);
        $mailResult = $mailService->sendVerificationCode($account, $user['username'], $code, 'reset_password', 10);
        
        if (!$mailResult['success']) {
            $logger->log('forgot_password', 'email_send_failed', $user['uuid'], [
                'email' => $account,
                'reason' => $mailResult['message']
            ]);
            jsonResponse(false, null, '邮件发送失败：' . $mailResult['message']);
        }

        $logger->log('forgot_password', 'email_sent', $user['uuid'], [
            'email' => $account
        ]);

        // 返回数据，包含 lot_number（用于二次验证）
        $responseData = [
            'code_id' => $codeId,
            'account_type' => 'email',
            'masked_account' => substr($account, 0, 3) . '****' . substr($account, strpos($account, '@'))
        ];
        
        // 如果有极验的 lot_number，也返回给前端
        if (isset($captchaResult['lot_number'])) {
            $responseData['lot_number'] = $captchaResult['lot_number'];
        }

        jsonResponse(true, $responseData, '验证码已发送到您的邮箱');
    }

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
