<?php
/**
 * 用户中心登录回调处理
 * 接收登录 token，换取 access_token 和 refresh_token
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

/**
 * 显示错误页面
 */
function showError($message) {
    http_response_code(400);
    echo "<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>登录失败 - 一碗小米周授权登录平台</title>
    <link rel='stylesheet' href='https://fa.2hs.cn/pro/7.0.0/css/all.css'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 60px 40px;
            text-align: center;
            max-width: 480px;
            width: 100%;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .error-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .error-icon i {
            font-size: 48px;
            color: white;
        }
        
        h1 {
            color: #2c3e50;
            font-size: 28px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        
        .error-message {
            color: #7f8c8d;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 40px;
        }
        
        .btn-container {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .btn {
            padding: 14px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-secondary {
            background: #ecf0f1;
            color: #7f8c8d;
        }
        
        .btn-secondary:hover {
            background: #bdc3c7;
            color: #2c3e50;
        }
        
        .divider {
            margin: 30px 0;
            height: 1px;
            background: linear-gradient(to right, transparent, #e0e0e0, transparent);
        }
        
        .help-text {
            color: #95a5a6;
            font-size: 14px;
        }
        
        .help-text a {
            color: #667eea;
            text-decoration: none;
        }
        
        .help-text a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 576px) {
            .error-container {
                padding: 40px 24px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .btn-container {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class='error-container'>
        <div class='error-icon'>
            <i class='fas fa-exclamation-circle'></i>
        </div>
        <h1>登录失败</h1>
        <p class='error-message'>{$message}</p>
        <div class='btn-container'>
            <a href='/user/login.php' class='btn btn-primary'>
                <i class='fas fa-redo'></i>
                <span>重新登录</span>
            </a>
            <a href='/' class='btn btn-secondary'>
                <i class='fas fa-home'></i>
                <span>返回首页</span>
            </a>
        </div>
        <div class='divider'></div>
        <p class='help-text'>
            遇到问题？<a href='/help'>查看帮助文档</a>
        </p>
    </div>
</body>
</html>";
    exit();
}

// ============================================
// 主逻辑
// ============================================

try {
    // 获取 token 参数
    $loginToken = $_GET['token'] ?? '';
    
    if (empty($loginToken)) {
        showError('缺少登录凭证');
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        showError('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 查询用户中心配置和应用密钥
    try {
        // 1. 查询用户中心配置
        $stmt = $pdo->prepare("
            SELECT 
                app_id,
                permissions
            FROM site_configs.user_center_config
            WHERE status = 1
            LIMIT 1
        ");
        $stmt->execute();
        
        $userCenterConfig = $stmt->fetch();
        
        if (!$userCenterConfig) {
            $logger->error('user_center', '用户中心配置不存在');
            showError('系统配置错误，请联系管理员');
        }
        
        $appId = $userCenterConfig['app_id'];
        $permissions = $userCenterConfig['permissions'];
        
        // 2. 根据 app_id 查询应用的 secret_key
        $stmt = $pdo->prepare("
            SELECT secret_key
            FROM site_config
            WHERE app_id = :app_id
            LIMIT 1
        ");
        $stmt->execute(['app_id' => $appId]);
        
        $appConfig = $stmt->fetch();
        
        if (!$appConfig) {
            $logger->error('user_center', '应用配置不存在', ['app_id' => $appId]);
            showError('应用配置不存在，请联系管理员');
        }
        
        $secretKey = $appConfig['secret_key'];
        
    } catch (PDOException $e) {
        $logger->error('user_center', '查询配置失败', [
            'error' => $e->getMessage()
        ]);
        showError('系统错误，请稍后重试');
    }
    
    // 调用 GetAccessToken API
    $apiUrl = 'http://'.$_SERVER['HTTP_HOST'].'/api/GetAccessToken/index.php';
    
    $postData = [
        'app_id' => $appId,
        'secret_key' => $secretKey,
        'token' => $loginToken,
        'permissions' => $permissions
    ];
    
    $logger->info('user_center', '开始换取 Access Token', [
        'app_id' => $appId,
        'login_token' => $loginToken,
        'permissions' => $permissions,
        'api_url' => $apiUrl
    ]);
    
    // 使用 cURL 调用 API
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        $logger->error('user_center', 'API 调用失败', [
            'curl_error' => $curlError
        ]);
        showError('系统错误，请稍后重试');
    }
    
    $logger->info('user_center', 'API 响应', [
        'http_code' => $httpCode,
        'response' => $response
    ]);
    
    $result = json_decode($response, true);
    
    if (!$result) {
        $logger->error('user_center', 'API 响应解析失败', [
            'response' => substr($response, 0, 500),  // 只记录前500个字符
            'json_error' => json_last_error_msg()
        ]);
        // 临时显示原始响应用于调试
        showError('系统错误：响应格式错误。原始响应：' . htmlspecialchars(substr($response, 0, 200)));
    }
    
    if (!$result['success']) {
        $errorMessage = $result['message'] ?? 'Token 换取失败';
        $logger->warning('user_center', 'Access Token 换取失败', [
            'message' => $errorMessage,
            'http_code' => $httpCode,
            'response_data' => $result
        ]);
        showError($errorMessage);
    }
    
    // 获取 token 数据
    $tokenData = $result['data'];
    $accessToken = $tokenData['access_token'];
    $refreshToken = $tokenData['refresh_token'];
    $expiresIn = $tokenData['expires_in'];
    $refreshExpiresIn = $tokenData['refresh_expires_in'];
    $openid = $tokenData['openid'];
    
    // 计算过期时间
    $accessTokenExpiresAt = time() + $expiresIn;
    $refreshTokenExpiresAt = time() + $refreshExpiresIn;
    
    // 从 login_token 表获取用户信息
    try {
        $stmt = $pdo->prepare("
            SELECT user_uuid, username
            FROM tokens.login_token
            WHERE token = :token
            LIMIT 1
        ");
        $stmt->execute(['token' => $loginToken]);
        
        $loginTokenRecord = $stmt->fetch();
        
        if ($loginTokenRecord) {
            $_SESSION['user_uuid'] = $loginTokenRecord['user_uuid'];
            $_SESSION['username'] = $loginTokenRecord['username'];
            
            $logger->info('user_center', '用户信息已从 login_token 获取', [
                'user_uuid' => $loginTokenRecord['user_uuid'],
                'username' => $loginTokenRecord['username']
            ]);
        } else {
            $logger->warning('user_center', '未找到 login_token 记录', [
                'token' => $loginToken
            ]);
        }
        
    } catch (PDOException $e) {
        $logger->warning('user_center', '查询 login_token 失败', [
            'error' => $e->getMessage()
        ]);
    }
    
    // 存储到 session
    $_SESSION['user_center'] = [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'openid' => $openid,
        'permissions' => $tokenData['permissions'],
        'access_token_expires_at' => $accessTokenExpiresAt,
        'refresh_token_expires_at' => $refreshTokenExpiresAt,
        'created_at' => time()
    ];
    
    $logger->info('user_center', 'Token 信息已存储到 session', [
        'openid' => $openid,
        'access_token_expires_at' => date('Y-m-d H:i:s', $accessTokenExpiresAt),
        'refresh_token_expires_at' => date('Y-m-d H:i:s', $refreshTokenExpiresAt),
        'session_user_uuid' => $_SESSION['user_uuid'] ?? 'not set'
    ]);
    
    $logger->info('user_center', '用户登录成功', [
        'openid' => $openid,
        'user_uuid' => $_SESSION['user_uuid'] ?? 'unknown',
        'access_token_expires_at' => date('Y-m-d H:i:s', $accessTokenExpiresAt),
        'refresh_token_expires_at' => date('Y-m-d H:i:s', $refreshTokenExpiresAt)
    ]);
    
    // 跳转到用户中心
    header('Location: /user/');
    exit();
    
} catch (Exception $e) {
    error_log("用户中心回调处理错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo && isset($logger)) {
            $logger->critical('user_center', '回调处理异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $e->getTraceAsString());
        }
    } catch (Exception $logException) {
        error_log("记录系统日志失败: " . $logException->getMessage());
    }
    
    showError('系统错误，请稍后重试');
}
