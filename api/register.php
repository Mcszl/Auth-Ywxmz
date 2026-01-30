<?php
/**
 * 用户注册 API
 * 一碗小米周授权登录平台
 */

// 抑制 PHP 8.x 的 deprecated 警告（来自第三方库）
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/../sms/SmsService.php';
require_once __DIR__ . '/../mail/MailService.php';
require_once __DIR__ . '/../captcha/CaptchaService.php';
require_once __DIR__ . '/../checks/NicknameCheckService.php';

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
    
    $username = trim($input['username'] ?? '');
    $nickname = trim($input['nickname'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    $codeId = trim($input['code_id'] ?? '');  // 验证码ID，用于防止验证码被他用
    $password = $input['password'] ?? '';
    
    // 极验参数
    $lotNumber = $input['lot_number'] ?? '';
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($callbackUrl)) {
        jsonResponse(false, null, '缺少 callback_url 参数', 400);
    }
    
    // 验证必填参数（手机号或邮箱至少填一个）
    if (empty($username) || empty($nickname) || empty($code) || empty($password)) {
        jsonResponse(false, null, '请填写完整信息', 400);
    }
    
    if (empty($phone) && empty($email)) {
        jsonResponse(false, null, '请提供手机号或邮箱', 400);
    }
    
    // 验证码注册必须提供 code_id
    if (empty($codeId)) {
        jsonResponse(false, null, '缺少验证码标识，请重新获取验证码', 400);
    }
    
    // 验证账号格式
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]{3,11}$/', $username)) {
        jsonResponse(false, null, '账号必须以字母开头，4-12位英文或数字', 400);
    }
    
    // 验证手机号格式（如果提供了手机号）
    if (!empty($phone) && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        jsonResponse(false, null, '手机号格式不正确', 400);
    }
    
    // 验证邮箱格式（如果提供了邮箱）
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, '邮箱格式不正确', 400);
    }
    
    // 验证密码长度
    if (strlen($password) < 6) {
        jsonResponse(false, null, '密码长度不能少于6位', 400);
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
    
    // 获取客户端 IP
    $clientIp = getClientIp();
    
    // 创建系统日志实例
    require_once __DIR__ . '/../logs/SystemLogger.php';
    $logger = new SystemLogger($pdo);
    
    // 初始化服务
    $smsService = new SmsService($pdo);
    $mailService = new MailService();
    $captchaService = new CaptchaService($pdo, $logger);
    
    // 验证验证码
    if (!empty($phone)) {
        // 验证手机验证码
        $verifyResult = $smsService->verifyCode($phone, $code, 'register', $codeId);
        if (!$verifyResult['success']) {
            jsonResponse(false, null, $verifyResult['message'], 400);
        }
    } elseif (!empty($email)) {
        // 验证邮箱验证码
        $verifyResult = $mailService->verifyCode($email, $code, 'register', $codeId);
        if (!$verifyResult['success']) {
            jsonResponse(false, null, $verifyResult['message'], 400);
        }
    } else {
        jsonResponse(false, null, '请提供手机号或邮箱', 400);
    }
    
    // 检查是否启用了人机验证
    $captchaConfig = $captchaService->getCaptchaConfig('register');
    $captchaEnabled = ($captchaConfig !== null);
    $captchaProvider = $captchaEnabled ? $captchaConfig['provider'] : null;
    
    // 如果启用了人机验证且提供了验证参数，则进行二次验证
    if ($captchaEnabled) {
        // 根据不同的验证服务商获取验证参数
        $captchaToken = null;
        switch ($captchaProvider) {
            case 'geetest':
                $captchaToken = $lotNumber;
                break;
            case 'turnstile':
            case 'recaptcha':
            case 'hcaptcha':
                $captchaToken = $input['captcha_token'] ?? '';
                break;
        }
        
        if (!empty($captchaToken)) {
            // 根据注册方式选择验证标识（手机号或邮箱）
            $verifyIdentifier = !empty($phone) ? $phone : $email;
            $verifyResult = $captchaService->verifySecondTime($captchaToken, $verifyIdentifier, $captchaProvider, 'register', $clientIp);
            if (!$verifyResult['success']) {
                jsonResponse(false, null, '人机验证已过期，请重新获取验证码', 400);
            }
        } else {
            jsonResponse(false, null, '缺少人机验证参数，请重新获取验证码', 400);
        }
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 检查用户名是否已存在
        $stmt = $pdo->prepare("SELECT id FROM users.user WHERE username = :username");
        $stmt->execute(['username' => $username]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(false, null, '用户名已被使用', 400);
        }
        
        // 检查手机号是否已注册（如果提供了手机号）
        if (!empty($phone)) {
            $stmt = $pdo->prepare("SELECT id FROM users.user WHERE phone = :phone");
            $stmt->execute(['phone' => $phone]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                jsonResponse(false, null, '该手机号已注册', 400);
            }
        }
        
        // 检查邮箱是否已注册（如果提供了邮箱）
        if (!empty($email)) {
            $stmt = $pdo->prepare("SELECT id FROM users.user WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                jsonResponse(false, null, '该邮箱已注册', 400);
            }
        }
        
        // 初始化昵称审核服务
        $nicknameCheckService = new NicknameCheckService($pdo);
        
        // 检查是否启用昵称审核
        $nicknameCheckEnabled = $nicknameCheckService->isEnabled();
        $originalNickname = $nickname; // 保存用户输入的昵称
        $needsNicknameCheck = false;
        
        // 如果启用昵称审核，使用游客昵称注册
        if ($nicknameCheckEnabled) {
            $guestNickname = $nicknameCheckService->generateGuestNickname();
            $nickname = $guestNickname; // 使用游客昵称
            $needsNicknameCheck = true;
        }
        
        // 生成密码哈希
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        
        // 插入用户数据
        $stmt = $pdo->prepare("
            INSERT INTO users.user (
                username, nickname, phone, email, password_hash, avatar,
                status, register_ip, last_login_ip, user_type
            ) VALUES (
                :username, :nickname, :phone, :email, :password_hash, :avatar,
                1, :register_ip, :last_login_ip, 'user'
            ) RETURNING id, uuid
        ");
        
        $stmt->execute([
            'username' => $username,
            'nickname' => $nickname,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'password_hash' => $passwordHash,
            'avatar' => 'https://avatar.ywxmz.com/user-6380868_1920.png',
            'register_ip' => $clientIp,
            'last_login_ip' => $clientIp
        ]);
        
        $user = $stmt->fetch();
        $userId = $user['id'];
        $userUuid = $user['uuid'];
        
        // 创建用户扩展信息
        $stmt = $pdo->prepare("
            INSERT INTO users.user_profile (user_id) 
            VALUES (:user_id)
        ");
        $stmt->execute(['user_id' => $userId]);
        
        // 如果启用昵称审核，提交昵称审核申请
        if ($needsNicknameCheck) {
            try {
                $checkResult = $nicknameCheckService->submitNicknameCheck(
                    $userUuid,
                    $nickname,  // 游客昵称
                    $originalNickname,  // 用户输入的昵称
                    'register',
                    '用户注册时设置的昵称',
                    $clientIp
                );
                
                // 如果昵称审核提交失败，回滚事务
                if (!$checkResult['success']) {
                    $pdo->rollBack();
                    $errorMsg = $checkResult['message'] ?? '昵称审核提交失败';
                    error_log("昵称审核提交失败: " . $errorMsg);
                    jsonResponse(false, null, '注册失败：' . $errorMsg, 400);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("昵称审核异常: " . $e->getMessage());
                jsonResponse(false, null, '注册失败：昵称审核异常', 500);
            }
        }
        
        // 提交事务
        $pdo->commit();
        
        // 注册成功后，标记验证码为已使用
        if (!empty($phone)) {
            $smsService->markCodeAsUsed($phone, $code, 'register');
        } elseif (!empty($email)) {
            $mailService->markCodeAsUsed($email, $code, 'register');
        }
        
        // 返回成功响应
        jsonResponse(true, [
            'user_id' => $userId,
            'uuid' => $userUuid,
            'username' => $username,
            'nickname' => $nickname,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'redirect_url' => $callbackUrl . (strpos($callbackUrl, '?') !== false ? '&' : '?') . 'user_id=' . $userId,
            'scene' => 'register',
            'captcha_enabled' => $captchaEnabled,
            'captcha_provider' => $captchaProvider
        ], '注册成功');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("注册失败: " . $e->getMessage());
        jsonResponse(false, null, '注册失败，请稍后重试', 500);
    }
    
} catch (Exception $e) {
    error_log("注册错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
