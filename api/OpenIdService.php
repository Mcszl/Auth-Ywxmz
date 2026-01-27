<?php
/**
 * OpenID 服务类
 * 用于管理用户在不同应用中的 OpenID
 */

class OpenIdService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 生成 OpenID
     */
    private function generateOpenId($userUuid, $appId) {
        // 使用 UUID + AppID + 随机数生成唯一的 OpenID
        $data = $userUuid . $appId . time() . random_bytes(16);
        return 'OPENID_' . strtoupper(substr(md5($data), 0, 24));
    }
    
    /**
     * 获取或创建用户的 OpenID
     * 
     * @param int $userUuid 用户UUID
     * @param string $appId 应用ID
     * @param array $options 可选参数（tags, group_name）
     * @return array 包含 openid 的结果
     */
    public function getOrCreateOpenId($userUuid, $appId, $options = []) {
        try {
            // 查询是否已存在 OpenID
            $stmt = $this->pdo->prepare("
                SELECT openid, tags, group_name, status 
                FROM users.openid 
                WHERE user_uuid = :user_uuid 
                AND app_id = :app_id
                LIMIT 1
            ");
            $stmt->execute([
                'user_uuid' => $userUuid,
                'app_id' => $appId
            ]);
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 如果已存在，直接返回
            if ($record) {
                return [
                    'success' => true,
                    'openid' => $record['openid'],
                    'is_new' => false,
                    'data' => $record
                ];
            }
            
            // 不存在则创建新的 OpenID
            $openid = $this->generateOpenId($userUuid, $appId);
            $tags = $options['tags'] ?? null;
            $groupName = $options['group_name'] ?? null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO users.openid (
                    openid, user_uuid, app_id, tags, group_name, status
                ) VALUES (
                    :openid, :user_uuid, :app_id, :tags, :group_name, 1
                ) RETURNING openid, tags, group_name, status
            ");
            
            $stmt->execute([
                'openid' => $openid,
                'user_uuid' => $userUuid,
                'app_id' => $appId,
                'tags' => $tags,
                'group_name' => $groupName
            ]);
            
            $newRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'openid' => $newRecord['openid'],
                'is_new' => true,
                'data' => $newRecord
            ];
            
        } catch (PDOException $e) {
            error_log("获取或创建 OpenID 失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '获取 OpenID 失败'
            ];
        }
    }
    
    /**
     * 通过 OpenID 获取用户UUID
     * 
     * @param string $openid OpenID
     * @param string $appId 应用ID
     * @return array 包含 user_uuid 的结果
     */
    public function getUserUuidByOpenId($openid, $appId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT user_uuid, status 
                FROM users.openid 
                WHERE openid = :openid 
                AND app_id = :app_id
                AND status = 1
                LIMIT 1
            ");
            $stmt->execute([
                'openid' => $openid,
                'app_id' => $appId
            ]);
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                return [
                    'success' => false,
                    'message' => 'OpenID 不存在或已禁用'
                ];
            }
            
            return [
                'success' => true,
                'user_uuid' => $record['user_uuid']
            ];
            
        } catch (PDOException $e) {
            error_log("通过 OpenID 获取用户UUID失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '查询失败'
            ];
        }
    }
    
    /**
     * 更新 OpenID 信息
     * 
     * @param string $openid OpenID
     * @param string $appId 应用ID
     * @param array $data 更新的数据（tags, group_name）
     * @return array 更新结果
     */
    public function updateOpenId($openid, $appId, $data) {
        try {
            $updates = [];
            $params = [
                'openid' => $openid,
                'app_id' => $appId
            ];
            
            if (isset($data['tags'])) {
                $updates[] = "tags = :tags";
                $params['tags'] = $data['tags'];
            }
            
            if (isset($data['group_name'])) {
                $updates[] = "group_name = :group_name";
                $params['group_name'] = $data['group_name'];
            }
            
            if (empty($updates)) {
                return [
                    'success' => false,
                    'message' => '没有需要更新的数据'
                ];
            }
            
            $sql = "UPDATE users.openid SET " . implode(', ', $updates) . " 
                    WHERE openid = :openid AND app_id = :app_id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'message' => '更新成功'
            ];
            
        } catch (PDOException $e) {
            error_log("更新 OpenID 失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '更新失败'
            ];
        }
    }
}
