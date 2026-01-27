<?php
/**
 * 一碗小米周开放平台 - 安装向导
 */
session_start();
define('INSTALL_LOCK_FILE', __DIR__ . '/../config/install.lock');
define('CONFIG_DIR', __DIR__ . '/../config');
define('SQL_DIR', __DIR__ . '/../sql');

// 检查是否已安装
if (file_exists(INSTALL_LOCK_FILE)) {
    die('系统已安装！如需重新安装，请删除 config/install.lock 文件。');
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    require_once 'install_handler.php';
    $handler = new InstallHandler();
    echo json_encode($handler->handle($_POST['action'], $_POST));
    exit;
}

// 必需的SQL文件列表（按执行顺序）
$requiredSqlFiles = [
    'users.sql', 'user_openid.sql', 'user_center_config.sql',
    'site.sql', 'tokens.sql', 'access_token.sql', 'authority.sql',
    'third_party_login_config.sql', 'qq_user_info.sql', 'wechat_user_info.sql',
    'weibo_user_info.sql', 'github_user_info.sql',
    'sms.sql', 'sms_limit.sql', 'email_code.sql', 'email_config.sql',
    'captcha_config.sql', 'captcha_log_optimization.sql',
    'storage_config.sql', 'system_logs.sql',
    'avatar_check.sql', 'avatar_check_migration.sql', 'nickname_check.sql'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - 一碗小米周开放平台</title>
    <link rel="stylesheet" href="https://fa.2hs.cn/pro/7.0.0/css/all.css">
    <link rel="stylesheet" href="install.css">
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1><i class="fas fa-rocket"></i> 一碗小米周开放平台</h1>
            <p>安装向导</p>
        </div>
        
        <div class="install-steps">
            <div class="step active" data-step="1"><span>1</span> 环境检查</div>
            <div class="step" data-step="2"><span>2</span> 数据库配置</div>
            <div class="step" data-step="3"><span>3</span> 站点配置</div>
            <div class="step" data-step="4"><span>4</span> 安装数据表</div>
            <div class="step" data-step="5"><span>5</span> 创建管理员</div>
            <div class="step" data-step="6"><span>6</span> 完成安装</div>
        </div>
        
        <div class="install-content" id="install-content">
            <!-- 内容将通过JavaScript动态加载 -->
        </div>
    </div>
    
    <script>
        const sqlFiles = <?php echo json_encode($requiredSqlFiles); ?>;
    </script>
    <script src="install.js"></script>
</body>
</html>
