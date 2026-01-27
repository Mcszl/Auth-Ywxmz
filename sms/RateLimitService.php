<?php
/**
 * 频率限制服务类
 * 基于 Redis 实现的短信发送频率限制
 */

require_once __DIR__ . '/../config/redis.config.php';

class RateLimitService {
    
    private $redis;
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->redis = getRedisConnection();
    }
    
    /**
     * 检查是否在白名单中
     */
    public function isInWhitelist($phone) {
        // 先检查 Redis 缓存
        if ($this->redis) {
            $cacheKey = REDIS_KEY_WHITELIST . ':' . $phone;
            $cached = $this->redis->get($cacheKey);
            
            if ($cached !== false) {
                return $cached === '1';
            }
        }
        
        // 查询数据库
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM sms.whitelist 
                WHERE phone = :phone 
                AND is_enabled = TRUE
                AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
            ");
            $stmt->execute(['phone' => $phone]);
            $result = $stmt->fetch();
            $inWhitelist = $result['count'] > 0;
            
            // 缓存结果（5分钟）
            if ($this->redis) {
                $this->redis->setex($cacheKey, 300, $inWhitelist ? '1' : '0');
            }
            
            return $inWhitelist;
            
        } catch (PDOException $e) {
            error_log("检查白名单失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查是否在黑名单中
     */
    public function isInBlacklist($phone) {
        // 先检查 Redis 缓存
        if ($this->redis) {
            $cacheKey = REDIS_KEY_BLACKLIST . ':' . $phone;
            $cached = $this->redis->get($cacheKey);
            
            if ($cached !== false) {
                return $cached === '1';
            }
        }
        
        // 查询数据库
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM sms.blacklist 
                WHERE phone = :phone 
                AND is_enabled = TRUE
                AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
            ");
            $stmt->execute(['phone' => $phone]);
            $result = $stmt->fetch();
            $inBlacklist = $result['count'] > 0;
            
            // 缓存结果（5分钟）
            if ($this->redis) {
                $this->redis->setex($cacheKey, 300, $inBlacklist ? '1' : '0');
            }
            
            return $inBlacklist;
            
        } catch (PDOException $e) {
            error_log("检查黑名单失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取发送限制配置
     */
    public function getSendLimits($templateId, $purpose) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM sms.send_limit 
                WHERE is_enabled = TRUE
                AND (template_id = :template_id OR template_id = '*')
                AND (purpose = :purpose OR purpose = '*')
                ORDER BY priority ASC
            ");
            $stmt->execute([
                'template_id' => $templateId,
                'purpose' => $purpose
            ]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("获取发送限制配置失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 检查频率限制
     */
    public function checkRateLimit($phone, $ip, $templateId, $purpose) {
        // 1. 检查黑名单
        if ($this->isInBlacklist($phone)) {
            return [
                'allowed' => false,
                'reason' => '该手机号已被加入黑名单',
                'type' => 'blacklist'
            ];
        }
        
        // 2. 检查白名单（白名单不受限制）
        if ($this->isInWhitelist($phone)) {
            return [
                'allowed' => true,
                'reason' => '白名单用户',
                'type' => 'whitelist'
            ];
        }
        
        // 3. 如果 Redis 不可用，使用数据库降级方案
        if (!$this->redis) {
            return $this->checkRateLimitFallback($phone, $purpose);
        }
        
        // 4. 获取限制配置
        $limits = $this->getSendLimits($templateId, $purpose);
        
        if (empty($limits)) {
            // 没有配置限制，允许发送
            return ['allowed' => true, 'reason' => '无限制配置'];
        }
        
        // 5. 检查每个限制规则
        foreach ($limits as $limit) {
            $key = $this->buildRateLimitKey($limit['limit_type'], $phone, $ip, $templateId);
            $count = $this->redis->get($key);
            
            if ($count !== false && $count >= $limit['max_count']) {
                $ttl = $this->redis->ttl($key);
                return [
                    'allowed' => false,
                    'reason' => $limit['limit_name'],
                    'type' => $limit['limit_type'],
                    'retry_after' => $ttl > 0 ? $ttl : $limit['time_window'],
                    'limit' => $limit['max_count'],
                    'current' => $count
                ];
            }
        }
        
        return ['allowed' => true, 'reason' => '通过频率检查'];
    }
    
    /**
     * 记录发送（增加计数）
     */
    public function recordSend($phone, $ip, $templateId, $purpose) {
        if (!$this->redis) {
            return true;
        }
        
        // 获取限制配置
        $limits = $this->getSendLimits($templateId, $purpose);
        
        // 为每个限制规则增加计数
        foreach ($limits as $limit) {
            $key = $this->buildRateLimitKey($limit['limit_type'], $phone, $ip, $templateId);
            
            // 使用 INCR 原子操作
            $count = $this->redis->incr($key);
            
            // 如果是第一次，设置过期时间
            if ($count == 1) {
                $this->redis->expire($key, $limit['time_window']);
            }
        }
        
        return true;
    }
    
    /**
     * 构建 Redis Key
     */
    private function buildRateLimitKey($limitType, $phone, $ip, $templateId) {
        $prefix = REDIS_KEY_RATE_LIMIT;
        
        switch ($limitType) {
            case 'phone':
                return $prefix . 'phone:' . $phone;
                
            case 'ip':
                return $prefix . 'ip:' . $ip;
                
            case 'phone_template':
                return $prefix . 'phone_template:' . $phone . ':' . $templateId;
                
            case 'ip_template':
                return $prefix . 'ip_template:' . $ip . ':' . $templateId;
                
            case 'global':
                return $prefix . 'global';
                
            default:
                return $prefix . 'unknown:' . $phone;
        }
    }
    
    /**
     * 降级方案：使用数据库检查频率限制
     */
    private function checkRateLimitFallback($phone, $purpose) {
        try {
            // 检查最近60秒内的发送次数
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM sms.code 
                WHERE phone = :phone 
                AND purpose = :purpose 
                AND created_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds'
            ");
            $stmt->execute([
                'phone' => $phone,
                'purpose' => $purpose
            ]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                return [
                    'allowed' => false,
                    'reason' => '发送过于频繁（数据库降级检查）',
                    'type' => 'fallback',
                    'retry_after' => 60
                ];
            }
            
            return ['allowed' => true, 'reason' => '通过降级检查'];
            
        } catch (PDOException $e) {
            error_log("降级检查失败: " . $e->getMessage());
            // 出错时允许发送，避免影响业务
            return ['allowed' => true, 'reason' => '检查失败，允许发送'];
        }
    }
    
    /**
     * 获取剩余次数
     */
    public function getRemainingCount($phone, $ip, $templateId, $purpose) {
        if (!$this->redis) {
            return null;
        }
        
        $limits = $this->getSendLimits($templateId, $purpose);
        $remaining = [];
        
        foreach ($limits as $limit) {
            $key = $this->buildRateLimitKey($limit['limit_type'], $phone, $ip, $templateId);
            $count = $this->redis->get($key);
            $current = $count !== false ? (int)$count : 0;
            $ttl = $this->redis->ttl($key);
            
            $remaining[] = [
                'limit_name' => $limit['limit_name'],
                'limit_type' => $limit['limit_type'],
                'max_count' => $limit['max_count'],
                'current_count' => $current,
                'remaining_count' => max(0, $limit['max_count'] - $current),
                'time_window' => $limit['time_window'],
                'reset_in' => $ttl > 0 ? $ttl : 0
            ];
        }
        
        return $remaining;
    }
    
    /**
     * 清除限制（用于测试或管理）
     */
    public function clearLimit($phone, $ip = null, $templateId = null) {
        if (!$this->redis) {
            return false;
        }
        
        $pattern = REDIS_KEY_RATE_LIMIT . '*' . $phone . '*';
        $keys = $this->redis->keys($pattern);
        
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        
        return true;
    }
    
    /**
     * 添加到白名单
     */
    public function addToWhitelist($phone, $reason, $expiresAt = null, $createdBy = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sms.whitelist (phone, reason, expires_at, created_by)
                VALUES (:phone, :reason, :expires_at, :created_by)
                ON CONFLICT (phone) 
                DO UPDATE SET 
                    reason = EXCLUDED.reason,
                    expires_at = EXCLUDED.expires_at,
                    is_enabled = TRUE,
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                'phone' => $phone,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy
            ]);
            
            // 清除缓存
            if ($this->redis) {
                $this->redis->del(REDIS_KEY_WHITELIST . ':' . $phone);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("添加白名单失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 添加到黑名单
     */
    public function addToBlacklist($phone, $reason, $expiresAt = null, $createdBy = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sms.blacklist (phone, reason, expires_at, created_by)
                VALUES (:phone, :reason, :expires_at, :created_by)
                ON CONFLICT (phone) 
                DO UPDATE SET 
                    reason = EXCLUDED.reason,
                    expires_at = EXCLUDED.expires_at,
                    is_enabled = TRUE,
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                'phone' => $phone,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy
            ]);
            
            // 清除缓存
            if ($this->redis) {
                $this->redis->del(REDIS_KEY_BLACKLIST . ':' . $phone);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("添加黑名单失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从白名单移除
     */
    public function removeFromWhitelist($phone) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sms.whitelist 
                SET is_enabled = FALSE, updated_at = CURRENT_TIMESTAMP
                WHERE phone = :phone
            ");
            $stmt->execute(['phone' => $phone]);
            
            // 清除缓存
            if ($this->redis) {
                $this->redis->del(REDIS_KEY_WHITELIST . ':' . $phone);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("移除白名单失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从黑名单移除
     */
    public function removeFromBlacklist($phone) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sms.blacklist 
                SET is_enabled = FALSE, updated_at = CURRENT_TIMESTAMP
                WHERE phone = :phone
            ");
            $stmt->execute(['phone' => $phone]);
            
            // 清除缓存
            if ($this->redis) {
                $this->redis->del(REDIS_KEY_BLACKLIST . ':' . $phone);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("移除黑名单失败: " . $e->getMessage());
            return false;
        }
    }
}
