<?php
/**
 * 绑定微信账号
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
    
    // 从数据库获取微信登录配置
    $stmt = $pdo->prepare("
        SELECT 
            app_id,
            app_secret,
            callback_url,
            extra_config
        FROM auth.third_party_login_config
        WHERE platform = :platform
        AND is_enabled = true
        AND status = 1
        ORDER BY priority ASC
        LIMIT 1
    ");
    
    $stmt->execute(['platform' => 'wechat']);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        echo json_encode([
            'success' => false,
            'message' => '微信登录配置未启用或不存在'
        ]);
        exit;
    }
    
    // 解析额外配置
    $extraConfig = [];
    if (!empty($config['extra_config'])) {
        $extraConfig = json_decode($config['extra_config'], true) ?: [];
    }
    
    // 微信开放平台授权地址
    $authUrl = $extraConfig['auth_url'] ?? 'https://open.weixin.qq.com/connect/qrconnect';
    
    // 生成state参数（防CSRF攻击）
    $state = bin2hex(random_bytes(16));
    
    // 将state和时间戳存储到session中
    $_SESSION['wechat_bind_state'] = $state;
    $_SESSION['wechat_bind_time'] = time();
    
    // 记录日志
    $logger->log('info', 'wechat_bind_start', '发起微信绑定请求', [
        'user_uuid' => $userUuid
    ]);
    
    // 处理scopes字段
    $scopes = $config['scopes'] ?? 'snsapi_login';
    if (is_string($scopes) && strpos($scopes, '[') === 0) {
        $scopesArray = json_decode($scopes, true);
        if (is_array($scopesArray) && !empty($scopesArray)) {
            $scopes = $scopesArray[0];
        } else {
            $scopes = 'snsapi_login';
        }
    }
    
    // 构建授权URL参数
    $params = [
        'appid' => $config['app_id'],
        'redirect_uri' => $config['callback_url'],
        'response_type' => 'code',
        'scope' => $scopes,
        'state' => $state
    ];
    
    // 构建完整的授权URL
    $authorizeUrl = $authUrl . '?' . http_build_query($params) . '#wechat_redirect';
    
    // 返回授权URL
    echo json_encode([
        'success' => true,
        'data' => [
            'authorize_url' => $authorizeUrl
        ],
        'message' => '获取授权地址成功'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log('error', 'wechat_bind_error', '微信绑定失败: ' . $e->getMessage(), [
            'user_uuid' => $_SESSION['user_uuid'] ?? null
        ]);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
