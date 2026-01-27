<?php
/**
 * 保存第三方登录配置
 * 
 * 功能：新增或更新第三方登录配置
 * 
 * 请求方式：POST
 * 请求参数（JSON）：
 *   - id: 配置ID（更新时必填）
 *   - config_name: 配置名称
 *   - platform: 第三方平台
 *   - app_id: 应用ID
 *   - app_secret: 应用密钥
 *   - callback_url: 回调地址
 *   - scopes: 授权范围
 *   - status: 状态
 *   - is_enabled: 是否启用
 *   - priority: 优先级
 *   - extra_config: 额外配置
 *   - description: 配置说明
 * 
 * 返回数据：
 *   - success: 是否成功
 *   - data: 配置ID
 *   - message: 提示信息
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 启动会话
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
    $requiredFields = ['config_name', 'platform', 'app_id', 'app_secret', 'callback_url'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            throw new Exception("缺少必填字段: $field");
        }
    }
    
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $configName = trim($input['config_name']);
    $platform = trim($input['platform']);
    $appId = trim($input['app_id']);
    $appSecret = trim($input['app_secret']);
    $callbackUrl = trim($input['callback_url']);
    $scopes = isset($input['scopes']) ? trim($input['scopes']) : '';
    $status = isset($input['status']) ? intval($input['status']) : 1;
    $isEnabled = isset($input['is_enabled']) ? (bool)$input['is_enabled'] : true;
    $priority = isset($input['priority']) ? intval($input['priority']) : 100;
    $extraConfig = isset($input['extra_config']) ? trim($input['extra_config']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    // 验证回调地址格式
    if (!filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
        throw new Exception('回调地址格式不正确');
    }
    
    // 验证JSON格式
    if ($extraConfig !== '' && json_decode($extraConfig) === null) {
        throw new Exception('额外配置必须是有效的JSON格式');
    }
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    if ($id > 0) {
        // 更新配置
        $stmt = $pdo->prepare("
            UPDATE auth.third_party_login_config
            SET 
                config_name = :config_name,
                platform = :platform,
                app_id = :app_id,
                app_secret = :app_secret,
                callback_url = :callback_url,
                scopes = :scopes,
                status = :status,
                is_enabled = :is_enabled,
                priority = :priority,
                extra_config = :extra_config::jsonb,
                description = :description,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':config_name', $configName);
        $stmt->bindParam(':platform', $platform);
        $stmt->bindParam(':app_id', $appId);
        $stmt->bindParam(':app_secret', $appSecret);
        $stmt->bindParam(':callback_url', $callbackUrl);
        $stmt->bindParam(':scopes', $scopes);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindParam(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':extra_config', $extraConfig !== '' ? $extraConfig : null);
        $stmt->bindParam(':description', $description);
        $stmt->execute();
        
        // 记录日志
        $logger->log(
            'info',
            'third_party_login_config',
            'update',
            "更新第三方登录配置: {$configName} (ID: {$id})",
            $_SESSION['user_uuid'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            ['config_id' => $id, 'platform' => $platform]
        );
        
        $message = '更新成功';
        
    } else {
        // 新增配置
        $stmt = $pdo->prepare("
            INSERT INTO auth.third_party_login_config (
                config_name,
                platform,
                app_id,
                app_secret,
                callback_url,
                scopes,
                status,
                is_enabled,
                priority,
                extra_config,
                description
            ) VALUES (
                :config_name,
                :platform,
                :app_id,
                :app_secret,
                :callback_url,
                :scopes,
                :status,
                :is_enabled,
                :priority,
                :extra_config::jsonb,
                :description
            ) RETURNING id
        ");
        
        $stmt->bindParam(':config_name', $configName);
        $stmt->bindParam(':platform', $platform);
        $stmt->bindParam(':app_id', $appId);
        $stmt->bindParam(':app_secret', $appSecret);
        $stmt->bindParam(':callback_url', $callbackUrl);
        $stmt->bindParam(':scopes', $scopes);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindParam(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':extra_config', $extraConfig !== '' ? $extraConfig : null);
        $stmt->bindParam(':description', $description);
        $stmt->execute();
        
        $id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
        
        // 记录日志
        $logger->log(
            'info',
            'third_party_login_config',
            'create',
            "创建第三方登录配置: {$configName} (ID: {$id})",
            $_SESSION['user_uuid'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            ['config_id' => $id, 'platform' => $platform]
        );
        
        $message = '创建成功';
    }
    
    // 返回成功
    echo json_encode([
        'success' => true,
        'data' => ['id' => $id],
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('保存第三方登录配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
