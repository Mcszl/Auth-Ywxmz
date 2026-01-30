<?php
/**
 * 发送注册验证码 API
 * 一碗小米周授权登录平台
 */

// 抑制 PHP 8.x 的 deprecated 警告（来自第三方库）
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/RateLimitService.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/../captcha/CaptchaService.php';
require_once __DIR__ . '/../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * 返回 JSON 响应
 */
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 获取客户端 IP
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 获取请求参数
    $input = json_decode(file_get_contents('php://input'), true);
    $phone = $input['phone'] ?? '';
    
    // 人机验证参数
    $captchaProvider = $input['captcha_provider'] ?? '';
    
    // 极验参数
    $lotNumber = $input['lot_number'] ?? '';
    $captchaOutput = $input['captcha_output'] ?? '';
    $passToken = $input['pass_token'] ?? '';
    $genTime = $input['gen_time'] ?? '';
    
    // Turnstile/reCAPTCHA/hCaptcha 参数
    $turnstileToken = $input['turnstile_token'] ?? '';
    $recaptchaToken = $input['recaptcha_token'] ?? '';
    $hcaptchaToken = $input['hcaptcha_token'] ?? '';
    
    // 验证手机号
    if (empty($phone)) {
        jsonResponse(false, null, '请提供手机号', 400);
    }
    
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        jsonResponse(false, null, '手机号格式不正确', 400);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 获取客户端 IP
    $clientIp = getClientIp();
    
    // 初始化服务
    $logger = new SystemLogger($pdo);
    $captchaService = new CaptchaService($pdo, $logger);
    $rateLimitService = new RateLimitService($pdo);
    $smsService = new SmsService($pdo);
    
    $logger->info('sms', '开始发送注册验证码', [
        'phone' => $phone,
        'client_ip' => $clientIp
    ]);
    
    // 检查是否启用了人机验证
    $captchaConfig = $captchaService->getCaptchaConfig('send_sms');
    $captchaEnabled = ($captchaConfig !== null);
    
    $logger->info('captcha', '人机验证状态检查', [
        'enabled' => $captchaEnabled,
        'has_config' => ($captchaConfig !== null),
        'provider' => $captchaConfig['provider'] ?? null,
        'scene' => 'send_sms'
    ]);
    
    // 如果启用了人机验证，则进行验证
    if ($captchaEnabled) {
        $logger->info('captcha', '开始人机验证', [
            'provider' => $captchaConfig['provider'],
            'captcha_provider_from_client' => $captchaProvider
        ]);
        
        // 准备验证数据
        $captchaData = [
            'lot_number' => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token' => $passToken,
            'gen_time' => $genTime,
            'turnstile_token' => $turnstileToken,
            'recaptcha_token' => $recaptchaToken,
            'hcaptcha_token' => $hcaptchaToken
        ];
        
        // 通用人机验证（支持多种服务商）
        $captchaResult = $captchaService->verifyCaptcha(
            $captchaConfig,
            $captchaData,
            $clientIp
        );
        
        $logger->info('captcha', '人机验证结果', [
            'success' => $captchaResult['success'],
            'message' => $captchaResult['message']
        ]);
        
        if (!$captchaResult['success']) {
            $logger->warning('captcha', '人机验证失败，拒绝发送验证码', [
                'phone' => $phone,
                'message' => $captchaResult['message']
            ]);
            
            // 保存失败的验证日志
            $captchaService->saveVerifyLog(
                $captchaConfig,
                'send_sms',
                $captchaData,
                false,
                $clientIp,
                $phone,
                $captchaResult
            );
            
            jsonResponse(false, null, $captchaResult['message'] ?? '人机验证失败', 400);
        }
        
        // 保存成功的验证日志
        $logId = $captchaService->saveVerifyLog(
            $captchaConfig,
            'send_sms',
            $captchaData,
            true,
            $clientIp,
            $phone,
            $captchaResult
        );
        
        $logger->info('captcha', '人机验证成功，已保存日志', [
            'log_id' => $logId,
            'phone' => $phone
        ]);
        
        // 保存 lot_number（如果有）
        $lotNumber = $captchaResult['lot_number'] ?? $lotNumber;
    } else {
        $logger->info('captcha', '人机验证已关闭，跳过验证');
    }
    
    // 获取短信配置（用于获取 template_id）
    $config = $smsService->getSmsConfig('register');
    if (!$config) {
        jsonResponse(false, null, '未找到注册验证码的短信配置，请联系管理员配置 register 场景的短信模板', 503);
    }
    
    $templateId = $config['template_id'];
    
    // 检查频率限制
    $rateLimitResult = $rateLimitService->checkRateLimit($phone, $clientIp, $templateId, 'register');
    
    if (!$rateLimitResult['allowed']) {
        $message = $rateLimitResult['reason'];
        $retryAfter = $rateLimitResult['retry_after'] ?? null;
        
        if ($retryAfter) {
            $message .= "，请在 {$retryAfter} 秒后重试";
        }
        
        jsonResponse(false, [
            'type' => $rateLimitResult['type'],
            'retry_after' => $retryAfter,
            'limit' => $rateLimitResult['limit'] ?? null,
            'current' => $rateLimitResult['current'] ?? null
        ], $message, 429);
    }
    
    // 发送验证码
    $result = $smsService->sendVerificationCode($phone, 'register', 900, $clientIp);
    
    if (!$result['success']) {
        $logger->error('sms', '验证码发送失败', [
            'phone' => $phone,
            'message' => $result['message']
        ]);
        jsonResponse(false, null, $result['message'], 500);
    }
    
    $logger->info('sms', '验证码发送成功', [
        'phone' => $phone,
        'sms_id' => $result['data']['sms_id'] ?? null
    ]);
    
    // 记录发送（增加频率限制计数）
    $rateLimitService->recordSend($phone, $clientIp, $templateId, 'register');
    
    // 返回成功响应（包含 code_id、lot_number 用于二次验证，以及是否启用人机验证的标识）
    jsonResponse(true, array_merge($result['data'], [
        'code_id' => $result['data']['sms_id'] ?? null,  // 添加 code_id，值为 sms_id
        'lot_number' => $lotNumber,
        'scene' => 'send_sms',
        'captcha_enabled' => $captchaEnabled,
        'captcha_provider' => $captchaEnabled ? $captchaConfig['provider'] : null
    ]), $result['message']);
    
} catch (Exception $e) {
    error_log("发送注册验证码错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo) {
            $logger = new SystemLogger($pdo);
            $logger->critical('sms', '发送注册验证码异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $e->getTraceAsString());
        }
    } catch (Exception $logException) {
        error_log("记录系统日志失败: " . $logException->getMessage());
    }
    
    jsonResponse(false, null, '服务器错误', 500);
}
