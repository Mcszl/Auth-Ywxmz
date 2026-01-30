<?php
/**
 * 发送注册验证码邮件 API
 * 用于用户注册时发送邮箱验证码
 */

require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/../captcha/CaptchaService.php';

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
function jsonResponse($success, $data = null, $message = '', $code = 200)
{
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
 * 生成验证码
 */
function generateCode($length = 6)
{
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * 保存验证码记录
 */
function saveCodeRecord($pdo, $email, $code, $lotNumber = null)
{
    try {
        $codeId = 'EMAIL_' . strtoupper(uniqid());
        $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15分钟后过期
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $extraInfo = [
            'lot_number' => $lotNumber,
            'client_ip' => $clientIp,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO email.code (
                code_id, email, code, status, purpose, 
                validity_period, expires_at, channel, 
                extra_info, created_at
            ) VALUES (
                :code_id, :email, :code, 1, 'register',
                900, :expires_at, 'system',
                :extra_info, CURRENT_TIMESTAMP
            )
        ");
        
        $stmt->execute([
            'code_id' => $codeId,
            'email' => $email,
            'code' => $code,
            'expires_at' => $expiresAt,
            'extra_info' => json_encode($extraInfo)
        ]);
        
        return $codeId;
        
    } catch (PDOException $e) {
        error_log("保存邮箱验证码记录失败: " . $e->getMessage());
        return null;
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
    
    // 获取请求数据
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        jsonResponse(false, null, '无效的请求数据', 400);
    }
    
    // 验证必填参数
    $email = $data['email'] ?? '';
    
    if (empty($email)) {
        jsonResponse(false, null, '缺少邮箱地址', 400);
    }
    
    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, '邮箱格式不正确', 400);
    }
    
    // 获取客户端IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 创建系统日志实例
    require_once __DIR__ . '/../logs/SystemLogger.php';
    $logger = new SystemLogger($pdo);
    
    // 创建邮件服务实例
    $mailService = new MailService();
    
    // 检查发送频率限制（60秒内不能重复发送）
    if ($mailService->checkRateLimit($email, 'register', 60)) {
        jsonResponse(false, null, '发送过于频繁，请稍后再试', 429);
    }
    
    // 人机验证（如果启用）
    $captchaService = new CaptchaService($pdo, $logger);
    
    // 人机验证参数
    $captchaProvider = $data['captcha_provider'] ?? '';
    $lotNumber = $data['lot_number'] ?? '';
    $captchaOutput = $data['captcha_output'] ?? '';
    $passToken = $data['pass_token'] ?? '';
    $genTime = $data['gen_time'] ?? '';
    $turnstileToken = $data['turnstile_token'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $hcaptchaToken = $data['hcaptcha_token'] ?? '';
    
    // 检查是否启用了人机验证
    $captchaConfig = $captchaService->getCaptchaConfig('send_email');
    $captchaEnabled = ($captchaConfig !== null);
    
    $logger->info('captcha', '人机验证状态检查', [
        'enabled' => $captchaEnabled,
        'provider' => $captchaConfig['provider'] ?? null,
        'scene' => 'send_email'
    ]);
    
    // 如果启用了人机验证，则进行验证
    if ($captchaEnabled) {
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
        
        // 通用人机验证
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
                'email' => $email,
                'message' => $captchaResult['message']
            ]);
            
            // 保存失败的验证日志
            $captchaService->saveVerifyLog(
                $captchaConfig,
                'send_email',
                $captchaData,
                false,
                $clientIp,
                $email,
                $captchaResult
            );
            
            jsonResponse(false, null, $captchaResult['message'], 400);
        }
        
        // 保存成功的验证日志
        $logId = $captchaService->saveVerifyLog(
            $captchaConfig,
            'send_email',
            $captchaData,
            true,
            $clientIp,
            $email,
            $captchaResult
        );
        
        $logger->info('captcha', '人机验证成功，已保存日志', [
            'log_id' => $logId,
            'email' => $email
        ]);
        
        // 保存 lot_number（如果有）
        $verifiedLotNumber = $captchaResult['lot_number'] ?? $lotNumber;
    } else {
        $verifiedLotNumber = $lotNumber;
    }
    
    // 生成验证码
    $code = generateCode(6);
    
    // 保存验证码记录
    $codeId = saveCodeRecord($pdo, $email, $code, $verifiedLotNumber);
    if (!$codeId) {
        jsonResponse(false, null, '保存验证码记录失败', 500);
    }
    
    // 发送邮件
    $result = $mailService->sendVerificationCode($email, $email, $code, 'register', 15);
    
    if ($result['success']) {
        jsonResponse(true, [
            'code_id' => $codeId,
            'lot_number' => $verifiedLotNumber,
            'expires_in' => 900,
            'scene' => 'send_email',
            'captcha_enabled' => $captchaEnabled,
            'captcha_provider' => $captchaEnabled ? $captchaConfig['provider'] : null
        ], '验证码已发送，请查收邮件');
    } else {
        jsonResponse(false, null, $result['message'], 400);
    }
    
} catch (Exception $e) {
    error_log("发送注册验证码邮件错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
