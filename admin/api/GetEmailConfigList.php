<?php
/**
 * 获取邮件配置列表 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 获取邮件配置列表
 * 3. 支持分页和搜索
 */

header('Content-Type: application/json; charset=utf-8');

// 启动会话
session_start();

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

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

try {
    // 只允许 GET 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查管理员权限
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $adminUuid = $_SESSION['user_uuid'];
    
    // 获取查询参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    
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
    
    // 构建查询条件
    $where = [];
    $params = [];
    
    if (!empty($search)) {
        $where[] = "(config_name ILIKE :search OR email ILIKE :search OR sender_name ILIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    if ($status !== '') {
        $where[] = "status = :status";
        $params[':status'] = intval($status);
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM site_configs.email_config {$whereClause}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 计算分页
    $offset = ($page - 1) * $pageSize;
    $totalPages = ceil($total / $pageSize);
    
    // 查询配置列表
    $sql = "
        SELECT 
            id,
            config_name,
            email,
            sender_name,
            smtp_host,
            smtp_port,
            encryption,
            scenes,
            daily_limit,
            daily_sent_count,
            last_reset_date,
            reply_to,
            enable_signature,
            status,
            is_enabled,
            priority,
            description,
            created_at,
            updated_at
        FROM site_configs.email_config
        {$whereClause}
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
    
    // 处理数据
    foreach ($configs as &$config) {
        // 解析 scenes JSON
        if (!empty($config['scenes'])) {
            $config['scenes'] = json_decode($config['scenes'], true);
        } else {
            $config['scenes'] = [];
        }
        
        // 脱敏密码
        $config['password'] = '******';
    }
    
    // 构建分页信息
    $pagination = [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_prev' => $page > 1,
        'has_next' => $page < $totalPages
    ];
    
    // 记录日志
    $logger->info('admin', '管理员查看邮件配置列表', [
        'admin_uuid' => $adminUuid,
        'page' => $page,
        'search' => $search
    ]);
    
    // 返回结果
    jsonResponse(true, [
        'configs' => $configs,
        'pagination' => $pagination
    ], '获取成功');
    
} catch (PDOException $e) {
    error_log('获取邮件配置列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取邮件配置列表失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('获取邮件配置列表失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '获取邮件配置列表失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
