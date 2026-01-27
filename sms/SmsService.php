<?php
/**
 * 短信服务类
 * 一碗小米周授权登录平台
 */

require_once __DIR__ . '/321comcn/vendor/autoload.php';

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class SmsService {
    
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 生成验证码
     */
    public function generateCode($length = 6) {
        return str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
    
    /**
     * 生成短信ID
     */
    public function generateSmsId() {
        return 'SMS_' . date('YmdHis') . '_' . mt_rand(100000, 999999);
    }
    
    /**
     * 获取短信配置
     */
    public function getSmsConfig($purpose) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.sms_config 
                WHERE purpose = :purpose 
                AND is_enabled = TRUE 
                AND status = 1
                AND daily_sent_count < daily_limit
                ORDER BY priority ASC
                LIMIT 1
            ");
            $stmt->execute(['purpose' => $purpose]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("获取短信配置失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查发送频率限制
     */
    public function checkRateLimit($phone, $purpose, $limitSeconds = 60) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM sms.code 
                WHERE phone = :phone 
                AND purpose = :purpose 
                AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 second' * :limit
            ");
            $stmt->execute([
                'phone' => $phone,
                'purpose' => $purpose,
                'limit' => $limitSeconds
            ]);
            $result = $stmt->fetch();
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("检查频率限制失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 保存验证码记录
     */
    public function saveCodeRecord($data) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sms.code (
                    sms_id, phone, code, status, purpose, validity_period, expires_at,
                    upstream_sms_id, channel, signature, template_id, template_params,
                    send_result, send_status_code, extra_info, client_ip
                ) VALUES (
                    :sms_id, :phone, :code, :status, :purpose, :validity_period, :expires_at,
                    :upstream_sms_id, :channel, :signature, :template_id, :template_params,
                    :send_result, :send_status_code, :extra_info, :client_ip
                ) RETURNING id
            ");
            
            $stmt->execute($data);
            $result = $stmt->fetch();
            return $result['id'];
        } catch (PDOException $e) {
            error_log("保存验证码记录失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 更新配置发送计数
     */
    public function updateSentCount($configId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE site_configs.sms_config 
                SET daily_sent_count = daily_sent_count + 1
                WHERE id = :id
            ");
            $stmt->execute(['id' => $configId]);
            return true;
        } catch (PDOException $e) {
            error_log("更新发送计数失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证验证码（不标记为已使用）
     */
    public function verifyCode($phone, $code, $purpose, $codeId = null) {
        try {
            // 构建查询条件
            $sql = "
                SELECT * FROM sms.code 
                WHERE phone = :phone 
                AND code = :code
                AND purpose = :purpose 
                AND status = 1 
                AND expires_at > CURRENT_TIMESTAMP
            ";
            
            $params = [
                'phone' => $phone,
                'code' => $code,
                'purpose' => $purpose
            ];
            
            // 如果提供了 code_id，添加到查询条件中
            if (!empty($codeId)) {
                $sql .= " AND sms_id = :code_id";
                $params['code_id'] = $codeId;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            
            // 查询有效的验证码
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $record = $stmt->fetch();
            
            if (!$record) {
                return [
                    'success' => false,
                    'message' => '验证码无效或已过期'
                ];
            }
            
            // 只更新验证次数和最后验证时间，不标记为已使用
            $updateStmt = $this->pdo->prepare("
                UPDATE sms.code 
                SET verify_count = verify_count + 1,
                    last_verify_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $record['id']]);
            
            return [
                'success' => true,
                'message' => '验证成功',
                'data' => $record
            ];
            
        } catch (PDOException $e) {
            error_log("验证验证码失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '验证失败'
            ];
        }
    }
    
    /**
     * 标记验证码为已使用
     */
    public function markCodeAsUsed($phone, $code, $purpose) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sms.code 
                SET status = 0
                WHERE phone = :phone 
                AND code = :code
                AND purpose = :purpose 
                AND status = 1
            ");
            $stmt->execute([
                'phone' => $phone,
                'code' => $code,
                'purpose' => $purpose
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("标记验证码为已使用失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 发送短信（321.com.cn平台）
     */
    public function sendSms321($config, $phone, $templateParams) {
        // 抑制阿里云SDK在PHP 8.x下的deprecated警告
        $originalErrorReporting = error_reporting();
        error_reporting($originalErrorReporting & ~E_DEPRECATED);
        
        try {
            // 解析 credentials
            $credentials = json_decode($config['credentials'], true);
            
            // 检查 credentials 是否正确解析
            if (!is_array($credentials)) {
                error_log("credentials 解析失败: " . $config['credentials']);
                error_reporting($originalErrorReporting);
                return [
                    'success' => false,
                    'error' => 'credentials 配置格式错误',
                    'type' => 'ConfigError'
                ];
            }
            
            // 检查必需的字段（支持两种格式）
            // 优先使用 accessKeyId/accessKeySecret，其次使用 access_key/secret_key
            if (isset($credentials['accessKeyId']) && isset($credentials['accessKeySecret'])) {
                $accessKeyId = $credentials['accessKeyId'];
                $accessKeySecret = $credentials['accessKeySecret'];
            } elseif (isset($credentials['access_key']) && isset($credentials['secret_key'])) {
                $accessKeyId = $credentials['access_key'];
                $accessKeySecret = $credentials['secret_key'];
            } else {
                error_log("credentials 缺少必需字段: " . json_encode($credentials));
                error_reporting($originalErrorReporting);
                return [
                    'success' => false,
                    'error' => 'credentials 缺少 accessKeyId/accessKeySecret 或 access_key/secret_key',
                    'type' => 'ConfigError'
                ];
            }
            
            // 配置 AlibabaCloud 客户端
            AlibabaCloud::accessKeyClient(
                $accessKeyId,
                $accessKeySecret
            )->regionId('sms321')->asDefaultClient();
            
            // 构建模板参数（根据 template_content 配置）
            $templateParam = json_encode($templateParams);
            
            // 发送请求
            $request = AlibabaCloud::rpc()
                ->product('smsapi')
                ->scheme('https')
                ->version('2017-05-25')
                ->action('SendSms')
                ->method('GET')
                ->options([
                    'query' => [
                        'PhoneNumbers' => $phone,
                        'SignName' => $config['signature'],
                        'TemplateCode' => $config['template_id'],
                        'TemplateParam' => $templateParam,
                    ],
                ]);
            
            $result = $request->request();
            $statusCode = $result->getStatusCode();
            
            // 使用 toArray() 获取响应数据
            $responseData = $result->toArray();
            $body = json_encode($responseData, JSON_UNESCAPED_UNICODE);
            
            // 恢复错误报告级别
            error_reporting($originalErrorReporting);
            
            // 321.com.cn 返回格式：{"data":"ok","httpStatus":200,"bizid":"xxxx"}
            // 只有 data 为 "ok" 才算发送成功
            $sendSuccess = false;
            if ($responseData && isset($responseData['data']) && $responseData['data'] === 'ok') {
                $sendSuccess = true;
            }
            
            return [
                'success' => $sendSuccess,
                'status_code' => $statusCode,
                'body' => $body,
                'response_data' => $responseData,
                'uri' => (string)$request->uri,
                'bizid' => $responseData['bizid'] ?? null,
                'error_msg' => $sendSuccess ? null : ($responseData['data'] ?? '未知错误')
            ];
            
        } catch (ClientException $e) {
            error_reporting($originalErrorReporting);
            return [
                'success' => false,
                'error' => $e->getErrorMessage(),
                'type' => 'ClientException'
            ];
        } catch (ServerException $e) {
            error_reporting($originalErrorReporting);
            return [
                'success' => false,
                'error' => $e->getErrorMessage(),
                'type' => 'ServerException'
            ];
        } catch (Exception $e) {
            error_reporting($originalErrorReporting);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'Exception'
            ];
        }
    }
    
    /**
     * 发送验证码（通用方法）
     */
    public function sendVerificationCode($phone, $purpose, $validityPeriod = 900, $clientIp = null) {
        // 检查发送频率限制
        if ($this->checkRateLimit($phone, $purpose, 60)) {
            return [
                'success' => false,
                'message' => '发送过于频繁，请稍后再试'
            ];
        }
        
        // 获取短信配置
        $config = $this->getSmsConfig($purpose);
        if (!$config) {
            return [
                'success' => false,
                'message' => '短信服务暂时不可用'
            ];
        }
        
        // 生成验证码和短信ID
        $code = $this->generateCode(6);
        $smsId = $this->generateSmsId();
        $validityMinutes = $validityPeriod / 60;
        $expiresAt = date('Y-m-d H:i:s', time() + $validityPeriod);
        
        // 解析模板内容配置，构建模板参数
        $templateContent = json_decode($config['template_content'], true);
        $templateParams = [];
        
        if (is_array($templateContent)) {
            foreach ($templateContent as $key => $description) {
                // 根据参数名称填充实际值
                if ($key === 'code') {
                    $templateParams[$key] = $code;
                } elseif ($key === 'minutes') {
                    $templateParams[$key] = $validityMinutes;
                } elseif ($key === 'phone') {
                    $templateParams[$key] = $phone;
                }
                // 可以根据需要添加更多参数映射
            }
        }
        
        // 发送短信
        $sendResult = null;
        $upstreamSmsId = null;
        $sendStatusCode = null;
        $sendSuccess = false;
        $errorReason = null;
        
        if ($config['channel'] === '321cn') {
            $sendResult = $this->sendSms321($config, $phone, $templateParams);
            
            if ($sendResult['success']) {
                // 321.com.cn 返回的 bizid 作为上游短信ID
                $upstreamSmsId = $sendResult['bizid'] ?? null;
                $sendStatusCode = (string)$sendResult['status_code'];
                $sendSuccess = true;
            } else {
                // 提取错误原因
                if (isset($sendResult['error_msg'])) {
                    // 321.com.cn 返回的错误信息（data 字段）
                    $errorReason = $sendResult['error_msg'];
                } elseif (isset($sendResult['error'])) {
                    // 异常错误（ClientException, ServerException, Exception）
                    $errorReason = $sendResult['error'];
                } elseif (isset($sendResult['response_data']['data'])) {
                    // 从响应数据中提取错误信息
                    $errorReason = $sendResult['response_data']['data'];
                } elseif (isset($sendResult['body'])) {
                    // 使用完整的响应体作为错误信息
                    $errorReason = '短信服务返回：' . $sendResult['body'];
                } else {
                    $errorReason = '未知错误';
                }
                
                // 记录详细的错误日志
                error_log("短信发送失败详情: " . json_encode([
                    'phone' => $phone,
                    'purpose' => $purpose,
                    'send_result' => $sendResult,
                    'error_reason' => $errorReason
                ], JSON_UNESCAPED_UNICODE));
            }
        }
        
        // 保存验证码记录（无论成功还是失败都要记录）
        $recordData = [
            'sms_id' => $smsId,
            'phone' => $phone,
            'code' => $code,
            'status' => $sendSuccess ? 1 : 2,  // 1-有效（发送成功），2-过期（发送失败）
            'purpose' => $purpose,
            'validity_period' => $validityPeriod,
            'expires_at' => $expiresAt,
            'upstream_sms_id' => $upstreamSmsId,
            'channel' => $config['channel'],
            'signature' => $config['signature'],
            'template_id' => $config['template_id'],
            'template_params' => json_encode($templateParams),
            'send_result' => json_encode($sendResult),  // 完整的上游响应或错误信息
            'send_status_code' => $sendStatusCode,
            'extra_info' => json_encode([
                'config_id' => $config['id'],
                'send_success' => $sendSuccess,
                'error_reason' => $errorReason
            ]),
            'client_ip' => $clientIp
        ];
        
        $recordId = $this->saveCodeRecord($recordData);
        if (!$recordId) {
            return [
                'success' => false,
                'message' => '保存验证码记录失败'
            ];
        }
        
        // 只有真正发送成功才更新计数
        if ($sendSuccess) {
            $this->updateSentCount($config['id']);
        }
        
        // 根据实际发送结果返回
        if (!$sendSuccess) {
            $errorMsg = '短信发送失败';
            if ($errorReason) {
                $errorMsg .= '：' . $errorReason;
            }
            return [
                'success' => false,
                'message' => $errorMsg,
                'data' => [
                    'sms_id' => $smsId,
                    'error_type' => $sendResult['type'] ?? 'send_failed',
                    'error_reason' => $errorReason
                ]
            ];
        }
        
        return [
            'success' => true,
            'message' => '验证码发送成功',
            'data' => [
                'sms_id' => $smsId,
                'phone' => $phone,
                'expires_in' => $validityPeriod,
                'send_success' => true
            ]
        ];
    }
}
