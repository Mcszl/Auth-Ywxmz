<?php
/**
 * 保存白名单（新增/编辑）
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
    if (!isset($data['phone']) || $data['phone'] === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '缺少必填参数：phone',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取参数
    $id = isset($data['id']) ? intval($data['id']) : null;
    $phone = trim($data['phone']);
    $reason = isset($data['reason']) ? trim($data['reason']) : '';
    $isEnabled = isset($data['is_enabled']) ? ($data['is_enabled'] ? true : false) : true;
    $expiresAt = isset($data['expires_at']) && $data['expires_at'] ? $data['expires_at'] : null;
    
    // 验证手机号格式
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '手机号格式不正确',
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
    
    // 获取当前管理员信息
    session_start();
    $createdBy = isset($_SESSION['username']) ? $_SESSION['username'] : null;
    
    if ($id) {
        // 编辑模式：更新现有记录
        $sql = "
            UPDATE sms.whitelist SET
                phone = :phone,
                reason = :reason,
                is_enabled = :is_enabled,
                expires_at = :expires_at
            WHERE id = :id
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':phone' => $phone,
            ':reason' => $reason,
            ':is_enabled' => $isEnabled,
            ':expires_at' => $expiresAt
        ]);
        
        // 记录系统日志
        $logger = new SystemLogger($pdo);
        $logger->log(
            'info',
            'operation',
            '编辑短信白名单',
            [
                'module' => '短信限制配置',
                'action' => '编辑白名单',
                'details' => [
                    'whitelist_id' => $id,
                    'phone' => $phone
                ]
            ]
        );
        
        $message = '编辑成功';
        
    } else {
        // 新增模式：检查是否已存在
        $checkSql = "SELECT id FROM sms.whitelist WHERE phone = :phone";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':phone' => $phone]);
        
        if ($checkStmt->fetch()) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => '该手机号已在白名单中',
                'data' => null,
                'timestamp' => time()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 插入新记录
        $sql = "
            INSERT INTO sms.whitelist (
                phone,
                reason,
                is_enabled,
                expires_at,
                created_by
            ) VALUES (
                :phone,
                :reason,
                :is_enabled,
                :expires_at,
                :created_by
            ) RETURNING id
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':phone' => $phone,
            ':reason' => $reason,
            ':is_enabled' => $isEnabled,
            ':expires_at' => $expiresAt,
            ':created_by' => $createdBy
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $result['id'];
        
        // 记录系统日志
        $logger = new SystemLogger($pdo);
        $logger->log(
            'info',
            'operation',
            '新增短信白名单',
            [
                'module' => '短信限制配置',
                'action' => '新增白名单',
                'details' => [
                    'whitelist_id' => $id,
                    'phone' => $phone
                ]
            ]
        );
        
        $message = '添加成功';
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
