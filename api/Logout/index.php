<?php
/**
 * 注销登录 API
 * 用于注销用户的登录状态，同时失效 Access Token 和 Refresh Token
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
 * 验证 OpenID 和 Token 的匹配关系
 */
function verifyTokenOwnership($pdo, $accessToken, $refreshToken, $appId, $openid) {
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
        
        // 验证 Access Token
        $stmt = $pdo->prepare("
            SELECT user_uuid, refresh_token_id 
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
        
        // 验证 Access Token 是否属于该用户
        if ($accessTokenRecord['user_uuid'] != $userUuid) {
            return ['success' => false, 'message' => 'Access Token 与 OpenID 不匹配'];
        }
        
        // 验证 Refresh Token
        $stmt = $pdo->prepare("
            SELECT id, user_uuid 
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
        
        // 验证 Refresh Token 是否属于该用户
        if ($refreshTokenRecord['user_uuid'] != $userUuid) {
            return ['success' => false, 'message' => 'Refresh Token 与 OpenID 不匹配'];
        }
        
        // 验证 Access Token 和 Refresh Token 是否关联
        if ($accessTokenRecord['refresh_token_id'] != $refreshTokenRecord['id']) {
            return ['success' => false, 'message' => 'Access Token 与 Refresh Token 不匹配'];
        }
        
        return [
            'success' => true,
            'user_uuid' => $userUuid,
            'refresh_token_id' => $refreshTokenRecord['id']
        ];
        
    } catch (PDOException $e) {
        error_log("验证 Token 所有权失败: " . $e->getMessage());
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
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    $logger->info('logout', '开始注销登录', [
        'app_id' => $appId,
        'openid' => $openid
    ]);
    
    // 验证应用信息
    $appInfo = verifyApp($pdo, $appId, $secretKey);
    if (!$appInfo['success']) {
        $logger->warning('logout', '应用验证失败', [
            'app_id' => $appId,
            'message' => $appInfo['message']
        ]);
        jsonResponse(false, null, $appInfo['message'], 403);
    }
    
    // 验证 Token 所有权
    $tokenInfo = verifyTokenOwnership($pdo, $accessToken, $refreshToken, $appId, $openid);
    if (!$tokenInfo['success']) {
        $logger->warning('logout', 'Token 验证失败', [
            'app_id' => $appId,
            'openid' => $openid,
            'message' => $tokenInfo['message']
        ]);
        jsonResponse(false, null, $tokenInfo['message'], 401);
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 注销 Access Token（状态改为 2 - 用户退出登录）
        $stmt = $pdo->prepare("
            UPDATE tokens.access_token 
            SET status = 2
            WHERE access_token = :access_token 
            AND app_id = :app_id
        ");
        $stmt->execute([
            'access_token' => $accessToken,
            'app_id' => $appId
        ]);
        
        $accessTokenAffected = $stmt->rowCount();
        
        // 注销 Refresh Token（状态改为 2 - 用户退出登录）
        $stmt = $pdo->prepare("
            UPDATE tokens.refresh_token 
            SET status = 2
            WHERE refresh_token = :refresh_token 
            AND app_id = :app_id
        ");
        $stmt->execute([
            'refresh_token' => $refreshToken,
            'app_id' => $appId
        ]);
        
        $refreshTokenAffected = $stmt->rowCount();
        
        // 提交事务
        $pdo->commit();
        
        $logger->info('logout', '注销登录成功', [
            'app_id' => $appId,
            'openid' => $openid,
            'user_uuid' => $tokenInfo['user_uuid'],
            'access_token_affected' => $accessTokenAffected,
            'refresh_token_affected' => $refreshTokenAffected
        ]);
        
        // 返回成功响应
        jsonResponse(true, [
            'openid' => $openid,
            'logout_time' => date('Y-m-d H:i:s')
        ], '注销成功');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $logger->error('logout', '注销登录失败', [
            'error' => $e->getMessage()
        ]);
        jsonResponse(false, null, '注销失败', 500);
    }
    
} catch (Exception $e) {
    error_log("注销登录错误: " . $e->getMessage());
    
    // 尝试记录到系统日志
    try {
        if (isset($pdo) && $pdo && isset($logger)) {
            $logger->critical('logout', '注销登录异常', [
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
