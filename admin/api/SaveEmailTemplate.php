<?php
/**
 * 保存邮件模板
 * 
 * 功能：
 * - 新增模板
 * - 编辑模板
 * - 验证必填字段
 * - 验证模板标识唯一性
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
    $requiredFields = ['template_code', 'template_name', 'scene', 'subject', 'template_content'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new Exception('缺少必填字段: ' . $field);
        }
    }
    
    // 获取模板ID（0表示新增）
    $templateId = isset($input['id']) ? intval($input['id']) : 0;
    
    // 验证模板标识唯一性
    if ($templateId > 0) {
        // 编辑模式：检查是否有其他模板使用相同标识
        $stmt = $pdo->prepare("
            SELECT id 
            FROM site_configs.email_template 
            WHERE template_code = :template_code AND id != :id
        ");
        $stmt->execute([
            ':template_code' => $input['template_code'],
            ':id' => $templateId
        ]);
    } else {
        // 新增模式：检查标识是否已存在
        $stmt = $pdo->prepare("
            SELECT id 
            FROM site_configs.email_template 
            WHERE template_code = :template_code
        ");
        $stmt->execute([':template_code' => $input['template_code']]);
    }
    
    if ($stmt->fetch()) {
        throw new Exception('模板标识已存在');
    }
    
    // 处理模板变量（确保是数组）
    $templateVariables = isset($input['template_variables']) ? $input['template_variables'] : [];
    if (is_string($templateVariables)) {
        $templateVariables = json_decode($templateVariables, true) ?: [];
    }
    
    // 处理变量说明（确保是对象）
    $variableDescriptions = isset($input['variable_descriptions']) ? $input['variable_descriptions'] : new stdClass();
    if (is_string($variableDescriptions)) {
        $variableDescriptions = json_decode($variableDescriptions, true) ?: new stdClass();
    }
    
    // 获取其他字段
    $status = isset($input['status']) ? intval($input['status']) : 1;
    $isEnabled = isset($input['is_enabled']) && $input['is_enabled'] ? true : false;
    $priority = isset($input['priority']) ? intval($input['priority']) : 100;
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    if ($templateId > 0) {
        // 更新模板
        $stmt = $pdo->prepare("
            UPDATE site_configs.email_template
            SET 
                template_code = :template_code,
                template_name = :template_name,
                scene = :scene,
                subject = :subject,
                template_content = :template_content,
                template_variables = :template_variables,
                variable_descriptions = :variable_descriptions,
                status = :status,
                is_enabled = :is_enabled,
                priority = :priority,
                description = :description
            WHERE id = :id
        ");
        
        $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
        $stmt->bindValue(':template_code', $input['template_code']);
        $stmt->bindValue(':template_name', $input['template_name']);
        $stmt->bindValue(':scene', $input['scene']);
        $stmt->bindValue(':subject', $input['subject']);
        $stmt->bindValue(':template_content', $input['template_content']);
        $stmt->bindValue(':template_variables', json_encode($templateVariables, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':variable_descriptions', json_encode($variableDescriptions, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description);
        
        $stmt->execute();
        
        $message = '模板更新成功';
        $returnId = $templateId;
        
    } else {
        // 新增模板
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.email_template (
                template_code,
                template_name,
                scene,
                subject,
                template_content,
                template_variables,
                variable_descriptions,
                status,
                is_enabled,
                priority,
                description
            ) VALUES (
                :template_code,
                :template_name,
                :scene,
                :subject,
                :template_content,
                :template_variables,
                :variable_descriptions,
                :status,
                :is_enabled,
                :priority,
                :description
            ) RETURNING id
        ");
        
        $stmt->bindValue(':template_code', $input['template_code']);
        $stmt->bindValue(':template_name', $input['template_name']);
        $stmt->bindValue(':scene', $input['scene']);
        $stmt->bindValue(':subject', $input['subject']);
        $stmt->bindValue(':template_content', $input['template_content']);
        $stmt->bindValue(':template_variables', json_encode($templateVariables, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':variable_descriptions', json_encode($variableDescriptions, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':description', $description);
        
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $returnId = $result['id'];
        
        $message = '模板创建成功';
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
    error_log('保存邮件模板失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
