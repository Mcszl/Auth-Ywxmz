<?php
/**
 * 验证验证码 API
 * 一碗小米周授权登录平台
 */

// 抑制 PHP 8.x 的 deprecated 警告（来自第三方库）
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/SmsService.php';

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
    $code = $input['code'] ?? '';
    $purpose = $input['purpose'] ?? '';
    
    // 验证参数
    if (empty($phone)) {
        jsonResponse(false, null, '请提供手机号', 400);
    }
    
    if (empty($code)) {
        jsonResponse(false, null, '请提供验证码', 400);
    }
    
    if (empty($purpose)) {
        jsonResponse(false, null, '请提供验证码用途', 400);
    }
    
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        jsonResponse(false, null, '手机号格式不正确', 400);
    }
    
    if (!preg_match('/^\d{6}$/', $code)) {
        jsonResponse(false, null, '验证码格式不正确', 400);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 创建短信服务实例
    $smsService = new SmsService($pdo);
    
    // 验证验证码
    $result = $smsService->verifyCode($phone, $code, $purpose);
    
    if ($result['success']) {
        jsonResponse(true, [
            'phone' => $phone,
            'purpose' => $purpose,
            'verified_at' => time()
        ], $result['message']);
    } else {
        jsonResponse(false, null, $result['message'], 400);
    }
    
} catch (Exception $e) {
    error_log("验证验证码错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
