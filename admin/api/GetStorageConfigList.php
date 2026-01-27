<?php
/**
 * 获取存储配置列表
 * 
 * @author AI Assistant
 * @date 2024-01-24
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 开启会话
session_start();

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
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
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    
    // 查询存储配置列表
    $stmt = $pdo->prepare("
        SELECT 
            id,
            config_name,
            usage_type,
            storage_type,
            enabled,
            local_path,
            local_url_prefix,
            local_auto_create_path,
            s3_endpoint,
            s3_region,
            s3_bucket,
            s3_path,
            s3_access_key,
            s3_secret_key,
            s3_use_path_style,
            s3_url_prefix,
            s3_auto_create_path,
            max_file_size,
            allowed_extensions,
            created_at,
            updated_at
        FROM site_configs.storage_config
        ORDER BY 
            CASE usage_type
                WHEN 'avatar' THEN 1
                WHEN 'avatar_pending' THEN 2
                WHEN 'document' THEN 3
                WHEN 'temp' THEN 4
                ELSE 5
            END,
            id ASC
    ");
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    foreach ($configs as &$config) {
        // 用途类型名称
        $usageTypeNames = [
            'avatar' => '头像存储',
            'avatar_pending' => '待审核头像',
            'document' => '文档存储',
            'temp' => '临时文件'
        ];
        $config['usage_type_name'] = $usageTypeNames[$config['usage_type']] ?? $config['usage_type'];
        
        // 存储类型名称
        $storageTypeNames = [
            'local' => '本地存储',
            's3' => 'S3对象存储'
        ];
        $config['storage_type_name'] = $storageTypeNames[$config['storage_type']] ?? $config['storage_type'];
        
        // 状态名称
        $config['status_name'] = $config['enabled'] ? '启用' : '禁用';
        
        // 格式化文件大小
        $config['max_file_size_mb'] = round($config['max_file_size'] / 1048576, 2);
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取存储配置列表成功',
        'data' => $configs,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('获取存储配置列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('获取存储配置列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系统错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
