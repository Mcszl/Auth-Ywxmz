<?php
/**
 * 获取 Google 绑定列表
 * 
 * 功能说明：
 * - 获取所有 Google 绑定记录
 * - 支持搜索（Google 邮箱、显示名称、用户名）
 * - 支持按绑定状态筛选
 * - 支持分页
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $bindStatus = isset($_GET['bind_status']) ? intval($_GET['bind_status']) : -1;
    
    $offset = ($page - 1) * $pageSize;
    
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(
            g.google_email ILIKE :search 
            OR g.google_name ILIKE :search 
            OR g.google_id ILIKE :search
            OR u.username ILIKE :search
            OR u.nickname ILIKE :search
        )";
        $params['search'] = '%' . $search . '%';
    }
    
    if ($bindStatus >= 0) {
        $whereConditions[] = "g.bind_status = :bind_status";
        $params['bind_status'] = $bindStatus;
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    $countSql = "
        SELECT COUNT(*) as total
        FROM auth.google_user_info g
        LEFT JOIN users.user u ON g.user_uuid = u.uuid
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $listSql = "
        SELECT 
            g.id,
            g.google_id,
            g.user_uuid,
            g.google_email,
            g.google_verified_email,
            g.google_name,
            g.google_avatar,
            g.google_given_name,
            g.google_family_name,
            g.google_locale,
            g.bind_status,
            g.last_login_at,
            g.created_at,
            g.updated_at,
            u.username,
            u.nickname,
            u.avatar,
            u.email,
            u.phone
        FROM auth.google_user_info g
        LEFT JOIN users.user u ON g.user_uuid = u.uuid
        $whereClause
        ORDER BY g.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $listStmt = $pdo->prepare($listSql);
    
    foreach ($params as $key => $value) {
        $listStmt->bindValue(':' . $key, $value);
    }
    $listStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $listStmt->execute();
    $list = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($list as &$item) {
        $item['bind_status_text'] = $item['bind_status'] == 1 ? '已绑定' : '未绑定';
        $item['verified_email_text'] = $item['google_verified_email'] ? '已验证' : '未验证';
        
        if ($item['user_uuid']) {
            $item['user'] = [
                'uuid' => $item['user_uuid'],
                'username' => $item['username'],
                'nickname' => $item['nickname'],
                'avatar' => $item['avatar'],
                'email' => $item['email'],
                'phone' => $item['phone']
            ];
        } else {
            $item['user'] = null;
        }
        
        unset($item['username'], $item['nickname'], $item['email'], $item['phone']);
    }
    
    $totalPages = ceil($total / $pageSize);
    
    jsonResponse(true, [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => intval($total),
            'total_pages' => $totalPages
        ]
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('数据库错误: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    error_log('系统错误: ' . $e->getMessage());
    jsonResponse(false, null, '系统错误', 500);
}
