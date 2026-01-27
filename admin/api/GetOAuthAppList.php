<?php
/**
 * 获取授权应用列表 API
 * 管理员查看所有接入的授权应用
 */

session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
    
    // 获取请求参数
    $page = intval($_GET['page'] ?? 1);
    $pageSize = intval($_GET['page_size'] ?? 20);
    $keyword = trim($_GET['keyword'] ?? '');
    $status = isset($_GET['status']) ? intval($_GET['status']) : null;
    
    // 参数验证
    if ($page < 1) $page = 1;
    if ($pageSize < 1 || $pageSize > 100) $pageSize = 20;
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    // 关键词搜索（应用名称或应用ID）
    if (!empty($keyword)) {
        $where[] = "(sc.site_name LIKE :keyword OR sc.app_id LIKE :keyword)";
        $params['keyword'] = '%' . $keyword . '%';
    }
    
    // 状态筛选
    if ($status !== null) {
        $where[] = "sc.status = :status";
        $params['status'] = $status;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM site_config sc $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // 查询应用列表
    $offset = ($page - 1) * $pageSize;
    $listSql = "
        SELECT 
            sc.id,
            sc.app_id,
            sc.site_name,
            sc.app_icon_url,
            sc.site_url,
            sc.status,
            sc.created_at,
            sc.updated_at,
            COUNT(DISTINCT o.user_uuid) as authorized_users
        FROM site_config sc
        LEFT JOIN users.openid o ON sc.app_id = o.app_id AND o.status = 1
        $whereClause
        GROUP BY sc.id, sc.app_id, sc.site_name, sc.app_icon_url, sc.site_url, sc.status, sc.created_at, sc.updated_at
        ORDER BY sc.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $apps = $stmt->fetchAll();
    
    // 格式化数据
    $formattedApps = [];
    foreach ($apps as $app) {
        $formattedApps[] = [
            'id' => $app['id'],
            'app_id' => $app['app_id'],
            'site_name' => $app['site_name'],
            'app_icon_url' => $app['app_icon_url'] ?: 'https://via.placeholder.com/64',
            'site_url' => $app['site_url'],
            'status' => $app['status'],
            'status_text' => getStatusText($app['status']),
            'authorized_users' => intval($app['authorized_users']),
            'created_at' => $app['created_at'],
            'updated_at' => $app['updated_at']
        ];
    }
    
    jsonResponse(true, [
        'apps' => $formattedApps,
        'total' => intval($total),
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => ceil($total / $pageSize)
    ], '获取成功');
    
} catch (Exception $e) {
    error_log("获取应用列表失败: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}

/**
 * 获取状态文本
 */
function getStatusText($status) {
    $statusMap = [
        0 => '封禁',
        1 => '正常',
        2 => '待审核'
    ];
    return $statusMap[$status] ?? '未知';
}
