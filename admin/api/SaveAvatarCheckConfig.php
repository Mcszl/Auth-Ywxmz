<?php
/**
 * 保存头像审核配置
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

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '请求方法错误',
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
    
    // 获取 POST 数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 获取配置参数
    $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : false;
    $checkType = isset($input['check_type']) ? trim($input['check_type']) : 'manual';
    $apiKey = isset($input['api_key']) ? trim($input['api_key']) : '';
    $apiSecret = isset($input['api_secret']) ? trim($input['api_secret']) : '';
    $region = isset($input['region']) ? trim($input['region']) : '';
    
    // 验证审核方式
    $validCheckTypes = ['manual', 'tencent', 'aliyun'];
    if (!in_array($checkType, $validCheckTypes)) {
        throw new Exception('无效的审核方式');
    }
    
    // 如果启用了第三方审核，需要验证必填字段
    if ($enabled && $checkType !== 'manual') {
        if (empty($apiKey)) {
            throw new Exception('API密钥不能为空');
        }
        if (empty($apiSecret)) {
            throw new Exception('API密钥不能为空');
        }
    }
    
    // 检查是否已存在配置
    $stmt = $pdo->prepare("
        SELECT id FROM site_configs.avatar_check_config LIMIT 1
    ");
    $stmt->execute();
    $existingConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingConfig) {
        // 更新现有配置
        $stmt = $pdo->prepare("
            UPDATE site_configs.avatar_check_config
            SET enabled = :enabled,
                check_type = :check_type,
                api_key = :api_key,
                api_secret = :api_secret,
                region = :region
            WHERE id = :id
        ");
        
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':check_type', $checkType);
        $stmt->bindValue(':api_key', $apiKey);
        $stmt->bindValue(':api_secret', $apiSecret);
        $stmt->bindValue(':region', $region);
        $stmt->bindValue(':id', $existingConfig['id'], PDO::PARAM_INT);
        
        $stmt->execute();
    } else {
        // 插入新配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.avatar_check_config (
                enabled, check_type, api_key, api_secret, region
            ) VALUES (
                :enabled, :check_type, :api_key, :api_secret, :region
            )
        ");
        
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':check_type', $checkType);
        $stmt->bindValue(':api_key', $apiKey);
        $stmt->bindValue(':api_secret', $apiSecret);
        $stmt->bindValue(':region', $region);
        
        $stmt->execute();
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '保存头像审核配置成功',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('保存头像审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('保存头像审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
