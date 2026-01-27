<?php
/**
 * 测试系统日志功能
 * 用于验证日志记录和查询是否正常工作
 */

require_once __DIR__ . '/../../config/postgresql.config.php';
require_once __DIR__ . '/../../logs/SystemLogger.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 设置时区为北京时间
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 创建系统日志实例
    $logger = new SystemLogger($pdo);
    
    // 测试不同级别的日志
    $testResults = [];
    
    // 1. 测试 DEBUG 日志
    $result1 = $logger->debug(SystemLogger::TYPE_SYSTEM, '这是一条调试日志', [
        'module' => 'test',
        'action' => 'debug_test',
        'username' => 'test_user',
        'details' => ['test_key' => 'test_value']
    ]);
    $testResults[] = ['level' => 'DEBUG', 'success' => $result1];
    
    // 2. 测试 INFO 日志
    $result2 = $logger->info(SystemLogger::TYPE_API, '这是一条信息日志', [
        'module' => 'test',
        'action' => 'info_test',
        'username' => 'test_user',
        'user_id' => 1,
        'details' => ['api_endpoint' => '/test/api']
    ]);
    $testResults[] = ['level' => 'INFO', 'success' => $result2];
    
    // 3. 测试 WARNING 日志
    $result3 = $logger->warning(SystemLogger::TYPE_SECURITY, '这是一条警告日志', [
        'module' => 'test',
        'action' => 'warning_test',
        'username' => 'test_user',
        'details' => ['warning_reason' => '测试警告']
    ]);
    $testResults[] = ['level' => 'WARNING', 'success' => $result3];
    
    // 4. 测试 ERROR 日志
    $result4 = $logger->error(SystemLogger::TYPE_DATABASE, '这是一条错误日志', [
        'module' => 'test',
        'action' => 'error_test',
        'username' => 'test_user',
        'details' => ['error_message' => '测试错误'],
        'stack_trace' => 'Test stack trace line 1\nTest stack trace line 2'
    ]);
    $testResults[] = ['level' => 'ERROR', 'success' => $result4];
    
    // 5. 测试 CRITICAL 日志
    $result5 = $logger->critical(SystemLogger::TYPE_SYSTEM, '这是一条严重错误日志', [
        'module' => 'test',
        'action' => 'critical_test',
        'username' => 'test_user',
        'details' => ['critical_issue' => '系统严重错误'],
        'stack_trace' => 'Critical error stack trace'
    ]);
    $testResults[] = ['level' => 'CRITICAL', 'success' => $result5];
    
    // 6. 测试操作日志
    $result6 = $logger->operation('user_login', '用户登录成功', [
        'module' => 'auth',
        'username' => 'test_user',
        'user_id' => 1,
        'details' => ['login_method' => 'password']
    ]);
    $testResults[] = ['level' => 'OPERATION', 'success' => $result6];
    
    // 7. 测试安全日志
    $result7 = $logger->security('检测到可疑登录尝试', [
        'module' => 'security',
        'username' => 'suspicious_user',
        'details' => ['attempt_count' => 5]
    ]);
    $testResults[] = ['level' => 'SECURITY', 'success' => $result7];
    
    // 8. 测试 API 日志
    $result8 = $logger->api('API 调用成功', [
        'module' => 'api',
        'action' => 'get_user_info',
        'username' => 'test_user',
        'details' => ['endpoint' => '/api/user/info']
    ]);
    $testResults[] = ['level' => 'API', 'success' => $result8];
    
    echo json_encode([
        'success' => true,
        'message' => '测试日志已创建',
        'data' => [
            'test_results' => $testResults,
            'total_tests' => count($testResults),
            'successful_tests' => count(array_filter($testResults, function($r) { return $r['success']; }))
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("测试系统日志失败: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '测试失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
