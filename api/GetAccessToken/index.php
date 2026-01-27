<?php
/**
 * 获取 Access Token API
 * 用于站点通过 login_token 换取 access_token 和 refresh_token
 */

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';
require_once __DIR__ . '/../../api/OpenIdService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
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
 * 生成 Access Token
 */
function generateAccessToken() {
    return 'AT_' . date('YmdHis') . '_' . bin2hex(random_bytes(16));
}

/**
 * 生成 Refresh Token
 */
function generateRefreshToken() {
    return 'RT_' . date('YmdHis') . '_' . bin2hex(random_bytes(16));
}

/**
 * 验证应用信息
 */
function verifyApp($pdo, $appId, $secretKey) {
    try {
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
        
        // 验证 secret_key
        if ($config['secret_key'] !== $secretKey) {
            return ['success' => false, 'message' => 'Secret Key 错误'];
        }
        
        return ['success' => true, 'config' => $config];
        
    } catch (PDOException $e) {
        error_log("验证应用失败: " . $e->getMessage());
        return ['success' => false, 'message' => '验证失败'];
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 支持 POST 和 GET 请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_GET;
    }
    
    // 获取参数
    $appId = $input['app_id'] ?? '';
    $secretKey = $input['secret_key'] ?? '';
    $loginToken = $input['token'] ?? '';
    $permissions = $input['permissions'] ?? '';
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($secretKey)) {
        jsonResponse(false, null, '缺少 secret_key 参数', 400);
    }
    
    if (empty($loginToken)) {
        jsonResponse(false, null, '缺少 token 参数', 400);
    }
    
    if (empty($permissions)) {
        jsonResponse(false, null, '缺少 permissions 参数', 400);
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
    
    // 获取客户端 IP
    $clientIp = getClientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    $openIdService = new OpenIdService($pdo);
    
    $logger->info('token', '开始换取 Access Token', [
        'app_id' => $appId,
        'login_token' => $loginToken,
        'client_ip' => $clientIp
    ]);
    
    // 验证应用信息
    $appInfo = verifyApp($pdo, $appId, $secretKey);
    if (!$appInfo['success']) {
        $logger->warning('token', '应用验证失败', [
            'app_id' => $appId,
            'message' => $appInfo['message']
        ]);
        jsonResponse(false, null, $appInfo['message'], 403);
    }
    
    // 查询 login_token
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM tokens.login_token 
            WHERE token = :token 
            AND app_id = :app_id 
            AND status = 1
            AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $stmt->execute([
            'token' => $loginToken,
            'app_id' => $appId
        ]);
        
        $tokenRecord = $stmt->fetch();
        
        if (!$tokenRecord) {
            $logger->warning('token', 'Login Token 无效或已过期', [
                'token' => $loginToken,
                'app_id' => $appId
            ]);
            jsonResponse(false, null, 'Token 无效或已过期', 400);
        }
        
        // 验证权限是否一致
        if ($tokenRecord['permissions'] !== $permissions) {
            $logger->warning('token', '权限不一致', [
                'token' => $loginToken,
                'expected_permissions' => $tokenRecord['permissions'],
                'provided_permissions' => $permissions
            ]);
            jsonResponse(false, null, '权限信息不一致', 400);
        }
        
        $logger->info('token', 'Login Token 验证通过', [
            'token' => $loginToken,
            'user_uuid' => $tokenRecord['user_uuid'],
            'username' => $tokenRecord['username']
        ]);
        
    } catch (PDOException $e) {
        $logger->error('token', '查询 Login Token 失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '服务器错误', 500);
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 获取或创建用户的 OpenID
        $openIdResult = $openIdService->getOrCreateOpenId($tokenRecord['user_uuid'], $appId);
        if (!$openIdResult['success']) {
            $pdo->rollBack();
            $logger->error('token', '获取 OpenID 失败', [
                'user_uuid' => $tokenRecord['user_uuid'],
                'app_id' => $appId
            ]);
            jsonResponse(false, null, '生成 Token 失败', 500);
        }
        
        $openid = $openIdResult['openid'];
        
        // 生成 Refresh Token
        $refreshToken = generateRefreshToken();
        $refreshValidityPeriod = 2592000; // 30天
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshValidityPeriod);
        
        $stmt = $pdo->prepare("
            INSERT INTO tokens.refresh_token (
                refresh_token, app_id, user_uuid, permissions,
                status, validity_period, expires_at,
                client_ip, user_agent
            ) VALUES (
                :refresh_token, :app_id, :user_uuid, :permissions,
                1, :validity_period, :expires_at,
                :client_ip, :user_agent
            ) RETURNING id
        ");
        
        $stmt->execute([
            'refresh_token' => $refreshToken,
            'app_id' => $appId,
            'user_uuid' => $tokenRecord['user_uuid'],
            'permissions' => $permissions,
            'validity_period' => $refreshValidityPeriod,
            'expires_at' => $refreshExpiresAt,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent
        ]);
        
        $result = $stmt->fetch();
        $refreshTokenId = $result['id'];
        
        // 生成 Access Token
        $accessToken = generateAccessToken();
        $accessValidityPeriod = 7200; // 2小时
        $accessExpiresAt = date('Y-m-d H:i:s', time() + $accessValidityPeriod);
        
        $stmt = $pdo->prepare("
            INSERT INTO tokens.access_token (
                access_token, refresh_token_id, app_id, user_uuid, permissions,
                status, validity_period, expires_at,
                client_ip, user_agent
            ) VALUES (
                :access_token, :refresh_token_id, :app_id, :user_uuid, :permissions,
                1, :validity_period, :expires_at,
                :client_ip, :user_agent
            ) RETURNING id
        ");
        
        $stmt->execute([
            'access_token' => $accessToken,
            'refresh_token_id' => $refreshTokenId,
            'app_id' => $appId,
            'user_uuid' => $tokenRecord['user_uuid'],
            'permissions' => $permissions,
            'validity_period' => $accessValidityPeriod,
            'expires_at' => $accessExpiresAt,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent
        ]);
        
        $result = $stmt->fetch();
        $accessTokenId = $result['id'];
        
        // 标记 login_token 为已使用（状态改为 0）
        $stmt = $pdo->prepare("
            UPDATE tokens.login_token 
            SET status = 0
            WHERE id = :id
        ");
        $stmt->execute(['id' => $tokenRecord['id']]);
        
        // 提交事务
        $pdo->commit();
        
        $logger->info('token', 'Access Token 生成成功', [
            'access_token_id' => $accessTokenId,
            'refresh_token_id' => $refreshTokenId,
            'user_uuid' => $tokenRecord['user_uuid'],
            'username' => $tokenRecord['username']
        ]);
        
        // 返回成功响应（使用 OpenID 代替 UUID）
        jsonResponse(true, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessValidityPeriod,
            'refresh_expires_in' => $refreshValidityPeriod,
            'openid' => $openid,
            'permissions' => explode(',', $permissions)
        ], 'Access Token 获取成功');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $logger->error('token', '生成 Access Token 失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '生成 Token 失败', 500);
    }
    
} catch (Exception $e) {
    error_log("获取 Access Token 错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo && isset($logger)) {
            $logger->critical('token', '获取 Access Token 异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $e->getTraceAsString());
        }
    } catch (Exception $logException) {
        error_log("记录系统日志失败: " . $logException->getMessage());
    }
    
    jsonResponse(false, null, '服务器错误', 500);
}
