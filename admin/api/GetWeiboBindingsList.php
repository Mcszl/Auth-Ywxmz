<?php
/**
 * 获取微博绑定列表
 * 
 * 功能说明：
 * - 获取所有微博绑定记录
 * - 支持搜索（微博昵称、UID、用户名）
 * - 支持按绑定状态筛选
 * - 支持分页
 * 
 * 请求方式：GET
 * 
 * 请求参数：
 * - page: 页码（可选，默认1）
 * - page_size: 每页数量（可选，默认20）
 * - search: 搜索关键词（可选）
 * - bind_status: 绑定状态（可选，-1=全部，0=未绑定，1=已绑定）
 * 
 * 返回数据：
 * {
 *   "success": true,
 *   "data": {
 *     "list": [...],
 *     "pagination": {
 *       "page": 1,
 *       "page_size": 20,
 *       "total": 100,
 *       "total_pages": 5
 *     }
 *   },
 *   "message": "获取成功"
 * }
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 开启会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 返回 JSON 响应
 */
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
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 创建日志实例
    $logger = new SystemLogger($pdo);
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取请求参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $bindStatus = isset($_GET['bind_status']) ? intval($_GET['bind_status']) : -1;
    
    // 计算偏移量
    $offset = ($page - 1) * $pageSize;
    
    // 构建查询条件
    $whereConditions = [];
    $params = [];
    
    // 搜索条件
    if (!empty($search)) {
        $whereConditions[] = "(
            w.weibo_nickname ILIKE :search 
            OR w.uid ILIKE :search 
            OR u.username ILIKE :search
            OR u.nickname ILIKE :search
        )";
        $params['search'] = '%' . $search . '%';
    }
    
    // 绑定状态筛选
    if ($bindStatus >= 0) {
        $whereConditions[] = "w.bind_status = :bind_status";
        $params['bind_status'] = $bindStatus;
    }
    
    // 组合 WHERE 子句
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM auth.weibo_user_info w
        LEFT JOIN users.user u ON w.user_uuid = u.uuid
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询列表数据
    $listSql = "
        SELECT 
            w.id,
            w.uid,
            w.user_uuid,
            w.weibo_nickname,
            w.weibo_avatar,
            w.weibo_gender,
            w.weibo_location,
            w.weibo_description,
            w.bind_status,
            w.last_login_at,
            w.created_at,
            w.updated_at,
            u.username,
            u.nickname,
            u.avatar,
            u.email,
            u.phone
        FROM auth.weibo_user_info w
        LEFT JOIN users.user u ON w.user_uuid = u.uuid
        $whereClause
        ORDER BY w.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $listStmt = $pdo->prepare($listSql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $listStmt->bindValue(':' . $key, $value);
    }
    $listStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $listStmt->execute();
    $list = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理列表数据
    foreach ($list as &$item) {
        // 绑定状态文本
        $item['bind_status_text'] = $item['bind_status'] == 1 ? '已绑定' : '未绑定';
        
        // 性别文本
        $genderMap = [
            'm' => '男',
            'f' => '女',
            'n' => '未知'
        ];
        $item['weibo_gender_text'] = $genderMap[$item['weibo_gender']] ?? '未知';
        
        // 用户信息
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
        
        // 移除冗余字段
        unset($item['username'], $item['nickname'], $item['email'], $item['phone']);
    }
    
    // 计算总页数
    $totalPages = ceil($total / $pageSize);
    
    // 返回结果
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
