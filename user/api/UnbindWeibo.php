<?php
/**
 * 解绑微博账号
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
    
    // 检查是否已绑定微博
    $stmt = $pdo->prepare("
        SELECT uid, weibo_nickname, bind_status
        FROM auth.weibo_user_info
        WHERE user_uuid = :user_uuid
        AND bind_status = 1
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $weiboInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$weiboInfo) {
        throw new Exception('未绑定微博账号');
    }
    
    // 解绑微博账号
    $stmt = $pdo->prepare("
        UPDATE auth.weibo_user_info
        SET 
            bind_status = 0,
            user_uuid = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_uuid = :user_uuid
        AND bind_status = 1
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    
    // 记录日志
    $logger->log(
        'info',
        'unbind_weibo_success',
        '解绑微博账号成功',
        [
            'user_uuid' => $userUuid,
            'uid' => $weiboInfo['uid'],
            'nickname' => $weiboInfo['weibo_nickname']
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '解绑成功'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'unbind_weibo_error',
            '解绑微博账号失败: ' . $e->getMessage(),
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
