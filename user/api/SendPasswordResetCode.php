<?php
/**
 * 发送密码重置验证码
 * 
 * 功能说明：
 * - 用户修改密码时发送验证码
 * - 支持手机号和邮箱两种方式
 * - 验证码有效期 10 分钟
 */

// 禁用 Deprecated 警告（GuzzleHttp 库在 PHP 8.1+ 会产生弃用警告）
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

    // 验证参数
    if (empty($method) || !in_array($method, ['phone', 'email'])) {
        jsonResponse(false, null, '验证方式参数错误');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    $rateLimitService = new RateLimitService($pdo);

    // 获取用户信息
    $stmt = $pdo->prepare("
        SELECT uuid, username, phone, email, status
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute(['uuid' => $userUuid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, null, '用户不存在');
    }

    if ($user['status'] != 1) {
        jsonResponse(false, null, '账户状态异常，无法修改密码');
    }

    // 检查用户是否绑定了对应的联系方式
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

    // 获取客户端 IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 如果是手机号方式，使用 RateLimitService 检查频率限制
    if ($method === 'phone') {
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
            // 记录日志以便排查
            error_log("未找到密码重置短信配置，请检查 site_configs.sms_config 表中是否有 purpose 为 'password_reset'、'reset_password' 或 '重置密码' 的配置");
            jsonResponse(false, null, '短信服务暂时不可用');
        }
        
        $templateId = $smsConfig['template_id'];
        
        // 使用 RateLimitService 检查频率限制
        $rateLimitResult = $rateLimitService->checkRateLimit($target, $clientIp, $templateId, 'password_reset');
        
        if (!$rateLimitResult['allowed']) {
            // 记录触发速率限制的日志
            $logger->log('password_reset', 'rate_limit_triggered', $userUuid, [
                'phone' => $target,
                'limit_name' => $rateLimitResult['reason'] ?? '未知限制',
                'limit_type' => $rateLimitResult['type'] ?? 'unknown',
                'retry_after' => $rateLimitResult['retry_after'] ?? 0,
                'limit' => $rateLimitResult['limit'] ?? null,
                'current' => $rateLimitResult['current'] ?? null,
                'template_id' => $templateId,
                'client_ip' => $clientIp
            ]);
            
            $message = '发送过于频繁';
            if (isset($rateLimitResult['retry_after'])) {
                $message .= '，请 ' . $rateLimitResult['retry_after'] . ' 秒后再试';
            }
            jsonResponse(false, null, $message);
        }
    } else {
        // 邮箱方式仍使用原有的简单频率检查（1分钟内只能发送一次）
        $stmt = $pdo->prepare("
            SELECT created_at
            FROM email.code
            WHERE email = :target
              AND purpose IN ('password_reset', 'reset_password', '重置密码')
              AND created_at > NOW() - INTERVAL '1 minute'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['target' => $target]);
        $recentCode = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($recentCode) {
            jsonResponse(false, null, '发送过于频繁，请稍后再试');
        }
    }

    // 生成6位数字验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 保存验证码到数据库
    if ($method === 'phone') {
        // 生成短信ID
        $smsId = 'pwd_' . date('YmdHis') . '_' . substr(md5($target . time()), 0, 8);
        
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
            'phone' => $target,
            'code' => $code,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } else {
        // 生成验证码ID
        $codeId = 'pwd_' . date('YmdHis') . '_' . substr(md5($target . time()), 0, 8);
        
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
            'email' => $target,
            'code' => $code,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    // 发送验证码
    if ($method === 'phone') {
        // 发送短信验证码
        require_once __DIR__ . '/../../sms/SmsService.php';
        $smsService = new SmsService($pdo);
        // 参数：手机号、用途、有效期（秒）、客户端IP
        $smsResult = $smsService->sendVerificationCode(
            $target, 
            'password_reset', 
            600, 
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        
        if (!$smsResult['success']) {
            $logger->log('password_reset', 'sms_send_failed', $userUuid, [
                'phone' => $target,
                'reason' => $smsResult['message'],
                'error_data' => $smsResult['data'] ?? null
            ]);
            jsonResponse(false, null, '短信发送失败：' . $smsResult['message']);
        }

        $logger->log('password_reset', 'sms_sent', $userUuid, [
            'phone' => $target,
            'sms_id' => $smsResult['data']['sms_id'] ?? null
        ]);

        // 记录发送（增加频率限制计数）
        $rateLimitService->recordSend($target, $clientIp, $templateId, 'password_reset');

        jsonResponse(true, [
            'method' => 'phone',
            'target' => substr($target, 0, 3) . '****' . substr($target, -4)
        ], '验证码已发送到您的手机');

    } else {
        // 发送邮件验证码
        require_once __DIR__ . '/../../mail/MailService.php';
        $mailService = new MailService($pdo);
        // 参数顺序：收件人邮箱、用户名、验证码、场景、过期时间（分钟）
        $mailResult = $mailService->sendVerificationCode($target, $user['username'], $code, 'reset_password', 10);
        
        if (!$mailResult['success']) {
            $logger->log('password_reset', 'email_send_failed', $userUuid, [
                'email' => $target,
                'reason' => $mailResult['message']
            ]);
            jsonResponse(false, null, '邮件发送失败：' . $mailResult['message']);
        }

        $logger->log('password_reset', 'email_sent', $userUuid, [
            'email' => $target
        ]);

        jsonResponse(true, [
            'method' => 'email',
            'target' => substr($target, 0, 3) . '****' . substr($target, strpos($target, '@'))
        ], '验证码已发送到您的邮箱');
    }

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
