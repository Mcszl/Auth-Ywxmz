<?php
/**
 * 获取极验配置 API
 * 一碗小米周授权登录平台
 */

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/GeetestService.php';

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
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 初始化服务
    $geetestService = new GeetestService($pdo);
    
    // 获取极验配置
    $config = $geetestService->getGeetestConfig($scene);
    
    if (!$config) {
        // 人机验证未启用，返回关闭状态
        jsonResponse(true, [
            'enabled' => false,
            'message' => '人机验证未启用'
        ], '获取成功');
    }
    
    // 返回配置（只返回前端需要的字段）
    jsonResponse(true, [
        'enabled' => true,
        'captcha_id' => $config['captcha_id'],
        'product' => 'bind', // 极验产品类型
        'protocol' => 'https://'
    ], '获取成功');
    
} catch (Exception $e) {
    error_log("获取极验配置错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
