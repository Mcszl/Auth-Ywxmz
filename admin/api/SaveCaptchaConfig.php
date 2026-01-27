<?php
/**
 * 保存人机验证配置（新增/编辑）
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
    
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 验证必填字段
    $requiredFields = ['name', 'provider', 'scenes'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            throw new Exception("字段 {$field} 不能为空");
        }
    }
    
    // 验证场景数据
    if (!is_array($input['scenes']) || empty($input['scenes'])) {
        throw new Exception('至少选择一个适用场景');
    }
    
    // 转换场景为JSON
    $scenesJson = json_encode($input['scenes']);
    
    // 处理配置JSON
    $configJson = isset($input['config']) && is_array($input['config']) ? json_encode($input['config']) : '{}';
    
    // 判断是新增还是编辑
    $id = isset($input['id']) ? intval($input['id']) : 0;
    
    if ($id > 0) {
        // 编辑配置
        $stmt = $pdo->prepare("
            UPDATE site_configs.captcha_config
            SET 
                name = :name,
                provider = :provider,
                scenes = :scenes,
                app_id = :app_id,
                app_secret = :app_secret,
                site_key = :site_key,
                secret_key = :secret_key,
                captcha_id = :captcha_id,
                captcha_key = :captcha_key,
                is_enabled = :is_enabled,
                status = :status,
                priority = :priority,
                config = :config,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':name', trim($input['name']));
        $stmt->bindValue(':provider', trim($input['provider']));
        $stmt->bindValue(':scenes', $scenesJson);
        $stmt->bindValue(':app_id', isset($input['app_id']) ? trim($input['app_id']) : null);
        $stmt->bindValue(':app_secret', isset($input['app_secret']) ? trim($input['app_secret']) : null);
        $stmt->bindValue(':site_key', isset($input['site_key']) ? trim($input['site_key']) : null);
        $stmt->bindValue(':secret_key', isset($input['secret_key']) ? trim($input['secret_key']) : null);
        $stmt->bindValue(':captcha_id', isset($input['captcha_id']) ? trim($input['captcha_id']) : null);
        $stmt->bindValue(':captcha_key', isset($input['captcha_key']) ? trim($input['captcha_key']) : null);
        $stmt->bindValue(':is_enabled', isset($input['is_enabled']) ? (bool)$input['is_enabled'] : true, PDO::PARAM_BOOL);
        $stmt->bindValue(':status', isset($input['status']) ? intval($input['status']) : 1, PDO::PARAM_INT);
        $stmt->bindValue(':priority', isset($input['priority']) ? intval($input['priority']) : 0, PDO::PARAM_INT);
        $stmt->bindValue(':config', $configJson);
        
        $stmt->execute();
        
        $message = '配置更新成功';
        
    } else {
        // 新增配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.captcha_config (
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
            ) VALUES (
                :name,
                :provider,
                :scenes,
                :app_id,
                :app_secret,
                :site_key,
                :secret_key,
                :captcha_id,
                :captcha_key,
                :is_enabled,
                :status,
                :priority,
                :config,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        
        $stmt->bindValue(':name', trim($input['name']));
        $stmt->bindValue(':provider', trim($input['provider']));
        $stmt->bindValue(':scenes', $scenesJson);
        $stmt->bindValue(':app_id', isset($input['app_id']) ? trim($input['app_id']) : null);
        $stmt->bindValue(':app_secret', isset($input['app_secret']) ? trim($input['app_secret']) : null);
        $stmt->bindValue(':site_key', isset($input['site_key']) ? trim($input['site_key']) : null);
        $stmt->bindValue(':secret_key', isset($input['secret_key']) ? trim($input['secret_key']) : null);
        $stmt->bindValue(':captcha_id', isset($input['captcha_id']) ? trim($input['captcha_id']) : null);
        $stmt->bindValue(':captcha_key', isset($input['captcha_key']) ? trim($input['captcha_key']) : null);
        $stmt->bindValue(':is_enabled', isset($input['is_enabled']) ? (bool)$input['is_enabled'] : true, PDO::PARAM_BOOL);
        $stmt->bindValue(':status', isset($input['status']) ? intval($input['status']) : 1, PDO::PARAM_INT);
        $stmt->bindValue(':priority', isset($input['priority']) ? intval($input['priority']) : 0, PDO::PARAM_INT);
        $stmt->bindValue(':config', $configJson);
        
        $stmt->execute();
        
        $id = $pdo->lastInsertId();
        $message = '配置创建成功';
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => ['id' => $id],
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('保存人机验证配置失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
