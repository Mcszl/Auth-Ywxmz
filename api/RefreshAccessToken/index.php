<?php
/**
 * 刷新 Access Token API
 * 用于在 Access Token 即将过期时刷新获取新的 Access Token
 */

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

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
        
        if ($config['status'] == 0) {
            return ['success' => false, 'message' => '应用已被封禁'];
        }
        
        if ($config['status'] == 2) {
            return ['success' => false, 'message' => '应用正在审核中'];
        }
        
        if ($config['secret_key'] !== $secretKey) {
            return ['success' => false, 'message' => 'Secret Key 错误'];
        }
        
        return ['success' => true, 'config' => $config];
        
    } catch (PDOException $e) {
        error_log("验证应用失败: " . $e->getMessage());
        return ['success' => false, 'message' => '验证失败'];
    }
}

/**
 * 验证 Token 并获取信息
 */
function verifyTokens($pdo, $accessToken, $refreshToken, $appId, $openid) {
    try {
        // 验证 OpenID
        $stmt = $pdo->prepare("
            SELECT user_uuid 
            FROM users.openid 
            WHERE openid = :openid 
            AND app_id = :app_id 
            AND status = 1
            LIMIT 1
        ");
        $stmt->execute([
            'openid' => $openid,
            'app_id' => $appId
        ]);
        
        $openidRecord = $stmt->fetch();
        
        if (!$openidRecord) {
            return ['success' => false, 'message' => 'OpenID 无效'];
        }
        
        $userUuid = $openidRecord['user_uuid'];
        
        // 验证 Refresh Token（必须有效且未过期）
        $stmt = $pdo->prepare("
            SELECT id, user_uuid, permissions, status, expires_at 
            FROM tokens.refresh_token 
            WHERE refresh_token = :refresh_token 
            AND app_id = :app_id
            LIMIT 1
        ");
        $stmt->execute([
            'refresh_token' => $refreshToken,
            'app_id' => $appId
        ]);
        
        $refreshTokenRecord = $stmt->fetch();
        
        if (!$refreshTokenRecord) {
            return ['success' => false, 'message' => 'Refresh Token 无效'];
        }
        
        // 检查 Refresh Token 状态（必须为 1 - 正常）
        if ($refreshTokenRecord['status'] != 1) {
            return ['success' => false, 'message' => 'Refresh Token 已失效'];
        }
        
        // 检查 Refresh Token 是否过期
        if (strtotime($refreshTokenRecord['expires_at']) <= time()) {
            return ['success' => false, 'message' => 'Refresh Token 已过期'];
        }
        
        // 验证 Refresh Token 是否属于该用户
        if ($refreshTokenRecord['user_uuid'] != $userUuid) {
            return ['success' => false, 'message' => 'Refresh Token 与 OpenID 不匹配'];
        }
        
        // 验证 Access Token（必须有效且未过期）
        $stmt = $pdo->prepare("
            SELECT id, user_uuid, refresh_token_id, permissions, status, expires_at 
            FROM tokens.access_token 
            WHERE access_token = :access_token 
            AND app_id = :app_id
            LIMIT 1
        ");
        $stmt->execute([
            'access_token' => $accessToken,
            'app_id' => $appId
        ]);
        
        $accessTokenRecord = $stmt->fetch();
        
        if (!$accessTokenRecord) {
            return ['success' => false, 'message' => 'Access Token 无效'];
        }
        
        // 检查 Access Token 状态（必须为 1 - 正常）
        if ($accessTokenRecord['status'] != 1) {
            return ['success' => false, 'message' => 'Access Token 已失效'];
        }
        
        // 检查 Access Token 是否过期
        if (strtotime($accessTokenRecord['expires_at']) <= time()) {
            return ['success' => false, 'message' => 'Access Token 已过期'];
        }
        
        // 验证 Access Token 是否属于该用户
        if ($accessTokenRecord['user_uuid'] != $userUuid) {
            return ['success' => false, 'message' => 'Access Token 与 OpenID 不匹配'];
        }
        
        // 验证 Access Token 和 Refresh Token 是否配对
        if ($accessTokenRecord['refresh_token_id'] != $refreshTokenRecord['id']) {
            return ['success' => false, 'message' => 'Access Token 与 Refresh Token 不匹配'];
        }
        
        return [
            'success' => true,
            'user_uuid' => $userUuid,
            'refresh_token_id' => $refreshTokenRecord['id'],
            'permissions' => $accessTokenRecord['permissions'],
            'old_access_token_id' => $accessTokenRecord['id']
        ];
        
    } catch (PDOException $e) {
        error_log("验证 Token 失败: " . $e->getMessage());
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
    
    // 也支持从 Authorization 头获取 access_token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $accessTokenFromHeader = $matches[1];
    } else {
        $accessTokenFromHeader = null;
    }
    
    // 获取参数
    $appId = $input['app_id'] ?? '';
    $secretKey = $input['secret_key'] ?? '';
    $openid = $input['openid'] ?? '';
    $accessToken = $input['access_token'] ?? $accessTokenFromHeader ?? '';
    $refreshToken = $input['refresh_token'] ?? '';
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($secretKey)) {
        jsonResponse(false, null, '缺少 secret_key 参数', 400);
    }
    
    if (empty($openid)) {
        jsonResponse(false, null, '缺少 openid 参数', 400);
    }
    
    if (empty($accessToken)) {
        jsonResponse(false, null, '缺少 access_token 参数', 400);
    }
    
    if (empty($refreshToken)) {
        jsonResponse(false, null, '缺少 refresh_token 参数', 400);
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
    
    // 获取客户端信息
    $clientIp = getClientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    $logger->info('token', '开始刷新 Access Token', [
        'app_id' => $appId,
        'openid' => $openid,
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
    
    // 验证 Token
    $tokenInfo = verifyTokens($pdo, $accessToken, $refreshToken, $appId, $openid);
    if (!$tokenInfo['success']) {
        $logger->warning('token', 'Token 验证失败', [
            'app_id' => $appId,
            'openid' => $openid,
            'message' => $tokenInfo['message']
        ]);
        jsonResponse(false, null, $tokenInfo['message'], 401);
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 1. 将旧的 Access Token 状态改为 0（过期）
        $stmt = $pdo->prepare("
            UPDATE tokens.access_token 
            SET status = 0
            WHERE id = :id
        ");
        $stmt->execute(['id' => $tokenInfo['old_access_token_id']]);
        
        // 2. 生成新的 Access Token
        $newAccessToken = generateAccessToken();
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
            'access_token' => $newAccessToken,
            'refresh_token_id' => $tokenInfo['refresh_token_id'],
            'app_id' => $appId,
            'user_uuid' => $tokenInfo['user_uuid'],
            'permissions' => $tokenInfo['permissions'],
            'validity_period' => $accessValidityPeriod,
            'expires_at' => $accessExpiresAt,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent
        ]);
        
        $result = $stmt->fetch();
        $newAccessTokenId = $result['id'];
        
        // 提交事务
        $pdo->commit();
        
        $logger->info('token', 'Access Token 刷新成功', [
            'app_id' => $appId,
            'openid' => $openid,
            'old_access_token_id' => $tokenInfo['old_access_token_id'],
            'new_access_token_id' => $newAccessTokenId,
            'user_uuid' => $tokenInfo['user_uuid']
        ]);
        
        // 返回成功响应
        jsonResponse(true, [
            'access_token' => $newAccessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessValidityPeriod,
            'openid' => $openid,
            'permissions' => explode(',', $tokenInfo['permissions'])
        ], 'Access Token 刷新成功');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $logger->error('token', '刷新 Access Token 失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '刷新失败', 500);
    }
    
} catch (Exception $e) {
    error_log("刷新 Access Token 错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo && isset($logger)) {
            $logger->critical('token', '刷新 Access Token 异常', [
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
