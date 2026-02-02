<?php
/**
 * 删除 Google 绑定记录
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
    $pdo = getDBConnection();
    
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    $logger = new SystemLogger($pdo);
    
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || empty($input['id'])) {
        jsonResponse(false, null, '缺少必要参数：id', 400);
    }
    
    $bindingId = intval($input['id']);
    
    $stmt = $pdo->prepare("
        SELECT id, google_id, google_email, user_uuid 
        FROM auth.google_user_info 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $bindingId]);
    $binding = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$binding) {
        jsonResponse(false, null, '绑定记录不存在', 404);
    }
    
    $pdo->beginTransaction();
    
    try {
        $deleteStmt = $pdo->prepare("
            DELETE FROM auth.google_user_info 
            WHERE id = :id
        ");
        $deleteStmt->execute(['id' => $bindingId]);
        
        $pdo->commit();
        
        $logger->info('admin', '管理员删除 Google 绑定记录', [
            'admin' => $admin['username'],
            'google_id' => $binding['google_id'],
            'google_email' => $binding['google_email'],
            'user_uuid' => $binding['user_uuid']
        ]);
        
        jsonResponse(true, null, '删除成功');
        
    } catch (Exception $e) {
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
