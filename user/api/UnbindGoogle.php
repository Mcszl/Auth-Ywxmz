<?php
/**
 * Google 账号解绑 API
 * 
 * 功能：解除 Google 账号与系统账号的绑定
 * 
 * @author 一碗小米周
 * @date 2026-01-28
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许 POST 请求'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 开启 session
session_start();

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_uuid'])) {
        throw new Exception('用户未登录');
    }

    $userUuid = $_SESSION['user_uuid'];

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");

    // 开启事务
    $pdo->beginTransaction();

    try {
        // 检查是否已绑定 Google
        $stmt = $pdo->prepare("
            SELECT id FROM auth.google_user_info
            WHERE user_uuid = :user_uuid
        ");
        $stmt->execute([':user_uuid' => $userUuid]);
        $binding = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$binding) {
            throw new Exception('您还未绑定 Google 账号');
        }

        // 删除绑定记录
        $stmt = $pdo->prepare("
            DELETE FROM auth.google_user_info
            WHERE user_uuid = :user_uuid
        ");
        $stmt->execute([':user_uuid' => $userUuid]);

        // 提交事务
        $pdo->commit();

        // 返回成功响应
        echo json_encode([
            'success' => true,
            'data' => null,
            'message' => 'Google 账号解绑成功'
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    // 返回错误响应
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
