<?php
/**
 * 头像审核诊断脚本
 * 用于检查存储信息字段是否正确写入
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');

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

try {
    // 检查是否已登录
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 1. 检查表结构
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, column_default, is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'checks' 
        AND table_name = 'avatar_check'
        AND column_name IN ('new_avatar_filename', 'storage_type', 'storage_config_id')
        ORDER BY column_name
    ");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. 检查最近的审核记录
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_uuid,
            new_avatar,
            new_avatar_filename,
            storage_type,
            storage_config_id,
            submitted_at
        FROM checks.avatar_check
        ORDER BY submitted_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. 检查存储配置
    $stmt = $pdo->prepare("
        SELECT 
            id,
            config_name,
            storage_type,
            usage_type,
            enabled
        FROM site_configs.storage_config
        ORDER BY id
    ");
    $stmt->execute();
    $storageConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. 统计存储信息为空的记录数
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN new_avatar_filename = '' THEN 1 ELSE 0 END) as empty_filename,
            SUM(CASE WHEN storage_type = '' THEN 1 ELSE 0 END) as empty_type,
            SUM(CASE WHEN storage_config_id = 0 THEN 1 ELSE 0 END) as empty_config_id
        FROM checks.avatar_check
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'columns' => $columns,
            'recent_records' => $recentRecords,
            'storage_configs' => $storageConfigs,
            'statistics' => $stats
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
