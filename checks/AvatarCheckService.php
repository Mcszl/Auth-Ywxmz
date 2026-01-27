<?php
/**
 * 头像审核服务类
 * 用于处理头像审核相关逻辑
 */

class AvatarCheckService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 检查头像是否需要审核
     * 
     * @param string $avatarUrl 头像URL
     * @param string $userUuid 用户UUID
     * @param string $oldAvatar 原头像URL
     * @param string $filename 文件名
     * @param string $storageType 存储类型
     * @param int $storageConfigId 存储配置ID
     * @return array 返回审核结果
     */
    public function checkAvatar($avatarUrl, $userUuid, $oldAvatar = null, $filename = '', $storageType = '', $storageConfigId = 0) {
        try {
            // 查询审核配置
            $stmt = $this->pdo->prepare("
                SELECT enabled, check_type, api_key, api_secret, region 
                FROM site_configs.avatar_check_config 
                LIMIT 1
            ");
            $stmt->execute();
            $config = $stmt->fetch();
            
            // 如果未启用审核，直接通过
            if (!$config || !$config['enabled']) {
                return [
                    'success' => true,
                    'need_manual_review' => false,
                    'message' => '审核未启用'
                ];
            }
            
            $checkType = $config['check_type'];
            
            // 根据审核类型处理
            switch ($checkType) {
                case 'manual':
                    // 人工审核：创建审核记录
                    return $this->createManualReview($avatarUrl, $userUuid, $oldAvatar, $filename, $storageType, $storageConfigId);
                    
                case 'tencent':
                    // 腾讯云审核
                    return $this->checkWithTencent($avatarUrl, $userUuid, $oldAvatar, $config, $filename, $storageType, $storageConfigId);
                    
                case 'aliyun':
                    // 阿里云审核
                    return $this->checkWithAliyun($avatarUrl, $userUuid, $oldAvatar, $config, $filename, $storageType, $storageConfigId);
                    
                default:
                    return [
                        'success' => false,
                        'need_manual_review' => false,
                        'message' => '未知的审核类型'
                    ];
            }
            
        } catch (Exception $e) {
            error_log("头像审核错误: " . $e->getMessage());
            return [
                'success' => false,
                'need_manual_review' => false,
                'message' => '审核服务异常'
            ];
        }
    }
    
    /**
     * 创建人工审核记录
     */
    private function createManualReview($avatarUrl, $userUuid, $oldAvatar, $filename, $storageType, $storageConfigId) {
        try {
            // 详细记录调试信息
            error_log("=== 创建人工审核记录 ===");
            error_log("avatarUrl: " . $avatarUrl);
            error_log("userUuid: " . $userUuid);
            error_log("oldAvatar: " . $oldAvatar);
            error_log("filename: " . $filename);
            error_log("storageType: " . $storageType);
            error_log("storageConfigId: " . $storageConfigId);
            error_log("filename empty: " . (empty($filename) ? 'YES' : 'NO'));
            error_log("storageType empty: " . (empty($storageType) ? 'YES' : 'NO'));
            error_log("storageConfigId empty: " . (empty($storageConfigId) ? 'YES' : 'NO'));
            error_log("======================");
            
            $stmt = $this->pdo->prepare("
                INSERT INTO checks.avatar_check 
                (user_uuid, old_avatar, new_avatar, new_avatar_filename, storage_type, storage_config_id, check_type, status, submitted_at) 
                VALUES (:user_uuid, :old_avatar, :new_avatar, :filename, :storage_type, :storage_config_id, 'manual', 0, CURRENT_TIMESTAMP)
            ");
            
            $params = [
                'user_uuid' => $userUuid,
                'old_avatar' => $oldAvatar,
                'new_avatar' => $avatarUrl,
                'filename' => $filename,
                'storage_type' => $storageType,
                'storage_config_id' => $storageConfigId
            ];
            
            error_log("SQL params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
            
            $stmt->execute($params);
            
            return [
                'success' => true,
                'need_manual_review' => true,
                'message' => '已提交人工审核'
            ];
            
        } catch (PDOException $e) {
            $errorMsg = "创建头像审核记录失败: " . $e->getMessage();
            error_log($errorMsg);
            error_log("SQL State: " . $e->getCode());
            error_log("Error Info: " . json_encode($e->errorInfo));
            
            return [
                'success' => false,
                'need_manual_review' => false,
                'message' => '提交审核失败：数据库错误'
            ];
        } catch (Exception $e) {
            $errorMsg = "创建头像审核记录异常: " . $e->getMessage();
            error_log($errorMsg);
            
            return [
                'success' => false,
                'need_manual_review' => false,
                'message' => '提交审核失败：系统错误'
            ];
        }
    }
    
    /**
     * 腾讯云内容审核
     */
    private function checkWithTencent($avatarUrl, $userUuid, $oldAvatar, $config, $filename, $storageType, $storageConfigId) {
        // TODO: 实现腾讯云内容审核API调用
        // 需要使用 $config['api_key'], $config['api_secret'], $config['region']
        
        // 暂时创建审核记录
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO checks.avatar_check 
                (user_uuid, old_avatar, new_avatar, new_avatar_filename, storage_type, storage_config_id, check_type, status, submitted_at) 
                VALUES (:user_uuid, :old_avatar, :new_avatar, :filename, :storage_type, :storage_config_id, 'tencent', 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                'user_uuid' => $userUuid,
                'old_avatar' => $oldAvatar,
                'new_avatar' => $avatarUrl,
                'filename' => $filename,
                'storage_type' => $storageType,
                'storage_config_id' => $storageConfigId
            ]);
            
            return [
                'success' => true,
                'need_manual_review' => false,
                'message' => '腾讯云审核（暂未实现，直接通过）'
            ];
            
        } catch (PDOException $e) {
            error_log("创建腾讯云审核记录失败: " . $e->getMessage());
            return [
                'success' => false,
                'need_manual_review' => false,
                'message' => '提交审核失败'
            ];
        }
    }
    
    /**
     * 阿里云内容审核
     */
    private function checkWithAliyun($avatarUrl, $userUuid, $oldAvatar, $config, $filename, $storageType, $storageConfigId) {
        // TODO: 实现阿里云内容审核API调用
        // 需要使用 $config['api_key'], $config['api_secret'], $config['region']
        
        // 暂时创建审核记录
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO checks.avatar_check 
                (user_uuid, old_avatar, new_avatar, new_avatar_filename, storage_type, storage_config_id, check_type, status, submitted_at) 
                VALUES (:user_uuid, :old_avatar, :new_avatar, :filename, :storage_type, :storage_config_id, 'aliyun', 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                'user_uuid' => $userUuid,
                'old_avatar' => $oldAvatar,
                'new_avatar' => $avatarUrl,
                'filename' => $filename,
                'storage_type' => $storageType,
                'storage_config_id' => $storageConfigId
            ]);
            
            return [
                'success' => true,
                'need_manual_review' => false,
                'message' => '阿里云审核（暂未实现，直接通过）'
            ];
            
        } catch (PDOException $e) {
            error_log("创建阿里云审核记录失败: " . $e->getMessage());
            return [
                'success' => false,
                'need_manual_review' => false,
                'message' => '提交审核失败'
            ];
        }
    }
    
    /**
     * 获取用户待审核的头像
     */
    public function getPendingAvatar($userUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, new_avatar, check_type, submitted_at 
                FROM checks.avatar_check 
                WHERE user_uuid = :user_uuid AND status = 0 
                ORDER BY submitted_at DESC 
                LIMIT 1
            ");
            $stmt->execute(['user_uuid' => $userUuid]);
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("查询待审核头像失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 审核通过
     */
    public function approve($checkId, $reviewerUuid = null) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE checks.avatar_check 
                SET status = 1, 
                    reviewer_uuid = :reviewer_uuid, 
                    reviewed_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $checkId,
                'reviewer_uuid' => $reviewerUuid
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("审核通过失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 审核不通过
     */
    public function reject($checkId, $message, $reviewerUuid = null) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE checks.avatar_check 
                SET status = 2, 
                    check_message = :message, 
                    reviewer_uuid = :reviewer_uuid, 
                    reviewed_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $checkId,
                'message' => $message,
                'reviewer_uuid' => $reviewerUuid
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("审核不通过失败: " . $e->getMessage());
            return false;
        }
    }
}
