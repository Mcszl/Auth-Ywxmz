<?php
/**
 * 获取邮件模板列表
 * 
 * 功能：
 * - 支持分页查询
 * - 支持按场景筛选
 * - 支持按状态筛选
 * - 支持关键词搜索（模板名称、模板标识）
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
    $scene = isset($_GET['scene']) ? trim($_GET['scene']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    
    // 构建查询条件
    $conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $conditions[] = "(template_name LIKE :search OR template_code LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if ($scene !== '') {
        $conditions[] = "scene = :scene";
        $params[':scene'] = $scene;
    }
    
    if ($status !== '') {
        $conditions[] = "status = :status";
        $params[':status'] = intval($status);
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "
        SELECT COUNT(*) as total
        FROM site_configs.email_template
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 查询列表数据
    $listSql = "
        SELECT 
            id,
            template_code,
            template_name,
            scene,
            subject,
            template_variables,
            variable_descriptions,
            status,
            is_enabled,
            priority,
            description,
            created_at,
            updated_at
        FROM site_configs.email_template
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
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    foreach ($templates as &$template) {
        // 解析JSON字段
        $template['template_variables'] = json_decode($template['template_variables'], true);
        $template['variable_descriptions'] = json_decode($template['variable_descriptions'], true);
        
        // 格式化时间
        $template['created_at'] = date('Y-m-d H:i:s', strtotime($template['created_at']));
        $template['updated_at'] = date('Y-m-d H:i:s', strtotime($template['updated_at']));
        
        // 添加场景中文名称
        $sceneMap = [
            'register' => '注册',
            'login' => '登录',
            'reset_password' => '重置密码',
            'password_reset' => '重置密码',
            'change_email' => '修改邮箱',
            'change_phone' => '修改手机号',
            'security_alert' => '安全警报',
            'welcome' => '欢迎邮件'
        ];
        $template['scene_name'] = $sceneMap[$template['scene']] ?? $template['scene'];
        
        // 添加状态中文名称
        $statusMap = [
            0 => '禁用',
            1 => '正常',
            2 => '草稿'
        ];
        $template['status_name'] = $statusMap[$template['status']] ?? '未知';
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'data' => [
            'list' => $templates,
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
    error_log('获取邮件模板列表失败: ' . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}
