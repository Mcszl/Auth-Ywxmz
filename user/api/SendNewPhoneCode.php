<?php
/**
 * 发送新手机号验证码
 * 
 * 功能说明：
 * - 用户修改手机号时，发送验证码到新手机号
 * - 需要先通过旧手机号/邮箱验证
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

    // 验证参数
    if (empty($newPhone)) {
        jsonResponse(false, null, '请输入新手机号');
    }

    if (!preg_match('/^1[3-9]\d{9}$/', $newPhone)) {
        jsonResponse(false, null, '手机号格式不正确');
    }

    // 检查是否已通过旧手机号/邮箱验证
    if (!isset($_SESSION['change_phone_old_verified']) || 
        $_SESSION['change_phone_old_verified'] !== true) {
        jsonResponse(false, null, '请先验证当前手机号或邮箱');
    }

    // 检查 session 是否过期
    if (!isset($_SESSION['change_phone_expires']) || 
        $_SESSION['change_phone_expires'] < time()) {
        jsonResponse(false, null, '验证信息已过期，请重新验证');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    $rateLimitService = new RateLimitService($pdo);

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

    // 获取客户端 IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 获取短信配置以获取 template_id
    $stmt = $pdo->prepare("
        SELECT template_id FROM site_configs.sms_config 
        WHERE purpose IN ('change_phone', '修改手机号')
        AND is_enabled = TRUE 
        AND status = 1
        AND daily_sent_count < daily_limit
        ORDER BY priority ASC
        LIMIT 1
    ");
    $stmt->execute();
    $smsConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$smsConfig) {
        error_log("未找到修改手机号短信配置");
        jsonResponse(false, null, '短信服务暂时不可用');
    }
    
    $templateId = $smsConfig['template_id'];
    
    // 使用 RateLimitService 检查频率限制
    $rateLimitResult = $rateLimitService->checkRateLimit($newPhone, $clientIp, $templateId, 'change_phone');
    
    if (!$rateLimitResult['allowed']) {
        // 记录触发速率限制的日志
        $logger->log('change_phone', 'new_phone_rate_limit_triggered', $userUuid, [
            'new_phone' => $newPhone,
            'limit_name' => $rateLimitResult['reason'] ?? '未知限制',
            'limit_type' => $rateLimitResult['type'] ?? 'unknown',
            'retry_after' => $rateLimitResult['retry_after'] ?? 0,
            'template_id' => $templateId,
            'client_ip' => $clientIp
        ]);
        
        $message = '发送过于频繁';
        if (isset($rateLimitResult['retry_after'])) {
            $message .= '，请 ' . $rateLimitResult['retry_after'] . ' 秒后再试';
        }
        jsonResponse(false, null, $message);
    }

    // 生成6位数字验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 生成短信ID
    $smsId = 'newph_' . date('YmdHis') . '_' . substr(md5($newPhone . time()), 0, 8);
    
    // 保存验证码到数据库
    $stmt = $pdo->prepare("
        INSERT INTO sms.code (
            sms_id, phone, code, status, purpose, 
            validity_period, expires_at, channel, client_ip, created_at
        ) VALUES (
            :sms_id, :phone, :code, 1, 'change_phone',
            600, NOW() + INTERVAL '10 minutes', 'system', :client_ip, NOW()
        )
    ");
    $stmt->execute([
        'sms_id' => $smsId,
        'phone' => $newPhone,
        'code' => $code,
        'client_ip' => $clientIp
    ]);

    // 发送短信验证码
    require_once __DIR__ . '/../../sms/SmsService.php';
    $smsService = new SmsService($pdo);
    $smsResult = $smsService->sendVerificationCode($newPhone, 'change_phone', 600, $clientIp);
    
    if (!$smsResult['success']) {
        $logger->log('change_phone', 'new_phone_sms_send_failed', $userUuid, [
            'new_phone' => $newPhone,
            'reason' => $smsResult['message']
        ]);
        jsonResponse(false, null, '短信发送失败：' . $smsResult['message']);
    }

    $logger->log('change_phone', 'new_phone_sms_sent', $userUuid, [
        'new_phone' => $newPhone
    ]);

    // 记录发送（增加频率限制计数）
    $rateLimitService->recordSend($newPhone, $clientIp, $templateId, 'change_phone');

    jsonResponse(true, [
        'phone' => substr($newPhone, 0, 3) . '****' . substr($newPhone, -4)
    ], '验证码已发送到新手机号');

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
