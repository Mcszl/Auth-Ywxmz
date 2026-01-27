<?php
/**
 * 检查用户登录状态 API
 * 用于验证用户的 Access Token 是否有效
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
 * 验证 Access Token
 */
function verifyAccessToken($pdo, $accessToken, $appId, $openid) {
    try {
        $stmt = $pdo->prepare("
            SELECT at.*, rt.user_uuid 
            FROM tokens.access_token at
            LEFT JOIN tokens.refresh_token rt ON at.refresh_token_id = rt.id
            WHERE at.access_token = :access_token 
            AND at.app_id = :app_id 
            AND at.status = 1
            AND at.expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $stmt->execute([
            'access_token' => $accessToken,
            'app_id' => $appId
        ]);
        
        $tokenRecord = $stmt->fetch();
        
        if (!$tokenRecord) {
            return ['success' => false, 'message' => 'Access Token 无效或已过期'];
        }
        
        // 验证 OpenID 是否匹配
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
        
        // 验证 OpenID 对应的用户是否与 Token 一致
        if ($openidRecord['user_uuid'] != $tokenRecord['user_uuid']) {
            return ['success' => false, 'message' => 'OpenID 与 Access Token 不匹配'];
        }
        
        // 计算剩余有效时间
        $expiresAt = strtotime($tokenRecord['expires_at']);
        $remainingTime = $expiresAt - time();
        
        return [
            'success' => true,
            'user_uuid' => $tokenRecord['user_uuid'],
            'permissions' => $tokenRecord['permissions'],
            'expires_at' => $tokenRecord['expires_at'],
            'remaining_time' => $remainingTime
        ];
        
    } catch (PDOException $e) {
        error_log("验证 Access Token 失败: " . $e->getMessage());
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
    $openid = $input['openid'] ?? '';
    $accessToken = $input['access_token'] ?? $accessTokenFromHeader ?? '';
    
    // 验证必填参数
    if (empty($appId)) {
        jsonResponse(false, null, '缺少 app_id 参数', 400);
    }
    
    if (empty($openid)) {
        jsonResponse(false, null, '缺少 openid 参数', 400);
    }
    
    if (empty($accessToken)) {
        jsonResponse(false, null, '缺少 access_token 参数', 400);
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
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    $logger->info('api', '开始检查登录状态', [
        'app_id' => $appId,
        'openid' => $openid
    ]);
    
    // 验证 Access Token
    $tokenInfo = verifyAccessToken($pdo, $accessToken, $appId, $openid);
    if (!$tokenInfo['success']) {
        $logger->warning('api', '登录状态检查失败', [
            'app_id' => $appId,
            'openid' => $openid,
            'message' => $tokenInfo['message']
        ]);
        jsonResponse(false, [
            'is_logged_in' => false
        ], $tokenInfo['message'], 401);
    }
    
    // 检查用户状态
    try {
        $stmt = $pdo->prepare("
            SELECT status 
            FROM users.user 
            WHERE uuid = :uuid 
            LIMIT 1
        ");
        $stmt->execute(['uuid' => $tokenInfo['user_uuid']]);
        
        $user = $stmt->fetch();
        
        if (!$user) {
            $logger->warning('api', '用户不存在', [
                'user_uuid' => $tokenInfo['user_uuid']
            ]);
            jsonResponse(false, [
                'is_logged_in' => false
            ], '用户不存在', 404);
        }
        
        if ($user['status'] != 1) {
            $logger->warning('api', '用户已被禁用', [
                'user_uuid' => $tokenInfo['user_uuid'],
                'status' => $user['status']
            ]);
            jsonResponse(false, [
                'is_logged_in' => false
            ], '用户已被禁用', 403);
        }
        
        $logger->info('api', '登录状态检查成功', [
            'app_id' => $appId,
            'openid' => $openid,
            'remaining_time' => $tokenInfo['remaining_time']
        ]);
        
        // 返回登录状态信息
        jsonResponse(true, [
            'is_logged_in' => true,
            'openid' => $openid,
            'permissions' => explode(',', $tokenInfo['permissions']),
            'expires_at' => $tokenInfo['expires_at'],
            'remaining_time' => $tokenInfo['remaining_time']
        ], '用户已登录');
        
    } catch (PDOException $e) {
        $logger->error('api', '查询用户状态失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '服务器错误', 500);
    }
    
} catch (Exception $e) {
    error_log("检查登录状态错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo && isset($logger)) {
            $logger->critical('api', '检查登录状态异常', [
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
