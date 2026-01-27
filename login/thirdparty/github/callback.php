<?php
/**
 * GitHub登录 - 授权回调处理
 * 一碗小米周授权登录平台
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
    // 支持两种模式：登录模式和绑定模式
    $isBindMode = false;
    
    if (isset($_SESSION['github_bind_state']) && $state === $_SESSION['github_bind_state']) {
        // 绑定模式
        $isBindMode = true;
        
        // 检查state是否过期（10分钟有效期）
        if (!isset($_SESSION['github_bind_time']) || (time() - $_SESSION['github_bind_time']) > 600) {
            throw new Exception('授权已过期，请重新操作');
        }
        
        // 检查是否已登录
        if (!isset($_SESSION['user_uuid'])) {
            throw new Exception('请先登录后再绑定GitHub账号');
        }
        
        // 清除session中的state
        unset($_SESSION['github_bind_state']);
        unset($_SESSION['github_bind_time']);
        
    } elseif (isset($_SESSION['github_oauth_state']) && $state === $_SESSION['github_oauth_state']) {
        // 登录模式
        $isBindMode = false;
        
        // 检查state是否过期（10分钟有效期）
        if (!isset($_SESSION['github_oauth_time']) || (time() - $_SESSION['github_oauth_time']) > 600) {
            throw new Exception('授权已过期，请重新登录');
        }
        
        // 从session中获取所有必要参数
        $appId = $_SESSION['github_oauth_app_id'] ?? '';
        $callbackUrl = $_SESSION['github_oauth_callback_url'] ?? '';
        $permissions = $_SESSION['github_oauth_permissions'] ?? '';
        $stateCode = $_SESSION['github_oauth_state_code'] ?? '';
        
        if (empty($appId)) {
            throw new Exception('应用ID缺失');
        }
        
        if (empty($callbackUrl)) {
            throw new Exception('回调地址缺失');
        }
        
        // 清除session中的state和time
        unset($_SESSION['github_oauth_state']);
        unset($_SESSION['github_oauth_time']);
        // 保留其他参数，后续使用
        
    } else {
        throw new Exception('State参数验证失败，可能存在CSRF攻击');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 从数据库获取GitHub登录配置
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
    
    $stmt->execute(['platform' => 'github']);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('GitHub登录配置未启用或不存在');
    }
    
    // 解析额外配置
    $extraConfig = json_decode($config['extra_config'], true);
    if (!$extraConfig) {
        $extraConfig = [];
    }
    
    // GitHub OAuth Token接口地址
    $tokenUrl = $extraConfig['token_url'] ?? 'https://github.com/login/oauth/access_token';
    
    // 构建获取Access Token的参数
    $tokenParams = [
        'client_id' => $config['app_id'],
        'client_secret' => $config['app_secret'],
        'code' => $code,
        'redirect_uri' => $config['callback_url']
    ];
    
    // 使用POST请求获取Access Token
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                       "Accept: application/json\r\n" .
                       "User-Agent: PHP-OAuth-Client\r\n",
            'content' => http_build_query($tokenParams),
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $tokenResponse = @file_get_contents($tokenUrl, false, $context);
    
    // 记录详细的请求和响应信息
    $logger->log(
        'debug',
        'github_token_request',
        'GitHub Token请求',
        [
            'url' => $tokenUrl,
            'params' => [
                'client_id' => $config['app_id'],
                'code' => substr($code, 0, 10) . '...',
                'redirect_uri' => $config['callback_url']
            ],
            'response' => $tokenResponse ? substr($tokenResponse, 0, 200) : 'empty',
            'http_response_header' => $http_response_header ?? []
        ]
    );
    
    if ($tokenResponse === false) {
        $error = error_get_last();
        $logger->log(
            'error',
            'github_token_request_failed',
            'GitHub Token请求失败',
            [
                'error' => $error['message'] ?? 'Unknown error',
                'url' => $tokenUrl
            ]
        );
        throw new Exception('获取Access Token失败：网络请求错误');
    }
    
    $tokenData = json_decode($tokenResponse, true);
    
    if (!$tokenData) {
        $logger->log(
            'error',
            'github_token_parse_failed',
            'GitHub Token响应解析失败',
            [
                'response' => $tokenResponse
            ]
        );
        throw new Exception('获取Access Token失败：响应格式错误');
    }
    
    if (isset($tokenData['error'])) {
        $errorMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? '获取Access Token失败';
        $logger->log(
            'error',
            'github_token_error',
            'GitHub返回错误',
            [
                'error' => $tokenData['error'],
                'error_description' => $tokenData['error_description'] ?? '',
                'error_uri' => $tokenData['error_uri'] ?? ''
            ]
        );
        throw new Exception($errorMsg);
    }
    
    $accessToken = $tokenData['access_token'] ?? '';
    
    if (empty($accessToken)) {
        $logger->log(
            'error',
            'github_token_empty',
            'GitHub Token为空',
            [
                'response' => $tokenData
            ]
        );
        throw new Exception('Access Token为空');
    }
    
    // 获取GitHub用户信息
    $userinfoUrl = $extraConfig['userinfo_url'] ?? 'https://api.github.com/user';
    
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: token {$accessToken}\r\nUser-Agent: PHP\r\nAccept: application/json\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    $userinfoResponse = file_get_contents($userinfoUrl, false, $context);
    
    if ($userinfoResponse === false) {
        throw new Exception('获取用户信息失败');
    }
    
    $userinfo = json_decode($userinfoResponse, true);
    
    if (!$userinfo || !isset($userinfo['id'])) {
        throw new Exception('获取用户信息失败');
    }
    
    // 记录完整的用户信息用于调试
    $logger->log(
        'debug',
        'github_userinfo_response',
        'GitHub用户信息响应',
        [
            'userinfo' => $userinfo
        ]
    );
    
    // 记录日志
    $logger->log(
        'info',
        'github_login_callback',
        'GitHub登录回调成功',
        [
            'app_id' => $appId ?? 'bind_mode',
            'github_id' => $userinfo['id'],
            'login' => $userinfo['login'] ?? '',
            'avatar_url' => $userinfo['avatar_url'] ?? 'not_found',
            'is_bind_mode' => $isBindMode
        ]
    );
    
    // 准备GitHub用户信息
    $githubId = (string)$userinfo['id'];
    $githubLogin = $userinfo['login'] ?? '';
    $githubName = $userinfo['name'] ?? '';
    $githubAvatar = $userinfo['avatar_url'] ?? '';
    $githubEmail = $userinfo['email'] ?? '';
    $githubBio = $userinfo['bio'] ?? '';
    
    // ============================================
    // 绑定模式处理
    // ============================================
    if ($isBindMode) {
        $userUuid = $_SESSION['user_uuid'];
        
        // 检查这个GitHub ID是否已经被其他用户绑定
        $stmt = $pdo->prepare("
            SELECT user_uuid, bind_status
            FROM auth.github_user_info
            WHERE github_id = :github_id
        ");
        
        $stmt->execute(['github_id' => $githubId]);
        $existingBind = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingBind && $existingBind['bind_status'] == 1 && $existingBind['user_uuid'] != $userUuid) {
            throw new Exception('该GitHub账号已被其他用户绑定');
        }
        
        // 检查当前用户是否已经绑定了其他GitHub
        $stmt = $pdo->prepare("
            SELECT github_id
            FROM auth.github_user_info
            WHERE user_uuid = :user_uuid
            AND bind_status = 1
        ");
        
        $stmt->execute(['user_uuid' => $userUuid]);
        $currentBind = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentBind) {
            throw new Exception('您已经绑定了其他GitHub账号，请先解绑');
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        try {
            if ($existingBind) {
                // 更新绑定
                $stmt = $pdo->prepare("
                    UPDATE auth.github_user_info
                    SET 
                        user_uuid = :user_uuid,
                        github_login = :github_login,
                        github_name = :github_name,
                        github_avatar = :github_avatar,
                        github_email = :github_email,
                        github_bio = :github_bio,
                        access_token = :access_token,
                        bind_status = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE github_id = :github_id
                ");
                
                $stmt->execute([
                    'user_uuid' => $userUuid,
                    'github_login' => $githubLogin,
                    'github_name' => $githubName,
                    'github_avatar' => $githubAvatar,
                    'github_email' => $githubEmail,
                    'github_bio' => $githubBio,
                    'access_token' => $accessToken,
                    'github_id' => $githubId
                ]);
            } else {
                // 插入新记录
                $stmt = $pdo->prepare("
                    INSERT INTO auth.github_user_info (
                        github_id,
                        user_uuid,
                        github_login,
                        github_name,
                        github_avatar,
                        github_email,
                        github_bio,
                        access_token,
                        bind_status,
                        last_login_at
                    ) VALUES (
                        :github_id,
                        :user_uuid,
                        :github_login,
                        :github_name,
                        :github_avatar,
                        :github_email,
                        :github_bio,
                        :access_token,
                        1,
                        CURRENT_TIMESTAMP
                    )
                ");
                
                $stmt->execute([
                    'github_id' => $githubId,
                    'user_uuid' => $userUuid,
                    'github_login' => $githubLogin,
                    'github_name' => $githubName,
                    'github_avatar' => $githubAvatar,
                    'github_email' => $githubEmail,
                    'github_bio' => $githubBio,
                    'access_token' => $accessToken
                ]);
            }
            
            // 提交事务
            $pdo->commit();
            
            // 记录日志
            $logger->log(
                'info',
                'bind_github_success',
                'GitHub账号绑定成功',
                [
                    'user_uuid' => $userUuid,
                    'github_id' => $githubId,
                    'login' => $githubLogin
                ]
            );
            
            // 绑定成功，显示成功页面
            ?>
            <!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>GitHub绑定成功</title>
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
                    .success-container {
                        background: white;
                        border-radius: 12px;
                        padding: 40px;
                        max-width: 500px;
                        width: 100%;
                        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
                        text-align: center;
                    }
                    .success-icon {
                        font-size: 64px;
                        color: #52c41a;
                        margin-bottom: 20px;
                    }
                    .avatar {
                        width: 80px;
                        height: 80px;
                        border-radius: 50%;
                        margin: 20px auto;
                        border: 3px solid #52c41a;
                    }
                    h1 {
                        color: #333;
                        font-size: 24px;
                        margin-bottom: 10px;
                    }
                    .nickname {
                        color: #52c41a;
                        font-size: 20px;
                        font-weight: bold;
                        margin-bottom: 20px;
                    }
                    .success-text {
                        color: #666;
                        font-size: 16px;
                        line-height: 1.8;
                        margin-bottom: 30px;
                    }
                    .btn-back {
                        display: inline-block;
                        padding: 12px 30px;
                        background: #667eea;
                        color: white;
                        text-decoration: none;
                        border-radius: 6px;
                        font-size: 16px;
                        transition: all 0.3s;
                    }
                    .btn-back:hover {
                        background: #5568d3;
                        transform: translateY(-2px);
                    }
                </style>
            </head>
            <body>
                <div class="success-container">
                    <div class="success-icon">✓</div>
                    <h1>GitHub账号绑定成功</h1>
                    <?php if (!empty($githubAvatar)): ?>
                    <img src="<?php echo htmlspecialchars($githubAvatar); ?>" alt="头像" class="avatar">
                    <?php endif; ?>
                    <div class="nickname"><?php echo htmlspecialchars($githubLogin); ?></div>
                    <div class="success-text">
                        您的GitHub账号已成功绑定！<br>
                        现在您可以使用GitHub账号快捷登录本平台。
                    </div>
                    <a href="../../../user/index.html#security" class="btn-back">返回用户中心</a>
                </div>
            </body>
            </html>
            <?php
            exit;
            
        } catch (Exception $e) {
            // 回滚事务
            $pdo->rollBack();
            throw $e;
        }
    }
    
    // ============================================
    // 登录模式处理
    // ============================================
    
    // ============================================
    // 3. 检查GitHub ID是否已绑定账号
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            gi.id,
            gi.github_id,
            gi.user_uuid,
            gi.bind_status,
            u.username,
            u.nickname as user_nickname,
            u.status as user_status
        FROM auth.github_user_info gi
        LEFT JOIN users.user u ON gi.user_uuid = u.uuid
        WHERE gi.github_id = :github_id
    ");
    
    $stmt->execute(['github_id' => $githubId]);
    $githubUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($githubUser && $githubUser['bind_status'] == 1 && !empty($githubUser['user_uuid'])) {
        // ============================================
        // 4. 已绑定账号，执行登录流程
        // ============================================
        
        // 检查用户状态
        if ($githubUser['user_status'] != 1) {
            $statusText = ['0' => '已封禁', '2' => '待验证'][$githubUser['user_status']] ?? '状态异常';
            throw new Exception("账号{$statusText}，无法登录");
        }
        
        // 更新GitHub用户信息和最后登录时间
        $stmt = $pdo->prepare("
            UPDATE auth.github_user_info
            SET 
                github_login = :github_login,
                github_name = :github_name,
                github_avatar = :github_avatar,
                github_email = :github_email,
                github_bio = :github_bio,
                access_token = :access_token,
                last_login_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE github_id = :github_id
        ");
        
        $stmt->execute([
            'github_login' => $githubLogin,
            'github_name' => $githubName,
            'github_avatar' => $githubAvatar,
            'github_email' => $githubEmail,
            'github_bio' => $githubBio,
            'access_token' => $accessToken,
            'github_id' => $githubId
        ]);
        
        // ============================================
        // 完整的OAuth登录流程
        // ============================================
        
        // 1. 验证应用信息
        $stmt = $pdo->prepare("
            SELECT * FROM site_configs.site_config 
            WHERE app_id = :app_id
        ");
        $stmt->execute(['app_id' => $appId]);
        $appConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appConfig) {
            throw new Exception('应用不存在');
        }
        
        // 验证应用状态
        if ($appConfig['status'] == 0) {
            throw new Exception('应用已被封禁');
        }
        
        if ($appConfig['status'] == 2) {
            throw new Exception('应用正在审核中');
        }
        
        // 验证是否启用第三方登录
        if (!$appConfig['enable_third_party_login']) {
            throw new Exception('应用未启用第三方登录功能');
        }
        
        // 验证是否启用GitHub登录
        if (!$appConfig['enable_github_login']) {
            throw new Exception('应用未启用GitHub登录功能');
        }
        
        // 2. 获取用户完整信息
        $stmt = $pdo->prepare("
            SELECT * FROM users.user 
            WHERE uuid = :uuid AND status = 1
        ");
        $stmt->execute(['uuid' => $githubUser['user_uuid']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('用户不存在或已被禁用');
        }
        
        // 3. 生成 Login Token
        $token = 'LT_' . date('YmdHis') . '_' . bin2hex(random_bytes(16));
        $validityPeriod = 900; // 15分钟
        $expiresAt = date('Y-m-d H:i:s', time() + $validityPeriod);
        
        // 4. 获取或创建用户的 OpenID
        $openIdService = new OpenIdService($pdo);
        $openIdResult = $openIdService->getOrCreateOpenId($user['uuid'], $appId);
        
        if (!$openIdResult['success']) {
            $logger->log(
                'error',
                'github_login_get_openid_failed',
                '获取 OpenID 失败',
                [
                    'user_uuid' => $user['uuid'],
                    'app_id' => $appId,
                    'message' => $openIdResult['message']
                ]
            );
            throw new Exception('登录失败，请稍后重试');
        }
        
        $userOpenid = $openIdResult['openid'];
        
        // 5. 获取客户端IP
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($clientIp, ',') !== false) {
            $ips = explode(',', $clientIp);
            $clientIp = trim($ips[0]);
        }
        
        // 6. 使用从session中获取的回调地址
        // 已经在前面从session中获取了 $callbackUrl
        
        // 7. 保存 Token 到数据库
        $stmt = $pdo->prepare("
            INSERT INTO tokens.login_token (
                token, user_id, user_uuid, username, app_id, status,
                login_method, login_ip, login_time, validity_period, expires_at,
                callback_url, permissions, extra_info
            ) VALUES (
                :token, :user_id, :user_uuid, :username, :app_id, :status,
                :login_method, :login_ip, CURRENT_TIMESTAMP, :validity_period, :expires_at,
                :callback_url, :permissions, :extra_info
            ) RETURNING id
        ");
        
        $stmt->execute([
            'token' => $token,
            'user_id' => $user['id'],
            'user_uuid' => $user['uuid'],
            'username' => $user['username'],
            'app_id' => $appId,
            'status' => 1,
            'login_method' => 'github',
            'login_ip' => $clientIp,
            'validity_period' => $validityPeriod,
            'expires_at' => $expiresAt,
            'callback_url' => $callbackUrl,
            'permissions' => $permissions,
            'extra_info' => json_encode([
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'login_time' => date('Y-m-d H:i:s'),
                'github_id' => $githubId,
                'github_login' => $githubLogin
            ])
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $tokenId = $result['id'];
        
        // 8. 更新用户最后登录信息
        $stmt = $pdo->prepare("
            UPDATE users.user 
            SET last_login_at = CURRENT_TIMESTAMP,
                last_login_ip = :ip
            WHERE id = :id
        ");
        $stmt->execute([
            'ip' => $clientIp,
            'id' => $user['id']
        ]);
        
        // 9. 设置登录session
        $_SESSION['user_uuid'] = $user['uuid'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_method'] = 'github';
        
        // 10. 记录登录日志
        $logger->log(
            'info',
            'github_login_success',
            'GitHub登录成功',
            [
                'app_id' => $appId,
                'user_uuid' => $user['uuid'],
                'username' => $user['username'],
                'openid' => $userOpenid,
                'github_id' => $githubId,
                'token_id' => $tokenId
            ]
        );
        
        // 11. 构建回调 URL
        $redirectUrl = $callbackUrl . (strpos($callbackUrl, '?') !== false ? '&' : '?') . 'token=' . $token;
        
        // 如果有 state_code 参数，也添加到回调 URL 中
        if (!empty($stateCode)) {
            $redirectUrl .= '&code=' . urlencode($stateCode);
        }
        
        // 清除session中的OAuth参数
        unset($_SESSION['github_oauth_app_id']);
        unset($_SESSION['github_oauth_callback_url']);
        unset($_SESSION['github_oauth_permissions']);
        unset($_SESSION['github_oauth_state_code']);
        
        // 重定向到应用回调地址
        header('Location: ' . $redirectUrl);
        exit;
        
    } else {
        // ============================================
        // 5. 未绑定账号，保存GitHub信息并提示用户绑定
        // ============================================
        
        if (!$githubUser) {
            // 首次使用GitHub登录，插入记录
            $stmt = $pdo->prepare("
                INSERT INTO auth.github_user_info (
                    github_id,
                    github_login,
                    github_name,
                    github_avatar,
                    github_email,
                    github_bio,
                    access_token,
                    bind_status,
                    last_login_at
                ) VALUES (
                    :github_id,
                    :github_login,
                    :github_name,
                    :github_avatar,
                    :github_email,
                    :github_bio,
                    :access_token,
                    0,
                    CURRENT_TIMESTAMP
                )
            ");
            
            $stmt->execute([
                'github_id' => $githubId,
                'github_login' => $githubLogin,
                'github_name' => $githubName,
                'github_avatar' => $githubAvatar,
                'github_email' => $githubEmail,
                'github_bio' => $githubBio,
                'access_token' => $accessToken
            ]);
        } else {
            // 更新GitHub用户信息
            $stmt = $pdo->prepare("
                UPDATE auth.github_user_info
                SET 
                    github_login = :github_login,
                    github_name = :github_name,
                    github_avatar = :github_avatar,
                    github_email = :github_email,
                    github_bio = :github_bio,
                    access_token = :access_token,
                    last_login_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE github_id = :github_id
            ");
            
            $stmt->execute([
                'github_login' => $githubLogin,
                'github_name' => $githubName,
                'github_avatar' => $githubAvatar,
                'github_email' => $githubEmail,
                'github_bio' => $githubBio,
                'access_token' => $accessToken,
                'github_id' => $githubId
            ]);
        }
        
        // 临时存储GitHub用户信息到session，供绑定页面使用
        $_SESSION['github_temp_info'] = [
            'github_id' => $githubId,
            'login' => $githubLogin,
            'name' => $githubName,
            'avatar' => $githubAvatar,
            'email' => $githubEmail
        ];
        
        // 记录日志
        $logger->log(
            'info',
            'github_login_need_bind',
            'GitHub登录需要绑定账号',
            [
                'app_id' => $appId,
                'github_id' => $githubId,
                'login' => $githubLogin
            ]
        );
    }
    
    // 未绑定账号提示页面
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
        <title>GitHub登录 - 需要绑定账号</title>
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
            .notice-container {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
                text-align: center;
            }
            .notice-icon {
                font-size: 64px;
                color: #ff9800;
                margin-bottom: 20px;
            }
            .avatar {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                margin: 20px auto;
                border: 3px solid #667eea;
            }
            h1 {
                color: #333;
                font-size: 24px;
                margin-bottom: 10px;
            }
            .nickname {
                color: #667eea;
                font-size: 20px;
                font-weight: bold;
                margin-bottom: 20px;
            }
            .notice-text {
                color: #666;
                font-size: 16px;
                line-height: 1.8;
                margin-bottom: 30px;
                text-align: left;
                background: #fff3e0;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #ff9800;
            }
            .notice-text strong {
                color: #ff9800;
            }
            .user-info {
                background: #f5f5f5;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                text-align: left;
            }
            .user-info-item {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #e0e0e0;
            }
            .user-info-item:last-child {
                border-bottom: none;
            }
            .user-info-label {
                color: #999;
                font-size: 14px;
            }
            .user-info-value {
                color: #333;
                font-size: 14px;
                font-weight: 500;
            }
            .btn-group {
                display: flex;
                gap: 15px;
                justify-content: center;
            }
            .btn {
                display: inline-block;
                padding: 12px 30px;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-size: 16px;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
            }
            .btn-primary {
                background: #667eea;
            }
            .btn-primary:hover {
                background: #5568d3;
                transform: translateY(-2px);
            }
            .btn-secondary {
                background: #999;
            }
            .btn-secondary:hover {
                background: #777;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="notice-container">
            <div class="notice-icon">⚠️</div>
            <h1>GitHub授权成功</h1>
            <?php if (!empty($githubAvatar)): ?>
            <img src="<?php echo htmlspecialchars($githubAvatar); ?>" alt="头像" class="avatar">
            <?php endif; ?>
            <div class="nickname"><?php echo htmlspecialchars($githubLogin); ?></div>
            
            <div class="notice-text">
                <strong>提示：</strong>您的GitHub账号尚未绑定本平台账号。<br><br>
                请先登录用户中心，在"账号安全"页面绑定GitHub账号后，即可使用GitHub快捷登录功能。
            </div>
            
            <div class="user-info">
                <div class="user-info-item">
                    <span class="user-info-label">GitHub用户名</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($githubLogin); ?></span>
                </div>
                <?php if (!empty($githubName)): ?>
                <div class="user-info-item">
                    <span class="user-info-label">显示名称</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($githubName); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($githubEmail)): ?>
                <div class="user-info-item">
                    <span class="user-info-label">邮箱</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($githubEmail); ?></span>
                </div>
                <?php endif; ?>
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
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'github_login_callback_error',
            'GitHub登录回调失败: ' . $e->getMessage(),
            [
                'code' => $_GET['code'] ?? '',
                'state' => $_GET['state'] ?? '',
                'error' => $e->getMessage(),
                'is_bind_mode' => $isBindMode ?? false
            ]
        );
    }
    
    // 错误处理
    $errorMessage = htmlspecialchars($e->getMessage());
    
    // 根据模式决定返回URL和按钮文字
    if (isset($isBindMode) && $isBindMode) {
        // 绑定模式：返回用户中心
        $returnUrl = '../../../user/index.html#security';
        $btnText = '返回用户中心';
    } else {
        // 登录模式：返回登录页
        $returnUrl = '../../index.html';
        if (isset($_SESSION['github_oauth_app_id']) && !empty($_SESSION['github_oauth_app_id'])) {
            $params = [
                'app_id' => $_SESSION['github_oauth_app_id'],
                'callback_url' => $_SESSION['github_oauth_callback_url'] ?? '',
                'permissions' => $_SESSION['github_oauth_permissions'] ?? '',
                'code' => $_SESSION['github_oauth_state_code'] ?? ''
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
        <title>GitHub<?php echo $isBindMode ? '绑定' : '登录'; ?>失败</title>
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
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-size: 16px;
                transition: all 0.3s;
            }
            .btn-back:hover {
                background: #5568d3;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>GitHub<?php echo $isBindMode ? '绑定' : '登录'; ?>失败</h1>
            <p><?php echo $errorMessage; ?></p>
            <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="btn-back"><?php echo $btnText; ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
