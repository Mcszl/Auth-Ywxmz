<?php
/**
 * 绑定QQ账号
 * 用户在用户中心点击绑定QQ后，跳转到QQ授权页面
 * 授权成功后回调，将QQ OpenID与用户UUID绑定
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 启动session
session_start();

try {
    // 检查登录状态
    if (!isset($_SESSION['user_uuid'])) {
        echo json_encode([
            'success' => false,
            'message' => '未登录'
        ]);
        exit;
    }
    
    $userUuid = $_SESSION['user_uuid'];
    
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 初始化日志记录器
    $logger = new SystemLogger($pdo);
    
    // 检查是否已经绑定QQ
    $stmt = $pdo->prepare("
        SELECT id, bind_status
        FROM auth.qq_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $existingBind = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingBind && $existingBind['bind_status'] == 1) {
        echo json_encode([
            'success' => false,
            'message' => '您已经绑定了QQ账号'
        ]);
        exit;
    }
    
    // 检查是否有临时QQ信息（从QQ登录回调保存的）
    if (!isset($_SESSION['qq_temp_info'])) {
        // 没有临时信息，需要跳转到QQ授权页面
        // 这里返回授权URL，让前端跳转
        
        // 从数据库获取QQ登录配置
        $stmt = $pdo->prepare("
            SELECT 
                app_id,
                callback_url,
                extra_config
            FROM auth.third_party_login_config
            WHERE platform = :platform
            AND is_enabled = true
            AND status = 1
            ORDER BY priority ASC
            LIMIT 1
        ");
        
        $stmt->execute(['platform' => 'qq']);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            throw new Exception('QQ登录配置未启用或不存在');
        }
        
        // 解析额外配置
        $extraConfig = json_decode($config['extra_config'], true);
        if (!$extraConfig) {
            $extraConfig = [];
        }
        
        // 生成state参数（防CSRF）
        $state = bin2hex(random_bytes(16));
        $_SESSION['qq_bind_state'] = $state;
        $_SESSION['qq_bind_time'] = time();
        $_SESSION['qq_bind_mode'] = 'bind'; // 标记为绑定模式
        
        // QQ互联授权地址
        $authorizeUrl = $extraConfig['authorize_url'] ?? 'https://graph.qq.com/oauth2.0/authorize';
        
        // 构建授权URL
        $params = [
            'response_type' => 'code',
            'client_id' => $config['app_id'],
            'redirect_uri' => $config['callback_url'],
            'state' => $state,
            'scope' => 'get_user_info'
        ];
        
        $authUrl = $authorizeUrl . '?' . http_build_query($params);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'auth_url' => $authUrl,
                'need_auth' => true
            ],
            'message' => '请跳转到QQ授权页面'
        ]);
        exit;
    }
    
    // 有临时QQ信息，执行绑定
    $qqInfo = $_SESSION['qq_temp_info'];
    $openid = $qqInfo['openid'];
    
    // 检查这个OpenID是否已经被其他用户绑定
    $stmt = $pdo->prepare("
        SELECT user_uuid, bind_status
        FROM auth.qq_user_info
        WHERE openid = :openid
    ");
    
    $stmt->execute(['openid' => $openid]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser && $existingUser['bind_status'] == 1 && $existingUser['user_uuid'] != $userUuid) {
        // 清除临时信息
        unset($_SESSION['qq_temp_info']);
        
        echo json_encode([
            'success' => false,
            'message' => '该QQ账号已被其他用户绑定'
        ]);
        exit;
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        if ($existingUser) {
            // 更新绑定状态
            $stmt = $pdo->prepare("
                UPDATE auth.qq_user_info
                SET 
                    user_uuid = :user_uuid,
                    bind_status = 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE openid = :openid
            ");
            
            $stmt->execute([
                'user_uuid' => $userUuid,
                'openid' => $openid
            ]);
        } else {
            // 插入新记录
            $stmt = $pdo->prepare("
                INSERT INTO auth.qq_user_info (
                    openid,
                    user_uuid,
                    qq_nickname,
                    qq_avatar,
                    qq_gender,
                    bind_status
                ) VALUES (
                    :openid,
                    :user_uuid,
                    :qq_nickname,
                    :qq_avatar,
                    :qq_gender,
                    1
                )
            ");
            
            $stmt->execute([
                'openid' => $openid,
                'user_uuid' => $userUuid,
                'qq_nickname' => $qqInfo['nickname'] ?? '',
                'qq_avatar' => $qqInfo['avatar'] ?? '',
                'qq_gender' => $qqInfo['gender'] ?? ''
            ]);
        }
        
        // 提交事务
        $pdo->commit();
        
        // 清除临时信息
        unset($_SESSION['qq_temp_info']);
        
        // 记录日志
        $logger->log(
            'info',
            'bind_qq_success',
            'QQ账号绑定成功',
            [
                'user_uuid' => $userUuid,
                'openid' => $openid,
                'nickname' => $qqInfo['nickname'] ?? ''
            ]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'QQ账号绑定成功'
        ]);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'bind_qq_error',
            'QQ账号绑定失败: ' . $e->getMessage(),
            [
                'user_uuid' => $_SESSION['user_uuid'] ?? null,
                'error' => $e->getMessage()
            ]
        );
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
