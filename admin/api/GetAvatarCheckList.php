<?php
/**
 * 获取头像审核列表
 * 
 * @author AI Assistant
 * @date 2026-01-24
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 开启会话
session_start();

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'message' => '请求方法错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    
    // 获取查询参数
    $status = isset($_GET['status']) ? (int)$_GET['status'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? min(100, max(1, (int)$_GET['page_size'])) : 20;
    $offset = ($page - 1) * $pageSize;
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    if ($status !== null) {
        $where[] = "ac.status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM checks.avatar_check ac
        {$whereClause}
    ";
    
    $stmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total = $stmt->fetch()['total'];
    
    // 查询列表
    $sql = "
        SELECT 
            ac.id,
            ac.user_uuid,
            ac.old_avatar,
            ac.new_avatar,
            ac.new_avatar_filename,
            ac.storage_type,
            ac.storage_config_id,
            ac.submitted_at as upload_time,
            '' as upload_ip,
            ac.status,
            ac.reviewed_at as review_time,
            ac.reviewer_uuid,
            '' as reviewer_name,
            ac.check_message as review_comment,
            ac.check_message as reject_reason,
            CASE WHEN ac.check_type != 'manual' THEN true ELSE false END as auto_reviewed,
            u.username,
            u.nickname,
            u.avatar as current_avatar,
            sc.config_name as storage_name,
            sc.storage_type as storage_type_name
        FROM checks.avatar_check ac
        LEFT JOIN users.user u ON ac.user_uuid::text = u.uuid::text
        LEFT JOIN site_configs.storage_config sc ON ac.storage_config_id = sc.id
        {$whereClause}
        ORDER BY 
            CASE 
                WHEN ac.status = 0 THEN 0
                ELSE 1
            END,
            ac.submitted_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理数据
    $list = array_map(function($item) {
        $item['current_avatar'] = $item['current_avatar'] ?: 'https://avatar.ywxmz.com/user-6380868_1920.png';
        return $item;
    }, $list);
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取成功',
        'data' => [
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => (int)$total,
                'total_pages' => ceil($total / $pageSize)
            ]
        ],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('获取头像审核列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('获取头像审核列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '服务器错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
