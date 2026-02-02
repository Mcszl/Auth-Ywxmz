<?php
/**
 * 重置密码
 * 
 * 功能说明：
 * - 用户修改密码
 * - 需要验证临时令牌
 * - 需要再次验证验证码
 * - 修改成功后清除所有 session
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function jsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '请求方法错误', 405);
    }

    // 检查用户登录状态
    if (!isset($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '请先登录', 401);
    }

    $userUuid = $_SESSION['user_uuid'];

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    $newPassword = $input['new_password'] ?? '';

    // 验证参数
    if (empty($token)) {
        jsonResponse(false, null, '令牌参数错误');
    }

    if (empty($newPassword)) {
        jsonResponse(false, null, '请输入新密码');
    }

    // 验证密码格式
    if (strlen($newPassword) < 8 || strlen($newPassword) > 20) {
        jsonResponse(false, null, '密码长度必须在8-20位之间');
    }

    // 连接数据库
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }

    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);

    // 验证临时令牌
    if (!isset($_SESSION['password_reset_token']) || 
        $_SESSION['password_reset_token'] !== $token) {
        $logger->log('password_reset', 'invalid_token', $userUuid);
        jsonResponse(false, null, '令牌无效，请重新验证');
    }

    // 检查令牌是否过期
    if (!isset($_SESSION['password_reset_expires']) || 
        $_SESSION['password_reset_expires'] < time()) {
        $logger->log('password_reset', 'token_expired', $userUuid);
        jsonResponse(false, null, '令牌已过期，请重新验证');
    }

    $codeId = $_SESSION['password_reset_code_id'] ?? null;
    $method = $_SESSION['password_reset_method'] ?? null;
    $target = $_SESSION['password_reset_target'] ?? null;
    $verifiedCode = $_SESSION['password_reset_code'] ?? null;

    // 调试日志
    $logger->log('password_reset', 'debug_session_data', $userUuid, [
        'code_id' => $codeId,
        'method' => $method,
        'target' => $target,
        'has_code' => !empty($verifiedCode)
    ]);

    if (!$codeId || !$method || !$target || !$verifiedCode) {
        jsonResponse(false, null, '验证信息不完整，请重新验证');
    }

    // 根据验证方式选择对应的表和字段
    if ($method === 'phone') {
        $tableName = 'sms.code';
        $targetField = 'phone';
        $idField = 'sms_id';
    } else {
        $tableName = 'email.code';
        $targetField = 'email';
        $idField = 'code_id';
    }

    // 调试日志
    $logger->log('password_reset', 'debug_query_params', $userUuid, [
        'table' => $tableName,
        'id_field' => $idField,
        'target_field' => $targetField,
        'code_id_value' => $codeId,
        'target_value' => $target
    ]);

    // 验证 session 中保存的验证码信息是否仍然有效
    $stmt = $pdo->prepare("
        SELECT code, expires_at, status, $targetField
        FROM $tableName
        WHERE $idField = :code_id
          AND $targetField = :target
          AND purpose IN ('password_reset', 'reset_password', '重置密码')
          AND code = :code
    ");
    $stmt->execute([
        'code_id' => $codeId,
        'target' => $target,
        'code' => $verifiedCode
    ]);
    $verificationCode = $stmt->fetch(PDO::FETCH_ASSOC);

    // 调试日志
    $logger->log('password_reset', 'debug_query_result', $userUuid, [
        'found' => !empty($verificationCode),
        'result' => $verificationCode ? 'has_data' : 'no_data'
    ]);

    if (!$verificationCode) {
        $logger->log('password_reset', 'code_not_found_final', $userUuid);
        jsonResponse(false, null, '验证信息已失效，请重新验证');
    }

    // 检查验证码状态（3-一次核验成功，4-二次核验成功）
    if ($verificationCode['status'] != 3 && $verificationCode['status'] != 4) {
        $logger->log('password_reset', 'code_not_verified', $userUuid);
        jsonResponse(false, null, '验证码未通过验证，请重新验证');
    }

    // 检查验证码是否过期
    if (strtotime($verificationCode['expires_at']) < time()) {
        $logger->log('password_reset', 'code_expired_final', $userUuid);
        jsonResponse(false, null, '验证码已过期，请重新验证');
    }

    // 开始事务
    $pdo->beginTransaction();

    try {
        // 更新密码
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE users.user
            SET password_hash = :password_hash,
                updated_at = NOW()
            WHERE uuid = :uuid
        ");
        $stmt->execute([
            'password_hash' => $hashedPassword,
            'uuid' => $userUuid
        ]);

        // 将该用户的所有有效 token 标记为失效（安全措施：密码修改后强制重新登录）
        
        // 1. 标记所有有效的 login_token 为强制关闭（status = 3）
        $stmt = $pdo->prepare("
            UPDATE tokens.login_token
            SET status = 3,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);
        
        // 2. 标记所有有效的 access_token 为用户退出登录（status = 2）
        $stmt = $pdo->prepare("
            UPDATE tokens.access_token
            SET status = 2,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);
        
        // 3. 标记所有有效的 refresh_token 为用户退出登录（status = 2）
        $stmt = $pdo->prepare("
            UPDATE tokens.refresh_token
            SET status = 2,
                updated_at = NOW()
            WHERE user_uuid = :user_uuid
              AND status = 1
        ");
        $stmt->execute(['user_uuid' => $userUuid]);

        // 标记验证码为已使用（status = 0）
        $stmt = $pdo->prepare("
            UPDATE $tableName
            SET status = 0,
                updated_at = NOW()
            WHERE $idField = :code_id
        ");
        $stmt->execute(['code_id' => $codeId]);

        // 提交事务
        $pdo->commit();

        $logger->log('password_reset', 'success', $userUuid, [
            'method' => $method
        ]);

        // 清除 session 中的临时数据
        unset($_SESSION['password_reset_token']);
        unset($_SESSION['password_reset_code_id']);
        unset($_SESSION['password_reset_method']);
        unset($_SESSION['password_reset_target']);
        unset($_SESSION['password_reset_code']);
        unset($_SESSION['password_reset_expires']);

        // 清除用户登录状态（强制用户重新登录）
        unset($_SESSION['user_uuid']);
        unset($_SESSION['username']);
        unset($_SESSION['login_token']);
        
        // 如果需要完全销毁 session，可以使用以下代码
        // session_destroy();

        jsonResponse(true, null, '密码修改成功，请重新登录');

    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
