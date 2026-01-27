<?php
/**
 * 解绑QQ账号
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 启动session
session_start();

try {
    // 检查登录状态
    if (!isset($_SESSION['user_uuid'])) {
        echo json_encode([
            'success' => false,
            'message' => '未登录'
        ]);
        exit;
    }
    
    $userUuid = $_SESSION['user_uuid'];
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 检查是否已绑定QQ
    $stmt = $pdo->prepare("
        SELECT id, openid, qq_nickname
        FROM auth.qq_user_info
        WHERE user_uuid = :user_uuid
        AND bind_status = 1
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $qqInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$qqInfo) {
        echo json_encode([
            'success' => false,
            'message' => '您还未绑定QQ账号'
        ]);
        exit;
    }
    
    // 解绑QQ（将bind_status设置为0，保留记录）
    $stmt = $pdo->prepare("
        UPDATE auth.qq_user_info
        SET 
            user_uuid = NULL,
            bind_status = 0,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    
    // 记录日志
    $logger->log(
        'info',
        'unbind_qq_success',
        'QQ账号解绑成功',
        [
            'user_uuid' => $userUuid,
            'openid' => $qqInfo['openid'],
            'nickname' => $qqInfo['qq_nickname']
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'QQ账号解绑成功'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'unbind_qq_error',
            'QQ账号解绑失败: ' . $e->getMessage(),
            [
                'user_uuid' => $_SESSION['user_uuid'] ?? null,
                'error' => $e->getMessage()
            ]
        );
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
