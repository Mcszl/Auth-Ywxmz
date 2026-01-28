<?php
/**
 * 绑定微博账号
 * 用户在用户中心发起微博账号绑定
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
    
    // 从数据库获取微博登录配置
    $stmt = $pdo->prepare("
        SELECT 
            app_id,
            callback_url,
            scopes,
            extra_config
        FROM auth.third_party_login_config
        WHERE platform = :platform
        AND is_enabled = true
        AND status = 1
        ORDER BY priority ASC
        LIMIT 1
    ");
    
    $stmt->execute(['platform' => 'weibo']);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('微博登录配置未启用或不存在');
    }
    
    // 解析额外配置
    $extraConfig = [];
    if (!empty($config['extra_config'])) {
        $extraConfig = json_decode($config['extra_config'], true) ?: [];
    }
    
    // 微博授权地址
    $authUrl = $extraConfig['auth_url'] ?? 'https://api.weibo.com/oauth2/authorize';
    
    // 生成state参数（防CSRF攻击）
    $state = bin2hex(random_bytes(16));
    
    // 将绑定模式的参数存储到session中
    $_SESSION['weibo_bind_state'] = $state;
    $_SESSION['weibo_bind_time'] = time();
    $_SESSION['weibo_config_callback_url'] = $config['callback_url']; // 存储微博配置的回调地址
    
    // 构建授权URL参数
    $params = [
        'client_id' => $config['app_id'],
        'redirect_uri' => $config['callback_url'],
        'state' => $state,
        'scope' => $config['scopes'] ?? 'email',
        'response_type' => 'code'
    ];
    
    // 构建完整的授权URL
    $authorizeUrl = $authUrl . '?' . http_build_query($params);
    
    // 记录日志
    $logger->log(
        'info',
        'bind_weibo_start',
        '发起微博账号绑定',
        [
            'user_uuid' => $userUuid,
            'state' => $state
        ]
    );
    
    echo json_encode([
        'success' => true,
        'data' => [
            'authorize_url' => $authorizeUrl
        ],
        'message' => '请前往微博授权页面完成绑定'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'bind_weibo_error',
            '发起微博绑定失败: ' . $e->getMessage(),
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
