<?php
/**
 * 更新用户信息 API
 * 
 * 功能：
 * 1. 验证管理员权限
 * 2. 更新指定用户的信息
 * 3. 支持更新：昵称、手机号、邮箱、用户类型、状态、性别、生日
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
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 检查管理员权限
    if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $adminUuid = $_SESSION['user_uuid'];
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, '无效的请求数据', 400);
    }
    
    $userUuid = isset($input['uuid']) ? trim($input['uuid']) : '';
    
    if (empty($userUuid)) {
        jsonResponse(false, null, '缺少用户UUID参数', 400);
    }
    
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
    
    // 判断是否是超级管理员（ID为1）
    $isSuperAdmin = (isset($admin['id']) && $admin['id'] == 1);
    
    // 检查目标用户是否存在
    $stmt = $pdo->prepare("
        SELECT id, username, user_type
        FROM users.user
        WHERE uuid = :uuid
    ");
    $stmt->execute([':uuid' => $userUuid]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        jsonResponse(false, null, '用户不存在', 404);
    }
    
    // 权限控制
    $isTargetUserId1 = ($targetUser['id'] == 1);
    $isTargetAdmin = in_array($targetUser['user_type'], ['admin', 'siteadmin']);
    
    // 普通管理员不能修改其他管理员
    if (!$isSuperAdmin && $isTargetAdmin) {
        jsonResponse(false, null, '无权修改管理员信息', 403);
    }
    
    // 构建更新字段
    $updateFields = [];
    $updateParams = [':uuid' => $userUuid];
    $changes = [];
    
    // 昵称
    if (isset($input['nickname'])) {
        $nickname = trim($input['nickname']);
        if (mb_strlen($nickname) > 20) {
            jsonResponse(false, null, '昵称长度不能超过20个字符', 400);
        }
        $updateFields[] = "nickname = :nickname";
        $updateParams[':nickname'] = $nickname;
        $changes[] = "昵称: {$nickname}";
    }
    
    // 手机号
    if (isset($input['phone'])) {
        $phone = trim($input['phone']);
        if (!empty($phone) && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            jsonResponse(false, null, '手机号格式不正确', 400);
        }
        
        // 检查手机号是否已被其他用户使用
        if (!empty($phone)) {
            $stmt = $pdo->prepare("
                SELECT uuid FROM users.user 
                WHERE phone = :phone AND uuid != :uuid
            ");
            $stmt->execute([':phone' => $phone, ':uuid' => $userUuid]);
            if ($stmt->fetch()) {
                jsonResponse(false, null, '该手机号已被其他用户使用', 400);
            }
        }
        
        $updateFields[] = "phone = :phone";
        $updateParams[':phone'] = $phone ?: null;
        $changes[] = "手机号: " . ($phone ?: '清空');
    }
    
    // 邮箱
    if (isset($input['email'])) {
        $email = trim($input['email']);
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, null, '邮箱格式不正确', 400);
        }
        
        // 检查邮箱是否已被其他用户使用
        if (!empty($email)) {
            $stmt = $pdo->prepare("
                SELECT uuid FROM users.user 
                WHERE email = :email AND uuid != :uuid
            ");
            $stmt->execute([':email' => $email, ':uuid' => $userUuid]);
            if ($stmt->fetch()) {
                jsonResponse(false, null, '该邮箱已被其他用户使用', 400);
            }
        }
        
        $updateFields[] = "email = :email";
        $updateParams[':email'] = $email ?: null;
        $changes[] = "邮箱: " . ($email ?: '清空');
    }
    
    // 用户类型
    if (isset($input['user_type'])) {
        $userType = trim($input['user_type']);
        $allowedTypes = ['user', 'admin', 'siteadmin'];
        if (!in_array($userType, $allowedTypes)) {
            jsonResponse(false, null, '无效的用户类型', 400);
        }
        
        // ID为1的用户不能被设置为非管理员
        if ($isTargetUserId1 && $userType !== 'admin') {
            jsonResponse(false, null, '超级管理员不能被设置为其他类型', 403);
        }
        
        // 普通管理员不能设置用户为管理员
        if (!$isSuperAdmin && $userType === 'admin') {
            jsonResponse(false, null, '无权设置管理员', 403);
        }
        
        // 普通管理员可以设置站点管理员
        
        $updateFields[] = "user_type = :user_type";
        $updateParams[':user_type'] = $userType;
        $changes[] = "用户类型: {$userType}";
    }
    
    // 状态
    if (isset($input['status'])) {
        $status = intval($input['status']);
        if (!in_array($status, [0, 1, 2, 3])) {
            jsonResponse(false, null, '无效的状态值', 400);
        }
        $updateFields[] = "status = :status";
        $updateParams[':status'] = $status;
        $statusText = ['已封禁', '正常', '手机号待核验', '邮箱待核验'][$status];
        $changes[] = "状态: {$statusText}";
    }
    
    // 性别
    if (isset($input['gender'])) {
        $gender = trim($input['gender']);
        // 将字符串转换为数字：male=1, female=2, other=3, 空=0
        $genderMap = [
            '' => 0,
            'male' => 1,
            'female' => 2,
            'other' => 3
        ];
        
        if (!array_key_exists($gender, $genderMap)) {
            jsonResponse(false, null, '无效的性别值', 400);
        }
        
        $genderValue = $genderMap[$gender];
        $updateFields[] = "gender = :gender";
        $updateParams[':gender'] = $genderValue;
        $genderText = ['未设置', '男', '女', '其他'][$genderValue];
        $changes[] = "性别: {$genderText}";
    }
    
    // 生日
    if (isset($input['birth_date'])) {
        $birthDate = trim($input['birth_date']);
        if (!empty($birthDate)) {
            // 验证日期格式
            $date = DateTime::createFromFormat('Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate) {
                jsonResponse(false, null, '日期格式不正确，应为 YYYY-MM-DD', 400);
            }
        }
        $updateFields[] = "birth_date = :birth_date";
        $updateParams[':birth_date'] = $birthDate ?: null;
        $changes[] = "生日: " . ($birthDate ?: '清空');
    }
    
    // 如果没有要更新的字段
    if (empty($updateFields)) {
        jsonResponse(false, null, '没有要更新的字段', 400);
    }
    
    // 添加更新时间
    $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
    
    // 执行更新
    $sql = "UPDATE users.user SET " . implode(', ', $updateFields) . " WHERE uuid = :uuid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($updateParams);
    
    // 记录日志
    $logger->info('admin', '管理员更新用户信息', [
        'admin' => $admin['username'],
        'target_user' => $targetUser['username'],
        'target_uuid' => $userUuid,
        'changes' => implode(', ', $changes)
    ]);
    
    // 返回成功
    jsonResponse(true, null, '更新成功');
    
} catch (PDOException $e) {
    error_log('更新用户信息失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '更新用户信息失败：数据库错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
} catch (Exception $e) {
    error_log('更新用户信息失败: ' . $e->getMessage());
    
    if (isset($logger)) {
        $logger->error('admin', '更新用户信息失败：系统错误', [
            'error' => $e->getMessage()
        ]);
    }
    
    jsonResponse(false, null, '系统错误，请稍后重试', 500);
}
