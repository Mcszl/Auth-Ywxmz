<?php
/**
 * 获取敏感词列表
 * 
 * @author AI Assistant
 * @date 2024-01-23
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 开启会话
session_start();

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

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    jsonResponse(false, null, '未登录', 401);
}

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, 'jsonResponse');
    
    // 获取查询参数
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? max(1, min(100, (int)$_GET['page_size'])) : 20;
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $isEnabled = isset($_GET['is_enabled']) ? $_GET['is_enabled'] : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // 构建查询条件
    $where = ['1=1'];
    $params = [];
    
    if ($category !== '') {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }
    
    if ($isEnabled !== '') {
        $where[] = 'is_enabled = :is_enabled';
        $params[':is_enabled'] = $isEnabled === '1' || $isEnabled === 'true';
    }
    
    if ($search !== '') {
        $where[] = '(word ILIKE :search OR description ILIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    
    $whereClause = implode(' AND ', $where);
    
    // 查询总数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM checks.sensitive_words
        WHERE {$whereClause}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 计算分页
    $totalPages = ceil($total / $pageSize);
    $offset = ($page - 1) * $pageSize;
    
    // 查询敏感词列表
    $stmt = $pdo->prepare("
        SELECT 
            id,
            word,
            category,
            severity,
            action,
            replacement,
            is_enabled,
            description,
            created_at,
            updated_at,
            created_by
        FROM checks.sensitive_words
        WHERE {$whereClause}
        ORDER BY severity DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $words = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    foreach ($words as &$word) {
        // 分类名称
        $categoryNames = [
            'system' => '系统保留',
            'political' => '政治',
            'pornographic' => '色情',
            'violent' => '暴力',
            'advertising' => '广告',
            'abuse' => '辱骂',
            'other' => '其他'
        ];
        $word['category_name'] = $categoryNames[$word['category']] ?? $word['category'];
        
        // 严重程度名称
        $severityNames = [
            1 => '低',
            2 => '中',
            3 => '高'
        ];
        $word['severity_name'] = $severityNames[$word['severity']] ?? '未知';
        
        // 处理动作名称
        $actionNames = [
            'reject' => '拒绝',
            'warn' => '警告',
            'replace' => '替换'
        ];
        $word['action_name'] = $actionNames[$word['action']] ?? $word['action'];
        
        // 状态名称
        $word['status_name'] = $word['is_enabled'] ? '启用' : '禁用';
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '获取敏感词列表成功',
        'data' => [
            'words' => $words,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $totalPages
        ],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('获取敏感词列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('获取敏感词列表失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系统错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
