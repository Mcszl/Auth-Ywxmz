<?php
/**
 * 获取头像审核配置
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
    
    // 查询头像审核配置
    $stmt = $pdo->prepare("
        SELECT 
            id,
            enabled,
            check_type,
            api_key,
            api_secret,
            region,
            created_at,
            updated_at
        FROM site_configs.avatar_check_config
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果没有配置，返回默认配置
    if (!$config) {
        $config = [
            'id' => null,
            'enabled' => false,
            'check_type' => 'manual',
            'api_key' => '',
            'api_secret' => '',
            'region' => '',
            'created_at' => null,
            'updated_at' => null
        ];
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取头像审核配置成功',
        'data' => $config,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('获取头像审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('获取头像审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系统错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
