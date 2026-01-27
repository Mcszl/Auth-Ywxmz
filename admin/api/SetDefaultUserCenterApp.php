<?php
/**
 * 设置默认用户中心应用 API
 * 管理员可以选择一个应用作为用户中心的默认登录应用
 */

session_start();
require_once __DIR__ . '/../../config/postgresql.config.php';

header('Content-Type: application/json; charset=utf-8');

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

/**
 * 检查管理员权限
 */
function checkAdminPermission($pdo) {
    if (!isset($_SESSION['user_uuid'])) {
        jsonResponse(false, null, '未登录', 401);
    }
    
    $userUuid = $_SESSION['user_uuid'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT user_type 
            FROM users.user 
            WHERE uuid = :uuid AND status = 1
        ");
        $stmt->execute(['uuid' => $userUuid]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, null, '用户不存在或已被禁用', 403);
        }
        
        if (!in_array($user['user_type'], ['admin', 'siteadmin'])) {
            jsonResponse(false, null, '权限不足', 403);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("检查管理员权限失败: " . $e->getMessage());
        jsonResponse(false, null, '服务器错误', 500);
    }
}

// ============================================
// 主逻辑
// ============================================

try {
    // 只允许 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, null, '不支持的请求方法', 405);
    }
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(false, null, '数据库连接失败', 500);
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 检查管理员权限
    checkAdminPermission($pdo);
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['app_id']) || empty($input['app_id'])) {
        jsonResponse(false, null, '应用ID不能为空', 400);
    }
    
    $appId = trim($input['app_id']);
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 1. 检查应用是否存在且状态正常
        $stmt = $pdo->prepare("
            SELECT 
                app_id,
                site_name,
                site_url,
                site_protocol,
                callback_urls,
                permissions,
                status
            FROM site_config
            WHERE app_id = :app_id
        ");
        $stmt->execute(['app_id' => $appId]);
        $app = $stmt->fetch();
        
        if (!$app) {
            $pdo->rollBack();
            jsonResponse(false, null, '应用不存在', 404);
        }
        
        if ($app['status'] != 1) {
            $pdo->rollBack();
            jsonResponse(false, null, '只能设置正常状态的应用为默认应用', 400);
        }
        
        // 2. 获取当前域名，构建用户中心回调地址
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $userCenterCallbackUrl = $protocol . '://' . $host . '/user/callback/';
        
        // 3. 获取应用的回调地址列表
        $callbackUrls = $app['callback_urls'];
        if (is_string($callbackUrls)) {
            // 如果是字符串，尝试解析为数组
            $decoded = json_decode($callbackUrls, true);
            if (is_array($decoded)) {
                $callbackUrls = $decoded;
            } else {
                // 如果不是JSON，可能是PostgreSQL数组格式 {url1,url2}
                $callbackUrls = trim($callbackUrls ?? '', '{}');
                if (!empty($callbackUrls)) {
                    $callbackUrls = explode(',', $callbackUrls);
                    $callbackUrls = array_map('trim', $callbackUrls);
                    // 移除空字符串
                    $callbackUrls = array_filter($callbackUrls, function($url) {
                        return !empty($url);
                    });
                } else {
                    $callbackUrls = [];
                }
            }
        }
        
        if (!is_array($callbackUrls)) {
            $callbackUrls = [];
        }
        
        // 4. 检查用户中心回调地址是否已存在
        $needAddCallback = !in_array($userCenterCallbackUrl, $callbackUrls);
        
        // 5. 如果需要添加回调地址，返回确认信息
        if ($needAddCallback && !isset($input['confirm_add_callback'])) {
            $pdo->rollBack();
            jsonResponse(true, [
                'need_confirm' => true,
                'callback_url' => $userCenterCallbackUrl,
                'current_callbacks' => $callbackUrls,
                'app_id' => $appId,
                'site_name' => $app['site_name']
            ], '需要添加回调地址到应用配置中');
        }
        
        // 6. 如果需要添加回调地址且已确认，则添加
        if ($needAddCallback && isset($input['confirm_add_callback']) && $input['confirm_add_callback'] === true) {
            $callbackUrls[] = $userCenterCallbackUrl;
            
            // 将数组转换为PostgreSQL数组格式
            $pgArray = '{' . implode(',', array_map(function($url) {
                // 转义双引号并用双引号包裹每个URL
                return '"' . str_replace('"', '\\"', $url) . '"';
            }, $callbackUrls)) . '}';
            
            // 更新应用的回调地址
            $stmt = $pdo->prepare("
                UPDATE site_config
                SET callback_urls = :callback_urls::text[],
                    updated_at = CURRENT_TIMESTAMP
                WHERE app_id = :app_id
            ");
            
            $stmt->execute([
                'callback_urls' => $pgArray,
                'app_id' => $appId
            ]);
        }
        
        // 7. 始终使用系统回调域（用户中心回调地址）
        // 注意：user_center_config 表存储的是用户中心登录的回调地址
        // 应该始终指向系统的 /user/callback/，而不是第三方应用的回调地址
        $callbackUrl = $userCenterCallbackUrl;
        
        // 验证回调地址必须是系统回调域
        if ($callbackUrl !== $userCenterCallbackUrl) {
            error_log("警告：尝试将非系统回调域写入 user_center_config: " . $callbackUrl);
            $callbackUrl = $userCenterCallbackUrl;
        }
        
        // 8. 获取权限列表
        $permissions = $app['permissions'];
        if (is_array($permissions)) {
            $permissionsStr = implode(',', $permissions);
        } else if (is_string($permissions)) {
            // 如果是PostgreSQL数组格式，转换为逗号分隔
            $permissionsStr = trim($permissions, '{}');
        } else {
            $permissionsStr = 'user.basic';
        }
        
        // 9. 更新或插入用户中心配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.user_center_config (
                app_id,
                callback_url,
                permissions,
                status,
                created_at,
                updated_at
            ) VALUES (
                :app_id,
                :callback_url,
                :permissions,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON CONFLICT (app_id) DO UPDATE SET
                callback_url = EXCLUDED.callback_url,
                permissions = EXCLUDED.permissions,
                status = EXCLUDED.status,
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            'app_id' => $appId,
            'callback_url' => $callbackUrl,
            'permissions' => $permissionsStr
        ]);
        
        // 10. 如果有其他应用被设置为默认，需要禁用它们
        $stmt = $pdo->prepare("
            UPDATE site_configs.user_center_config
            SET status = 0, updated_at = CURRENT_TIMESTAMP
            WHERE app_id != :app_id AND status = 1
        ");
        $stmt->execute(['app_id' => $appId]);
        
        // 提交事务
        $pdo->commit();
        
        jsonResponse(true, [
            'app_id' => $appId,
            'site_name' => $app['site_name'],
            'callback_added' => $needAddCallback
        ], '设置成功');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("设置默认用户中心应用失败: " . $e->getMessage());
        jsonResponse(false, null, '设置失败：' . $e->getMessage(), 500);
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("设置默认用户中心应用错误: " . $e->getMessage());
    jsonResponse(false, null, '服务器错误', 500);
}
