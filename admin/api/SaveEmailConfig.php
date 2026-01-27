<?php
/**
 * 保存邮件配置 API（新增或更新）
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 新增或更新邮件配置
 */

header('Content-Type: application/json; charset=utf-8');

// 启动会话
session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

/**
 * 返回 JSON 响应
 */
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查管理员权限
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $adminUuid = $_SESSION['user_uuid'];
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, '无效的请求数据', 400);
    }
    
    $configId = isset($input['id']) ? intval($input['id']) : 0;
    $configName = isset($input['config_name']) ? trim($input['config_name']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    $senderName = isset($input['sender_name']) ? trim($input['sender_name']) : '';
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    $smtpHost = isset($input['smtp_host']) ? trim($input['smtp_host']) : '';
    $smtpPort = isset($input['smtp_port']) ? intval($input['smtp_port']) : 465;
    $encryption = isset($input['encryption']) ? trim($input['encryption']) : 'ssl';
    $scenes = isset($input['scenes']) ? $input['scenes'] : [];
    $dailyLimit = isset($input['daily_limit']) ? intval($input['daily_limit']) : 1000;
    $replyTo = isset($input['reply_to']) ? trim($input['reply_to']) : '';
    // 修复布尔类型转换：确保传递给 PostgreSQL 的是真正的布尔值
    $enableSignature = isset($input['enable_signature']) && $input['enable_signature'] ? true : false;
    $signatureCert = isset($input['signature_cert']) ? trim($input['signature_cert']) : '';
    $signatureKey = isset($input['signature_key']) ? trim($input['signature_key']) : '';
    $status = isset($input['status']) ? intval($input['status']) : 1;
    // 修复布尔类型转换：确保传递给 PostgreSQL 的是真正的布尔值
    $isEnabled = isset($input['is_enabled']) && $input['is_enabled'] ? true : false;
    $priority = isset($input['priority']) ? intval($input['priority']) : 100;
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    // 验证必填字段
    if (empty($configName) || empty($email) || empty($senderName) || empty($username) || empty($smtpHost)) {
        jsonResponse(false, null, '请填写完整信息', 400);
    }
    
    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, '邮箱格式不正确', 400);
    }
    
    // 验证加密方式
    if (!in_array($encryption, ['none', 'ssl', 'tls'])) {
        jsonResponse(false, null, '无效的加密方式', 400);
    }
    
    // 验证状态
    if (!in_array($status, [0, 1, 2])) {
        jsonResponse(false, null, '无效的状态值', 400);
    }
    
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 转换 scenes 为 JSON
    $scenesJson = json_encode($scenes);
    
    // 判断是新增还是更新
    $isUpdate = ($configId > 0);
    
    if ($isUpdate) {
        // 检查配置是否存在
        $stmt = $pdo->prepare("SELECT id, password FROM site_configs.email_config WHERE id = :id");
        $stmt->execute([':id' => $configId]);
        $existingConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingConfig) {
            jsonResponse(false, null, '配置不存在', 404);
        }
        
        // 如果密码是 ****** 则保持原密码不变
        if ($password === '******') {
            $password = $existingConfig['password'];
        }
        
        // 更新配置
        $stmt = $pdo->prepare("
            UPDATE site_configs.email_config SET
                config_name = :config_name,
                email = :email,
                sender_name = :sender_name,
                username = :username,
                password = :password,
                smtp_host = :smtp_host,
                smtp_port = :smtp_port,
                encryption = :encryption,
                scenes = :scenes::jsonb,
                daily_limit = :daily_limit,
                reply_to = :reply_to,
                enable_signature = :enable_signature,
                signature_cert = :signature_cert,
                signature_key = :signature_key,
                status = :status,
                is_enabled = :is_enabled,
                priority = :priority,
                description = :description
            WHERE id = :id
        ");
        
        // 使用 bindValue 确保布尔类型正确传递
        $stmt->bindValue(':id', $configId, PDO::PARAM_INT);
        $stmt->bindValue(':config_name', $configName, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':sender_name', $senderName, PDO::PARAM_STR);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':password', $password, PDO::PARAM_STR);
        $stmt->bindValue(':smtp_host', $smtpHost, PDO::PARAM_STR);
        $stmt->bindValue(':smtp_port', $smtpPort, PDO::PARAM_INT);
        $stmt->bindValue(':encryption', $encryption, PDO::PARAM_STR);
        $stmt->bindValue(':scenes', $scenesJson, PDO::PARAM_STR);
        $stmt->bindValue(':daily_limit', $dailyLimit, PDO::PARAM_INT);
        $stmt->bindValue(':reply_to', $replyTo ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':enable_signature', $enableSignature, PDO::PARAM_BOOL);
        $stmt->bindValue(':signature_cert', $signatureCert ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':signature_key', $signatureKey ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description ?: null, PDO::PARAM_STR);
        $stmt->execute();
        
        $logger->info('admin', '管理员更新邮件配置', [
            'admin' => $admin['username'],
            'config_id' => $configId,
            'config_name' => $configName
        ]);
        
        jsonResponse(true, ['id' => $configId], '更新成功');
        
    } else {
        // 新增配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.email_config (
                config_name, email, sender_name, username, password,
                smtp_host, smtp_port, encryption, scenes, daily_limit,
                reply_to, enable_signature, signature_cert, signature_key,
                status, is_enabled, priority, description
            ) VALUES (
                :config_name, :email, :sender_name, :username, :password,
                :smtp_host, :smtp_port, :encryption, :scenes::jsonb, :daily_limit,
                :reply_to, :enable_signature, :signature_cert, :signature_key,
                :status, :is_enabled, :priority, :description
            ) RETURNING id
        ");
        
        // 使用 bindValue 确保布尔类型正确传递
        $stmt->bindValue(':config_name', $configName, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':sender_name', $senderName, PDO::PARAM_STR);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':password', $password, PDO::PARAM_STR);
        $stmt->bindValue(':smtp_host', $smtpHost, PDO::PARAM_STR);
        $stmt->bindValue(':smtp_port', $smtpPort, PDO::PARAM_INT);
        $stmt->bindValue(':encryption', $encryption, PDO::PARAM_STR);
        $stmt->bindValue(':scenes', $scenesJson, PDO::PARAM_STR);
        $stmt->bindValue(':daily_limit', $dailyLimit, PDO::PARAM_INT);
        $stmt->bindValue(':reply_to', $replyTo ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':enable_signature', $enableSignature, PDO::PARAM_BOOL);
        $stmt->bindValue(':signature_cert', $signatureCert ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':signature_key', $signatureKey ?: null, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description ?: null, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newConfigId = $result['id'];
        
        $logger->info('admin', '管理员新增邮件配置', [
            'admin' => $admin['username'],
            'config_id' => $newConfigId,
            'config_name' => $configName
        ]);
        
        jsonResponse(true, ['id' => $newConfigId], '新增成功');
    }
    
} catch (PDOException $e) {
    error_log('保存邮件配置失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '保存邮件配置失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('保存邮件配置失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '保存邮件配置失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
