<?php
/**
 * 保存短信配置
 * 
 * 功能：
 * - 新增配置
 * - 编辑配置
 * - 验证必填字段
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
    $requiredFields = ['config_name', 'purpose', 'channel', 'signature', 'template_id', 'credentials'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new Exception('缺少必填字段: ' . $field);
        }
    }
    
    // 获取配置ID（0表示新增）
    $configId = isset($input['id']) ? intval($input['id']) : 0;
    
    // 处理密钥信息（确保是对象）
    $credentials = isset($input['credentials']) ? $input['credentials'] : new stdClass();
    if (is_string($credentials)) {
        $credentials = json_decode($credentials, true) ?: new stdClass();
    }
    
    // 处理渠道配置（确保是对象）
    $channelConfig = isset($input['channel_config']) ? $input['channel_config'] : new stdClass();
    if (is_string($channelConfig)) {
        $channelConfig = json_decode($channelConfig, true) ?: new stdClass();
    }
    
    // 获取其他字段
    $templateContent = isset($input['template_content']) ? trim($input['template_content']) : '';
    $status = isset($input['status']) ? intval($input['status']) : 1;
    $isEnabled = isset($input['is_enabled']) && $input['is_enabled'] ? true : false;
    $priority = isset($input['priority']) ? intval($input['priority']) : 100;
    $dailyLimit = isset($input['daily_limit']) ? intval($input['daily_limit']) : 1000;
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    if ($configId > 0) {
        // 更新配置
        $stmt = $pdo->prepare("
            UPDATE site_configs.sms_config
            SET 
                config_name = :config_name,
                purpose = :purpose,
                channel = :channel,
                signature = :signature,
                template_id = :template_id,
                template_content = :template_content,
                credentials = :credentials,
                channel_config = :channel_config,
                is_enabled = :is_enabled,
                status = :status,
                priority = :priority,
                daily_limit = :daily_limit,
                description = :description
            WHERE id = :id
        ");
        
        $stmt->bindValue(':id', $configId, PDO::PARAM_INT);
        $stmt->bindValue(':config_name', $input['config_name']);
        $stmt->bindValue(':purpose', $input['purpose']);
        $stmt->bindValue(':channel', $input['channel']);
        $stmt->bindValue(':signature', $input['signature']);
        $stmt->bindValue(':template_id', $input['template_id']);
        $stmt->bindValue(':template_content', $templateContent);
        $stmt->bindValue(':credentials', json_encode($credentials, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':channel_config', json_encode($channelConfig, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':daily_limit', $dailyLimit, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description);
        
        $stmt->execute();
        
        $message = '配置更新成功';
        $returnId = $configId;
        
    } else {
        // 新增配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.sms_config (
                config_name,
                purpose,
                channel,
                signature,
                template_id,
                template_content,
                credentials,
                channel_config,
                is_enabled,
                status,
                priority,
                daily_limit,
                description
            ) VALUES (
                :config_name,
                :purpose,
                :channel,
                :signature,
                :template_id,
                :template_content,
                :credentials,
                :channel_config,
                :is_enabled,
                :status,
                :priority,
                :daily_limit,
                :description
            ) RETURNING id
        ");
        
        $stmt->bindValue(':config_name', $input['config_name']);
        $stmt->bindValue(':purpose', $input['purpose']);
        $stmt->bindValue(':channel', $input['channel']);
        $stmt->bindValue(':signature', $input['signature']);
        $stmt->bindValue(':template_id', $input['template_id']);
        $stmt->bindValue(':template_content', $templateContent);
        $stmt->bindValue(':credentials', json_encode($credentials, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':channel_config', json_encode($channelConfig, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':daily_limit', $dailyLimit, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description);
        
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $returnId = $result['id'];
        
        $message = '配置创建成功';
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => ['id' => $returnId],
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('保存短信配置失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
