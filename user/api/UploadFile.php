<?php
/**
 * 文件上传 API
 * 用户上传文件（如头像）
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../storage/StorageService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

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
    
    // 从 session 获取用户 UUID
    $uuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($uuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 检查是否有文件上传
    if (!isset($_FILES['file']) || empty($_FILES['file']['name'])) {
        jsonResponse(false, null, '请选择要上传的文件');
    }
    
    $file = $_FILES['file'];
    
    // 获取上传类型（avatar, document等）
    $uploadType = $_POST['type'] ?? 'avatar';
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 创建存储服务实例
    try {
        $storageService = new StorageService($pdo);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        
        $logger->error('storage', '存储服务初始化失败', [
            'error' => $errorMessage
        ]);
        
        // 返回友好的错误信息
        jsonResponse(false, null, $errorMessage);
    }
    
    // 根据上传类型设置目录
    $directory = 'uploads/' . $uploadType . '/' . date('Y/m');
    
    // 上传文件
    $result = $storageService->uploadFile($file, $directory);
    
    if (!$result['success']) {
        $logger->warning('storage', '文件上传失败', [
            'uuid' => $uuid,
            'type' => $uploadType,
            'error' => $result['message']
        ]);
        jsonResponse(false, null, $result['message']);
    }
    
    $logger->info('storage', '文件上传成功', [
        'uuid' => $uuid,
        'type' => $uploadType,
        'url' => $result['url'],
        'size' => $result['size']
    ]);
    
    // 返回上传结果
    jsonResponse(true, [
        'url' => $result['url'],
        'path' => $result['path'],
        'size' => $result['size'],
        'type' => $result['type']
    ], '文件上传成功');
    
} catch (Exception $e) {
    error_log("文件上传错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
