<?php
/**
 * 新增敏感词
 * 
 * @author AI Assistant
 * @date 2024-01-24
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

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, '请求方法错误', 405);
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
    
    // 获取 POST 数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 验证必填字段
    $word = isset($input['word']) ? trim($input['word']) : '';
    $category = isset($input['category']) ? trim($input['category']) : '';
    $severity = isset($input['severity']) ? (int)$input['severity'] : 1;
    $action = isset($input['action']) ? trim($input['action']) : 'reject';
    $replacement = isset($input['replacement']) ? trim($input['replacement']) : null;
    $description = isset($input['description']) ? trim($input['description']) : '';
    
    if (empty($word)) {
        throw new Exception('敏感词不能为空');
    }
    
    if (empty($category)) {
        throw new Exception('请选择分类');
    }
    
    // 检查敏感词是否已存在
    $stmt = $pdo->prepare("
        SELECT id FROM checks.sensitive_words WHERE word = :word
    ");
    $stmt->execute([':word' => $word]);
    
    if ($stmt->fetch()) {
        throw new Exception('该敏感词已存在');
    }
    
    // 插入敏感词
    $stmt = $pdo->prepare("
        INSERT INTO checks.sensitive_words (
            word, category, severity, action, replacement,
            description, is_enabled, created_by
        ) VALUES (
            :word, :category, :severity, :action, :replacement,
            :description, TRUE, :created_by
        )
        RETURNING id
    ");
    
    $stmt->execute([
        ':word' => $word,
        ':category' => $category,
        ':severity' => $severity,
        ':action' => $action,
        ':replacement' => $replacement,
        ':description' => $description,
        ':created_by' => $user['username']
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '新增敏感词成功',
        'data' => ['id' => $result['id']],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('新增敏感词失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('新增敏感词失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
