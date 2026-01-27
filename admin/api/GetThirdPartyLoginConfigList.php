<?php
/**
 * 获取第三方登录配置列表
 * 
 * 功能：获取第三方登录配置列表，支持分页、搜索和筛选
 * 
 * 请求方式：GET
 * 请求参数：
 *   - page: 页码（可选，默认1）
 *   - page_size: 每页数量（可选，默认20）
 *   - search: 搜索关键词（可选，搜索配置名称、平台）
 *   - platform: 平台筛选（可选）
 *   - status: 状态筛选（可选）
 * 
 * 返回数据：
 *   - success: 是否成功
 *   - data: 配置列表和分页信息
 *   - message: 提示信息
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 启动会话
session_start();

try {
    // 验证登录状态
    if (!isset($_SESSION['user_uuid'])) {
        throw new Exception('未登录');
    }

    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        throw new Exception($message);
    });
    
    // 获取分页参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 获取筛选参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $platform = isset($_GET['platform']) ? trim($_GET['platform']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    if ($search !== '') {
        $where[] = "(config_name ILIKE :search OR platform ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if ($platform !== '') {
        $where[] = "platform = :platform";
        $params[':platform'] = $platform;
    }
    
    if ($status !== '') {
        $where[] = "status = :status";
        $params[':status'] = intval($status);
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM auth.third_party_login_config $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询配置列表
    $sql = "
        SELECT 
            id,
            config_name,
            platform,
            app_id,
            callback_url,
            status,
            is_enabled,
            priority,
            description,
            created_at,
            updated_at
        FROM auth.third_party_login_config
        $whereClause
        ORDER BY priority ASC, id DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 计算分页信息
    $totalPages = ceil($total / $pageSize);
    $hasPrev = $page > 1;
    $hasNext = $page < $totalPages;
    
    // 返回成功
    echo json_encode([
        'success' => true,
        'data' => [
            'configs' => $configs,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => intval($total),
                'total_pages' => $totalPages,
                'has_prev' => $hasPrev,
                'has_next' => $hasNext
            ]
        ],
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('获取第三方登录配置列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
