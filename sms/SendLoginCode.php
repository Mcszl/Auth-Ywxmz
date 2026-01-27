<?php
/**
 * 发送登录验证码 API
 * 一碗小米周授权登录平台
 */

// 抑制 PHP 8.x 中 GuzzleHttp 库的 Deprecated 警告
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/RateLimitService.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/../captcha/GeetestService.php';
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
    
    // 极验参数
    $lotNumber = $input['lot_number'] ?? '';
    $captchaOutput = $input['captcha_output'] ?? '';
    $passToken = $input['pass_token'] ?? '';
    $genTime = $input['gen_time'] ?? '';
    
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
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 获取客户端 IP
    $clientIp = getClientIp();
    
    // 初始化服务
    $logger = new SystemLogger($pdo);
    $geetestService = new GeetestService($pdo, $logger);
    $rateLimitService = new RateLimitService($pdo);
    $smsService = new SmsService($pdo);
    
    $logger->info('sms', '开始发送登录验证码', [
        'phone' => $phone,
        'client_ip' => $clientIp
    ]);
    
    // 检查手机号是否已注册
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, status FROM users.user 
            WHERE phone = :phone
            LIMIT 1
        ");
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $logger->warning('sms', '手机号未注册', [
                'phone' => $phone,
                'client_ip' => $clientIp
            ]);
            jsonResponse(false, null, '该手机号未注册', 400);
        }
        
        if ($user['status'] != 1) {
            $logger->warning('sms', '用户账号已被禁用', [
                'phone' => $phone,
                'user_id' => $user['id'],
                'status' => $user['status']
            ]);
            jsonResponse(false, null, '该账号已被禁用，无法登录', 403);
        }
        
        $logger->info('sms', '手机号验证通过', [
            'phone' => $phone,
            'user_id' => $user['id'],
            'username' => $user['username']
        ]);
        
    } catch (PDOException $e) {
        $logger->error('sms', '查询用户信息失败', [
            'phone' => $phone,
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '服务器错误', 500);
    }
    
    // 检查是否启用了人机验证
    $captchaConfig = $geetestService->getGeetestConfig('send_sms');
    $captchaEnabled = ($captchaConfig !== null);
    
    $logger->info('captcha', '人机验证状态检查', [
        'enabled' => $captchaEnabled,
        'has_config' => ($captchaConfig !== null),
        'scene' => 'send_sms'
    ]);
    
    // 如果启用了人机验证，则进行验证
    if ($captchaEnabled) {
        $logger->info('captcha', '开始人机验证', [
            'has_lot_number' => !empty($lotNumber),
            'has_captcha_output' => !empty($captchaOutput),
            'has_pass_token' => !empty($passToken),
            'has_gen_time' => !empty($genTime)
        ]);
        
        // 极验验证（服务端二次验证）
        $geetestResult = $geetestService->verifyGeetest(
            $lotNumber,
            $captchaOutput,
            $passToken,
            $genTime,
            'send_sms',
            $clientIp,
            $phone
        );
        
        $logger->info('captcha', '人机验证结果', [
            'success' => $geetestResult['success'],
            'message' => $geetestResult['message'],
            'disabled' => $geetestResult['disabled'] ?? false
        ]);
        
        if (!$geetestResult['success']) {
            $logger->warning('captcha', '人机验证失败，拒绝发送验证码', [
                'phone' => $phone,
                'message' => $geetestResult['message']
            ]);
            jsonResponse(false, null, $geetestResult['message'] ?? '人机验证失败', 400);
        }
    } else {
        $logger->info('captcha', '人机验证已关闭，跳过验证');
    }
    
    // 获取短信配置（用于获取 template_id）
    $config = $smsService->getSmsConfig('login');
    if (!$config) {
        $logger->error('sms', '短信配置不存在', [
            'purpose' => 'login'
        ]);
        jsonResponse(false, null, '未找到登录验证码的短信配置，请联系管理员配置 login 场景的短信模板', 503);
    }
    
    $templateId = $config['template_id'];
    
    // 检查频率限制
    $rateLimitResult = $rateLimitService->checkRateLimit($phone, $clientIp, $templateId, 'login');
    
    if (!$rateLimitResult['allowed']) {
        $message = $rateLimitResult['reason'];
        $retryAfter = $rateLimitResult['retry_after'] ?? null;
        
        if ($retryAfter) {
            $message .= "，请在 {$retryAfter} 秒后重试";
        }
        
        $logger->warning('sms', '触发频率限制', [
            'phone' => $phone,
            'type' => $rateLimitResult['type'],
            'retry_after' => $retryAfter
        ]);
        
        jsonResponse(false, [
            'type' => $rateLimitResult['type'],
            'retry_after' => $retryAfter,
            'limit' => $rateLimitResult['limit'] ?? null,
            'current' => $rateLimitResult['current'] ?? null
        ], $message, 429);
    }
    
    // 发送验证码
    $result = $smsService->sendVerificationCode($phone, 'login', 900, $clientIp);
    
    if (!$result['success']) {
        $logger->error('sms', '验证码发送失败', [
            'phone' => $phone,
            'message' => $result['message'],
            'error_data' => $result['data'] ?? null
        ]);
        jsonResponse(false, null, $result['message'], 500);
    }
    
    $logger->info('sms', '登录验证码发送成功', [
        'phone' => $phone,
        'user_id' => $user['id'],
        'username' => $user['username'],
        'sms_id' => $result['data']['sms_id'] ?? null
    ]);
    
    // 记录发送（增加频率限制计数）
    $rateLimitService->recordSend($phone, $clientIp, $templateId, 'login');
    
    // 返回成功响应（包含 lot_number 用于二次验证，以及是否启用人机验证的标识）
    jsonResponse(true, array_merge($result['data'], [
        'lot_number' => $lotNumber,
        'captcha_enabled' => $captchaEnabled,
        'scene' => 'send_sms',
        'captcha_provider' => $captchaEnabled ? 'geetest' : null
    ]), $result['message']);
    
} catch (Exception $e) {
    error_log("发送登录验证码错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo) {
            $logger = new SystemLogger($pdo);
            $logger->critical('sms', '发送登录验证码异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'phone' => $phone ?? null
            ], $e->getTraceAsString());
        }
    } catch (Exception $logException) {
        error_log("记录系统日志失败: " . $logException->getMessage());
    }
    
    jsonResponse(false, null, '服务器错误', 500);
}
