<?php
/**
 * 管理员审核昵称 API
 * 审核通过后更新用户昵称
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
    jsonResponse(false, null, '请求方法错误', 400);
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
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $checkId = isset($input['check_id']) ? (int)$input['check_id'] : 0;
    $action = $input['action'] ?? '';
    $rejectReason = $input['reject_reason'] ?? '';
    $reviewComment = $input['review_comment'] ?? '';
    
    if ($checkId <= 0) {
        jsonResponse(false, null, '无效的审核ID', 400);
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        jsonResponse(false, null, '无效的操作', 400);
    }
    
    if ($action === 'reject' && empty($rejectReason)) {
        jsonResponse(false, null, '请填写拒绝原因', 400);
    }
    
    // 查询审核记录
    $stmt = $pdo->prepare("
        SELECT * FROM checks.nickname_check 
        WHERE id = :id AND status = 0
    ");
    $stmt->execute([':id' => $checkId]);
    $checkRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkRecord) {
        jsonResponse(false, null, '审核记录不存在或已处理', 404);
    }
    
    // 开启事务
    $pdo->beginTransaction();
    
    try {
        if ($action === 'approve') {
            // 审核通过 - 更新用户昵称
            $stmt = $pdo->prepare("
                UPDATE users.user 
                SET nickname = :nickname, updated_at = CURRENT_TIMESTAMP 
                WHERE uuid = :uuid
            ");
            $stmt->execute([
                ':nickname' => $checkRecord['new_nickname'],
                ':uuid' => $checkRecord['user_uuid']
            ]);
            
            // 更新审核记录为通过
            $stmt = $pdo->prepare("
                UPDATE checks.nickname_check 
                SET status = 1,
                    review_time = CURRENT_TIMESTAMP,
                    reviewer_uuid = :reviewer_uuid,
                    reviewer_name = :reviewer_name,
                    review_comment = :review_comment
                WHERE id = :id
            ");
            $stmt->execute([
                ':reviewer_uuid' => $admin['uuid'],
                ':reviewer_name' => $admin['username'],
                ':review_comment' => $reviewComment,
                ':id' => $checkId
            ]);
            
            $pdo->commit();
            
            jsonResponse(true, [
                'nickname' => $checkRecord['new_nickname']
            ], '审核通过，昵称已更新');
            
        } else {
            // 审核拒绝
            $stmt = $pdo->prepare("
                UPDATE checks.nickname_check 
                SET status = 2,
                    review_time = CURRENT_TIMESTAMP,
                    reviewer_uuid = :reviewer_uuid,
                    reviewer_name = :reviewer_name,
                    review_comment = :review_comment,
                    reject_reason = :reject_reason
                WHERE id = :id
            ");
            $stmt->execute([
                ':reviewer_uuid' => $admin['uuid'],
                ':reviewer_name' => $admin['username'],
                ':review_comment' => $reviewComment,
                ':reject_reason' => $rejectReason,
                ':id' => $checkId
            ]);
            
            $pdo->commit();
            
            jsonResponse(true, null, '已标记为不通过');
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('审核昵称失败: ' . $e->getMessage());
    jsonResponse(false, null, '数据库错误', 500);
} catch (Exception $e) {
    // 其他错误
    error_log('审核昵称失败: ' . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
