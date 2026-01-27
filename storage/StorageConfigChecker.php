<?php
/**
 * 存储配置检查工具类
 * 用于检查存储配置是否正确
 */

class StorageConfigChecker {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 检查所有存储配置
     * 
     * @return array 返回检查结果
     */
    public function checkAllConfigs() {
        $results = [];
        $usageTypes = ['avatar', 'avatar_pending', 'document', 'temp'];
        
        foreach ($usageTypes as $usageType) {
            $results[$usageType] = $this->checkConfig($usageType);
        }
        
        return $results;
    }
    
    /**
     * 检查指定用途的存储配置
     * 
     * @param string $usageType 用途类型
     * @return array 返回检查结果
     */
    public function checkConfig($usageType) {
        $result = [
            'usage_type' => $usageType,
            'exists' => false,
            'enabled' => false,
            'valid' => false,
            'errors' => [],
            'warnings' => []
        ];
        
        try {
            // 查询配置
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.storage_config 
                WHERE usage_type = :usage_type
            ");
            $stmt->execute(['usage_type' => $usageType]);
            $config = $stmt->fetch();
            
            if (!$config) {
                $result['errors'][] = '配置不存在';
                return $result;
            }
            
            $result['exists'] = true;
            $result['enabled'] = $config['enabled'];
            
            if (!$config['enabled']) {
                $result['warnings'][] = '配置已禁用';
                return $result;
            }
            
            // 验证配置
            $storageType = $config['storage_type'];
            
            if ($storageType === 'local') {
                $this->validateLocalConfig($config, $result);
            } elseif ($storageType === 's3') {
                $this->validateS3Config($config, $result);
            } else {
                $result['errors'][] = '不支持的存储类型：' . $storageType;
            }
            
            // 如果没有错误，标记为有效
            if (empty($result['errors'])) {
                $result['valid'] = true;
            }
            
        } catch (PDOException $e) {
            $result['errors'][] = '数据库查询失败：' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 验证本地存储配置
     */
    private function validateLocalConfig($config, &$result) {
        // 检查路径
        if (empty($config['local_path'])) {
            $result['errors'][] = '本地存储路径未配置';
        } else {
            $path = $config['local_path'];
            
            if (!is_dir($path)) {
                $result['warnings'][] = '存储目录不存在：' . $path;
                
                // 尝试创建
                if (@mkdir($path, 0755, true)) {
                    $result['warnings'][] = '已自动创建目录';
                } else {
                    $result['errors'][] = '无法创建存储目录';
                }
            }
            
            if (is_dir($path) && !is_writable($path)) {
                $result['errors'][] = '存储目录不可写：' . $path;
            }
        }
        
        // 检查URL前缀
        if (empty($config['local_url_prefix'])) {
            $result['errors'][] = 'URL前缀未配置';
        }
        
        // 检查文件大小限制
        if (empty($config['max_file_size']) || $config['max_file_size'] <= 0) {
            $result['warnings'][] = '文件大小限制未设置或无效';
        }
        
        // 检查允许的扩展名
        if (empty($config['allowed_extensions'])) {
            $result['warnings'][] = '允许的文件扩展名未设置';
        }
    }
    
    /**
     * 验证S3存储配置
     */
    private function validateS3Config($config, &$result) {
        // 检查端点
        if (empty($config['s3_endpoint'])) {
            $result['errors'][] = 'S3服务端点未配置';
        }
        
        // 检查区域
        if (empty($config['s3_region'])) {
            $result['warnings'][] = 'S3区域未配置';
        }
        
        // 检查存储桶
        if (empty($config['s3_bucket'])) {
            $result['errors'][] = 'S3存储桶未配置';
        }
        
        // 检查访问密钥
        if (empty($config['s3_access_key'])) {
            $result['errors'][] = 'S3访问密钥未配置';
        }
        
        if (empty($config['s3_secret_key'])) {
            $result['errors'][] = 'S3密钥Secret未配置';
        }
        
        // 检查URL前缀
        if (empty($config['s3_url_prefix'])) {
            $result['warnings'][] = 'S3 URL前缀未配置';
        }
    }
    
    /**
     * 生成检查报告
     * 
     * @param array $results 检查结果
     * @return string 返回格式化的报告
     */
    public function generateReport($results) {
        $report = "存储配置检查报告\n";
        $report .= "==================\n\n";
        
        foreach ($results as $usageType => $result) {
            $report .= "用途类型: {$usageType}\n";
            $report .= "状态: " . ($result['valid'] ? '✓ 正常' : '✗ 异常') . "\n";
            
            if (!empty($result['errors'])) {
                $report .= "错误:\n";
                foreach ($result['errors'] as $error) {
                    $report .= "  - {$error}\n";
                }
            }
            
            if (!empty($result['warnings'])) {
                $report .= "警告:\n";
                foreach ($result['warnings'] as $warning) {
                    $report .= "  - {$warning}\n";
                }
            }
            
            $report .= "\n";
        }
        
        return $report;
    }
}
