<?php
/**
 * 保存昵称审核配置
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
    
    // 获取用户名（用于日志记录）
    $username = $admin['username'];
    
    // 获取 POST 数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 验证必填字段
    $isEnabled = isset($input['is_enabled']) ? (bool)$input['is_enabled'] : true;
    $autoApprove = isset($input['auto_approve']) ? (bool)$input['auto_approve'] : false;
    $checkSensitiveWords = isset($input['check_sensitive_words']) ? (bool)$input['check_sensitive_words'] : true;
    $maxLength = isset($input['max_length']) ? (int)$input['max_length'] : 20;
    $minLength = isset($input['min_length']) ? (int)$input['min_length'] : 2;
    $allowSpecialChars = isset($input['allow_special_chars']) ? (bool)$input['allow_special_chars'] : false;
    $guestPrefix = isset($input['guest_prefix']) ? trim($input['guest_prefix']) : '游客-';
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    // 验证长度范围
    if ($minLength < 1 || $minLength > 50) {
        throw new Exception('最小长度必须在 1-50 之间');
    }
    
    if ($maxLength < $minLength || $maxLength > 100) {
        throw new Exception('最大长度必须大于等于最小长度，且不超过 100');
    }
    
    // 开启事务
    $pdo->beginTransaction();
    
    try {
        // 检查是否已存在配置
        $stmt = $pdo->prepare("
            SELECT id FROM site_configs.nickname_check_config LIMIT 1
        ");
        $stmt->execute();
        $existingConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingConfig) {
            // 更新现有配置
            $stmt = $pdo->prepare("
                UPDATE site_configs.nickname_check_config
                SET 
                    is_enabled = :is_enabled,
                    auto_approve = :auto_approve,
                    check_sensitive_words = :check_sensitive_words,
                    max_length = :max_length,
                    min_length = :min_length,
                    allow_special_chars = :allow_special_chars,
                    guest_prefix = :guest_prefix,
                    description = :description,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':is_enabled' => $isEnabled,
                ':auto_approve' => $autoApprove,
                ':check_sensitive_words' => $checkSensitiveWords,
                ':max_length' => $maxLength,
                ':min_length' => $minLength,
                ':allow_special_chars' => $allowSpecialChars,
                ':guest_prefix' => $guestPrefix,
                ':description' => $description,
                ':updated_by' => $username,
                ':id' => $existingConfig['id']
            ]);
        } else {
            // 插入新配置
            $stmt = $pdo->prepare("
                INSERT INTO site_configs.nickname_check_config (
                    is_enabled, auto_approve, check_sensitive_words,
                    max_length, min_length, allow_special_chars,
                    guest_prefix, description, created_by, updated_by
                ) VALUES (
                    :is_enabled, :auto_approve, :check_sensitive_words,
                    :max_length, :min_length, :allow_special_chars,
                    :guest_prefix, :description, :created_by, :updated_by
                )
            ");
            
            $stmt->execute([
                ':is_enabled' => $isEnabled,
                ':auto_approve' => $autoApprove,
                ':check_sensitive_words' => $checkSensitiveWords,
                ':max_length' => $maxLength,
                ':min_length' => $minLength,
                ':allow_special_chars' => $allowSpecialChars,
                ':guest_prefix' => $guestPrefix,
                ':description' => $description,
                ':created_by' => $username,
                ':updated_by' => $username
            ]);
        }
        
        // 提交事务
        $pdo->commit();
        
        // 返回成功响应
        echo json_encode([
            'success' => true,
            'message' => '保存配置成功',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('保存昵称审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('保存昵称审核配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
