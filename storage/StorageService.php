<?php
/**
 * 存储服务类
 * 支持本地存储和兼容S3的对象存储
 */

class StorageService {
    private $pdo;
    private $config;
    private $usageType;
    
    public function __construct($pdo, $usageType = 'avatar') {
        $this->pdo = $pdo;
        $this->usageType = $usageType;
        $this->loadConfig();
    }
    
    /**
     * 加载存储配置
     */
    private function loadConfig() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.storage_config 
                WHERE usage_type = :usage_type AND enabled = true 
                LIMIT 1
            ");
            $stmt->execute(['usage_type' => $this->usageType]);
            $this->config = $stmt->fetch();
            
            if (!$this->config) {
                // 检查是否存在该用途类型的配置（但未启用）
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) as count FROM site_configs.storage_config 
                    WHERE usage_type = :usage_type
                ");
                $stmt->execute(['usage_type' => $this->usageType]);
                $count = $stmt->fetch()['count'];
                
                if ($count > 0) {
                    throw new Exception('存储配置已禁用，请联系管理员启用存储服务');
                } else {
                    throw new Exception('管理员未配置存储策略，请联系管理员配置存储服务');
                }
            }
            
            // 验证配置完整性
            $this->validateConfig();
            
        } catch (PDOException $e) {
            error_log("加载存储配置失败: " . $e->getMessage());
            throw new Exception('存储配置加载失败，请联系管理员');
        }
    }
    
    /**
     * 验证配置完整性
     */
    private function validateConfig() {
        $storageType = $this->config['storage_type'];
        
        if ($storageType === 'local') {
            // 验证本地存储配置
            if (empty($this->config['local_path'])) {
                throw new Exception('本地存储路径未配置，请联系管理员');
            }
            
            if (empty($this->config['local_url_prefix'])) {
                throw new Exception('本地存储URL前缀未配置，请联系管理员');
            }
            
            // 检查目录是否存在且可写
            $path = $this->config['local_path'];
            if (!is_dir($path)) {
                // 尝试创建目录
                if (!@mkdir($path, 0755, true)) {
                    throw new Exception('存储目录不存在且无法创建，请联系管理员');
                }
            }
            
            if (!is_writable($path)) {
                throw new Exception('存储目录不可写，请联系管理员');
            }
            
        } elseif ($storageType === 's3') {
            // 验证S3存储配置
            if (empty($this->config['s3_endpoint'])) {
                throw new Exception('S3服务端点未配置，请联系管理员');
            }
            
            if (empty($this->config['s3_bucket'])) {
                throw new Exception('S3存储桶未配置，请联系管理员');
            }
            
            if (empty($this->config['s3_access_key']) || empty($this->config['s3_secret_key'])) {
                throw new Exception('S3访问密钥未配置，请联系管理员');
            }
            
        } else {
            throw new Exception('不支持的存储类型，请联系管理员');
        }
    }
    
    /**
     * 上传文件
     * 
     * @param array $file $_FILES中的文件数组
     * @param string $directory 目标目录（相对路径）
     * @return array 返回上传结果
     */
    public function uploadFile($file, $directory = 'uploads') {
        // 验证文件
        $validation = $this->validateFile($file);
        if (!$validation['success']) {
            return $validation;
        }
        
        // 生成文件名
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = $this->generateFilename($extension);
        $filepath = trim($directory, '/') . '/' . $filename;
        
        // 根据存储类型上传
        if ($this->config['storage_type'] === 'local') {
            return $this->uploadToLocal($file, $filepath);
        } elseif ($this->config['storage_type'] === 's3') {
            return $this->uploadToS3($file, $filepath);
        } else {
            return [
                'success' => false,
                'message' => '不支持的存储类型'
            ];
        }
    }
    
    /**
     * 验证文件
     */
    private function validateFile($file) {
        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => '文件上传失败：' . $this->getUploadErrorMessage($file['error'])
            ];
        }
        
        // 检查文件大小
        $maxSize = $this->config['max_file_size'] ?? 5242880; // 默认5MB
        if ($file['size'] > $maxSize) {
            return [
                'success' => false,
                'message' => '文件大小超过限制（最大' . ($maxSize / 1024 / 1024) . 'MB）'
            ];
        }
        
        // 检查文件扩展名
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = explode(',', $this->config['allowed_extensions'] ?? 'jpg,jpeg,png,gif,webp');
        $allowedExtensions = array_map('trim', $allowedExtensions);
        
        if (!in_array($extension, $allowedExtensions)) {
            return [
                'success' => false,
                'message' => '不支持的文件类型，允许的类型：' . implode(', ', $allowedExtensions)
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * 生成唯一文件名
     * 格式：时间(20260124145330)-随机8位大写字母与数字组合.扩展名
     * 例如：20260124145330-A3B7C9D2.jpg
     */
    private function generateFilename($extension) {
        // 生成时间部分（格式：YmdHis）
        $timestamp = date('YmdHis');
        
        // 生成8位随机大写字母与数字组合
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < 8; $i++) {
            $randomString .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // 组合文件名：时间-随机字符串.扩展名
        return $timestamp . '-' . $randomString . '.' . $extension;
    }
    
    /**
     * 上传到本地存储
     */
    private function uploadToLocal($file, $filepath) {
        try {
            $basePath = $this->config['local_path'];
            $fullPath = rtrim($basePath, '/') . '/' . $filepath;
            
            // 创建目录
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => '创建目录失败'
                    ];
                }
            }
            
            // 移动上传的文件
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                return [
                    'success' => false,
                    'message' => '保存文件失败'
                ];
            }
            
            // 设置文件权限
            chmod($fullPath, 0644);
            
            // 生成访问URL
            $urlPrefix = rtrim($this->config['local_url_prefix'], '/');
            $url = $urlPrefix . '/' . $filepath;
            
            return [
                'success' => true,
                'url' => $url,
                'path' => $filepath,
                'full_path' => $fullPath,  // 添加完整物理路径
                'size' => $file['size'],
                'type' => $file['type']
            ];
            
        } catch (Exception $e) {
            error_log("本地存储上传失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '文件上传失败'
            ];
        }
    }
    
    /**
     * 上传到S3兼容存储
     */
    private function uploadToS3($file, $filepath) {
        try {
            // 引入AWS SDK
            require_once __DIR__ . '/vendor/autoload.php';
            
            // 获取S3配置
            $region = $this->config['s3_region'] ?: 'us-east-1';
            $endpoint = $this->config['s3_endpoint'];
            $bucket = $this->config['s3_bucket'];
            $s3Path = $this->config['s3_path'] ?: '';
            $accessKey = $this->config['s3_access_key'];
            $secretKey = $this->config['s3_secret_key'];
            $usePathStyle = $this->config['s3_use_path_style'];
            
            // 如果配置了路径前缀，添加到文件路径前面
            if (!empty($s3Path)) {
                $filepath = trim($s3Path, '/') . '/' . $filepath;
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
                'http' => [
                    'verify' => false
                ]
            ];
            
            // 如果提供了自定义endpoint
            if (!empty($endpoint)) {
                if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
                    $endpoint = 'https://' . $endpoint;
                }
                $config['endpoint'] = $endpoint;
            }
            
            // 创建S3客户端
            $s3Client = new Aws\S3\S3Client($config);
            
            // 上传文件
            $result = $s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $filepath,
                'SourceFile' => $file['tmp_name'],
                'ContentType' => $file['type'],
                'ACL' => 'public-read' // 设置为公开可读
            ]);
            
            // 生成访问URL
            if (!empty($this->config['s3_url_prefix'])) {
                // 使用自定义URL前缀（如CDN域名）
                $url = rtrim($this->config['s3_url_prefix'], '/') . '/' . $filepath;
            } else {
                // 使用S3对象URL
                $url = $result['ObjectURL'];
            }
            
            return [
                'success' => true,
                'url' => $url,
                'path' => $filepath,
                'size' => $file['size'],
                'type' => $file['type']
            ];
            
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("S3存储上传失败: " . $e->getAwsErrorMessage());
            return [
                'success' => false,
                'message' => 'S3上传失败：' . $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            error_log("S3存储上传失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '文件上传失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 删除文件
     */
    public function deleteFile($filepath) {
        if ($this->config['storage_type'] === 'local') {
            return $this->deleteFromLocal($filepath);
        } elseif ($this->config['storage_type'] === 's3') {
            return $this->deleteFromS3($filepath);
        }
        
        return false;
    }
    
    /**
     * 从本地存储删除
     */
    private function deleteFromLocal($filepath) {
        try {
            $basePath = $this->config['local_path'];
            $fullPath = rtrim($basePath, '/') . '/' . $filepath;
            
            if (file_exists($fullPath)) {
                return unlink($fullPath);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("本地存储删除失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从S3存储删除
     */
    private function deleteFromS3($filepath) {
        try {
            // 引入AWS SDK
            require_once __DIR__ . '/vendor/autoload.php';
            
            // 获取S3配置
            $region = $this->config['s3_region'] ?: 'us-east-1';
            $endpoint = $this->config['s3_endpoint'];
            $bucket = $this->config['s3_bucket'];
            $s3Path = $this->config['s3_path'] ?: '';
            $accessKey = $this->config['s3_access_key'];
            $secretKey = $this->config['s3_secret_key'];
            $usePathStyle = $this->config['s3_use_path_style'];
            
            // 如果配置了路径前缀，添加到文件路径前面
            if (!empty($s3Path)) {
                $filepath = trim($s3Path, '/') . '/' . $filepath;
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
                'http' => [
                    'verify' => false
                ]
            ];
            
            // 如果提供了自定义endpoint
            if (!empty($endpoint)) {
                if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
                    $endpoint = 'https://' . $endpoint;
                }
                $config['endpoint'] = $endpoint;
            }
            
            // 创建S3客户端
            $s3Client = new Aws\S3\S3Client($config);
            
            // 删除文件
            $s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $filepath
            ]);
            
            return true;
            
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("S3存储删除失败: " . $e->getAwsErrorMessage());
            return false;
        } catch (Exception $e) {
            error_log("S3存储删除失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取上传错误信息
     */
    private function getUploadErrorMessage($errorCode) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件大小超过php.ini限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => 'PHP扩展停止了文件上传'
        ];
        
        return $errors[$errorCode] ?? '未知错误';
    }
    
    /**
     * 获取存储配置
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * 获取用途类型
     */
    public function getUsageType() {
        return $this->usageType;
    }
    
    /**
     * 移动文件到另一个存储配置
     * 用于审核通过后将文件从待审核存储移动到正式存储
     * 
     * @param string $sourceFilepath 源文件路径（相对路径）
     * @param string $targetUsageType 目标存储用途类型
     * @return array 返回结果
     */
    public function moveToStorage($sourceFilepath, $targetUsageType) {
        try {
            // 创建目标存储服务
            $targetStorage = new StorageService($this->pdo, $targetUsageType);
            
            // 从当前存储读取文件内容
            $fileContent = $this->getFileContentByPath($sourceFilepath);
            if (!$fileContent['success']) {
                return [
                    'success' => false,
                    'message' => '读取源文件失败：' . $fileContent['message']
                ];
            }
            
            // 创建临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'avatar_move_');
            file_put_contents($tempFile, $fileContent['content']);
            
            // 获取文件扩展名
            $extension = strtolower(pathinfo($sourceFilepath, PATHINFO_EXTENSION));
            
            // 准备文件信息
            $fileInfo = [
                'name' => basename($sourceFilepath),
                'tmp_name' => $tempFile,
                'size' => filesize($tempFile),
                'type' => mime_content_type($tempFile),
                'error' => UPLOAD_ERR_OK
            ];
            
            // 上传到目标存储（只使用日期作为目录，不添加avatars前缀）
            $result = $targetStorage->uploadFile($fileInfo, date('Y/m'));
            
            // 删除临时文件
            @unlink($tempFile);
            
            // 如果上传成功，删除源文件
            if ($result['success']) {
                $this->deleteFile($sourceFilepath);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("移动文件失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '移动文件失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取文件内容（通过文件路径）
     * 用于管理员查看待审核的头像
     * 
     * @param string $filepath 文件相对路径
     * @return array 返回结果
     */
    public function getFileContentByPath($filepath) {
        if ($this->config['storage_type'] === 'local') {
            return $this->getFileContentFromLocalByPath($filepath);
        } else {
            return $this->getFileContentFromS3ByPath($filepath);
        }
    }
    
    /**
     * 从本地存储获取文件内容（通过路径）
     */
    private function getFileContentFromLocalByPath($filepath) {
        try {
            // 构建完整路径
            $fullPath = rtrim($this->config['local_path'], '/') . '/' . ltrim($filepath, '/');
            
            if (!file_exists($fullPath)) {
                return [
                    'success' => false,
                    'message' => '文件不存在'
                ];
            }
            
            $content = file_get_contents($fullPath);
            
            if ($content === false) {
                return [
                    'success' => false,
                    'message' => '读取文件失败'
                ];
            }
            
            return [
                'success' => true,
                'content' => $content
            ];
            
        } catch (Exception $e) {
            error_log("从本地存储读取文件失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '读取文件失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 从S3存储获取文件内容（通过路径）
     */
    private function getFileContentFromS3ByPath($filepath) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            
            // 获取S3配置
            $region = $this->config['s3_region'] ?: 'us-east-1';
            $endpoint = $this->config['s3_endpoint'];
            $bucket = $this->config['s3_bucket'];
            $accessKey = $this->config['s3_access_key'];
            $secretKey = $this->config['s3_secret_key'];
            $usePathStyle = $this->config['s3_use_path_style'];
            
            // 创建S3客户端配置
            $config = [
                'version' => 'latest',
                'region' => $region,
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey
                ],
                'use_path_style_endpoint' => $usePathStyle,
                'signature_version' => 'v4',
                'http' => [
                    'verify' => false
                ]
            ];
            
            // 如果提供了自定义endpoint
            if (!empty($endpoint)) {
                if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
                    $endpoint = 'https://' . $endpoint;
                }
                $config['endpoint'] = $endpoint;
            }
            
            // 创建S3客户端
            $s3Client = new Aws\S3\S3Client($config);
            
            // 获取对象
            $result = $s3Client->getObject([
                'Bucket' => $bucket,
                'Key' => ltrim($filepath, '/')
            ]);
            
            return [
                'success' => true,
                'content' => (string)$result['Body']
            ];
            
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("从S3存储读取文件失败: " . $e->getAwsErrorMessage());
            return [
                'success' => false,
                'message' => 'S3错误: ' . $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            error_log("从S3存储读取文件失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '读取文件失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取文件内容
     * 用于管理员查看待审核的头像
     */
    public function getFileContent($filepath) {
        if ($this->config['storage_type'] === 'local') {
            return $this->getFileContentFromLocal($filepath);
        } else {
            return $this->getFileContentFromS3($filepath);
        }
    }
    
    /**
     * 从本地存储获取文件内容
     */
    private function getFileContentFromLocal($filepath) {
        // 从URL中提取文件路径
        $parsedUrl = parse_url($filepath);
        $path = $parsedUrl['path'] ?? $filepath;
        
        // 移除开头的斜杠
        $path = ltrim($path, '/');
        
        // 构建完整路径
        $fullPath = rtrim($this->config['local_path'], '/') . '/' . $path;
        
        if (!file_exists($fullPath)) {
            return [
                'success' => false,
                'message' => '文件不存在'
            ];
        }
        
        $content = file_get_contents($fullPath);
        
        if ($content === false) {
            return [
                'success' => false,
                'message' => '读取文件失败'
            ];
        }
        
        return [
            'success' => true,
            'content' => $content
        ];
    }
    
    /**
     * 从S3存储获取文件内容
     */
    private function getFileContentFromS3($filepath) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            
            $s3Client = new Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->config['s3_region'],
                'endpoint' => $this->config['s3_endpoint'],
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => $this->config['s3_access_key'],
                    'secret' => $this->config['s3_secret_key']
                ]
            ]);
            
            // 从URL中提取对象键
            $parsedUrl = parse_url($filepath);
            $path = $parsedUrl['path'] ?? $filepath;
            $path = ltrim($path, '/');
            
            // 移除bucket名称（如果存在）
            $bucket = $this->config['s3_bucket'];
            if (strpos($path, $bucket . '/') === 0) {
                $path = substr($path, strlen($bucket) + 1);
            }
            
            // 添加路径前缀
            if (!empty($this->config['s3_path_prefix'])) {
                $prefix = trim($this->config['s3_path_prefix'], '/');
                if (strpos($path, $prefix . '/') !== 0) {
                    $path = $prefix . '/' . $path;
                }
            }
            
            $result = $s3Client->getObject([
                'Bucket' => $bucket,
                'Key' => $path
            ]);
            
            return [
                'success' => true,
                'content' => (string)$result['Body']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'S3错误: ' . $e->getMessage()
            ];
        }
    }
}
