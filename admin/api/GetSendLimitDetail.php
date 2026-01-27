<?php
/**
 * 获取频率限制详情
 * 
 * 功能：获取指定频率限制的详细信息
 * 
 * 请求方式：GET
 * 请求参数：
 *   - id: 频率限制ID
 * 
 * 返回数据：
 *   - success: 是否成功
 *   - data: 频率限制详情
 *   - message: 提示信息
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

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
    
    // 获取参数
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        throw new Exception('参数错误');
    }
    
    // 查询频率限制详情
    $stmt = $pdo->prepare("
        SELECT 
            id,
            limit_name,
            template_id,
            purpose,
            limit_type,
            time_window,
            max_count,
            is_enabled,
            priority,
            description,
            created_at,
            updated_at
        FROM sms.send_limit
        WHERE id = :id
    ");
    
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    $limit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$limit) {
        throw new Exception('频率限制不存在');
    }
    
    // 返回成功
    echo json_encode([
        'success' => true,
        'data' => $limit,
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('获取频率限制详情失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
