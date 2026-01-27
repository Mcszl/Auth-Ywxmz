<?php
/**
 * 批量更新敏感词（启用/禁用/改分类）
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
    $ids = isset($input['ids']) ? $input['ids'] : [];
    $action = isset($input['action']) ? trim($input['action']) : '';
    
    if (empty($ids) || !is_array($ids)) {
        throw new Exception('敏感词ID列表不能为空');
    }
    
    if (empty($action)) {
        throw new Exception('请指定操作类型');
    }
    
    // 验证所有ID都是数字
    foreach ($ids as $id) {
        if (!is_numeric($id) || $id <= 0) {
            throw new Exception('敏感词ID无效');
        }
    }
    
    // 根据操作类型执行不同的更新
    switch ($action) {
        case 'enable':
            // 批量启用
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                UPDATE checks.sensitive_words
                SET is_enabled = TRUE
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $message = '批量启用成功';
            break;
            
        case 'disable':
            // 批量禁用
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                UPDATE checks.sensitive_words
                SET is_enabled = FALSE
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $message = '批量禁用成功';
            break;
            
        case 'change_category':
            // 批量更改分类
            $category = isset($input['category']) ? trim($input['category']) : '';
            
            if (empty($category)) {
                throw new Exception('请选择新分类');
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                UPDATE checks.sensitive_words
                SET category = ?
                WHERE id IN ($placeholders)
            ");
            
            $params = array_merge([$category], $ids);
            $stmt->execute($params);
            $message = '批量更改分类成功';
            break;
            
        default:
            throw new Exception('不支持的操作类型');
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => ['affected_count' => $stmt->rowCount()],
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('批量更新敏感词失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('批量更新敏感词失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
