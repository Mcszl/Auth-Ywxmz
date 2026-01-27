<?php
/**
 * 获取人机验证配置详情
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once '../../config/postgresql.config.php';

// 开启会话
session_start();

try {
    // 验证登录状态
    if (!isset($_SESSION['user_uuid'])) {
        throw new Exception('未登录');
    }

    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        throw new Exception($message);
    });
    
    // 获取配置ID
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        throw new Exception('无效的配置ID');
    }
    
    // 查询配置详情
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            provider,
            scenes,
            app_id,
            app_secret,
            site_key,
            secret_key,
            captcha_id,
            captcha_key,
            is_enabled,
            status,
            priority,
            config,
            created_at,
            updated_at
        FROM site_configs.captcha_config
        WHERE id = :id
    ");
    
    $stmt->execute([':id' => $id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('配置不存在');
    }
    
    // 格式化数据
    $config['created_at'] = date('Y-m-d H:i:s', strtotime($config['created_at']));
    $config['updated_at'] = date('Y-m-d H:i:s', strtotime($config['updated_at']));
    
    // 解析场景JSON
    $config['scenes'] = json_decode($config['scenes'], true) ?: [];
    
    // 解析配置JSON
    $config['config'] = json_decode($config['config'], true) ?: [];
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => $config,
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('获取人机验证配置详情失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
