<?php
/**
 * 删除授权应用 API
 * 管理员删除应用（需检查授权用户）
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

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

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 从 session 获取管理员 UUID
    $adminUuid = $_SESSION['user_uuid'] ?? '';
    
    if (empty($adminUuid)) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $appId = trim($input['app_id'] ?? '');
    $force = $input['force'] ?? false; // 是否强制删除
    
    if (empty($appId)) {
        jsonResponse(false, null, '应用ID不能为空');
    }
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 检查应用是否存在
    $stmt = $pdo->prepare("SELECT id, site_name FROM site_config WHERE app_id = :app_id");
    $stmt->execute(['app_id' => $appId]);
    $app = $stmt->fetch();
    
    if (!$app) {
        jsonResponse(false, null, '应用不存在', 404);
    }
    
    // 检查是否有授权用户
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users.openid WHERE app_id = :app_id AND status = 1");
    $stmt->execute(['app_id' => $appId]);
    $authorizedUsers = $stmt->fetch()['count'];
    
    if ($authorizedUsers > 0 && !$force) {
        jsonResponse(false, [
            'authorized_users' => intval($authorizedUsers)
        ], "该应用还有 {$authorizedUsers} 个授权用户，请先清理授权记录或使用强制删除");
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 如果强制删除，先删除所有授权记录
        if ($force && $authorizedUsers > 0) {
            $stmt = $pdo->prepare("DELETE FROM users.openid WHERE app_id = :app_id");
            $stmt->execute(['app_id' => $appId]);
        }
        
        // 删除应用
        $stmt = $pdo->prepare("DELETE FROM site_config WHERE app_id = :app_id");
        $stmt->execute(['app_id' => $appId]);
        
        // 提交事务
        $pdo->commit();
        
        // 记录操作日志
        $logger->info('admin', '删除授权应用', [
            'admin_uuid' => $adminUuid,
            'app_id' => $appId,
            'site_name' => $app['site_name'],
            'force' => $force,
            'deleted_users' => $force ? $authorizedUsers : 0
        ]);
        
        $message = $force && $authorizedUsers > 0 
            ? "应用已删除，同时删除了 {$authorizedUsers} 个授权记录" 
            : '应用已删除';
        
        jsonResponse(true, null, $message);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log("删除应用失败: " . $e->getMessage());
    jsonResponse(false, null, '删除应用失败', 500);
} catch (Exception $e) {
    error_log("删除应用错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
