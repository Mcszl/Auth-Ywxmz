<?php
/**
 * GitHub 账号绑定 API
 * 
 * 功能：发起 GitHub 账号绑定流程
 * 
 * @author 一碗小米周
 * @date 2024-01-26
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

    // 获取 GitHub 登录配置
    $stmt = $pdo->prepare("
        SELECT app_id, callback_url, scopes
        FROM auth.third_party_login_config
        WHERE platform = 'github'
        AND status = 1
        ORDER BY priority ASC
        LIMIT 1
    ");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        throw new Exception('GitHub 登录配置不存在或未启用');
    }

    // 生成 state 参数（防 CSRF 攻击）
    $state = bin2hex(random_bytes(16));
    
    // 将 state 和用户信息存储到 session 中
    $_SESSION['github_bind_state'] = $state;
    $_SESSION['github_bind_user_uuid'] = $userUuid;
    $_SESSION['github_bind_time'] = time();

    // 构建 GitHub 授权 URL
    $authUrl = 'https://github.com/login/oauth/authorize';
    $params = [
        'client_id' => $config['app_id'],
        'redirect_uri' => $config['callback_url'] . '?mode=bind',
        'scope' => $config['scopes'] ?? 'read:user user:email',
        'state' => $state
    ];

    $authUrl .= '?' . http_build_query($params);

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => [
            'auth_url' => $authUrl
        ],
        'message' => '请在新窗口中完成 GitHub 授权'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // 返回错误响应
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
