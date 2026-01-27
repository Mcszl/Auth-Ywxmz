<?php
/**
 * 昵称审核服务类
 * 一碗小米周授权登录平台
 */

class NicknameCheckService {
    
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 获取昵称审核配置
     */
    public function getConfig() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM site_configs.nickname_check_config 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("获取昵称审核配置失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查是否启用昵称审核
     */
    public function isEnabled() {
        $config = $this->getConfig();
        return $config && $config['is_enabled'];
    }
    
    /**
     * 生成游客昵称
     * 格式：游客-XXXXXX（6位随机大小写字母和数字）
     */
    public function generateGuestNickname() {
        $config = $this->getConfig();
        $prefix = $config['guest_prefix'] ?? '游客-';
        
        // 生成6位随机字符（大小写字母和数字）
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $prefix . $random;
    }
    
    /**
     * 验证昵称格式
     */
    public function validateNickname($nickname) {
        $config = $this->getConfig();
        
        if (!$config) {
            return [
                'valid' => false,
                'message' => '昵称审核配置未找到'
            ];
        }
        
        // 检查长度
        $length = mb_strlen($nickname, 'UTF-8');
        if ($length < $config['min_length']) {
            return [
                'valid' => false,
                'message' => "昵称长度不能少于{$config['min_length']}个字符"
            ];
        }
        
        if ($length > $config['max_length']) {
            return [
                'valid' => false,
                'message' => "昵称长度不能超过{$config['max_length']}个字符"
            ];
        }
        
        // 检查特殊字符
        if (!$config['allow_special_chars']) {
            if (preg_match('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9_]/u', $nickname)) {
                return [
                    'valid' => false,
                    'message' => '昵称只能包含中文、英文、数字和下划线'
                ];
            }
        }
        
        return [
            'valid' => true,
            'message' => '昵称格式正确'
        ];
    }
    
    /**
     * 检查敏感词
     */
    public function checkSensitiveWords($nickname) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM checks.sensitive_words 
                WHERE is_enabled = TRUE
            ");
            $stmt->execute();
            $sensitiveWords = $stmt->fetchAll();
            
            $foundWords = [];
            $shouldReject = false;
            
            foreach ($sensitiveWords as $wordData) {
                $word = $wordData['word'];
                if (mb_stripos($nickname, $word) !== false) {
                    $foundWords[] = [
                        'word' => $word,
                        'category' => $wordData['category'],
                        'severity' => $wordData['severity'],
                        'action' => $wordData['action']
                    ];
                    
                    if ($wordData['action'] === 'reject') {
                        $shouldReject = true;
                    }
                }
            }
            
            return [
                'has_sensitive' => !empty($foundWords),
                'should_reject' => $shouldReject,
                'words' => $foundWords
            ];
            
        } catch (PDOException $e) {
            error_log("检查敏感词失败: " . $e->getMessage());
            return [
                'has_sensitive' => false,
                'should_reject' => false,
                'words' => []
            ];
        }
    }
    
    /**
     * 提交昵称审核申请
     */
    public function submitNicknameCheck($userUuid, $oldNickname, $newNickname, $applyType = 'manual', $applyReason = '', $clientIp = null) {
        try {
            $config = $this->getConfig();
            
            // 验证昵称格式
            $validation = $this->validateNickname($newNickname);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }
            
            // 检查敏感词
            $sensitiveCheck = $this->checkSensitiveWords($newNickname);
            
            // 如果包含需要拒绝的敏感词，直接拒绝
            if ($sensitiveCheck['should_reject']) {
                return [
                    'success' => false,
                    'message' => '昵称包含敏感词，无法使用',
                    'sensitive_words' => $sensitiveCheck['words']
                ];
            }
            
            // 确定审核状态
            $status = 0; // 默认待审核
            $autoReviewed = false;
            
            // 如果启用自动审核且没有敏感词，自动通过
            if ($config && $config['auto_approve'] && !$sensitiveCheck['has_sensitive']) {
                $status = 1; // 自动通过
                $autoReviewed = true;
            }
            
            // 插入审核记录
            $stmt = $this->pdo->prepare("
                INSERT INTO checks.nickname_check (
                    user_uuid, old_nickname, new_nickname, apply_reason,
                    apply_ip, status, sensitive_words, auto_reviewed, apply_type,
                    review_time, review_comment
                ) VALUES (
                    :user_uuid, :old_nickname, :new_nickname, :apply_reason,
                    :apply_ip, :status, :sensitive_words, :auto_reviewed, :apply_type,
                    :review_time, :review_comment
                ) RETURNING id
            ");
            
            // 使用 bindValue 明确指定参数类型
            $stmt->bindValue(':user_uuid', $userUuid, PDO::PARAM_INT);
            $stmt->bindValue(':old_nickname', $oldNickname, PDO::PARAM_STR);
            $stmt->bindValue(':new_nickname', $newNickname, PDO::PARAM_STR);
            $stmt->bindValue(':apply_reason', $applyReason, PDO::PARAM_STR);
            $stmt->bindValue(':apply_ip', $clientIp, PDO::PARAM_STR);
            $stmt->bindValue(':status', $status, PDO::PARAM_INT);
            $stmt->bindValue(':sensitive_words', !empty($sensitiveCheck['words']) ? json_encode($sensitiveCheck['words'], JSON_UNESCAPED_UNICODE) : null, PDO::PARAM_STR);
            $stmt->bindValue(':auto_reviewed', $autoReviewed, PDO::PARAM_BOOL);
            $stmt->bindValue(':apply_type', $applyType, PDO::PARAM_STR);
            $stmt->bindValue(':review_time', $autoReviewed ? date('Y-m-d H:i:s') : null, PDO::PARAM_STR);
            $stmt->bindValue(':review_comment', $autoReviewed ? '自动审核通过' : null, PDO::PARAM_STR);
            
            $stmt->execute();
            
            $result = $stmt->fetch();
            $checkId = $result['id'];
            
            // 如果自动通过，更新用户昵称
            if ($status === 1) {
                $this->updateUserNickname($userUuid, $newNickname);
            }
            
            return [
                'success' => true,
                'message' => $status === 1 ? '昵称已更新' : '昵称审核申请已提交，请等待审核',
                'check_id' => $checkId,
                'status' => $status,
                'auto_approved' => $autoReviewed
            ];
            
        } catch (PDOException $e) {
            error_log("提交昵称审核失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '提交审核失败'
            ];
        }
    }
    
    /**
     * 更新用户昵称
     */
    private function updateUserNickname($userUuid, $nickname) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users.user 
                SET nickname = :nickname 
                WHERE uuid = :uuid
            ");
            $stmt->execute([
                'nickname' => $nickname,
                'uuid' => $userUuid
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("更新用户昵称失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户的审核记录
     */
    public function getUserCheckRecords($userUuid, $limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM checks.nickname_check 
                WHERE user_uuid = :user_uuid 
                ORDER BY apply_time DESC 
                LIMIT :limit
            ");
            $stmt->execute([
                'user_uuid' => $userUuid,
                'limit' => $limit
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("获取审核记录失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取待审核的记录
     */
    public function getPendingChecks($limit = 50, $offset = 0) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT nc.*, u.username, u.phone, u.email 
                FROM checks.nickname_check nc
                LEFT JOIN users.user u ON nc.user_uuid = u.uuid
                WHERE nc.status = 0 
                ORDER BY nc.apply_time ASC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([
                'limit' => $limit,
                'offset' => $offset
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("获取待审核记录失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 审核昵称（管理员操作）
     */
    public function reviewNickname($checkId, $status, $reviewerUuid, $reviewerName, $reviewComment = '', $rejectReason = '') {
        try {
            // 获取审核记录
            $stmt = $this->pdo->prepare("
                SELECT * FROM checks.nickname_check WHERE id = :id
            ");
            $stmt->execute(['id' => $checkId]);
            $check = $stmt->fetch();
            
            if (!$check) {
                return [
                    'success' => false,
                    'message' => '审核记录不存在'
                ];
            }
            
            if ($check['status'] != 0) {
                return [
                    'success' => false,
                    'message' => '该记录已被审核'
                ];
            }
            
            // 更新审核记录
            $stmt = $this->pdo->prepare("
                UPDATE checks.nickname_check 
                SET status = :status,
                    review_time = CURRENT_TIMESTAMP,
                    reviewer_uuid = :reviewer_uuid,
                    reviewer_name = :reviewer_name,
                    review_comment = :review_comment,
                    reject_reason = :reject_reason
                WHERE id = :id
            ");
            
            $stmt->execute([
                'status' => $status,
                'reviewer_uuid' => $reviewerUuid,
                'reviewer_name' => $reviewerName,
                'review_comment' => $reviewComment,
                'reject_reason' => $rejectReason,
                'id' => $checkId
            ]);
            
            // 如果审核通过，更新用户昵称
            if ($status == 1) {
                $this->updateUserNickname($check['user_uuid'], $check['new_nickname']);
            }
            
            return [
                'success' => true,
                'message' => $status == 1 ? '审核通过' : '审核拒绝'
            ];
            
        } catch (PDOException $e) {
            error_log("审核昵称失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '审核失败'
            ];
        }
    }
}
