<?php
/**
 * 删除第三方登录配置
 * 
 * 功能：删除指定的第三方登录配置
 * 
 * 请求方式：POST
 * 请求参数（JSON）：
 *   - id: 配置ID
 * 
 * 返回数据：
 *   - success: 是否成功
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
    
    if (!$input || !isset($input['id'])) {
        throw new Exception('缺少配置ID');
    }
    
    $id = intval($input['id']);
    
    if ($id <= 0) {
        throw new Exception('无效的配置ID');
    }
    
    // 查询配置信息（用于日志）
    $stmt = $pdo->prepare("SELECT config_name, platform FROM auth.third_party_login_config WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('配置不存在');
    }
    
    // 删除配置
    $stmt = $pdo->prepare("DELETE FROM auth.third_party_login_config WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 记录日志
    $logger->log(
        'warning',
        'third_party_login_config',
        'delete',
        "删除第三方登录配置: {$config['config_name']} (ID: {$id})",
        $_SESSION['user_uuid'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        ['config_id' => $id, 'platform' => $config['platform']]
    );
    
    // 返回成功
    echo json_encode([
        'success' => true,
        'data' => null,
        'message' => '删除成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('删除第三方登录配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
