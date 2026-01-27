<?php
/**
 * 系统日志服务类
 * 用于记录系统操作、错误、安全事件等日志
 */

class SystemLogger
{
    private $pdo;
    
    // 日志级别常量
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    // 日志类型常量
    const TYPE_SYSTEM = 'system';
    const TYPE_SECURITY = 'security';
    const TYPE_OPERATION = 'operation';
    const TYPE_API = 'api';
    const TYPE_DATABASE = 'database';
    const TYPE_EMAIL = 'email';
    const TYPE_SMS = 'sms';
    const TYPE_CAPTCHA = 'captcha';
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * 记录日志
     * 
     * @param string $level 日志级别
     * @param string $type 日志类型
     * @param string $message 日志消息
     * @param array $options 可选参数
     * @return bool
     */
    public function log($level, $type, $message, $options = [])
    {
        try {
            $sql = "
                INSERT INTO logs.system_logs (
                    log_level, log_type, message, context, stack_trace,
                    request_method, request_uri, request_params,
                    client_ip, user_agent, user_id, session_id, created_by
                ) VALUES (
                    :log_level, :log_type, :message, :context, :stack_trace,
                    :request_method, :request_uri, :request_params,
                    :client_ip, :user_agent, :user_id, :session_id, :created_by
                )
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            // 构建 context 对象
            $context = [];
            if (isset($options['module'])) {
                $context['module'] = $options['module'];
            }
            if (isset($options['action'])) {
                $context['action'] = $options['action'];
            }
            if (isset($options['username'])) {
                $context['username'] = $options['username'];
            }
            if (isset($options['details'])) {
                $context['details'] = $options['details'];
            }
            
            // 绑定参数
            $stmt->bindValue(':log_level', strtoupper($level));
            $stmt->bindValue(':log_type', $type);
            $stmt->bindValue(':message', $message);
            $stmt->bindValue(':context', !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null);
            $stmt->bindValue(':stack_trace', $options['stack_trace'] ?? null);
            $stmt->bindValue(':request_method', $options['request_method'] ?? $_SERVER['REQUEST_METHOD'] ?? null);
            $stmt->bindValue(':request_uri', $options['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? null);
            $stmt->bindValue(':request_params', isset($options['request_params']) ? json_encode($options['request_params'], JSON_UNESCAPED_UNICODE) : null);
            $stmt->bindValue(':client_ip', $options['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null);
            $stmt->bindValue(':user_agent', $options['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null);
            $stmt->bindValue(':user_id', $options['user_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':session_id', $options['session_id'] ?? session_id() ?? null);
            $stmt->bindValue(':created_by', $options['created_by'] ?? $options['username'] ?? 'system');
            
            return $stmt->execute();
        } catch (Exception $e) {
            // 记录日志失败时写入错误日志
            error_log("系统日志记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 记录调试日志
     */
    public function debug($type, $message, $options = [])
    {
        return $this->log(self::LEVEL_DEBUG, $type, $message, $options);
    }
    
    /**
     * 记录信息日志
     */
    public function info($type, $message, $options = [])
    {
        return $this->log(self::LEVEL_INFO, $type, $message, $options);
    }
    
    /**
     * 记录警告日志
     */
    public function warning($type, $message, $options = [])
    {
        return $this->log(self::LEVEL_WARNING, $type, $message, $options);
    }
    
    /**
     * 记录错误日志
     */
    public function error($type, $message, $options = [])
    {
        return $this->log(self::LEVEL_ERROR, $type, $message, $options);
    }
    
    /**
     * 记录严重错误日志
     */
    public function critical($type, $message, $options = [])
    {
        return $this->log(self::LEVEL_CRITICAL, $type, $message, $options);
    }
    
    /**
     * 记录安全事件
     */
    public function security($message, $options = [])
    {
        $options['module'] = $options['module'] ?? 'security';
        return $this->log(self::LEVEL_WARNING, self::TYPE_SECURITY, $message, $options);
    }
    
    /**
     * 记录操作日志
     */
    public function operation($action, $message, $options = [])
    {
        $options['action'] = $action;
        return $this->log(self::LEVEL_INFO, self::TYPE_OPERATION, $message, $options);
    }
    
    /**
     * 记录API调用日志
     */
    public function api($message, $options = [])
    {
        return $this->log(self::LEVEL_INFO, self::TYPE_API, $message, $options);
    }
}
