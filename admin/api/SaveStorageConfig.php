<?php
/**
 * 保存存储配置
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

// 检查是否登录
if (!isset($_SESSION['user_uuid'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '请求方法错误',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取数据库连接
    $pdo = getDBConnection();
    
    // 设置时区
    $pdo->exec("SET timezone = 'Asia/Shanghai'");
    
    // 验证管理员权限
    require_once __DIR__ . '/AdminAuthHelper.php';
    $admin = AdminAuthHelper::checkAdminPermission($pdo, function($success, $data, $message, $code) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    
    // 获取 POST 数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 获取配置参数
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $configName = isset($input['config_name']) ? trim($input['config_name']) : '';
    $usageType = isset($input['usage_type']) ? trim($input['usage_type']) : '';
    $storageType = isset($input['storage_type']) ? trim($input['storage_type']) : 'local';
    $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : true;
    
    // 本地存储配置
    $localPath = isset($input['local_path']) ? trim($input['local_path']) : '';
    $localUrlPrefix = isset($input['local_url_prefix']) ? trim($input['local_url_prefix']) : '';
    $localAutoCreatePath = isset($input['local_auto_create_path']) ? (bool)$input['local_auto_create_path'] : false;
    
    // S3存储配置
    $s3Endpoint = isset($input['s3_endpoint']) ? trim($input['s3_endpoint']) : '';
    $s3Region = isset($input['s3_region']) ? trim($input['s3_region']) : '';
    $s3Bucket = isset($input['s3_bucket']) ? trim($input['s3_bucket']) : '';
    $s3Path = isset($input['s3_path']) ? trim($input['s3_path']) : '';
    $s3AccessKey = isset($input['s3_access_key']) ? trim($input['s3_access_key']) : '';
    $s3SecretKey = isset($input['s3_secret_key']) ? trim($input['s3_secret_key']) : '';
    $s3UsePathStyle = isset($input['s3_use_path_style']) ? (bool)$input['s3_use_path_style'] : false;
    $s3UrlPrefix = isset($input['s3_url_prefix']) ? trim($input['s3_url_prefix']) : '';
    $s3AutoCreatePath = isset($input['s3_auto_create_path']) ? (bool)$input['s3_auto_create_path'] : false;
    
    // 通用配置
    $maxFileSize = isset($input['max_file_size']) ? (int)$input['max_file_size'] : 5242880;
    $allowedExtensions = isset($input['allowed_extensions']) ? trim($input['allowed_extensions']) : '';
    
    // 验证必填字段
    if (empty($configName)) {
        throw new Exception('配置名称不能为空');
    }
    
    if (empty($usageType)) {
        throw new Exception('用途类型不能为空');
    }
    
    // 根据存储类型验证必填字段
    if ($storageType === 'local') {
        if (empty($localPath)) {
            throw new Exception('本地存储路径不能为空');
        }
        if (empty($localUrlPrefix)) {
            throw new Exception('本地存储URL前缀不能为空');
        }
        
        // 检查路径是否存在
        if (!file_exists($localPath)) {
            // 如果勾选了自动创建路径
            if ($localAutoCreatePath) {
                // 尝试创建目录（递归创建，权限设置为0755）
                if (!mkdir($localPath, 0755, true)) {
                    throw new Exception('无法创建本地存储路径：' . $localPath . '，请检查父目录权限');
                }
                // 创建成功后记录日志
                error_log('自动创建存储路径成功: ' . $localPath);
            } else {
                throw new Exception('本地存储路径不存在：' . $localPath . '（可勾选"自动创建路径"选项）');
            }
        }
        
        // 检查是否是目录
        if (!is_dir($localPath)) {
            throw new Exception('本地存储路径不是一个目录：' . $localPath);
        }
        
        // 检查是否可写
        if (!is_writable($localPath)) {
            throw new Exception('本地存储路径不可写，请检查目录权限：' . $localPath);
        }
        
    } elseif ($storageType === 's3') {
        if (empty($s3Endpoint)) {
            throw new Exception('S3服务端点不能为空');
        }
        if (empty($s3Bucket)) {
            throw new Exception('S3存储桶名称不能为空');
        }
        if (empty($s3AccessKey)) {
            throw new Exception('S3访问密钥不能为空');
        }
        if (empty($s3SecretKey)) {
            throw new Exception('S3密钥不能为空');
        }
        
        // 测试S3连接
        try {
            testS3Connection($s3Endpoint, $s3Region, $s3Bucket, $s3AccessKey, $s3SecretKey, $s3UsePathStyle);
        } catch (Exception $e) {
            throw new Exception('S3连接测试失败：' . $e->getMessage());
        }
    }
    
    if ($id > 0) {
        // 更新现有配置
        $stmt = $pdo->prepare("
            UPDATE site_configs.storage_config
            SET config_name = :config_name,
                storage_type = :storage_type,
                enabled = :enabled,
                local_path = :local_path,
                local_url_prefix = :local_url_prefix,
                local_auto_create_path = :local_auto_create_path,
                s3_endpoint = :s3_endpoint,
                s3_region = :s3_region,
                s3_bucket = :s3_bucket,
                s3_path = :s3_path,
                s3_access_key = :s3_access_key,
                s3_secret_key = :s3_secret_key,
                s3_use_path_style = :s3_use_path_style,
                s3_url_prefix = :s3_url_prefix,
                s3_auto_create_path = :s3_auto_create_path,
                max_file_size = :max_file_size,
                allowed_extensions = :allowed_extensions
            WHERE id = :id
        ");
        
        $stmt->bindValue(':config_name', $configName);
        $stmt->bindValue(':storage_type', $storageType);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':local_path', $localPath);
        $stmt->bindValue(':local_url_prefix', $localUrlPrefix);
        $stmt->bindValue(':local_auto_create_path', $localAutoCreatePath, PDO::PARAM_BOOL);
        $stmt->bindValue(':s3_endpoint', $s3Endpoint);
        $stmt->bindValue(':s3_region', $s3Region);
        $stmt->bindValue(':s3_bucket', $s3Bucket);
        $stmt->bindValue(':s3_path', $s3Path);
        $stmt->bindValue(':s3_access_key', $s3AccessKey);
        $stmt->bindValue(':s3_secret_key', $s3SecretKey);
        $stmt->bindValue(':s3_use_path_style', $s3UsePathStyle, PDO::PARAM_BOOL);
        $stmt->bindValue(':s3_url_prefix', $s3UrlPrefix);
        $stmt->bindValue(':s3_auto_create_path', $s3AutoCreatePath, PDO::PARAM_BOOL);
        $stmt->bindValue(':max_file_size', $maxFileSize, PDO::PARAM_INT);
        $stmt->bindValue(':allowed_extensions', $allowedExtensions);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        $stmt->execute();
    } else {
        // 插入新配置
        $stmt = $pdo->prepare("
            INSERT INTO site_configs.storage_config (
                config_name, usage_type, storage_type, enabled,
                local_path, local_url_prefix, local_auto_create_path,
                s3_endpoint, s3_region, s3_bucket, s3_path, s3_access_key, s3_secret_key,
                s3_use_path_style, s3_url_prefix, s3_auto_create_path,
                max_file_size, allowed_extensions
            ) VALUES (
                :config_name, :usage_type, :storage_type, :enabled,
                :local_path, :local_url_prefix, :local_auto_create_path,
                :s3_endpoint, :s3_region, :s3_bucket, :s3_path, :s3_access_key, :s3_secret_key,
                :s3_use_path_style, :s3_url_prefix, :s3_auto_create_path,
                :max_file_size, :allowed_extensions
            )
        ");
        
        $stmt->bindValue(':config_name', $configName);
        $stmt->bindValue(':usage_type', $usageType);
        $stmt->bindValue(':storage_type', $storageType);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':local_path', $localPath);
        $stmt->bindValue(':local_url_prefix', $localUrlPrefix);
        $stmt->bindValue(':local_auto_create_path', $localAutoCreatePath, PDO::PARAM_BOOL);
        $stmt->bindValue(':s3_endpoint', $s3Endpoint);
        $stmt->bindValue(':s3_region', $s3Region);
        $stmt->bindValue(':s3_bucket', $s3Bucket);
        $stmt->bindValue(':s3_path', $s3Path);
        $stmt->bindValue(':s3_access_key', $s3AccessKey);
        $stmt->bindValue(':s3_secret_key', $s3SecretKey);
        $stmt->bindValue(':s3_use_path_style', $s3UsePathStyle, PDO::PARAM_BOOL);
        $stmt->bindValue(':s3_url_prefix', $s3UrlPrefix);
        $stmt->bindValue(':s3_auto_create_path', $s3AutoCreatePath, PDO::PARAM_BOOL);
        $stmt->bindValue(':max_file_size', $maxFileSize, PDO::PARAM_INT);
        $stmt->bindValue(':allowed_extensions', $allowedExtensions);
        
        $stmt->execute();
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => '保存存储配置成功',
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // 数据库错误
    error_log('保存存储配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // 其他错误
    error_log('保存存储配置失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 测试S3连接
 * 使用AWS SDK进行连接测试，支持S3兼容的对象存储服务
 * 
 * @param string $endpoint S3服务端点
 * @param string $region S3区域
 * @param string $bucket 存储桶名称
 * @param string $accessKey 访问密钥
 * @param string $secretKey 密钥
 * @param bool $usePathStyle 是否使用路径样式
 * @throws Exception 连接失败时抛出异常
 */
function testS3Connection($endpoint, $region, $bucket, $accessKey, $secretKey, $usePathStyle) {
    try {
        // 引入AWS SDK（通过包装文件设置环境变量）
        require_once __DIR__ . '/../../storage/aws_env_setup.php';
        
        // 如果region为空，使用默认值
        if (empty($region)) {
            $region = 'us-east-1';
        }
        
        // 创建S3客户端配置
        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey
            ],
            'use_path_style_endpoint' => $usePathStyle,
            // 显式指定使用Signature Version 4（AWS推荐的签名算法）
            'signature_version' => 'v4',
            // 禁用SSL验证（某些自建对象存储可能使用自签名证书）
            'http' => [
                'verify' => false
            ],
            // 禁用从配置文件和环境变量加载凭证，避免 open_basedir 限制
            'use_aws_shared_config_files' => false
        ];
        
        // 如果提供了自定义endpoint，添加到配置中
        if (!empty($endpoint)) {
            // 确保endpoint包含协议
            if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
                $endpoint = 'https://' . $endpoint;
            }
            $config['endpoint'] = $endpoint;
        }
        
        // 创建S3客户端
        $s3Client = new Aws\S3\S3Client($config);
        
        // 测试连接：尝试获取存储桶的元数据
        // headBucket操作只检查存储桶是否存在和是否有访问权限，不会列出对象
        $result = $s3Client->headBucket([
            'Bucket' => $bucket
        ]);
        
        // 如果没有抛出异常，说明连接成功
        return true;
        
    } catch (Aws\S3\Exception\S3Exception $e) {
        // AWS S3特定异常
        $errorCode = $e->getAwsErrorCode();
        $errorMessage = $e->getAwsErrorMessage();
        
        // 根据错误码提供友好的错误信息
        switch ($errorCode) {
            case 'NoSuchBucket':
            case '404':
                throw new Exception('存储桶不存在，请检查存储桶名称');
            
            case 'InvalidAccessKeyId':
                throw new Exception('访问密钥ID无效，请检查Access Key');
            
            case 'SignatureDoesNotMatch':
                throw new Exception('密钥签名不匹配，请检查Secret Key');
            
            case 'AccessDenied':
            case '403':
                throw new Exception('访问被拒绝，请检查密钥权限或存储桶策略');
            
            case 'InvalidBucketName':
                throw new Exception('存储桶名称格式无效');
            
            default:
                // 记录详细错误信息到日志
                error_log("S3连接测试失败 - 错误码: {$errorCode}, 错误信息: {$errorMessage}");
                throw new Exception("S3连接失败：{$errorMessage}");
        }
        
    } catch (Aws\Exception\CredentialsException $e) {
        // 凭证异常
        throw new Exception('访问密钥配置错误：' . $e->getMessage());
        
    } catch (Exception $e) {
        // 其他异常（如网络错误、SDK加载失败等）
        $message = $e->getMessage();
        
        // 检查是否是网络连接问题
        if (strpos($message, 'cURL error') !== false || 
            strpos($message, 'Connection') !== false ||
            strpos($message, 'resolve host') !== false) {
            throw new Exception('无法连接到S3服务端点，请检查endpoint地址和网络连接');
        }
        
        // 检查是否是SDK加载问题
        if (strpos($message, 'autoload') !== false || 
            strpos($message, 'Class') !== false) {
            throw new Exception('AWS SDK未正确安装，请联系管理员');
        }
        
        // 记录详细错误到日志
        error_log("S3连接测试异常: " . $message);
        throw new Exception('S3连接测试失败：' . $message);
    }
}
