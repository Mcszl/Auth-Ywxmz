<?php
/**
 * 获取用户第三方账号绑定信息
 */

// 引入数据库配置
require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

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
    
    // 查询QQ绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            openid,
            qq_nickname,
            qq_avatar,
            qq_gender,
            bind_status,
            created_at,
            updated_at
        FROM auth.qq_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $qqInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 查询微信绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            openid,
            wechat_nickname,
            wechat_avatar,
            wechat_gender,
            bind_status,
            created_at,
            updated_at
        FROM auth.wechat_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $wechatInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 查询微博绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            uid,
            weibo_nickname,
            weibo_avatar,
            weibo_gender,
            bind_status,
            created_at,
            updated_at
        FROM auth.weibo_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $weiboInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 查询 GitHub 绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            github_id,
            github_login,
            github_name,
            github_avatar,
            github_email,
            bind_status,
            created_at,
            updated_at
        FROM auth.github_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $githubInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 查询 Google 绑定信息
    $stmt = $pdo->prepare("
        SELECT 
            google_id,
            google_email,
            google_name,
            google_avatar,
            bind_status,
            created_at,
            updated_at
        FROM auth.google_user_info
        WHERE user_uuid = :user_uuid
    ");
    
    $stmt->execute(['user_uuid' => $userUuid]);
    $googleInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 构建返回数据
    $bindings = [
        'qq' => null,
        'wechat' => null,
        'weibo' => null,
        'github' => null,
        'google' => null
    ];
    
    if ($qqInfo && $qqInfo['bind_status'] == 1) {
        $bindings['qq'] = [
            'bound' => true,
            'nickname' => $qqInfo['qq_nickname'],
            'avatar' => $qqInfo['qq_avatar'],
            'gender' => $qqInfo['qq_gender'],
            'bind_time' => $qqInfo['updated_at'] ?? $qqInfo['created_at']
        ];
    } else {
        $bindings['qq'] = [
            'bound' => false
        ];
    }
    
    if ($wechatInfo && $wechatInfo['bind_status'] == 1) {
        // 微信性别：0-未知，1-男，2-女
        $genderText = ['0' => '未知', '1' => '男', '2' => '女'][$wechatInfo['wechat_gender']] ?? '未知';
        
        $bindings['wechat'] = [
            'bound' => true,
            'nickname' => $wechatInfo['wechat_nickname'],
            'avatar' => $wechatInfo['wechat_avatar'],
            'gender' => $genderText,
            'bind_time' => $wechatInfo['updated_at'] ?? $wechatInfo['created_at']
        ];
    } else {
        $bindings['wechat'] = [
            'bound' => false
        ];
    }
    
    if ($weiboInfo && $weiboInfo['bind_status'] == 1) {
        // 微博性别：m-男，f-女，n-未知
        $genderText = ['m' => '男', 'f' => '女', 'n' => '未知'][$weiboInfo['weibo_gender']] ?? '未知';
        
        $bindings['weibo'] = [
            'bound' => true,
            'nickname' => $weiboInfo['weibo_nickname'],
            'avatar' => $weiboInfo['weibo_avatar'],
            'gender' => $genderText,
            'bind_time' => $weiboInfo['updated_at'] ?? $weiboInfo['created_at']
        ];
    } else {
        $bindings['weibo'] = [
            'bound' => false
        ];
    }
    
    if ($githubInfo && $githubInfo['bind_status'] == 1) {
        $bindings['github'] = [
            'bound' => true,
            'login' => $githubInfo['github_login'],
            'nickname' => $githubInfo['github_name'] ?? $githubInfo['github_login'],
            'avatar' => $githubInfo['github_avatar'],
            'email' => $githubInfo['github_email'],
            'bind_time' => $githubInfo['updated_at'] ?? $githubInfo['created_at']
        ];
    } else {
        $bindings['github'] = [
            'bound' => false
        ];
    }
    
    if ($googleInfo && $googleInfo['bind_status'] == 1) {
        $bindings['google'] = [
            'bound' => true,
            'name' => $googleInfo['google_name'],
            'nickname' => $googleInfo['google_name'],
            'avatar' => $googleInfo['google_avatar'],
            'email' => $googleInfo['google_email'],
            'bind_time' => $googleInfo['updated_at'] ?? $googleInfo['created_at']
        ];
    } else {
        $bindings['google'] = [
            'bound' => false
        ];
    }
    
    // 记录日志
    $logger->log(
        'info',
        'get_third_party_bindings',
        '获取第三方绑定信息',
        [
            'user_uuid' => $userUuid,
            'qq_bound' => $bindings['qq']['bound'],
            'wechat_bound' => $bindings['wechat']['bound'],
            'weibo_bound' => $bindings['weibo']['bound'],
            'github_bound' => $bindings['github']['bound'],
            'google_bound' => $bindings['google']['bound']
        ]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $bindings,
        'message' => '获取成功'
    ]);
    
} catch (Exception $e) {
    // 记录错误日志
    if (isset($logger)) {
        $logger->log(
            'error',
            'get_third_party_bindings_error',
            '获取第三方绑定信息失败: ' . $e->getMessage(),
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
