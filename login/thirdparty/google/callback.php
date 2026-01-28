<?php
/**
 * Google登录 - 授权回调处理
 * 一碗小米周开放平台
 * 
 * 注意：此文件是基于GitHub/QQ登录的简化实现
 * 完整实现请参考 github/callback.php 或 qq/callback.php
 */

// 引入数据库配置
require_once __DIR__ . '/../../../config/postgresql.config.php';
require_once __DIR__ . '/../../../logs/SystemLogger.php';
require_once __DIR__ . '/../../../api/OpenIdService.php';

// 设置响应头
header('Content-Type: text/html; charset=UTF-8');

// 启动session
session_start();

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 验证回调参数
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    
    if (empty($code)) {
        throw new Exception('授权码缺失');
    }
    
    // 验证state参数（防CSRF攻击）
    $isBindMode = false;
    
    if (isset($_SESSION['google_bind_state']) && $state === $_SESSION['google_bind_state']) {
        $isBindMode = true;
        if (!isset($_SESSION['google_bind_time']) || (time() - $_SESSION['google_bind_time']) > 600) {
            throw new Exception('授权已过期，请重新操作');
        }
        if (!isset($_SESSION['user_uuid'])) {
            throw new Exception('请先登录后再绑定Google账号');
        }
        unset($_SESSION['google_bind_state']);
        unset($_SESSION['google_bind_time']);
    } elseif (isset($_SESSION['google_oauth_state']) && $state === $_SESSION['google_oauth_state']) {
        $isBindMode = false;
        if (!isset($_SESSION['google_oauth_time']) || (time() - $_SESSION['google_oauth_time']) > 600) {
            throw new Exception('授权已过期，请重新登录');
        }
        $appId = $_SESSION['google_oauth_app_id'] ?? '';
        $callbackUrl = $_SESSION['google_oauth_callback_url'] ?? '';
        $permissions = $_SESSION['google_oauth_permissions'] ?? '';
        $stateCode = $_SESSION['google_oauth_state_code'] ?? '';
        if (empty($appId) || empty($callbackUrl)) {
            throw new Exception('应用参数缺失');
        }
        unset($_SESSION['google_oauth_state']);
        unset($_SESSION['google_oauth_time']);
    } else {
        throw new Exception('State参数验证失败');
    }
    
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 获取Google登录配置
    $stmt = $pdo->prepare("
        SELECT app_id, app_secret, callback_url, extra_config
        FROM auth.third_party_login_config
        WHERE platform = :platform AND is_enabled = true AND status = 1
        ORDER BY priority ASC LIMIT 1
    ");
    $stmt->execute(['platform' => 'google']);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        throw new Exception('Google登录配置未启用或不存在');
    }
    
    // 解析额外配置
    $extraConfig = [];
    if (!empty($config['extra_config'])) {
        $extraConfig = json_decode($config['extra_config'], true) ?: [];
    }
    $tokenUrl = $extraConfig['token_url'] ?? 'https://oauth2.googleapis.com/token';
    
    // 获取Access Token
    $tokenParams = [
        'code' => $code,
        'client_id' => $config['app_id'],
        'client_secret' => $config['app_secret'],
        'redirect_uri' => $config['callback_url'],
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $tokenResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($tokenResponse === false || $httpCode !== 200) {
        throw new Exception('获取Access Token失败');
    }
    
    $tokenData = json_decode($tokenResponse, true);
    if (!$tokenData || isset($tokenData['error'])) {
        throw new Exception($tokenData['error_description'] ?? '获取Access Token失败');
    }
    
    $accessToken = $tokenData['access_token'] ?? '';
    if (empty($accessToken)) {
        throw new Exception('Access Token为空');
    }
    
    // 获取用户信息
    $userinfoUrl = $extraConfig['userinfo_url'] ?? 'https://www.googleapis.com/oauth2/v2/userinfo';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $userinfoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $userinfoResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($userinfoResponse === false || $httpCode !== 200) {
        throw new Exception('获取用户信息失败');
    }
    
    $userinfo = json_decode($userinfoResponse, true);
    if (!$userinfo || isset($userinfo['error'])) {
        throw new Exception('获取用户信息失败');
    }
    
    $googleId = $userinfo['id'] ?? '';
    $googleEmail = $userinfo['email'] ?? '';
    $googleName = $userinfo['name'] ?? '';
    $googleAvatar = $userinfo['picture'] ?? '';
    $googleVerifiedEmail = $userinfo['verified_email'] ?? false;
    
    if (empty($googleId)) {
        throw new Exception('Google用户ID为空');
    }
    
    $logger->log('info', 'google_login_callback', 'Google登录回调成功', [
        'app_id' => $appId ?? 'bind_mode',
        'google_id' => $googleId,
        'email' => $googleEmail,
        'is_bind_mode' => $isBindMode
    ]);
    
    // ============================================
    // 绑定模式处理
    // ============================================
    if ($isBindMode) {
        $userUuid = $_SESSION['user_uuid'];
        
        // 检查Google ID是否已被其他用户绑定
        $stmt = $pdo->prepare("SELECT user_uuid, bind_status FROM auth.google_user_info WHERE google_id = :google_id");
        $stmt->execute(['google_id' => $googleId]);
        $existingBind = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingBind && $existingBind['bind_status'] == 1 && $existingBind['user_uuid'] != $userUuid) {
            throw new Exception('该Google账号已被其他用户绑定');
        }
        
        // 检查当前用户是否已绑定其他Google账号
        $stmt = $pdo->prepare("SELECT google_id FROM auth.google_user_info WHERE user_uuid = :user_uuid AND bind_status = 1");
        $stmt->execute(['user_uuid' => $userUuid]);
        if ($stmt->fetch()) {
            throw new Exception('您已经绑定了其他Google账号，请先解绑');
        }
        
        $pdo->beginTransaction();
        try {
            if ($existingBind) {
                $stmt = $pdo->prepare("
                    UPDATE auth.google_user_info SET 
                        user_uuid = :user_uuid, google_email = :email, google_name = :name,
                        google_avatar = :avatar, google_verified_email = :verified,
                        access_token = :token, bind_status = 1, updated_at = CURRENT_TIMESTAMP
                    WHERE google_id = :google_id
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO auth.google_user_info (
                        google_id, user_uuid, google_email, google_name, google_avatar,
                        google_verified_email, access_token, bind_status, last_login_at
                    ) VALUES (:google_id, :user_uuid, :email, :name, :avatar, :verified, :token, 1, CURRENT_TIMESTAMP)
                ");
            }
            $stmt->execute([
                'google_id' => $googleId,
                'user_uuid' => $userUuid,
                'email' => $googleEmail,
                'name' => $googleName,
                'avatar' => $googleAvatar,
                'verified' => $googleVerifiedEmail ? 't' : 'f',
                'token' => $accessToken
            ]);
            $pdo->commit();
            
            $logger->log('info', 'bind_google_success', 'Google账号绑定成功', [
                'user_uuid' => $userUuid,
                'google_id' => $googleId
            ]);
            
            // 显示绑定成功页面
            ?>
            <!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Google绑定成功</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                           background: #f5f5f5; display: flex; justify-content: center; align-items: center;
                           min-height: 100vh; margin: 0; padding: 20px; }
                    .success-container { background: white; border-radius: 12px; padding: 40px;
                                       max-width: 500px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                                       text-align: center; }
                    .success-icon { font-size: 64px; color: #52c41a; margin-bottom: 20px; }
                    .avatar { width: 80px; height: 80px; border-radius: 50%; margin: 20px auto;
                             border: 3px solid #52c41a; }
                    h1 { color: #333; font-size: 24px; margin-bottom: 10px; }
                    .name { color: #52c41a; font-size: 20px; font-weight: bold; margin-bottom: 20px; }
                    .success-text { color: #666; font-size: 16px; line-height: 1.8; margin-bottom: 30px; }
                    .btn-back { display: inline-block; padding: 12px 30px; background: #4285f4;
                               color: white; text-decoration: none; border-radius: 6px; font-size: 16px;
                               transition: all 0.3s; }
                    .btn-back:hover { background: #357ae8; transform: translateY(-2px); }
                </style>
            </head>
            <body>
                <div class="success-container">
                    <div class="success-icon">✓</div>
                    <h1>Google账号绑定成功</h1>
                    <?php if (!empty($googleAvatar)): ?>
                    <img src="<?php echo htmlspecialchars($googleAvatar); ?>" alt="头像" class="avatar">
                    <?php endif; ?>
                    <div class="name"><?php echo htmlspecialchars($googleName); ?></div>
                    <div class="success-text">
                        您的Google账号已成功绑定！<br>
                        现在您可以使用Google账号快捷登录本平台。
                    </div>
                    <a href="../../../user/index.html#security" class="btn-back">返回用户中心</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    // ============================================
    // 登录模式处理
    // ============================================
    
    // 检查Google ID是否已绑定账号
    $stmt = $pdo->prepare("
        SELECT gi.*, u.username, u.nickname, u.status as user_status
        FROM auth.google_user_info gi
        LEFT JOIN users.user u ON gi.user_uuid = u.uuid
        WHERE gi.google_id = :google_id
    ");
    $stmt->execute(['google_id' => $googleId]);
    $googleUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($googleUser && $googleUser['bind_status'] == 1 && !empty($googleUser['user_uuid'])) {
        // 已绑定账号，执行登录
        if ($googleUser['user_status'] != 1) {
            throw new Exception('账号状态异常，无法登录');
        }
        
        // 更新Google用户信息
        $stmt = $pdo->prepare("
            UPDATE auth.google_user_info SET 
                google_email = :email, google_name = :name, google_avatar = :avatar,
                google_verified_email = :verified, access_token = :token,
                last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE google_id = :google_id
        ");
        $stmt->execute([
            'email' => $googleEmail,
            'name' => $googleName,
            'avatar' => $googleAvatar,
            'verified' => $googleVerifiedEmail ? 't' : 'f',
            'token' => $accessToken,
            'google_id' => $googleId
        ]);
        
        // 注意：完整的OAuth登录流程需要：
        // 1. 验证应用信息
        // 2. 生成Login Token
        // 3. 获取或创建OpenID
        // 4. 保存Token到数据库
        // 5. 更新用户最后登录信息
        // 6. 设置登录session
        // 7. 重定向到应用回调地址
        // 详细实现请参考 github/callback.php 或 qq/callback.php
        
        throw new Exception('登录功能尚未完全实现，请参考README.md完成callback.php');
        
    } else {
        // 未绑定账号，保存信息并提示绑定
        if (!$googleUser) {
            $stmt = $pdo->prepare("
                INSERT INTO auth.google_user_info (
                    google_id, google_email, google_name, google_avatar,
                    google_verified_email, access_token, bind_status, last_login_at
                ) VALUES (:google_id, :email, :name, :avatar, :verified, :token, 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                'google_id' => $googleId,
                'email' => $googleEmail,
                'name' => $googleName,
                'avatar' => $googleAvatar,
                'verified' => $googleVerifiedEmail ? 't' : 'f',
                'token' => $accessToken
            ]);
        }
        
        $_SESSION['google_temp_info'] = [
            'google_id' => $googleId,
            'email' => $googleEmail,
            'name' => $googleName,
            'avatar' => $googleAvatar
        ];
        
        $logger->log('info', 'google_login_need_bind', 'Google登录需要绑定账号', [
            'app_id' => $appId,
            'google_id' => $googleId
        ]);
    }
    
    // 未绑定账号提示页面
    $returnLoginUrl = '../../index.html';
    if (!empty($appId)) {
        $params = ['app_id' => $appId, 'callback_url' => $callbackUrl ?? '', 
                   'permissions' => $permissions ?? '', 'code' => $stateCode ?? ''];
        $returnLoginUrl .= '?' . http_build_query($params);
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google登录 - 需要绑定账号</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                   background: #f5f5f5; display: flex; justify-content: center; align-items: center;
                   min-height: 100vh; margin: 0; padding: 20px; }
            .notice-container { background: white; border-radius: 12px; padding: 40px;
                              max-width: 500px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                              text-align: center; }
            .notice-icon { font-size: 64px; color: #ff9800; margin-bottom: 20px; }
            .avatar { width: 80px; height: 80px; border-radius: 50%; margin: 20px auto;
                     border: 3px solid #4285f4; }
            h1 { color: #333; font-size: 24px; margin-bottom: 10px; }
            .name { color: #4285f4; font-size: 20px; font-weight: bold; margin-bottom: 20px; }
            .notice-text { color: #666; font-size: 16px; line-height: 1.8; margin-bottom: 30px;
                          text-align: left; background: #fff3e0; padding: 20px; border-radius: 8px;
                          border-left: 4px solid #ff9800; }
            .btn-group { display: flex; gap: 15px; justify-content: center; }
            .btn { display: inline-block; padding: 12px 30px; color: white; text-decoration: none;
                  border-radius: 6px; font-size: 16px; transition: all 0.3s; }
            .btn-primary { background: #4285f4; }
            .btn-primary:hover { background: #357ae8; transform: translateY(-2px); }
            .btn-secondary { background: #999; }
            .btn-secondary:hover { background: #777; transform: translateY(-2px); }
        </style>
    </head>
    <body>
        <div class="notice-container">
            <div class="notice-icon">⚠️</div>
            <h1>Google授权成功</h1>
            <?php if (!empty($googleAvatar)): ?>
            <img src="<?php echo htmlspecialchars($googleAvatar); ?>" alt="头像" class="avatar">
            <?php endif; ?>
            <div class="name"><?php echo htmlspecialchars($googleName); ?></div>
            <div class="notice-text">
                <strong>提示：</strong>您的Google账号尚未绑定本平台账号。<br><br>
                请先登录用户中心，在"账号安全"页面绑定Google账号后，即可使用Google快捷登录功能。
            </div>
            <div class="btn-group">
                <a href="<?php echo htmlspecialchars($returnLoginUrl); ?>" class="btn btn-secondary">返回登录</a>
                <a href="../../../user/index.html" class="btn btn-primary">前往用户中心绑定</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
    
} catch (Exception $e) {
    // 错误处理
    if (isset($logger)) {
        $logger->log('error', 'google_login_callback_error', 'Google登录回调失败: ' . $e->getMessage(), [
            'code' => $_GET['code'] ?? '',
            'state' => $_GET['state'] ?? '',
            'is_bind_mode' => $isBindMode ?? false
        ]);
    }
    
    $errorMessage = htmlspecialchars($e->getMessage());
    
    // 根据模式决定返回URL
    if (isset($isBindMode) && $isBindMode) {
        $returnUrl = '../../../user/index.html#security';
        $btnText = '返回用户中心';
    } else {
        $returnUrl = '../../index.html';
        if (isset($_SESSION['google_oauth_app_id']) && !empty($_SESSION['google_oauth_app_id'])) {
            $params = [
                'app_id' => $_SESSION['google_oauth_app_id'],
                'callback_url' => $_SESSION['google_oauth_callback_url'] ?? '',
                'permissions' => $_SESSION['google_oauth_permissions'] ?? '',
                'code' => $_SESSION['google_oauth_state_code'] ?? ''
            ];
            $returnUrl .= '?' . http_build_query($params);
        }
        $btnText = '返回登录页';
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google<?php echo isset($isBindMode) && $isBindMode ? '绑定' : '登录'; ?>失败</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                   background: #f5f5f5; display: flex; justify-content: center; align-items: center;
                   min-height: 100vh; margin: 0; padding: 20px; }
            .error-container { background: white; border-radius: 12px; padding: 40px;
                             max-width: 500px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                             text-align: center; }
            .error-icon { font-size: 64px; color: #f44336; margin-bottom: 20px; }
            h1 { color: #333; font-size: 24px; margin-bottom: 10px; }
            p { color: #666; font-size: 16px; line-height: 1.6; margin-bottom: 30px; }
            .btn-back { display: inline-block; padding: 12px 30px; background: #4285f4;
                       color: white; text-decoration: none; border-radius: 6px; font-size: 16px;
                       transition: all 0.3s; }
            .btn-back:hover { background: #357ae8; transform: translateY(-2px); }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>Google<?php echo isset($isBindMode) && $isBindMode ? '绑定' : '登录'; ?>失败</h1>
            <p><?php echo $errorMessage; ?></p>
            <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="btn-back"><?php echo $btnText; ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
