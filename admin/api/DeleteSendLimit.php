<?php
/**
 * 删除短信发送频率限制
 * 
 * @author 一碗小米粥
 * @date 2025-01-26
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许 POST 请求',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取请求数据
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 验证必填字段
    if (!isset($data['id']) || empty($data['id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '缺少必填参数：id',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $id = intval($data['id']);
    
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 查询限制信息
    $checkSql = "SELECT limit_name FROM sms.send_limit WHERE id = :id";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([':id' => $id]);
    $limit = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$limit) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => '频率限制不存在',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 删除限制
    $deleteSql = "DELETE FROM sms.send_limit WHERE id = :id";
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([':id' => $id]);
    
    // 记录系统日志
    $logger = new SystemLogger($pdo);
    $logger->log(
        'info',
        'operation',
        '删除短信频率限制',
        [
            'module' => '短信限制配置',
            'action' => '删除频率限制',
            'details' => [
                'limit_id' => $id,
                'limit_name' => $limit['limit_name']
            ]
        ]
    );
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '删除成功',
        'data' => null,
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
