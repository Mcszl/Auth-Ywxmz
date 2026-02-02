<?php
/**
 * 获取邮件模板详情
 * 
 * 功能：
 * - 根据ID获取模板完整信息
 * - 包含模板内容和所有配置
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
    
    // 获取模板ID
    $templateId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($templateId <= 0) {
        throw new Exception('模板ID无效');
    }
    
    // 查询模板详情
    $stmt = $pdo->prepare("
        SELECT 
            id,
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
            description,
            created_at,
            updated_at
        FROM site_configs.email_template
        WHERE id = :id
    ");
    
    $stmt->execute([':id' => $templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        throw new Exception('模板不存在');
    }
    
    // 解析JSON字段
    $template['template_variables'] = json_decode($template['template_variables'], true);
    $template['variable_descriptions'] = json_decode($template['variable_descriptions'], true);
    
    // 格式化时间
    $template['created_at'] = date('Y-m-d H:i:s', strtotime($template['created_at']));
    $template['updated_at'] = date('Y-m-d H:i:s', strtotime($template['updated_at']));
    
    // 添加场景中文名称
    $sceneMap = [
        'register' => '注册',
        'login' => '登录',
        'reset_password' => '重置密码',
        'password_reset' => '重置密码',
        'change_email' => '修改邮箱',
        'change_phone' => '修改手机号',
        'security_alert' => '安全警报',
        'welcome' => '欢迎邮件'
    ];
    $template['scene_name'] = $sceneMap[$template['scene']] ?? $template['scene'];
    
    // 添加状态中文名称
    $statusMap = [
        0 => '禁用',
        1 => '正常',
        2 => '草稿'
    ];
    $template['status_name'] = $statusMap[$template['status']] ?? '未知';
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => $template,
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('获取邮件模板详情失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
