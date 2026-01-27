<?php
/**
 * 管理员审核头像 API
 * 审核通过后将头像从待审核存储移动到正式存储
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../storage/StorageService.php';
require_once __DIR__ . '/../../checks/AvatarCheckService.php';

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
    
    // 从 session 获取管理员 UUID
    $adminUuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($adminUuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $checkId = intval($input['check_id'] ?? 0);
    $action = $input['action'] ?? ''; // approve 或 reject
    $rejectMessage = $input['message'] ?? '';
    
    if ($checkId <= 0) {
        jsonResponse(false, null, '无效的审核ID');
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        jsonResponse(false, null, '无效的操作');
    }
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 创建头像审核服务实例
    $avatarCheckService = new AvatarCheckService($pdo);
    
    // 查询审核记录
    $stmt = $pdo->prepare("
        SELECT * FROM checks.avatar_check 
        WHERE id = :id AND status = 0
    ");
    $stmt->execute(['id' => $checkId]);
    $checkRecord = $stmt->fetch();
    
    if (!$checkRecord) {
        jsonResponse(false, null, '审核记录不存在或已处理');
    }
    
    if ($action === 'reject') {
        // 审核不通过
        if (empty($rejectMessage)) {
            jsonResponse(false, null, '请填写不通过原因');
        }
        
        // 检查是否需要删除头像
        $deleteAvatar = $input['delete_avatar'] ?? false;
        
        if ($deleteAvatar) {
            // 删除待审核的头像文件
            try {
                $filename = $checkRecord['new_avatar_filename'];
                if (!empty($filename)) {
                    $pendingStorage = new StorageService($pdo, 'avatar_pending');
                    $pendingStorage->deleteFile($filename);
                    
                    $logger->info('admin', '删除待审核头像', [
                        'check_id' => $checkId,
                        'filename' => $filename
                    ]);
                }
            } catch (Exception $e) {
                $logger->warning('admin', '删除待审核头像异常', [
                    'check_id' => $checkId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $result = $avatarCheckService->reject($checkId, $rejectMessage, $adminUuid);
        
        if ($result) {
            $logger->info('admin', '头像审核不通过', [
                'check_id' => $checkId,
                'user_uuid' => $checkRecord['user_uuid'],
                'admin_uuid' => $adminUuid,
                'message' => $rejectMessage,
                'deleted' => $deleteAvatar
            ]);
            
            jsonResponse(true, null, '已标记为不通过' . ($deleteAvatar ? '，头像已删除' : ''));
        } else {
            jsonResponse(false, null, '操作失败', 500);
        }
    }
    
    // 审核通过 - 需要移动文件
    $filename = $checkRecord['new_avatar_filename'];
    $storageType = $checkRecord['storage_type'];
    $storageConfigId = $checkRecord['storage_config_id'];
    
    if (empty($filename) || empty($storageType) || empty($storageConfigId)) {
        $logger->error('admin', '审核记录存储信息不完整', [
            'check_id' => $checkId,
            'filename' => $filename,
            'storage_type' => $storageType,
            'storage_config_id' => $storageConfigId,
            'record' => $checkRecord
        ]);
        jsonResponse(false, null, '审核记录存储信息不完整，无法处理。请联系技术人员检查数据库字段是否正确添加。');
    }
    
    // 创建待审核存储服务
    try {
        $pendingStorage = new StorageService($pdo, 'avatar_pending');
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        
        $logger->error('storage', '待审核存储服务初始化失败', [
            'error' => $errorMessage,
            'check_id' => $checkId
        ]);
        
        jsonResponse(false, null, '待审核存储服务不可用：' . $errorMessage);
    }
    
    // 移动文件到正式存储
    // 注意：这里传入的是文件路径，不是URL
    $moveResult = $pendingStorage->moveToStorage($filename, 'avatar');
    
    if (!$moveResult['success']) {
        $logger->error('admin', '移动头像文件失败', [
            'check_id' => $checkId,
            'filename' => $filename,
            'error' => $moveResult['message']
        ]);
        jsonResponse(false, null, '移动文件失败：' . $moveResult['message']);
    }
    
    $finalAvatarUrl = $moveResult['url'];
    
    // 更新用户头像
    try {
        $stmt = $pdo->prepare("
            UPDATE users.user 
            SET avatar = :avatar, updated_at = CURRENT_TIMESTAMP 
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'avatar' => $finalAvatarUrl,
            'uuid' => $checkRecord['user_uuid']
        ]);
        
        // 标记审核通过
        $avatarCheckService->approve($checkId, $adminUuid);
        
        $logger->info('admin', '头像审核通过', [
            'check_id' => $checkId,
            'user_uuid' => $checkRecord['user_uuid'],
            'admin_uuid' => $adminUuid,
            'filename' => $filename,
            'final_url' => $finalAvatarUrl
        ]);
        
        jsonResponse(true, [
            'avatar' => $finalAvatarUrl
        ], '审核通过，头像已更新');
        
    } catch (PDOException $e) {
        $logger->error('admin', '更新用户头像失败', [
            'check_id' => $checkId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '更新用户头像失败', 500);
    }
    
} catch (Exception $e) {
    error_log("头像审核错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
