<?php
/**
 * 获取短信配置列表
 * 
 * 功能：
 * - 支持分页查询
 * - 支持按用途筛选
 * - 支持按渠道筛选
 * - 支持按状态筛选
 * - 支持关键词搜索（配置名称、签名）
 */

// 设置时区为北京时间
date_default_timezone_set('Asia/Shanghai');

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once '../../config/postgresql.config.php';

// 开启会话
session_start();

try {
    // 验证登录状态
    if (!isset($_SESSION['user_uuid'])) {
        throw new Exception('未登录');
    }

    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        throw new Exception($message);
    });
    
    // 获取请求参数
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['pageSize']) ? max(1, min(100, intval($_GET['pageSize']))) : 10;
    $offset = ($page - 1) * $pageSize;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $purpose = isset($_GET['purpose']) ? trim($_GET['purpose']) : '';
    $channel = isset($_GET['channel']) ? trim($_GET['channel']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    
    // 构建查询条件
    $conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $conditions[] = "(config_name LIKE :search OR signature LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if ($purpose !== '') {
        $conditions[] = "purpose = :purpose";
        $params[':purpose'] = $purpose;
    }
    
    if ($channel !== '') {
        $conditions[] = "channel = :channel";
        $params[':channel'] = $channel;
    }
    
    if ($status !== '') {
        $conditions[] = "status = :status";
        $params[':status'] = intval($status);
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM site_configs.sms_config
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询列表数据
    $listSql = "
        SELECT 
            id,
            config_name,
            purpose,
            channel,
            signature,
            template_id,
            template_content,
            is_enabled,
            status,
            priority,
            daily_limit,
            daily_sent_count,
            last_reset_date,
            description,
            created_at,
            updated_at
        FROM site_configs.sms_config
        $whereClause
        ORDER BY priority ASC, id DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($listSql);
    
    // 绑定参数
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    foreach ($configs as &$config) {
        // 格式化时间
        $config['created_at'] = date('Y-m-d H:i:s', strtotime($config['created_at']));
        $config['updated_at'] = date('Y-m-d H:i:s', strtotime($config['updated_at']));
        
        // 添加用途中文名称
        $purposeMap = [
            'register' => '注册',
            'login' => '登录',
            'reset_password' => '重置密码',
            'password_reset' => '重置密码',
            'change_phone' => '修改手机号',
            'change_email' => '修改邮箱',
            'security_alert' => '安全警报',
            'verify' => '验证'
        ];
        $config['purpose_name'] = $purposeMap[$config['purpose']] ?? $config['purpose'];
        
        // 添加渠道中文名称
        $channelMap = [
            'aliyun' => '阿里云',
            'tencent' => '腾讯云',
            '321cn' => '321.com.cn'
        ];
        $config['channel_name'] = $channelMap[$config['channel']] ?? $config['channel'];
        
        // 添加状态中文名称
        $statusMap = [
            0 => '禁用',
            1 => '正常',
            2 => '维护中'
        ];
        $config['status_name'] = $statusMap[$config['status']] ?? '未知';
        
        // 计算剩余额度
        $config['remaining_quota'] = max(0, $config['daily_limit'] - $config['daily_sent_count']);
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => [
            'list' => $configs,
            'total' => intval($total),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($total / $pageSize)
        ],
        'message' => '获取成功',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 记录错误日志
    error_log('获取短信配置列表失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
