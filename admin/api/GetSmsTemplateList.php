<?php
/**
 * 获取短信模板列表（用于限制配置选择）
 * 
 * @author 一碗小米粥
 * @date 2025-01-26
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// 只允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许 GET 请求',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 查询所有启用的短信配置
    $sql = "
        SELECT 
            id,
            config_name,
            purpose,
            channel,
            signature,
            template_id,
            template_content
        FROM site_configs.sms_config
        WHERE is_enabled = TRUE
        ORDER BY channel ASC, purpose ASC
    ";
    
    $stmt = $pdo->query($sql);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按渠道分组
    $groupedByChannel = [];
    foreach ($templates as $template) {
        $channel = $template['channel'];
        if (!isset($groupedByChannel[$channel])) {
            $groupedByChannel[$channel] = [];
        }
        $groupedByChannel[$channel][] = $template;
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取模板列表成功',
        'data' => [
            'templates' => $templates,
            'grouped_by_channel' => $groupedByChannel
        ],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('数据库错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('系统错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '系统错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
