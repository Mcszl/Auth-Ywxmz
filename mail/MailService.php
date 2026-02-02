<?php
/**
 * 邮件服务类
 * 使用 PHPMailer 发送邮件
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../config/postgresql.config.php';
require_once __DIR__ . '/../logs/SystemLogger.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    private $pdo;
    private $logger;
    
    public function __construct()
    {
        $this->pdo = getDBConnection();
        $this->logger = new SystemLogger($this->pdo);
    }
    
    /**
     * 发送邮件
     * 
     * @param string $to 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body 邮件内容（HTML格式）
     * @param string $scene 使用场景
     * @param array $options 额外选项
     * @return array 发送结果
     */
    public function sendMail($to, $subject, $body, $scene = 'default', $options = [])
    {
        try {
            // 获取邮件配置
            $config = $this->getMailConfig($scene);
            if (!$config) {
                $this->logger->error('邮件配置不存在', 'mail', [
                    'scene' => $scene,
                    'to' => $to
                ]);
                return [
                    'success' => false,
                    'message' => "未找到 {$scene} 场景的邮件配置，请联系管理员配置邮件服务"
                ];
            }
            
            // 检查是否启用
            if (!$config['is_enabled'] || $config['status'] != 1) {
                $this->logger->warning('邮件配置未启用', 'mail', [
                    'config_id' => $config['id'],
                    'scene' => $scene
                ]);
                return [
                    'success' => false,
                    'message' => '邮件服务未启用'
                ];
            }
            
            // 检查每日发送限制
            if ($config['daily_sent_count'] >= $config['daily_limit']) {
                $this->logger->warning('超出每日发送限制', 'mail', [
                    'config_id' => $config['id'],
                    'daily_sent_count' => $config['daily_sent_count'],
                    'daily_limit' => $config['daily_limit']
                ]);
                return [
                    'success' => false,
                    'message' => '超出每日发送限制'
                ];
            }
            
            // 创建 PHPMailer 实例
            $mail = new PHPMailer(true);
            
            // 服务器配置
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->Port = $config['smtp_port'];
            
            // 设置加密方式
            if ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // 发件人
            $mail->setFrom($config['email'], $config['sender_name']);
            
            // 收件人
            $mail->addAddress($to);
            
            // 回复地址
            if (!empty($config['reply_to'])) {
                $mail->addReplyTo($config['reply_to']);
            }
            
            // 抄送和密送（可选）
            if (isset($options['cc']) && is_array($options['cc'])) {
                foreach ($options['cc'] as $ccEmail) {
                    $mail->addCC($ccEmail);
                }
            }
            if (isset($options['bcc']) && is_array($options['bcc'])) {
                foreach ($options['bcc'] as $bccEmail) {
                    $mail->addBCC($bccEmail);
                }
            }
            
            // 附件（可选）
            if (isset($options['attachments']) && is_array($options['attachments'])) {
                foreach ($options['attachments'] as $attachment) {
                    if (is_array($attachment)) {
                        $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                    } else {
                        $mail->addAttachment($attachment);
                    }
                }
            }
            
            // 邮件内容
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // 纯文本版本（可选）
            if (isset($options['alt_body'])) {
                $mail->AltBody = $options['alt_body'];
            }
            
            // 发送邮件
            $sendResult = $mail->send();
            
            if ($sendResult) {
                // 更新发送计数
                $this->updateSentCount($config['id']);
                
                // 记录日志
                $this->logger->info('邮件发送成功', 'mail', [
                    'config_id' => $config['id'],
                    'to' => $to,
                    'subject' => $subject,
                    'scene' => $scene
                ]);
                
                return [
                    'success' => true,
                    'message' => '邮件发送成功',
                    'data' => [
                        'config_id' => $config['id'],
                        'to' => $to
                    ]
                ];
            } else {
                $this->logger->error('邮件发送失败', 'mail', [
                    'config_id' => $config['id'],
                    'to' => $to,
                    'error' => $mail->ErrorInfo
                ]);
                
                return [
                    'success' => false,
                    'message' => '邮件发送失败: ' . $mail->ErrorInfo
                ];
            }
            
        } catch (Exception $e) {
            $this->logger->error('邮件发送异常', 'mail', [
                'to' => $to,
                'scene' => $scene,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '邮件发送异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 使用模板发送邮件
     * 
     * @param string $to 收件人邮箱
     * @param string $templateCode 模板代码
     * @param array $variables 模板变量
     * @param array $options 额外选项
     * @return array 发送结果
     */
    public function sendMailByTemplate($to, $templateCode, $variables = [], $options = [])
    {
        try {
            // 获取邮件模板
            $template = $this->getMailTemplate($templateCode);
            if (!$template) {
                // 检查模板是否存在但未启用
                $stmt = $this->pdo->prepare("
                    SELECT scene FROM site_configs.email_template
                    WHERE template_code = :template_code
                    LIMIT 1
                ");
                $stmt->execute(['template_code' => $templateCode]);
                $existingTemplate = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingTemplate) {
                    $scene = $existingTemplate['scene'];
                    $this->logger->warning('邮件模板未启用', 'mail', [
                        'template_code' => $templateCode,
                        'scene' => $scene,
                        'to' => $to
                    ]);
                    return [
                        'success' => false,
                        'message' => "该场景暂无可用的邮件模板，请联系管理员配置"
                    ];
                } else {
                    $this->logger->error('邮件模板不存在', 'mail', [
                        'template_code' => $templateCode,
                        'to' => $to
                    ]);
                    return [
                        'success' => false,
                        'message' => "该场景暂无可用的邮件模板，请联系管理员配置"
                    ];
                }
            }
            
            // 检查模板是否启用（双重检查）
            if (!$template['is_enabled'] || $template['status'] != 1) {
                $this->logger->warning('邮件模板未启用', 'mail', [
                    'template_id' => $template['id'],
                    'template_code' => $templateCode
                ]);
                return [
                    'success' => false,
                    'message' => "该场景暂无可用的邮件模板，请联系管理员配置"
                ];
            }
            
            // 替换模板变量
            $subject = $this->replaceVariables($template['subject'], $variables);
            $body = $this->replaceVariables($template['template_content'], $variables);
            
            // 发送邮件
            return $this->sendMail($to, $subject, $body, $template['scene'], $options);
            
        } catch (Exception $e) {
            $this->logger->error('模板邮件发送异常', 'mail', [
                'to' => $to,
                'template_code' => $templateCode,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '模板邮件发送异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取邮件配置
     * 
     * @param string $scene 使用场景
     * @return array|null 配置信息
     */
    private function getMailConfig($scene)
    {
        try {
            // 先尝试精确匹配场景
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.email_config
                WHERE is_enabled = TRUE 
                AND status = 1
                AND scenes @> :scene::jsonb
                ORDER BY priority ASC
                LIMIT 1
            ");
            $stmt->execute(['scene' => json_encode([$scene])]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 如果没有找到，记录日志
            if (!$config) {
                $this->logger->warning('未找到匹配的邮件配置', 'mail', [
                    'scene' => $scene,
                    'search_json' => json_encode([$scene])
                ]);
            }
            
            return $config;
        } catch (PDOException $e) {
            $this->logger->error('获取邮件配置失败', 'mail', [
                'scene' => $scene,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 获取邮件模板
     * 
     * @param string $templateCode 模板代码
     * @return array|null 模板信息
     */
    private function getMailTemplate($templateCode)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.email_template
                WHERE template_code = :template_code
                AND is_enabled = TRUE
                AND status = 1
                LIMIT 1
            ");
            $stmt->execute(['template_code' => $templateCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('获取邮件模板失败', 'mail', [
                'template_code' => $templateCode,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 替换模板变量
     * 
     * @param string $content 模板内容
     * @param array $variables 变量数组
     * @return string 替换后的内容
     */
    private function replaceVariables($content, $variables)
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        return $content;
    }
    
    /**
     * 更新发送计数
     * 
     * @param int $configId 配置ID
     * @return bool 是否成功
     */
    private function updateSentCount($configId)
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE site_configs.email_config
                SET daily_sent_count = daily_sent_count + 1
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $configId]);
        } catch (PDOException $e) {
            $this->logger->error('更新邮件发送计数失败', 'mail', [
                'config_id' => $configId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 发送验证码邮件
     * 
     * @param string $to 收件人邮箱
     * @param string $username 用户名
     * @param string $code 验证码
     * @param string $scene 场景（register/login/reset_password）
     * @param int $expireMinutes 过期时间（分钟）
     * @return array 发送结果
     */
    public function sendVerificationCode($to, $username, $code, $scene = 'register', $expireMinutes = 15)
    {
        $templateMap = [
            'register' => 'REGISTER_CODE',
            'login' => 'LOGIN_CODE',
            'reset_password' => 'RESET_PASSWORD_CODE',
            'change_phone' => 'CHANGE_PHONE_CODE',
            'change_email' => 'CHANGE_EMAIL_CODE'
        ];
        
        $templateCode = $templateMap[$scene] ?? 'REGISTER_CODE';
        
        $variables = [
            'username' => $username,
            'code' => $code,
            'expire_minutes' => $expireMinutes
        ];
        
        // 如果是登录场景，添加额外信息
        if ($scene === 'login') {
            $variables['login_time'] = date('Y-m-d H:i:s');
            $variables['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '未知';
            $variables['login_location'] = '未知'; // TODO: 可以集成IP定位服务
        }
        
        return $this->sendMailByTemplate($to, $templateCode, $variables);
    }
    
    /**
     * 发送欢迎邮件
     * 
     * @param string $to 收件人邮箱
     * @param string $username 用户名
     * @param string $nickname 昵称
     * @param string $platformUrl 平台URL
     * @return array 发送结果
     */
    public function sendWelcomeEmail($to, $username, $nickname, $platformUrl = 'https://auth.example.com')
    {
        $variables = [
            'username' => $username,
            'nickname' => $nickname,
            'register_time' => date('Y-m-d H:i:s'),
            'platform_url' => $platformUrl
        ];
        
        return $this->sendMailByTemplate($to, 'WELCOME_EMAIL', $variables);
    }
    
    /**
     * 检查发送频率限制
     * 
     * @param string $email 邮箱地址
     * @param string $purpose 用途
     * @param int $limitSeconds 限制秒数（默认60秒）
     * @return bool 是否超出限制
     */
    public function checkRateLimit($email, $purpose, $limitSeconds = 60)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM email.code 
                WHERE email = :email 
                AND purpose = :purpose 
                AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 second' * :limit
            ");
            $stmt->execute([
                'email' => $email,
                'purpose' => $purpose,
                'limit' => $limitSeconds
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $isLimited = $result['count'] > 0;
            
            if ($isLimited) {
                $this->logger->warning('邮件发送频率限制', 'mail', [
                    'email' => $email,
                    'purpose' => $purpose,
                    'limit_seconds' => $limitSeconds
                ]);
            }
            
            return $isLimited;
        } catch (PDOException $e) {
            $this->logger->error('检查邮件频率限制失败', 'mail', [
                'email' => $email,
                'purpose' => $purpose,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 验证邮箱验证码（不标记为已使用）
     * 
     * @param string $email 邮箱地址
     * @param string $code 验证码
     * @param string $purpose 用途（register/login/reset_password）
     * @return array 验证结果
     */
    public function verifyCode($email, $code, $purpose, $codeId = null)
    {
        try {
            // 构建查询条件
            $sql = "
                SELECT * FROM email.code 
                WHERE email = :email 
                AND code = :code
                AND purpose = :purpose 
                AND status = 1 
                AND expires_at > CURRENT_TIMESTAMP
            ";
            
            $params = [
                'email' => $email,
                'code' => $code,
                'purpose' => $purpose
            ];
            
            // 如果提供了 code_id，添加到查询条件中
            if (!empty($codeId)) {
                $sql .= " AND code_id = :code_id";
                $params['code_id'] = $codeId;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            
            // 查询有效的验证码
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                return [
                    'success' => false,
                    'message' => '验证码无效或已过期'
                ];
            }
            
            // 只更新验证次数和最后验证时间，不标记为已使用
            $updateStmt = $this->pdo->prepare("
                UPDATE email.code 
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
            $this->logger->error('验证邮箱验证码失败', 'mail', [
                'email' => $email,
                'purpose' => $purpose,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => '验证失败'
            ];
        }
    }
    
    /**
     * 标记验证码为已使用
     * 
     * @param string $email 邮箱地址
     * @param string $code 验证码
     * @param string $purpose 用途
     * @return bool 是否成功
     */
    public function markCodeAsUsed($email, $code, $purpose)
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE email.code 
                SET status = 0
                WHERE email = :email 
                AND code = :code
                AND purpose = :purpose 
                AND status = 1
            ");
            $stmt->execute([
                'email' => $email,
                'code' => $code,
                'purpose' => $purpose
            ]);
            return true;
        } catch (PDOException $e) {
            $this->logger->error('标记邮箱验证码为已使用失败', 'mail', [
                'email' => $email,
                'purpose' => $purpose,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
