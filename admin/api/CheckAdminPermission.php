<?php
/**
 * 检查管理员权限
 * 
 * 功能：
 * 1. 检查用户是否已登录
 * 2. 检查用户权限是否为 admin
 * 3. 返回权限检查结果
 */

header('Content-Type: application/json; charset=utf-8');

// 启动会话
session_start();

require_once __DIR__ . '/../../logs/SystemLogger.php';

try {
    // 检查是否已登录
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => '未登录',
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取用户UUID
    $uuid = $_SESSION['user_uuid'];
    
    // 连接数据库
    require_once __DIR__ . '/../../config/postgresql.config.php';
    
    $pdo = getDBConnection();
    
    if (!$pdo) {
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => '数据库连接失败',
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 查询用户权限
    $stmt = $pdo->prepare("
        SELECT user_type, status, username, nickname, avatar
        FROM users.user
        WHERE uuid = :uuid
    ");
    
    $stmt->execute([
        ':uuid' => $uuid
    ]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // 用户不存在，清除会话
        session_destroy();
        
        $logger->warning('admin', '管理员权限检查失败：用户不存在', [
            'uuid' => $uuid
        ]);
        
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => '用户不存在',
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查账户状态
    if ($user['status'] == 0) {
        $logger->warning('admin', '管理员权限检查失败：账户已被封禁', [
            'uuid' => $uuid,
            'username' => $user['username']
        ]);
        
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => '账户已被封禁',
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查是否是管理员
    if ($user['user_type'] !== 'admin') {
        $logger->warning('admin', '管理员权限检查失败：权限不足', [
            'uuid' => $uuid,
            'username' => $user['username'],
            'user_type' => $user['user_type']
        ]);
        
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => '权限不足，需要管理员权限',
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 权限检查通过
    $logger->info('admin', '管理员权限检查通过', [
        'uuid' => $uuid,
        'username' => $user['username']
    ]);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'user_type' => $user['user_type'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar']
        ],
        'message' => '权限验证通过',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 记录错误日志
    error_log('管理员权限检查失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '管理员权限检查失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => '系统错误，请稍后重试',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 记录错误日志
    error_log('管理员权限检查失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '管理员权限检查失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => '系统错误，请稍后重试',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
