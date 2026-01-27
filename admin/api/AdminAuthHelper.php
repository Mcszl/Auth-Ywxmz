<?php
/**
 * 管理员权限校验辅助类
 * 提供统一的管理员权限校验功能
 * 
 * @author AI Assistant
 * @date 2026-01-25
 */

class AdminAuthHelper {
    
    /**
     * 检查管理员权限
     * 
     * @param PDO $pdo 数据库连接
     * @param callable $jsonResponse JSON响应函数
     * @return array 返回管理员信息
     */
    public static function checkAdminPermission($pdo, $jsonResponse) {
        // 检查是否已登录
        if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
            $jsonResponse(false, null, '未登录', 401);
        }
        
        $userUuid = $_SESSION['user_uuid'];
        
        try {
            $stmt = $pdo->prepare("
                SELECT uuid, username, user_type, status 
                FROM users.user 
                WHERE uuid = :uuid
            ");
            $stmt->execute(['uuid' => $userUuid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $jsonResponse(false, null, '用户不存在', 403);
            }
            
            // 检查账户状态
            if ($user['status'] != 1) {
                $jsonResponse(false, null, '账户已被禁用', 403);
            }
            
            // 检查是否是管理员
            if (!in_array($user['user_type'], ['admin', 'siteadmin'])) {
                $jsonResponse(false, null, '权限不足，需要管理员权限', 403);
            }
            
            return $user;
            
        } catch (PDOException $e) {
            error_log("检查管理员权限失败: " . $e->getMessage());
            $jsonResponse(false, null, '服务器错误', 500);
        }
    }
}
