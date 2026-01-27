<?php
/**
 * 获取短信配置详情
 * 
 * 功能：
 * - 根据ID获取配置完整信息
 * - 包含密钥信息（脱敏显示）
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
    
    // 获取配置ID
    $configId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($configId <= 0) {
        throw new Exception('配置ID无效');
    }
    
    // 查询配置详情
    $stmt = $pdo->prepare("
        SELECT 
            id,
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
            daily_sent_count,
            last_reset_date,
            description,
            created_at,
            updated_at
        FROM site_configs.sms_config
        WHERE id = :id
    ");
    
    $stmt->execute([':id' => $configId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('配置不存在');
    }
    
    // 解析JSON字段
    $config['credentials'] = json_decode($config['credentials'], true);
    $config['channel_config'] = json_decode($config['channel_config'], true);
    
    // 格式化时间
    $config['created_at'] = date('Y-m-d H:i:s', strtotime($config['created_at']));
    $config['updated_at'] = date('Y-m-d H:i:s', strtotime($config['updated_at']));
    
    // 添加用途中文名称
    $purposeMap = [
        'register' => '注册',
        'login' => '登录',
        'reset_password' => '重置密码',
        'security_alert' => '安全警报',
        'verify' => '验证'
    ];
    $config['purpose_name'] = $purposeMap[$config['purpose']] ?? $config['purpose'];
    
    // 添加渠道中文名称
    $channelMap = [
        'aliyun' => '阿里云',
        'tencent' => '腾讯云',
        '321cn' => '321.com.cn'
    ];
    $config['channel_name'] = $channelMap[$config['channel']] ?? $config['channel'];
    
    // 添加状态中文名称
    $statusMap = [
        0 => '禁用',
        1 => '正常',
        2 => '维护中'
    ];
    $config['status_name'] = $statusMap[$config['status']] ?? '未知';
    
    // 计算剩余额度
    $config['remaining_quota'] = max(0, $config['daily_limit'] - $config['daily_sent_count']);
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => $config,
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('获取短信配置详情失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
