<?php
/**
 * 获取人机验证配置 API（支持多种验证服务商）
 * 一碗小米周授权登录平台
 */

require_once __DIR__ . '/../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 获取场景参数
    $scene = $_GET['scene'] ?? 'register';
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 查询人机验证配置
    // 注意：使用 jsonb_exists 函数避免 ? 操作符与 PDO 参数占位符冲突
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name as config_name,
            provider,
            captcha_id,
            captcha_key,
            app_id,
            app_secret,
            site_key,
            secret_key,
            scenes,
            priority,
            status,
            is_enabled
        FROM site_configs.captcha_config
        WHERE status = 1 
        AND is_enabled = true
        AND jsonb_exists(scenes, :scene)
        ORDER BY priority ASC, id ASC
        LIMIT 1
    ");
    
    $stmt->execute(['scene' => $scene]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // 人机验证未启用，返回关闭状态
        jsonResponse(true, [
            'enabled' => false,
            'message' => '人机验证未启用'
        ], '获取成功');
    }
    
    // 根据不同的服务商返回不同的配置
    $responseData = [
        'enabled' => true,
        'provider' => $config['provider']
    ];
    
    switch ($config['provider']) {
        case 'geetest':
            // 极验配置
            $responseData['captcha_id'] = $config['captcha_id'] ?: $config['app_id'];
            $responseData['product'] = 'bind';
            $responseData['protocol'] = 'https://';
            break;
            
        case 'turnstile':
            // Cloudflare Turnstile 配置
            $responseData['site_key'] = $config['site_key'] ?: $config['app_id'];
            break;
            
        case 'recaptcha':
            // Google reCAPTCHA 配置
            $responseData['site_key'] = $config['site_key'] ?: $config['app_id'];
            break;
            
        case 'hcaptcha':
            // hCaptcha 配置
            $responseData['site_key'] = $config['site_key'] ?: $config['app_id'];
            break;
            
        default:
            jsonResponse(false, null, '不支持的验证服务商', 400);
    }
    
    // 返回配置
    jsonResponse(true, $responseData, '获取成功');
    
} catch (Exception $e) {
    error_log("获取人机验证配置错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
