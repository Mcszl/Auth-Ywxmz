<?php
/**
 * 微博登录 - 发起授权请求
 * 一碗小米周授权登录平台
 */

// 引入数据库配置
require_once __DIR__ . '/../../../config/postgresql.config.php';
require_once __DIR__ . '/../../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: text/html; charset=UTF-8');

try {
    // 获取前端传递的参数
    $appId = $_GET['app_id'] ?? '';
    $callbackUrl = $_GET['callback_url'] ?? '';
    $permissions = $_GET['permissions'] ?? '';
    $stateCode = $_GET['state_code'] ?? '';
    
    if (empty($appId)) {
        throw new Exception('缺少应用ID参数');
    }
    
    if (empty($callbackUrl)) {
        throw new Exception('缺少回调地址参数');
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // ============================================
    // 1. 校验应用信息（完整验证）
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            id,
            app_id,
            site_name,
            status,
            enable_login,
            enable_third_party_login,
            enable_weibo_login,
            callback_urls,
            permissions
        FROM site_configs.site_config
        WHERE app_id = :app_id
    ");
    
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$app) {
        $logger->log('error', 'weibo_login_app_not_found', '微博登录失败：应用不存在', ['app_id' => $appId]);
        throw new Exception('应用不存在');
    }
    
    // 检查应用状态
    if ($app['status'] == 0) {
        $logger->log('warning', 'weibo_login_app_banned', '微博登录失败：应用已被封禁', [
            'app_id' => $appId,
            'status' => $app['status']
        ]);
        throw new Exception('应用已被封禁，无法使用登录功能');
    }
    
    if ($app['status'] == 2) {
        $logger->log('warning', 'weibo_login_app_pending', '微博登录失败：应用正在审核中', [
            'app_id' => $appId,
            'status' => $app['status']
        ]);
        throw new Exception('应用正在审核中，无法使用登录功能');
    }
    
    // 检查是否启用登录功能
    if (!$app['enable_login']) {
        $logger->log('warning', 'weibo_login_disabled', '微博登录失败：应用未启用登录功能', ['app_id' => $appId]);
        throw new Exception('该应用未启用登录功能');
    }
    
    // 检查是否开启第三方登录
    if (!$app['enable_third_party_login']) {
        $logger->log('warning', 'weibo_login_third_party_disabled', '微博登录失败：应用未开启第三方登录', ['app_id' => $appId]);
        throw new Exception('该应用未开启第三方登录功能');
    }
    
    // 检查是否开启微博登录
    if (!$app['enable_weibo_login']) {
        $logger->log('warning', 'weibo_login_platform_disabled', '微博登录失败：应用未开启微博登录', ['app_id' => $appId]);
        throw new Exception('该应用未开启微博登录功能');
    }
    
    // ============================================
    // 2. 验证回调域是否在允许列表中
    // ============================================
    $allowedCallbacks = $app['callback_urls'];
    if (is_string($allowedCallbacks)) {
        $allowedCallbacks = trim($allowedCallbacks, '{}');
        $allowedCallbacks = $allowedCallbacks ? explode(',', $allowedCallbacks) : [];
    }
    
    $callbackValid = false;
    foreach ($allowedCallbacks as $allowedUrl) {
        if (strpos($callbackUrl, trim($allowedUrl)) === 0) {
            $callbackValid = true;
            break;
        }
    }
    
    if (!$callbackValid) {
        $logger->log('warning', 'weibo_login_callback_invalid', '微博登录失败：回调地址未授权', [
            'app_id' => $appId,
            'callback_url' => $callbackUrl,
            'allowed_callbacks' => $allowedCallbacks
        ]);
        throw new Exception('回调地址未授权');
    }
    
    // ============================================
    // 3. 验证请求的权限是否被授权
    // ============================================
    if (!empty($permissions)) {
        $configPermissions = $app['permissions'];
        if (is_string($configPermissions)) {
            $configPermissions = trim($configPermissions, '{}');
            $configPermissions = $configPermissions ? explode(',', $configPermissions) : [];
        }
        
        $requestedPermissions = explode(',', $permissions);
        $invalidPermissions = array_diff($requestedPermissions, $configPermissions);
        
        if (!empty($invalidPermissions)) {
            $logger->log('warning', 'weibo_login_permissions_invalid', '微博登录失败：请求的权限未授权', [
                'app_id' => $appId,
                'requested_permissions' => $requestedPermissions,
                'invalid_permissions' => $invalidPermissions
            ]);
            throw new Exception('请求的权限未授权：' . implode(', ', $invalidPermissions));
        }
    }
    
    // ============================================
    // 4. 从数据库获取微博登录配置
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            app_id,
            app_secret,
            callback_url,
            scopes,
            extra_config,
            status,
            is_enabled
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
        $logger->log('error', 'weibo_login_config_not_found', '微博登录失败：微博登录配置未启用或不存在', ['app_id' => $appId]);
        throw new Exception('微博登录配置未启用或不存在');
    }
    
    // 解析额外配置
    $extraConfig = json_decode($config['extra_config'], true);
    if (!$extraConfig) {
        $extraConfig = [];
    }
    
    // 微博授权地址
    $authUrl = $extraConfig['auth_url'] ?? 'https://api.weibo.com/oauth2/authorize';
    
    // 生成state参数（防CSRF攻击）
    $state = bin2hex(random_bytes(16));
    
    // 将所有必要参数存储到session中，供callback.php使用
    session_start();
    $_SESSION['weibo_oauth_state'] = $state;
    $_SESSION['weibo_oauth_time'] = time();
    $_SESSION['weibo_oauth_app_id'] = $appId;
    $_SESSION['weibo_oauth_callback_url'] = $callbackUrl;
    $_SESSION['weibo_oauth_permissions'] = $permissions;
    $_SESSION['weibo_oauth_state_code'] = $stateCode;
    $_SESSION['weibo_config_callback_url'] = $config['callback_url']; // 存储微博配置的回调地址
    
    // 记录日志
    $logger->log('info', 'weibo_login_start', '微博登录发起授权请求', [
        'app_id' => $appId,
        'site_name' => $app['site_name'],
        'callback_url' => $callbackUrl,
        'permissions' => $permissions
    ]);
    
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
    
    // 重定向到微博授权页面
    header('Location: ' . $authorizeUrl);
    exit;
    
} catch (Exception $e) {
    // 错误处理
    $errorMessage = htmlspecialchars($e->getMessage());
    
    // 构建返回登录页的URL，携带登录参数
    $returnLoginUrl = '../../index.html';
    if (!empty($appId)) {
        $params = [
            'app_id' => $appId,
            'callback_url' => $callbackUrl ?? '',
            'permissions' => $permissions ?? '',
            'code' => $stateCode ?? ''
        ];
        $returnLoginUrl .= '?' . http_build_query($params);
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>微博登录错误</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                background: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #f44336;
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                font-size: 24px;
                margin-bottom: 10px;
            }
            p {
                color: #666;
                font-size: 16px;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .btn-back {
                display: inline-block;
                padding: 12px 30px;
                background: #ff8200;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-size: 16px;
                transition: all 0.3s;
            }
            .btn-back:hover {
                background: #e67300;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>微博登录失败</h1>
            <p><?php echo $errorMessage; ?></p>
            <a href="<?php echo htmlspecialchars($returnLoginUrl); ?>" class="btn-back">返回登录页</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
