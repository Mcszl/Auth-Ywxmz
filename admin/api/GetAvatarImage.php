<?php
/**
 * 获取待审核头像图片
 * 需要管理员权限才能访问
 * 通过文件名从数据库查询存储配置，然后获取图片内容
 * 
 * @author AI Assistant
 * @date 2026-01-24
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../storage/StorageService.php';

// 开启会话
session_start();

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    http_response_code(403);
    echo '未登录';
    exit;
}

// 只允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo '请求方法错误';
    exit;
}

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        http_response_code($code);
        echo $message;
        exit;
    });
    
    // 获取审核记录ID参数
    $checkId = isset($_GET['check_id']) ? intval($_GET['check_id']) : 0;
    
    if ($checkId <= 0) {
        http_response_code(400);
        echo '缺少审核记录ID参数';
        exit;
    }
    
    // 从数据库查询审核记录和存储配置
    $stmt = $pdo->prepare("
        SELECT 
            ac.new_avatar_filename,
            ac.storage_type,
            ac.storage_config_id,
            sc.usage_type
        FROM checks.avatar_check ac
        LEFT JOIN site_configs.storage_config sc ON ac.storage_config_id = sc.id
        WHERE ac.id = :check_id
    ");
    $stmt->execute([':check_id' => $checkId]);
    $checkRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkRecord) {
        http_response_code(404);
        echo '审核记录不存在';
        exit;
    }
    
    $filename = $checkRecord['new_avatar_filename'];
    $usageType = $checkRecord['usage_type'];
    
    if (empty($filename) || empty($usageType)) {
        http_response_code(500);
        echo '存储信息不完整';
        exit;
    }
    
    // 创建存储服务实例
    $storage = new StorageService($pdo, $usageType);
    
    // 获取文件内容（传入文件路径）
    $result = $storage->getFileContentByPath($filename);
    
    if (!$result['success']) {
        http_response_code(404);
        echo '图片不存在：' . $result['message'];
        exit;
    }
    
    // 获取文件扩展名，设置正确的Content-Type
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $contentType = 'image/jpeg'; // 默认
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $contentType = 'image/jpeg';
            break;
        case 'png':
            $contentType = 'image/png';
            break;
        case 'gif':
            $contentType = 'image/gif';
            break;
        case 'webp':
            $contentType = 'image/webp';
            break;
        case 'bmp':
            $contentType = 'image/bmp';
            break;
    }
    
    // 设置响应头
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($result['content']));
    header('Cache-Control: private, max-age=3600'); // 缓存1小时
    header('X-Content-Type-Options: nosniff');
    
    // 输出图片内容
    echo $result['content'];
    
} catch (Exception $e) {
    error_log('获取头像图片失败: ' . $e->getMessage());
    http_response_code(500);
    echo '服务器错误';
}
