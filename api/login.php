<?php
/**
 * 用户登录 API
 * 一碗小米周授权登录平台
 */

// 抑制 PHP 8.x 中 GuzzleHttp 库的 Deprecated 警告
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/../sms/SmsService.php';
require_once __DIR__ . '/../mail/MailService.php';
require_once __DIR__ . '/../captcha/GeetestService.php';
require_once __DIR__ . '/../logs/SystemLogger.php';
require_once __DIR__ . '/../api/OpenIdService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * 验证应用信息
 */
function verifyApp($pdo, $appId, $callbackUrl, $permissions) {
    try {
        // 查询应用配置
        $stmt = $pdo->prepare("SELECT * FROM site_config WHERE app_id = :app_id");
        $stmt->execute(['app_id' => $appId]);
        $config = $stmt->fetch();
        
        if (!$config) {
            return ['success' => false, 'message' => '应用不存在'];
        }
        
        // 验证应用状态
        if ($config['status'] == 0) {
            return ['success' => false, 'message' => '应用已被封禁'];
        }
        
        if ($config['status'] == 2) {
            return ['success' => false, 'message' => '应用正在审核中'];
        }
        
        // 验证是否启用登录
        if (!$config['enable_login']) {
            return ['success' => false, 'message' => '应用未启用登录功能'];
        }
        
        // 验证回调域
        $allowedCallbacks = $config['callback_urls'];
        if (is_string($allowedCallbacks)) {
            $allowedCallbacks = trim($allowedCallbacks, '{}');
            $allowedCallbacks = $allowedCallbacks ? explode(',', $allowedCallbacks) : [];
        }
        
        $callbackValid = false;
        foreach ($allowedCallbacks as $allowedUrl) {
            if (strpos($callbackUrl, $allowedUrl) === 0) {
                $callbackValid = true;
                break;
            }
        }
        
        if (!$callbackValid) {
            return ['success' => false, 'message' => '回调地址未授权'];
        }
        
        // 验证权限
        if (!empty($permissions)) {
            $configPermissions = $config['permissions'];
            if (is_string($configPermissions)) {
                $configPermissions = trim($configPermissions, '{}');
                $configPermissions = $configPermissions ? explode(',', $configPermissions) : [];
            }
            
            $requestedPermissions = explode(',', $permissions);
            $invalidPermissions = array_diff($requestedPermissions, $configPermissions);
            
            if (!empty($invalidPermissions)) {
                return ['success' => false, 'message' => '请求的权限未授权'];
            }
        }
        
        return ['success' => true, 'config' => $config];
        
    } catch (PDOException $e) {
        error_log("验证应用失败: " . $e->getMessage());
        return ['success' => false, 'message' => '验证失败'];
    }
}

/**
 * 返回 JSON 响应
 */
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 获取客户端 IP
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/**
 * 生成 Token
 */
function generateToken() {
    return 'LT_' . date('YmdHis') . '_' . bin2hex(random_bytes(16));
}

/**
 * 验证用户密码
 */
function verifyPassword($pdo, $account, $password) {
    try {
        // 支持用户名、手机号、邮箱登录
        $stmt = $pdo->prepare("
            SELECT * FROM users.user 
            WHERE (username = :account OR phone = :account OR email = :account)
            AND status = 1
            LIMIT 1
        ");
        $stmt->execute(['account' => $account]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => '账号不存在或已被禁用'];
        }
        
        // 验证密码
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => '密码错误'];
        }
        
        return ['success' => true, 'user' => $user];
        
    } catch (PDOException $e) {
        error_log("验证密码失败: " . $e->getMessage());
        return ['success' => false, 'message' => '验证失败'];
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 获取请求参数
    $input = json_decode(file_get_contents('php://input'), true);
    
    $appId = $input['app_id'] ?? '';
    $callbackUrl = $input['callback_url'] ?? '';
    $permissions = $input['permissions'] ?? '';
    $stateCode = $input['state_code'] ?? '';  // 前端传入的 state code 参数，需要在回调时返回
    
    $loginMethod = $input['login_method'] ?? '';  // password/sms/email
    $account = trim($input['account'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $code = trim($input['code'] ?? '');
    $codeId = trim($input['code_id'] ?? '');  // 验证码ID，用于防止验证码被他用
    
    // 如果没有 account，尝试从 phone 或 email 获取
    if (empty($account)) {
        $account = !empty($phone) ? $phone : $email;
    }
    
    // 极验参数
    $lotNumber = $input['lot_number'] ?? '';
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($callbackUrl)) {
        jsonResponse(false, null, '缺少 callback_url 参数', 400);
    }
    
    // 自动判断登录方式
    if (empty($loginMethod)) {
        // 如果没有指定登录方式，根据参数自动判断
        if (!empty($password)) {
            $loginMethod = 'password';
        } elseif (!empty($code)) {
            // 根据 account 格式判断是手机号还是邮箱
            if (!empty($phone) || preg_match('/^1[3-9]\d{9}$/', $account)) {
                $loginMethod = 'sms';
            } elseif (!empty($email) || filter_var($account, FILTER_VALIDATE_EMAIL)) {
                $loginMethod = 'email';
            } else {
                jsonResponse(false, null, '无法识别登录方式', 400);
            }
        } else {
            jsonResponse(false, null, '缺少登录凭证', 400);
        }
    }
    
    if (empty($account)) {
        jsonResponse(false, null, '请输入账号', 400);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 确保 Schema 存在
    ensureSchemaExists($pdo);
    
    // 验证 APP 信息
    $appInfo = verifyApp($pdo, $appId, $callbackUrl, $permissions);
    if (!$appInfo['success']) {
        jsonResponse(false, null, $appInfo['message'], 403);
    }
    
    $appConfig = $appInfo['config'];
    
    // 获取客户端 IP
    $clientIp = getClientIp();
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 初始化服务
    $smsService = new SmsService($pdo);
    $mailService = new MailService();
    $geetestService = new GeetestService($pdo, $logger);
    $openIdService = new OpenIdService($pdo);
    
    // 验证登录方式是否在应用允许的登录方式中
    $user = null;
    
    // 检查是否启用了人机验证（登录场景）
    $captchaConfig = $geetestService->getGeetestConfig('login');
    $captchaEnabled = ($captchaConfig !== null);
    
    // 如果启用了人机验证且提供了 lot_number，则进行二次验证
    // 注意：只有在验证码登录时才需要二次验证（因为发送验证码时已经验证过一次）
    $needSecondVerify = false;
    if ($loginMethod === 'sms' || $loginMethod === 'email') {
        $needSecondVerify = true;
    }
    
    if ($captchaEnabled && $needSecondVerify && !empty($lotNumber)) {
        $geetestVerify = $geetestService->verifySecondTime($lotNumber, $account);
        if (!$geetestVerify['success']) {
            $logger->warning('login', '人机验证二次验证失败', [
                'account' => $account,
                'login_method' => $loginMethod,
                'message' => $geetestVerify['message']
            ]);
            jsonResponse(false, null, $geetestVerify['message'], 400);
        }
    } elseif ($captchaEnabled && $needSecondVerify && empty($lotNumber)) {
        // 如果启用了人机验证但没有提供 lot_number，说明可能是场景配置问题
        $logger->warning('login', '缺少人机验证参数', [
            'account' => $account,
            'login_method' => $loginMethod,
            'captcha_enabled' => $captchaEnabled
        ]);
        jsonResponse(false, null, '缺少人机验证参数，请重新获取验证码', 400);
    }
    
    switch ($loginMethod) {
        case 'password':
            // 验证是否启用密码登录
            if (!$appConfig['enable_password_login']) {
                jsonResponse(false, null, '应用未启用密码登录', 403);
            }
            
            if (empty($password)) {
                jsonResponse(false, null, '请输入密码', 400);
            }
            
            // 验证密码
            $verifyResult = verifyPassword($pdo, $account, $password);
            if (!$verifyResult['success']) {
                jsonResponse(false, null, $verifyResult['message'], 400);
            }
            
            $user = $verifyResult['user'];
            break;
            
        case 'sms':
            // 验证是否启用短信验证码登录
            if (!$appConfig['enable_phone_code_login']) {
                jsonResponse(false, null, '应用未启用短信验证码登录', 403);
            }
            
            if (empty($code)) {
                jsonResponse(false, null, '请输入验证码', 400);
            }
            
            // 验证码登录必须提供 code_id
            if (empty($codeId)) {
                jsonResponse(false, null, '缺少验证码标识，请重新获取验证码', 400);
            }
            
            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $account)) {
                jsonResponse(false, null, '手机号格式不正确', 400);
            }
            
            // 验证短信验证码
            $verifyResult = $smsService->verifyCode($account, $code, 'login', $codeId);
            if (!$verifyResult['success']) {
                jsonResponse(false, null, $verifyResult['message'], 400);
            }
            
            // 查询用户
            $stmt = $pdo->prepare("
                SELECT * FROM users.user 
                WHERE phone = :phone AND status = 1
                LIMIT 1
            ");
            $stmt->execute(['phone' => $account]);
            $user = $stmt->fetch();
            
            if (!$user) {
                jsonResponse(false, null, '该手机号未注册', 400);
            }
            
            // 标记验证码为已使用
            $smsService->markCodeAsUsed($account, $code, 'login');
            break;
            
        case 'email':
            // 验证是否启用邮箱验证码登录
            if (!$appConfig['enable_email_code_login']) {
                jsonResponse(false, null, '应用未启用邮箱验证码登录', 403);
            }
            
            if (empty($code)) {
                jsonResponse(false, null, '请输入验证码', 400);
            }
            
            // 验证码登录必须提供 code_id
            if (empty($codeId)) {
                jsonResponse(false, null, '缺少验证码标识，请重新获取验证码', 400);
            }
            
            // 验证邮箱格式
            if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(false, null, '邮箱格式不正确', 400);
            }
            
            // 验证邮箱验证码
            $verifyResult = $mailService->verifyCode($account, $code, 'login', $codeId);
            if (!$verifyResult['success']) {
                jsonResponse(false, null, $verifyResult['message'], 400);
            }
            
            // 查询用户
            $stmt = $pdo->prepare("
                SELECT * FROM users.user 
                WHERE email = :email AND status = 1
                LIMIT 1
            ");
            $stmt->execute(['email' => $account]);
            $user = $stmt->fetch();
            
            if (!$user) {
                jsonResponse(false, null, '该邮箱未注册', 400);
            }
            
            // 标记验证码为已使用
            $mailService->markCodeAsUsed($account, $code, 'login');
            break;
            
        default:
            jsonResponse(false, null, '不支持的登录方式', 400);
    }
    
    // 生成 Token
    $token = generateToken();
    $validityPeriod = 900; // 15分钟
    $expiresAt = date('Y-m-d H:i:s', time() + $validityPeriod);
    
    // 获取或创建用户的 OpenID
    $openIdResult = $openIdService->getOrCreateOpenId($user['uuid'], $appId);
    if (!$openIdResult['success']) {
        $logger->error('login', '获取 OpenID 失败', [
            'user_uuid' => $user['uuid'],
            'app_id' => $appId,
            'message' => $openIdResult['message']
        ]);
        jsonResponse(false, null, '登录失败，请稍后重试', 500);
    }
    
    $openid = $openIdResult['openid'];
    
    $logger->info('login', 'OpenID 获取成功', [
        'openid' => $openid,
        'is_new' => $openIdResult['is_new'],
        'app_id' => $appId
    ]);
    
    // 保存 Token 到数据库
    try {
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
            'login_method' => $loginMethod,
            'login_ip' => $clientIp,
            'validity_period' => $validityPeriod,
            'expires_at' => $expiresAt,
            'callback_url' => $callbackUrl,
            'permissions' => $permissions,
            'extra_info' => json_encode([
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'login_time' => date('Y-m-d H:i:s')
            ])
        ]);
        
        $result = $stmt->fetch();
        $tokenId = $result['id'];
        
        // 更新用户最后登录信息
        $updateStmt = $pdo->prepare("
            UPDATE users.user 
            SET last_login_at = CURRENT_TIMESTAMP,
                last_login_ip = :ip
            WHERE id = :id
        ");
        $updateStmt->execute([
            'ip' => $clientIp,
            'id' => $user['id']
        ]);
        
        // 记录日志
        $logger->info('login', '用户登录成功', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'login_method' => $loginMethod,
            'app_id' => $appId,
            'token_id' => $tokenId
        ]);
        
        // 构建回调 URL
        $redirectUrl = $callbackUrl . (strpos($callbackUrl, '?') !== false ? '&' : '?') . 'token=' . $token;
        
        // 如果有 code 参数，也添加到回调 URL 中
        if (!empty($stateCode)) {
            $redirectUrl .= '&code=' . urlencode($stateCode);
        }
        
        // 返回成功响应（使用 OpenID 代替 UUID 和 user_id）
        jsonResponse(true, [
            'token' => $token,
            'openid' => $openid,
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'expires_in' => $validityPeriod,
            'redirect_url' => $redirectUrl,
            'login_method' => $loginMethod,
            'scene' => 'login',
            'captcha_enabled' => $captchaEnabled,
            'captcha_provider' => $captchaEnabled ? 'geetest' : null
        ], '登录成功');
        
    } catch (PDOException $e) {
        error_log("保存登录Token失败: " . $e->getMessage());
        jsonResponse(false, null, '登录失败，请稍后重试', 500);
    }
    
} catch (Exception $e) {
    error_log("登录错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
