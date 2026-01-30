<?php
/**
 * 通用人机验证服务类
 * 支持多种验证服务商：Geetest、Cloudflare Turnstile、reCAPTCHA、hCaptcha
 */

class CaptchaService {
    private $pdo;
    private $logger;
    
    public function __construct($pdo, $logger = null) {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }
    
    /**
     * 获取人机验证配置
     * 
     * @param string $scene 使用场景
     * @return array|null 返回配置数组或 null
     */
    public function getCaptchaConfig($scene) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    id,
                    name as config_name,
                    provider,
                    captcha_id,
                    captcha_key,
                    app_id,
                    app_secret,
                    site_key,
                    secret_key,
                    scenes,
                    priority,
                    status,
                    is_enabled
                FROM site_configs.captcha_config
                WHERE status = 1 
                AND is_enabled = true
                AND jsonb_exists(scenes, :scene)
                ORDER BY priority ASC, id ASC
                LIMIT 1
            ");
            
            $stmt->execute(['scene' => $scene]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($this->logger) {
                $this->logger->info('captcha', '获取人机验证配置', [
                    'scene' => $scene,
                    'found' => ($config !== false),
                    'provider' => $config['provider'] ?? null
                ]);
            }
            
            return $config ?: null;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('captcha', '获取人机验证配置失败', [
                    'scene' => $scene,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }
    
    /**
     * 验证人机验证
     * 
     * @param array $config 验证配置
     * @param array $data 验证数据
     * @param string $clientIp 客户端IP
     * @return array 验证结果
     */
    public function verifyCaptcha($config, $data, $clientIp) {
        if (!$config) {
            return [
                'success' => true,
                'message' => '人机验证未启用'
            ];
        }
        
        $provider = $config['provider'];
        
        switch ($provider) {
            case 'geetest':
                return $this->verifyGeetest($config, $data, $clientIp);
                
            case 'turnstile':
                return $this->verifyTurnstile($config, $data, $clientIp);
                
            case 'recaptcha':
                return $this->verifyRecaptcha($config, $data, $clientIp);
                
            case 'hcaptcha':
                return $this->verifyHcaptcha($config, $data, $clientIp);
                
            default:
                return [
                    'success' => false,
                    'message' => '不支持的验证服务商: ' . $provider
                ];
        }
    }
    
    /**
     * 验证极验
     */
    private function verifyGeetest($config, $data, $clientIp) {
        $lotNumber = $data['lot_number'] ?? '';
        $captchaOutput = $data['captcha_output'] ?? '';
        $passToken = $data['pass_token'] ?? '';
        $genTime = $data['gen_time'] ?? '';
        
        if (empty($lotNumber) || empty($captchaOutput) || empty($passToken) || empty($genTime)) {
            return [
                'success' => false,
                'message' => '缺少极验验证参数'
            ];
        }
        
        // 调用极验服务端验证 API
        $captchaId = $config['captcha_id'] ?: $config['app_id'];
        $captchaKey = $config['captcha_key'] ?: $config['app_secret'];
        
        $signToken = hash_hmac('sha256', $lotNumber, $captchaKey);
        
        $query = http_build_query([
            'lot_number' => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token' => $passToken,
            'gen_time' => $genTime,
            'sign_token' => $signToken
        ]);
        
        $url = "https://gcaptcha4.geetest.com/validate?captcha_id={$captchaId}&{$query}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => '极验服务器响应异常'
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($result['result'] === 'success') {
            return [
                'success' => true,
                'message' => '验证成功',
                'lot_number' => $lotNumber
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['reason'] ?? '极验验证失败'
            ];
        }
    }
    
    /**
     * 验证 Cloudflare Turnstile
     */
    private function verifyTurnstile($config, $data, $clientIp) {
        $token = $data['turnstile_token'] ?? '';
        
        if (empty($token)) {
            return [
                'success' => false,
                'message' => '缺少 Turnstile 验证 token'
            ];
        }
        
        $secretKey = $config['secret_key'] ?: $config['app_secret'];
        
        // 调用 Cloudflare Turnstile 验证 API
        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        
        $postData = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $clientIp
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error('captcha', 'Turnstile 服务器响应异常', [
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
            }
            return [
                'success' => false,
                'message' => 'Turnstile 服务器响应异常'
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($this->logger) {
            $this->logger->info('captcha', 'Turnstile 验证响应', [
                'success' => $result['success'] ?? false,
                'error_codes' => $result['error-codes'] ?? []
            ]);
        }
        
        if ($result['success']) {
            // 生成一个唯一标识用于二次验证
            $lotNumber = 'TURNSTILE_' . time() . '_' . bin2hex(random_bytes(16));
            return [
                'success' => true,
                'message' => '验证成功',
                'lot_number' => $lotNumber
            ];
        } else {
            $errorCodes = $result['error-codes'] ?? [];
            $errorMessage = 'Turnstile 验证失败';
            
            if (in_array('timeout-or-duplicate', $errorCodes)) {
                $errorMessage = '验证已过期或重复使用';
            } elseif (in_array('invalid-input-response', $errorCodes)) {
                $errorMessage = '无效的验证 token';
            }
            
            return [
                'success' => false,
                'message' => $errorMessage
            ];
        }
    }
    
    /**
     * 验证 Google reCAPTCHA
     */
    private function verifyRecaptcha($config, $data, $clientIp) {
        $token = $data['recaptcha_token'] ?? '';
        
        if (empty($token)) {
            return [
                'success' => false,
                'message' => '缺少 reCAPTCHA 验证 token'
            ];
        }
        
        $secretKey = $config['secret_key'] ?: $config['app_secret'];
        
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        
        $postData = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $clientIp
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => 'reCAPTCHA 服务器响应异常'
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($this->logger) {
            $this->logger->info('captcha', 'reCAPTCHA 验证响应', [
                'success' => $result['success'] ?? false,
                'error_codes' => $result['error-codes'] ?? []
            ]);
        }
        
        if ($result['success']) {
            // 生成一个唯一标识用于二次验证
            $lotNumber = 'RECAPTCHA_' . time() . '_' . bin2hex(random_bytes(16));
            
            return [
                'success' => true,
                'message' => '验证成功',
                'lot_number' => $lotNumber
            ];
        } else {
            return [
                'success' => false,
                'message' => 'reCAPTCHA 验证失败'
            ];
        }
    }
    
    /**
     * 验证 hCaptcha
     */
    private function verifyHcaptcha($config, $data, $clientIp) {
        $token = $data['hcaptcha_token'] ?? '';
        
        if (empty($token)) {
            return [
                'success' => false,
                'message' => '缺少 hCaptcha 验证 token'
            ];
        }
        
        $secretKey = $config['secret_key'] ?: $config['app_secret'];
        
        $url = 'https://hcaptcha.com/siteverify';
        
        $postData = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $clientIp
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => 'hCaptcha 服务器响应异常'
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($result['success']) {
            // 生成一个唯一标识用于二次验证
            $lotNumber = 'HCAPTCHA_' . time() . '_' . bin2hex(random_bytes(16));
            return [
                'success' => true,
                'message' => '验证成功',
                'lot_number' => $lotNumber
            ];
        } else {
            return [
                'success' => false,
                'message' => 'hCaptcha 验证失败'
            ];
        }
    }
    
    /**
     * 二次验证（用于登录/注册时验证之前保存的验证记录）
     * 
     * @param string $token 验证 token（lot_number 或其他 token）
     * @param string $identifier 验证标识（手机号、邮箱等）
     * @param string $provider 验证服务商
     * @param string $scene 验证场景（register、login等）
     * @param string $clientIp 客户端IP
     * @return array 验证结果
     */
    public function verifySecondTime($token, $identifier, $provider, $scene = 'unknown', $clientIp = null) {
        try {
            // 查询验证记录
            // 注意：不限制 scene，因为发送验证码时的 scene（send_sms/send_email）
            // 与注册/登录时的 scene（register/login）不同
            // 只要 token、identifier、provider 匹配且未过期即可
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.captcha_verify_log
                WHERE (lot_number = :token OR challenge = :token)
                AND (phone = :identifier OR email = :identifier)
                AND provider = :provider
                AND verify_success = true
                AND expires_at > CURRENT_TIMESTAMP
                AND scene NOT LIKE '%_second_verify'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([
                'token' => $token,
                'identifier' => $identifier,
                'provider' => $provider
            ]);
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                if ($this->logger) {
                    $this->logger->warning('captcha', '二次验证失败：未找到有效的验证记录', [
                        'token' => substr($token, 0, 20) . '...',
                        'identifier' => $identifier,
                        'provider' => $provider,
                        'scene' => $scene
                    ]);
                }
                
                // 记录二次验证失败日志
                $this->saveSecondVerifyLog(
                    null,
                    $scene,
                    $provider,
                    $token,
                    $identifier,
                    false,
                    '未找到有效的验证记录或验证已过期',
                    $clientIp
                );
                
                return [
                    'success' => false,
                    'message' => '人机验证已过期，请重新获取验证码'
                ];
            }
            
            if ($this->logger) {
                $this->logger->info('captcha', '二次验证成功', [
                    'log_id' => $record['id'],
                    'original_scene' => $record['scene'],
                    'current_scene' => $scene,
                    'identifier' => $identifier,
                    'provider' => $provider
                ]);
            }
            
            // 记录二次验证成功日志
            $this->saveSecondVerifyLog(
                $record['config_id'],
                $scene,
                $provider,
                $token,
                $identifier,
                true,
                '二次验证成功',
                $clientIp,
                $record
            );
            
            return [
                'success' => true,
                'message' => '验证成功',
                'log_id' => $record['id'],
                'original_scene' => $record['scene']
            ];
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('captcha', '二次验证异常', [
                    'error' => $e->getMessage(),
                    'identifier' => $identifier,
                    'provider' => $provider,
                    'scene' => $scene
                ]);
            }
            
            // 记录二次验证异常日志
            $this->saveSecondVerifyLog(
                null,
                $scene,
                $provider,
                $token,
                $identifier,
                false,
                '二次验证异常: ' . $e->getMessage(),
                $clientIp
            );
            
            return [
                'success' => false,
                'message' => '验证失败，请稍后重试'
            ];
        }
    }
    
    /**
     * 保存二次验证日志
     * 
     * @param int|null $configId 配置ID
     * @param string $scene 验证场景
     * @param string $provider 验证服务商
     * @param string $token 验证token
     * @param string $identifier 验证标识（手机号或邮箱）
     * @param bool $success 验证是否成功
     * @param string $message 验证消息
     * @param string|null $clientIp 客户端IP
     * @param array|null $originalRecord 原始验证记录
     * @return int|null 日志ID
     */
    private function saveSecondVerifyLog($configId, $scene, $provider, $token, $identifier, $success, $message, $clientIp = null, $originalRecord = null) {
        try {
            // 判断 identifier 是手机号还是邮箱
            $phone = null;
            $email = null;
            if ($identifier) {
                if (preg_match('/^1[3-9]\d{9}$/', $identifier)) {
                    $phone = $identifier;
                } elseif (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $email = $identifier;
                }
            }
            
            // 获取客户端IP（如果未提供）
            if (!$clientIp) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            }
            
            // 准备验证结果数据
            $verifyResultData = [
                'second_verify' => true,
                'original_log_id' => $originalRecord['id'] ?? null,
                'original_scene' => $originalRecord['scene'] ?? null,
                'current_scene' => $scene,
                'message' => $message
            ];
            
            // 准备插入参数
            $insertParams = [
                'config_id' => $configId,
                'scene' => $scene . '_second_verify', // 标记为二次验证
                'provider' => $provider,
                'lot_number' => null,
                'captcha_output' => null,
                'pass_token' => null,
                'gen_time' => null,
                'challenge' => null,
                'verify_success' => $success,
                'verify_result' => json_encode($verifyResultData, JSON_UNESCAPED_UNICODE),
                'error_message' => $success ? null : $message,
                'client_ip' => $clientIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'phone' => $phone,
                'email' => $email,
                'expires_at' => null // 二次验证日志不需要过期时间
            ];
            
            // 根据验证服务商设置相应字段
            if ($provider === 'geetest') {
                $insertParams['lot_number'] = $token;
                if ($originalRecord) {
                    $insertParams['captcha_output'] = $originalRecord['captcha_output'] ?? null;
                    $insertParams['pass_token'] = $originalRecord['pass_token'] ?? null;
                    $insertParams['gen_time'] = $originalRecord['gen_time'] ?? null;
                }
            } else {
                $insertParams['challenge'] = $token;
            }
            
            // 插入日志记录
            $stmt = $this->pdo->prepare("
                INSERT INTO site_configs.captcha_verify_log (
                    config_id, scene, provider,
                    lot_number, captcha_output, pass_token, gen_time,
                    challenge,
                    verify_success, verify_result, error_message,
                    client_ip, user_agent,
                    phone, email,
                    created_at, expires_at
                ) VALUES (
                    :config_id, :scene, :provider,
                    :lot_number, :captcha_output, :pass_token, :gen_time,
                    :challenge,
                    :verify_success, :verify_result, :error_message,
                    :client_ip, :user_agent,
                    :phone, :email,
                    CURRENT_TIMESTAMP, :expires_at
                ) RETURNING id
            ");
            
            $executeResult = $stmt->execute($insertParams);
            
            if (!$executeResult) {
                if ($this->logger) {
                    $this->logger->error('captcha', '保存二次验证日志失败：SQL执行失败', [
                        'scene' => $scene,
                        'provider' => $provider
                    ]);
                }
                return null;
            }
            
            $logResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $logId = $logResult['id'] ?? null;
            
            if ($this->logger && $logId) {
                $this->logger->info('captcha', '保存二次验证日志成功', [
                    'log_id' => $logId,
                    'scene' => $scene,
                    'original_scene' => $originalRecord['scene'] ?? null,
                    'provider' => $provider,
                    'success' => $success,
                    'identifier' => $identifier
                ]);
            }
            
            return $logId;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('captcha', '保存二次验证日志异常', [
                    'error' => $e->getMessage(),
                    'scene' => $scene,
                    'provider' => $provider
                ]);
            }
            return null;
        }
    }
    
    /**
     * 保存验证日志
     * 
     * @param array $config 验证配置
     * @param string $scene 验证场景
     * @param array $data 验证数据
     * @param bool $success 验证是否成功
     * @param string $clientIp 客户端IP
     * @param string $identifier 验证标识（手机号或邮箱）
     * @param array $result 验证结果
     * @return int|null 日志ID
     */
    public function saveVerifyLog($config, $scene, $data, $success, $clientIp, $identifier = null, $result = null) {
        try {
            // 验证必要参数
            if (!isset($config['id']) || !isset($config['provider'])) {
                if ($this->logger) {
                    $this->logger->error('captcha', '保存验证日志失败：配置参数不完整', [
                        'config' => $config,
                        'scene' => $scene
                    ]);
                }
                error_log("CaptchaService::saveVerifyLog - 配置参数不完整: " . json_encode($config));
                return null;
            }
            
            $provider = $config['provider'];
            
            // 根据不同的验证服务商提取相关字段
            $lotNumber = null;
            $captchaOutput = null;
            $passToken = null;
            $genTime = null;
            $challenge = null;
            
            switch ($provider) {
                case 'geetest':
                    $lotNumber = $data['lot_number'] ?? null;
                    $captchaOutput = $data['captcha_output'] ?? null;
                    $passToken = $data['pass_token'] ?? null;
                    $genTime = $data['gen_time'] ?? null;
                    break;
                    
                case 'turnstile':
                    $challenge = $data['turnstile_token'] ?? null;
                    // 如果验证成功，从结果中获取生成的 lot_number
                    if ($success && $result && isset($result['lot_number'])) {
                        $lotNumber = $result['lot_number'];
                    }
                    break;
                    
                case 'recaptcha':
                    $challenge = $data['recaptcha_token'] ?? null;
                    // 如果验证成功，从结果中获取生成的 lot_number
                    if ($success && $result && isset($result['lot_number'])) {
                        $lotNumber = $result['lot_number'];
                    }
                    break;
                    
                case 'hcaptcha':
                    $challenge = $data['hcaptcha_token'] ?? null;
                    // 如果验证成功，从结果中获取生成的 lot_number
                    if ($success && $result && isset($result['lot_number'])) {
                        $lotNumber = $result['lot_number'];
                    }
                    break;
            }
            
            // 判断 identifier 是手机号还是邮箱
            $phone = null;
            $email = null;
            if ($identifier) {
                if (preg_match('/^1[3-9]\d{9}$/', $identifier)) {
                    $phone = $identifier;
                } elseif (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $email = $identifier;
                }
            }
            
            // 设置过期时间（15分钟后）
            $expiresAt = date('Y-m-d H:i:s', time() + 900);
            
            // 如果 lot_number 或 challenge 已存在，先删除旧记录（避免唯一约束冲突）
            if (!empty($lotNumber)) {
                try {
                    $deleteStmt = $this->pdo->prepare("
                        DELETE FROM site_configs.captcha_verify_log 
                        WHERE lot_number = :lot_number
                    ");
                    $deleteStmt->execute(['lot_number' => $lotNumber]);
                    
                    if ($this->logger) {
                        $this->logger->info('captcha', '删除旧的验证日志记录', [
                            'lot_number' => $lotNumber
                        ]);
                    }
                } catch (Exception $deleteException) {
                    error_log("CaptchaService::saveVerifyLog - 删除旧记录失败: " . $deleteException->getMessage());
                }
            }
            
            // 准备插入参数
            $insertParams = [
                'config_id' => $config['id'],
                'scene' => $scene,
                'provider' => $provider,
                'lot_number' => $lotNumber,
                'captcha_output' => $captchaOutput,
                'pass_token' => $passToken,
                'gen_time' => $genTime,
                'challenge' => $challenge,
                'verify_success' => $success,
                'verify_result' => $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
                'error_message' => $success ? null : ($result['message'] ?? '验证失败'),
                'client_ip' => $clientIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'phone' => $phone,
                'email' => $email,
                'expires_at' => $expiresAt
            ];
            
            // 插入日志记录
            $stmt = $this->pdo->prepare("
                INSERT INTO site_configs.captcha_verify_log (
                    config_id, scene, provider,
                    lot_number, captcha_output, pass_token, gen_time,
                    challenge,
                    verify_success, verify_result, error_message,
                    client_ip, user_agent,
                    phone, email,
                    created_at, expires_at
                ) VALUES (
                    :config_id, :scene, :provider,
                    :lot_number, :captcha_output, :pass_token, :gen_time,
                    :challenge,
                    :verify_success, :verify_result, :error_message,
                    :client_ip, :user_agent,
                    :phone, :email,
                    CURRENT_TIMESTAMP, :expires_at
                ) RETURNING id
            ");
            
            $executeResult = $stmt->execute($insertParams);
            
            if (!$executeResult) {
                $errorInfo = $stmt->errorInfo();
                if ($this->logger) {
                    $this->logger->error('captcha', '保存验证日志失败：SQL执行失败', [
                        'error_info' => $errorInfo,
                        'sql_state' => $errorInfo[0] ?? null,
                        'driver_code' => $errorInfo[1] ?? null,
                        'driver_message' => $errorInfo[2] ?? null,
                        'scene' => $scene,
                        'provider' => $provider
                    ]);
                }
                error_log("CaptchaService::saveVerifyLog - SQL执行失败: " . json_encode($errorInfo));
                return null;
            }
            
            $logResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $logId = $logResult['id'] ?? null;
            
            if (!$logId) {
                if ($this->logger) {
                    $this->logger->error('captcha', '保存验证日志失败：未获取到日志ID', [
                        'scene' => $scene,
                        'provider' => $provider,
                        'fetch_result' => $logResult
                    ]);
                }
                error_log("CaptchaService::saveVerifyLog - 未获取到日志ID，fetch结果: " . json_encode($logResult));
                return null;
            }
            
            // 只在系统日志中记录成功信息，不输出到 PHP 错误日志
            if ($this->logger) {
                $this->logger->info('captcha', '保存验证日志成功', [
                    'log_id' => $logId,
                    'scene' => $scene,
                    'provider' => $provider,
                    'success' => $success,
                    'identifier' => $identifier
                ]);
            }
            
            return $logId;
            
        } catch (PDOException $e) {
            $errorMessage = "CaptchaService::saveVerifyLog - PDO异常: " . $e->getMessage();
            $errorDetails = [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'driver_message' => $e->errorInfo[2] ?? null,
                'scene' => $scene,
                'provider' => $provider ?? 'unknown',
                'config_id' => $config['id'] ?? null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
            
            if ($this->logger) {
                $this->logger->error('captcha', '保存验证日志失败', $errorDetails);
            }
            
            error_log($errorMessage);
            error_log("详细错误信息: " . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
            
            return null;
        } catch (Throwable $e) {
            $errorMessage = "CaptchaService::saveVerifyLog - 异常: " . $e->getMessage();
            $errorDetails = [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'scene' => $scene,
                'provider' => $provider ?? 'unknown',
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
            
            if ($this->logger) {
                $this->logger->error('captcha', '保存验证日志失败', $errorDetails);
            }
            
            error_log($errorMessage);
            error_log("详细错误信息: " . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
            
            return null;
        }
    }
}
