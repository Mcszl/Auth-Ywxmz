<?php
/**
 * 极验验证服务类
 * 一碗小米周授权登录平台
 */

class GeetestService {
    
    private $pdo;
    private $logger;
    
    public function __construct($pdo, $logger = null) {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }
    
    /**
     * 记录日志
     */
    private function logInfo($message, $context = null) {
        if ($this->logger) {
            $this->logger->info('captcha', $message, $context);
        }
        //error_log("[GeetestService INFO] " . $message . ($context ? " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE) : ""));
    }
    
    private function logError($message, $context = null, $stackTrace = null) {
        if ($this->logger) {
            $this->logger->error('captcha', $message, $context, $stackTrace);
        }
        //error_log("[GeetestService ERROR] " . $message . ($context ? " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE) : ""));
    }
    
    private function logWarning($message, $context = null) {
        if ($this->logger) {
            $this->logger->warning('captcha', $message, $context);
        }
        //error_log("[GeetestService WARNING] " . $message . ($context ? " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE) : ""));
    }
    
    /**
     * 获取极验配置
     */
    public function getGeetestConfig($scene = 'register') {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.captcha_config 
                WHERE provider = 'geetest'
                AND is_enabled = TRUE 
                AND status = 1
                AND scenes @> :scene::jsonb
                ORDER BY priority ASC
                LIMIT 1
            ");
            $stmt->execute(['scene' => json_encode([$scene])]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("获取极验配置失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 验证极验结果（服务端二次验证）
     */
    public function verifyGeetest($lotNumber, $captchaOutput, $passToken, $genTime, $scene, $clientIp = null, $phone = null) {
        // 记录验证开始
        $this->logInfo("开始极验验证", [
            'scene' => $scene,
            'has_lot_number' => !empty($lotNumber),
            'has_captcha_output' => !empty($captchaOutput),
            'has_pass_token' => !empty($passToken),
            'has_gen_time' => !empty($genTime),
            'client_ip' => $clientIp,
            'phone' => $phone
        ]);
        
        // 获取配置
        $config = $this->getGeetestConfig($scene);
        
        // 如果没有配置，说明关闭了人机验证，直接返回成功
        if (!$config) {
            $this->logInfo("人机验证已关闭（未找到配置）", ['scene' => $scene]);
            return [
                'success' => true,
                'message' => '人机验证已关闭',
                'disabled' => true
            ];
        }
        
        $this->logInfo("找到人机验证配置", [
            'config_id' => $config['id'],
            'captcha_id' => $config['captcha_id'],
            'scene' => $scene
        ]);
        
        // 验证参数（只有在启用人机验证时才检查参数）
        if (empty($lotNumber) || empty($captchaOutput) || empty($passToken) || empty($genTime)) {
            // 如果参数不完整，可能是前端没有加载极验
            $this->logWarning("极验参数不完整，跳过验证", [
                'scene' => $scene,
                'lot_number' => $lotNumber,
                'captcha_output' => substr($captchaOutput, 0, 20) . '...',
                'pass_token' => substr($passToken, 0, 20) . '...',
                'gen_time' => $genTime,
                'gen_time_type' => gettype($genTime),
                'gen_time_length' => strlen($genTime)
            ]);
            
            return [
                'success' => true,
                'message' => '人机验证参数不完整，跳过验证',
                'disabled' => true
            ];
        }
        
        // 记录 gen_time 的详细信息
        $this->logInfo("极验参数详情", [
            'lot_number' => $lotNumber,
            'gen_time' => $genTime,
            'gen_time_type' => gettype($genTime),
            'gen_time_length' => strlen((string)$genTime),
            'gen_time_numeric' => is_numeric($genTime),
            'current_time' => time(),
            'current_time_ms' => round(microtime(true) * 1000),
            'captcha_output_length' => strlen($captchaOutput),
            'pass_token_length' => strlen($passToken)
        ]);
        
        // 确保 gen_time 是字符串类型
        $genTime = (string)$genTime;
        
        // 构建验证请求
        $captchaId = $config['captcha_id'];
        $captchaKey = $config['captcha_key'];
        
        // 生成签名
        $signToken = hash_hmac('sha256', $lotNumber, $captchaKey);
        
        // 构建请求参数（注意：需要包含 captcha_id）
        $params = [
            'lot_number' => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token' => $passToken,
            'gen_time' => $genTime,
            'captcha_id' => $captchaId,  // 必须包含 captcha_id
            'sign_token' => $signToken
        ];
        
        $this->logInfo("准备发送极验验证请求", [
            'captcha_id' => $captchaId,
            'lot_number' => $lotNumber,
            'params_keys' => array_keys($params)
        ]);
        
        // 发送验证请求到极验服务器（使用 form-urlencoded 格式）
        $url = 'https://gcaptcha4.geetest.com/validate';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));  // 使用 form-urlencoded
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'  // 修改为 form-urlencoded
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->logError("极验验证请求失败", [
                'curl_error' => $curlError,
                'url' => $url
            ]);
            
            return [
                'success' => false,
                'message' => '验证请求失败'
            ];
        }
        
        $result = json_decode($response, true);
        
        $this->logInfo("收到极验验证响应", [
            'http_code' => $httpCode,
            'response' => $result
        ]);
        
        // 判断验证结果
        $verifySuccess = false;
        $errorMessage = null;
        
        if ($httpCode === 200 && isset($result['result']) && $result['result'] === 'success') {
            $verifySuccess = true;
            $this->logInfo("极验验证成功", ['lot_number' => $lotNumber]);
        } else {
            $errorMessage = $result['reason'] ?? $result['msg'] ?? '验证失败';
            $this->logWarning("极验验证失败", [
                'lot_number' => $lotNumber,
                'error_message' => $errorMessage,
                'error_code' => $result['code'] ?? null,
                'http_code' => $httpCode,
                'result' => $result
            ]);
        }
        
        // 保存验证记录
        $saveResult = $this->saveVerifyLog([
            'config_id' => $config['id'],
            'scene' => $scene,
            'provider' => 'geetest',
            'lot_number' => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token' => $passToken,
            'gen_time' => $genTime,
            'verify_success' => $verifySuccess,
            'verify_result' => json_encode($result),
            'error_message' => $errorMessage,
            'client_ip' => $clientIp,
            'phone' => $phone,
            'expires_at' => date('Y-m-d H:i:s', time() + 300) // 5分钟有效期
        ]);
        
        if (!$saveResult) {
            $this->logError("保存极验验证记录失败", [
                'lot_number' => $lotNumber,
                'verify_success' => $verifySuccess
            ]);
        }
        
        // 更新配置验证计数
        if ($verifySuccess) {
            $this->updateVerifyCount($config['id']);
        }
        
        return [
            'success' => $verifySuccess,
            'message' => $verifySuccess ? '验证成功' : $errorMessage,
            'lot_number' => $lotNumber
        ];
    }
    
    /**
     * 二次验证（注册时验证）
     * 不限制 scene，因为发送验证码和注册使用的 scene 可能不同
     */
    public function verifySecondTime($lotNumber, $identifier = null) {
        try {
            // 不限制 scene，只要 lot_number 匹配且验证成功即可
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.captcha_verify_log 
                WHERE lot_number = :lot_number
                AND verify_success = TRUE
                AND expires_at > CURRENT_TIMESTAMP
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute(['lot_number' => $lotNumber]);
            $record = $stmt->fetch();
            
            if (!$record) {
                $this->logWarning("二次验证失败：验证记录不存在或已过期", [
                    'lot_number' => $lotNumber,
                    'identifier' => $identifier
                ]);
                
                // 查询是否存在该 lot_number 的记录（不管是否过期）
                $checkStmt = $this->pdo->prepare("
                    SELECT lot_number, verify_success, expires_at, created_at 
                    FROM site_configs.captcha_verify_log 
                    WHERE lot_number = :lot_number
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $checkStmt->execute(['lot_number' => $lotNumber]);
                $existingRecord = $checkStmt->fetch();
                
                if ($existingRecord) {
                    $this->logWarning("找到验证记录但不符合条件", [
                        'lot_number' => $lotNumber,
                        'verify_success' => $existingRecord['verify_success'],
                        'expires_at' => $existingRecord['expires_at'],
                        'created_at' => $existingRecord['created_at'],
                        'current_time' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $this->logWarning("未找到任何验证记录", [
                        'lot_number' => $lotNumber
                    ]);
                }
                
                return [
                    'success' => false,
                    'message' => '验证已过期或不存在'
                ];
            }
            
            // 如果提供了标识符（手机号或邮箱），验证是否匹配
            // 注意：phone 字段可能存储手机号或邮箱
            if ($identifier && $record['phone']) {
                if ($record['phone'] !== $identifier) {
                    $this->logWarning("二次验证失败：标识符不匹配", [
                        'lot_number' => $lotNumber,
                        'expected' => $record['phone'],
                        'provided' => $identifier
                    ]);
                    return [
                        'success' => false,
                        'message' => '验证信息不匹配'
                    ];
                }
            }
            
            $this->logInfo("二次验证成功", [
                'lot_number' => $lotNumber,
                'identifier' => $identifier,
                'scene' => $record['scene']
            ]);
            
            return [
                'success' => true,
                'message' => '验证通过',
                'data' => $record
            ];
            
        } catch (PDOException $e) {
            $this->logError("二次验证异常", [
                'lot_number' => $lotNumber,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => '验证失败'
            ];
        }
    }
    
    /**
     * 保存验证记录
     */
    private function saveVerifyLog($data) {
        try {
            // 确保 verify_success 是 boolean 类型
            // 空字符串、null、0、false 都转换为 false，其他转换为 true
            if (isset($data['verify_success'])) {
                if ($data['verify_success'] === '' || $data['verify_success'] === null) {
                    $data['verify_success'] = false;
                } else {
                    $data['verify_success'] = (bool)$data['verify_success'];
                }
            } else {
                $data['verify_success'] = false;
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO site_configs.captcha_verify_log (
                    config_id, scene, provider, lot_number, captcha_output, pass_token, gen_time,
                    verify_success, verify_result, error_message, client_ip, phone, expires_at
                ) VALUES (
                    :config_id, :scene, :provider, :lot_number, :captcha_output, :pass_token, :gen_time,
                    :verify_success, :verify_result, :error_message, :client_ip, :phone, :expires_at
                )
            ");
            
            // 使用 PDO::PARAM_BOOL 明确指定参数类型
            $stmt->bindValue(':config_id', $data['config_id'], PDO::PARAM_INT);
            $stmt->bindValue(':scene', $data['scene'], PDO::PARAM_STR);
            $stmt->bindValue(':provider', $data['provider'], PDO::PARAM_STR);
            $stmt->bindValue(':lot_number', $data['lot_number'], PDO::PARAM_STR);
            $stmt->bindValue(':captcha_output', $data['captcha_output'], PDO::PARAM_STR);
            $stmt->bindValue(':pass_token', $data['pass_token'], PDO::PARAM_STR);
            $stmt->bindValue(':gen_time', $data['gen_time'], PDO::PARAM_STR);
            $stmt->bindValue(':verify_success', $data['verify_success'], PDO::PARAM_BOOL);
            $stmt->bindValue(':verify_result', $data['verify_result'], PDO::PARAM_STR);
            $stmt->bindValue(':error_message', $data['error_message'], PDO::PARAM_STR);
            $stmt->bindValue(':client_ip', $data['client_ip'], PDO::PARAM_STR);
            $stmt->bindValue(':phone', $data['phone'], PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', $data['expires_at'], PDO::PARAM_STR);
            
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            error_log("保存验证记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新验证计数
     */
    private function updateVerifyCount($configId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE site_configs.captcha_config 
                SET daily_verify_count = daily_verify_count + 1
                WHERE id = :id
            ");
            $stmt->execute(['id' => $configId]);
            return true;
        } catch (PDOException $e) {
            error_log("更新验证计数失败: " . $e->getMessage());
            return false;
        }
    }
}
