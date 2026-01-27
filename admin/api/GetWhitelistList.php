<?php
/**
 * 获取短信白名单列表
 * 
 * 功能：
 * - 支持分页查询
 * - 支持按手机号搜索
 * - 支持按启用状态筛选
 * - 自动过滤已过期的记录
 * 
 * @author 一碗小米粥
 * @date 2025-01-26
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// 只允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许 GET 请求',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 连接数据库
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 获取查询参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $isEnabled = isset($_GET['is_enabled']) ? $_GET['is_enabled'] : '';
    
    // 计算偏移量
    $offset = ($page - 1) * $pageSize;
    
    // 构建查询条件
    $conditions = [];
    $params = [];
    
    // 搜索条件
    if (!empty($search)) {
        $conditions[] = "(phone ILIKE :search OR reason ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // 启用状态筛选
    if ($isEnabled !== '') {
        $conditions[] = "is_enabled = :is_enabled";
        $params[':is_enabled'] = $isEnabled === '1' ? true : false;
    }
    
    // 只显示未过期的记录
    $conditions[] = "(expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
    
    // 组合查询条件
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "SELECT COUNT(*) FROM sms.whitelist $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    // 查询列表数据
    $sql = "
        SELECT 
            id,
            phone,
            reason,
            is_enabled,
            expires_at,
            created_by,
            created_at,
            updated_at
        FROM sms.whitelist
        $whereClause
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $whitelist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 计算分页信息
    $totalPages = ceil($total / $pageSize);
    $hasPrev = $page > 1;
    $hasNext = $page < $totalPages;
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取白名单列表成功',
        'data' => [
            'whitelist' => $whitelist,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_prev' => $hasPrev,
                'has_next' => $hasNext
            ]
        ],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('数据库错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('系统错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '系统错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
