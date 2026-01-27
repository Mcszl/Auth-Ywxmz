<?php
/**
 * 用户中心登录入口
 * 用于用户直接访问用户中心
 */

session_start();

// 如果已经登录，直接跳转到用户中心
if (isset($_SESSION['user_uuid']) && !empty($_SESSION['user_uuid'])) {
    header('Location: /user/');
    exit();
}

// 跳转到登录页面
// 从数据库加载用户中心配置
require_once __DIR__ . '/../config/postgresql.config.php';

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        die('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 查询用户中心配置
    $stmt = $pdo->prepare("
        SELECT 
            app_id,
            callback_url,
            permissions,
            status
        FROM site_configs.user_center_config
        WHERE status = 1
        LIMIT 1
    ");
    $stmt->execute();
    
    $config = $stmt->fetch();
    
    if (!$config) {
        die('用户中心配置不存在，请联系管理员');
    }
    
    // 构建登录页面 URL
    $appId = $config['app_id'];
    $callbackUrl = urlencode($config['callback_url']);
    $permissions = $config['permissions'];
    
    header("Location: https://".$_SERVER['HTTP_HOST']."/login/?app_id={$appId}&callback_url={$callbackUrl}&permissions={$permissions}");
    exit();
    
} catch (Exception $e) {
    error_log("加载用户中心配置错误: " . $e->getMessage());
    die('加载配置失败');
}
