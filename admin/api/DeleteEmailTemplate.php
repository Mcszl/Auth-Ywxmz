<?php
/**
 * 删除邮件模板
 * 
 * 功能：
 * - 根据ID删除模板
 * - 验证权限
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
    
    if (!$input || !isset($input['id'])) {
        throw new Exception('缺少模板ID');
    }
    
    $templateId = intval($input['id']);
    
    if ($templateId <= 0) {
        throw new Exception('模板ID无效');
    }
    
    // 检查模板是否存在
    $stmt = $pdo->prepare("
        SELECT id, template_name 
        FROM site_configs.email_template 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        throw new Exception('模板不存在');
    }
    
    // 删除模板
    $stmt = $pdo->prepare("
        DELETE FROM site_configs.email_template 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $templateId]);
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => null,
        'message' => '模板删除成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('删除邮件模板失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
