<?php
/**
 * 头像上传 API
 * 用户上传头像文件，如果开启审核则上传到待审核存储
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../storage/StorageService.php';

// 条件引用AvatarCheckService（如果文件存在）
$avatarCheckServicePath = __DIR__ . '/../../checks/AvatarCheckService.php';
$hasAvatarCheckService = file_exists($avatarCheckServicePath);
if ($hasAvatarCheckService) {
    require_once $avatarCheckServicePath;
}

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
    if (!isset($_FILES['avatar']) || empty($_FILES['avatar']['name'])) {
        jsonResponse(false, null, '请选择要上传的头像文件');
    }
    
    $file = $_FILES['avatar'];
    
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
    
    // 查询用户当前头像
    $stmt = $pdo->prepare("SELECT avatar FROM users.user WHERE uuid = :uuid");
    $stmt->execute(['uuid' => $uuid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 检查是否启用头像审核
    $stmt = $pdo->prepare("SELECT enabled, check_type FROM site_configs.avatar_check_config LIMIT 1");
    $stmt->execute();
    $checkConfig = $stmt->fetch();
    
    $needReview = false;
    $usageType = 'avatar'; // 默认使用正式头像存储
    
    if ($checkConfig && $checkConfig['enabled']) {
        // 启用了审核，使用待审核存储
        $needReview = true;
        $usageType = 'avatar_pending';
    }
    
    // 创建存储服务实例
    try {
        $storageService = new StorageService($pdo, $usageType);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        
        $logger->error('storage', '存储服务初始化失败', [
            'error' => $errorMessage,
            'usage_type' => $usageType,
            'uuid' => $uuid
        ]);
        
        // 返回友好的错误信息
        jsonResponse(false, null, $errorMessage);
    }
    
    // 上传文件
    $directory = date('Y/m'); // 只使用日期作为子目录，不再包含 avatars
    $uploadResult = $storageService->uploadFile($file, $directory);
    
    if (!$uploadResult['success']) {
        $logger->warning('storage', '头像上传失败', [
            'uuid' => $uuid,
            'error' => $uploadResult['message']
        ]);
        jsonResponse(false, null, $uploadResult['message']);
    }
    
    $avatarUrl = $uploadResult['url'];
    
    $logger->info('storage', '头像上传成功', [
        'uuid' => $uuid,
        'url' => $avatarUrl,
        'size' => $uploadResult['size'],
        'usage_type' => $usageType
    ]);
    
    // 如果需要审核
    if ($needReview) {
        // 检查AvatarCheckService是否可用
        if (!$hasAvatarCheckService || !class_exists('AvatarCheckService')) {
            $logger->error('user', '头像审核服务不可用', [
                'uuid' => $uuid,
                'avatar' => $avatarUrl,
                'service_exists' => $hasAvatarCheckService,
                'class_exists' => class_exists('AvatarCheckService', false)
            ]);
            jsonResponse(false, null, '头像审核服务暂时不可用，请联系管理员');
        }
        
        // 创建头像审核服务实例
        $avatarCheckService = new AvatarCheckService($pdo);
        
        // 获取存储配置信息
        $storageConfig = $storageService->getConfig();
        
        // 确保获取到必要的字段
        if (!isset($storageConfig['id']) || !isset($storageConfig['storage_type'])) {
            error_log("ERROR: StorageService::getConfig() 返回的数据不完整");
            error_log("storageConfig keys: " . implode(', ', array_keys($storageConfig)));
            error_log("storageConfig dump: " . print_r($storageConfig, true));
            
            $logger->error('user', 'StorageService配置不完整', [
                'uuid' => $uuid,
                'config_keys' => array_keys($storageConfig),
                'config' => $storageConfig
            ]);
            
            jsonResponse(false, null, '存储配置不完整，请联系管理员检查存储配置');
        }
        
        $filename = $uploadResult['path']; // 文件相对路径
        $fullPath = $uploadResult['full_path'] ?? ''; // 完整物理路径
        $storageType = $storageConfig['storage_type'];
        $storageConfigId = $storageConfig['id'];
        
        // 详细记录调试信息
        error_log("=== 头像上传存储信息调试 ===");
        error_log("uploadResult keys: " . implode(', ', array_keys($uploadResult)));
        error_log("storageConfig keys: " . implode(', ', array_keys($storageConfig)));
        error_log("filename (相对路径): " . $filename);
        error_log("fullPath (完整路径): " . $fullPath);
        error_log("storageType: " . $storageType);
        error_log("storageConfigId: " . $storageConfigId);
        error_log("storageType is empty: " . (empty($storageType) ? 'YES' : 'NO'));
        error_log("storageConfigId is empty: " . (empty($storageConfigId) ? 'YES' : 'NO'));
        error_log("storageConfigId type: " . gettype($storageConfigId));
        error_log("=========================");
        
        // 记录调试信息到系统日志
        $logger->info('user', '准备创建头像审核记录', [
            'uuid' => $uuid,
            'avatar_url' => $avatarUrl,
            'filename' => $filename,
            'full_path' => $fullPath,
            'storage_type' => $storageType,
            'storage_config_id' => $storageConfigId,
            'upload_result_keys' => array_keys($uploadResult),
            'storage_config_keys' => array_keys($storageConfig),
            'storage_config_id_type' => gettype($storageConfigId),
            'storage_type_type' => gettype($storageType)
        ]);
        
        // 创建审核记录，传递存储信息
        $checkResult = $avatarCheckService->checkAvatar(
            $avatarUrl, 
            $uuid, 
            $user['avatar'],
            $filename,
            $storageType,
            $storageConfigId
        );
        
        if (!$checkResult['success']) {
            $logger->warning('user', '头像审核记录创建失败', [
                'uuid' => $uuid,
                'avatar' => $avatarUrl,
                'reason' => $checkResult['message'],
                'filename' => $filename,
                'storage_type' => $storageType,
                'storage_config_id' => $storageConfigId
            ]);
            
            // 记录到错误日志
            error_log("头像审核记录创建失败 - UUID: {$uuid}, 原因: {$checkResult['message']}");
            
            jsonResponse(false, null, $checkResult['message']);
        }
        
        $logger->info('user', '头像提交审核', [
            'uuid' => $uuid,
            'old_avatar' => $user['avatar'],
            'new_avatar' => $avatarUrl,
            'filename' => $filename,
            'storage_type' => $storageType
        ]);
        
        jsonResponse(true, [
            'need_review' => true,
            'avatar' => $avatarUrl
        ], '头像已上传并提交审核，请等待管理员审核');
    }
    
    // 不需要审核，直接更新用户头像
    try {
        $stmt = $pdo->prepare("
            UPDATE users.user 
            SET avatar = :avatar, updated_at = CURRENT_TIMESTAMP 
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'avatar' => $avatarUrl,
            'uuid' => $uuid
        ]);
        
        $logger->info('user', '头像更新成功', [
            'uuid' => $uuid,
            'old_avatar' => $user['avatar'],
            'new_avatar' => $avatarUrl
        ]);
        
        jsonResponse(true, [
            'need_review' => false,
            'avatar' => $avatarUrl
        ], '头像上传成功');
        
    } catch (PDOException $e) {
        $logger->error('user', '更新头像失败', [
            'uuid' => $uuid,
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '更新头像失败', 500);
    }
    
} catch (Exception $e) {
    error_log("头像上传错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
