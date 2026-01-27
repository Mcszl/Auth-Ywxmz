<?php
/**
 * 解绑微信账号
 * 用户中心 - 账号安全
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 启动session
session_start();

try {
    // 检查用户是否已登录
    if (!isset($_SESSION['user_uuid'])) {
        echo json_encode([
            'success' => false,
            'message' => '请先登录'
        ]);
        exit;
    }
    
    $userUuid = $_SESSION['user_uuid'];
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 检查用户是否已绑定微信
    $stmt = $pdo->prepare("
        SELECT openid, wechat_nickname
        FROM auth.wechat_user_info
        WHERE user_uuid = :user_uuid
        AND bind_status = 1
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $wechatInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wechatInfo) {
        echo json_encode([
            'success' => false,
            'message' => '您还未绑定微信账号'
        ]);
        exit;
    }
    
    // 解绑微信账号（将bind_status设置为0）
    $stmt = $pdo->prepare("
        UPDATE auth.wechat_user_info
        SET 
            bind_status = 0,
            user_uuid = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_uuid = :user_uuid
        AND bind_status = 1
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    
    // 记录日志
    $logger->log('info', 'wechat_unbind_success', '微信账号解绑成功', [
        'user_uuid' => $userUuid,
        'openid' => $wechatInfo['openid'],
        'nickname' => $wechatInfo['wechat_nickname']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => '微信账号解绑成功'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log('error', 'wechat_unbind_error', '微信解绑失败: ' . $e->getMessage(), [
            'user_uuid' => $_SESSION['user_uuid'] ?? null
        ]);
    }
    
    echo json_encode([
        'success' => false,
        'message' => '解绑失败：' . $e->getMessage()
    ]);
}
