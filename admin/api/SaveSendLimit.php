<?php
/**
 * 保存短信发送频率限制（新增/编辑）
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
    $requiredFields = ['limit_name', 'template_id', 'purpose', 'limit_type', 'time_window', 'max_count'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "缺少必填参数：{$field}",
                'data' => null,
                'timestamp' => time()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 获取参数
    $id = isset($data['id']) ? intval($data['id']) : null;
    $limitName = trim($data['limit_name']);
    $templateId = trim($data['template_id']);
    $purpose = trim($data['purpose']);
    $limitType = trim($data['limit_type']);
    $timeWindow = intval($data['time_window']);
    $maxCount = intval($data['max_count']);
    $isEnabled = isset($data['is_enabled']) ? ($data['is_enabled'] ? true : false) : true;
    $priority = isset($data['priority']) ? intval($data['priority']) : 100;
    $description = isset($data['description']) ? trim($data['description']) : '';
    
    // 验证限制类型
    $validTypes = ['phone', 'ip', 'phone_template', 'ip_template', 'global'];
    if (!in_array($limitType, $validTypes)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '无效的限制类型',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 验证时间窗口和最大次数
    if ($timeWindow <= 0 || $maxCount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '时间窗口和最大次数必须大于0',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    if ($id) {
        // 编辑模式：更新现有记录
        $sql = "
            UPDATE sms.send_limit SET
                limit_name = :limit_name,
                template_id = :template_id,
                purpose = :purpose,
                limit_type = :limit_type,
                time_window = :time_window,
                max_count = :max_count,
                is_enabled = :is_enabled,
                priority = :priority,
                description = :description
            WHERE id = :id
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':limit_name' => $limitName,
            ':template_id' => $templateId,
            ':purpose' => $purpose,
            ':limit_type' => $limitType,
            ':time_window' => $timeWindow,
            ':max_count' => $maxCount,
            ':is_enabled' => $isEnabled,
            ':priority' => $priority,
            ':description' => $description
        ]);
        
        // 记录系统日志
        $logger = new SystemLogger($pdo);
        $logger->log(
            'info',
            'operation',
            '编辑短信频率限制',
            [
                'module' => '短信限制配置',
                'action' => '编辑频率限制',
                'details' => [
                    'limit_id' => $id,
                    'limit_name' => $limitName
                ]
            ]
        );
        
        $message = '编辑成功';
        
    } else {
        // 新增模式：插入新记录
        $sql = "
            INSERT INTO sms.send_limit (
                limit_name,
                template_id,
                purpose,
                limit_type,
                time_window,
                max_count,
                is_enabled,
                priority,
                description
            ) VALUES (
                :limit_name,
                :template_id,
                :purpose,
                :limit_type,
                :time_window,
                :max_count,
                :is_enabled,
                :priority,
                :description
            ) RETURNING id
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':limit_name' => $limitName,
            ':template_id' => $templateId,
            ':purpose' => $purpose,
            ':limit_type' => $limitType,
            ':time_window' => $timeWindow,
            ':max_count' => $maxCount,
            ':is_enabled' => $isEnabled,
            ':priority' => $priority,
            ':description' => $description
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $result['id'];
        
        // 记录系统日志
        $logger = new SystemLogger($pdo);
        $logger->log(
            'info',
            'operation',
            '新增短信频率限制',
            [
                'module' => '短信限制配置',
                'action' => '新增频率限制',
                'details' => [
                    'limit_id' => $id,
                    'limit_name' => $limitName
                ]
            ]
        );
        
        $message = '新增成功';
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => ['id' => $id],
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
