<?php
/**
 * 获取昵称审核配置
 * 
 * @author AI Assistant
 * @date 2024-01-23
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
    
    // 查询昵称审核配置
    $stmt = $pdo->prepare("
        SELECT 
            id,
            is_enabled,
            auto_approve,
            check_sensitive_words,
            max_length,
            min_length,
            allow_special_chars,
            guest_prefix,
            description,
            created_at,
            updated_at,
            created_by,
            updated_by
        FROM site_configs.nickname_check_config
        ORDER BY id DESC
        LIMIT 1
    ");
    
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果没有配置，返回默认值
    if (!$config) {
        $config = [
            'id' => null,
            'is_enabled' => true,
            'auto_approve' => false,
            'check_sensitive_words' => true,
            'max_length' => 20,
            'min_length' => 2,
            'allow_special_chars' => false,
            'guest_prefix' => '游客-',
            'description' => '',
            'created_at' => null,
            'updated_at' => null,
            'created_by' => null,
            'updated_by' => null
        ];
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取配置成功',
        'data' => $config,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('获取昵称审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('获取昵称审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系统错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
