<?php
/**
 * 删除已拒绝的头像文件
 * 
 * @author AI Assistant
 * @date 2026-01-24
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../storage/StorageService.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 开启会话
session_start();

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '请求方法错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $checkId = isset($input['check_id']) ? (int)$input['check_id'] : 0;
    
    if ($checkId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => '无效的审核ID',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 查询审核记录
    $stmt = $pdo->prepare("
        SELECT * 
        FROM checks.avatar_check 
        WHERE id = :id AND status = 2
    ");
    $stmt->execute([':id' => $checkId]);
    $checkRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkRecord) {
        echo json_encode([
            'success' => false,
            'message' => '审核记录不存在或状态不正确',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 删除待审核的头像文件
    try {
        $filename = $checkRecord['new_avatar_filename'];
        
        if (empty($filename)) {
            echo json_encode([
                'success' => false,
                'message' => '文件名信息缺失',
                'data' => null,
                'timestamp' => time()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $pendingStorage = new StorageService($pdo, 'avatar_pending');
        $pendingStorage->deleteFile($filename);
        
        // 记录日志
        $logger->info('admin', '删除已拒绝的头像', [
            'check_id' => $checkId,
            'user_uuid' => $checkRecord['user_uuid'],
            'admin_uuid' => $_SESSION['user_uuid'],
            'filename' => $filename
        ]);
        
        // 返回成功响应
        echo json_encode([
            'success' => true,
            'message' => '删除成功',
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $logger->error('admin', '删除待审核头像异常', [
            'check_id' => $checkId,
            'error' => $e->getMessage()
        ]);
        
        echo json_encode([
            'success' => false,
            'message' => '删除头像文件失败：' . $e->getMessage(),
            'data' => null,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('删除已拒绝头像失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('删除已拒绝头像失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '服务器错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
