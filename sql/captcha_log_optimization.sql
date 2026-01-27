-- 人机验证日志表性能优化
-- 用于提高大数据量下的查询性能

-- 1. 添加复合索引（日期 + 场景）
CREATE INDEX IF NOT EXISTS idx_captcha_log_created_scene 
ON site_configs.captcha_verify_log(created_at DESC, scene);

-- 2. 添加复合索引（日期 + 提供商）
CREATE INDEX IF NOT EXISTS idx_captcha_log_created_provider 
ON site_configs.captcha_verify_log(created_at DESC, provider);

-- 3. 添加复合索引（日期 + 验证结果）
CREATE INDEX IF NOT EXISTS idx_captcha_log_created_success 
ON site_configs.captcha_verify_log(created_at DESC, verify_success);

-- 4. 添加复合索引（日期 + IP）
CREATE INDEX IF NOT EXISTS idx_captcha_log_created_ip 
ON site_configs.captcha_verify_log(created_at DESC, client_ip);

-- 5. 添加邮箱索引
CREATE INDEX IF NOT EXISTS idx_captcha_log_email 
ON site_configs.captcha_verify_log(email);

-- 6. 添加用户ID索引
CREATE INDEX IF NOT EXISTS idx_captcha_log_user_id 
ON site_configs.captcha_verify_log(user_id);

-- 7. 添加提供商索引
CREATE INDEX IF NOT EXISTS idx_captcha_log_provider 
ON site_configs.captcha_verify_log(provider);

-- 8. 添加验证成功索引
CREATE INDEX IF NOT EXISTS idx_captcha_log_success 
ON site_configs.captcha_verify_log(verify_success);

-- 9. 创建分区表（可选，用于超大数据量）
-- 按月分区，提高查询性能
-- 注意：这需要 PostgreSQL 10+ 版本

-- 示例：创建分区表（如果需要）
-- CREATE TABLE site_configs.captcha_verify_log_partitioned (
--     LIKE site_configs.captcha_verify_log INCLUDING ALL
-- ) PARTITION BY RANGE (created_at);

-- 创建月度分区
-- CREATE TABLE site_configs.captcha_verify_log_2026_01 
-- PARTITION OF site_configs.captcha_verify_log_partitioned
-- FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');

-- 10. 添加统计信息收集
ANALYZE site_configs.captcha_verify_log;

-- 11. 查看索引使用情况
-- SELECT 
--     schemaname,
--     tablename,
--     indexname,
--     idx_scan,
--     idx_tup_read,
--     idx_tup_fetch
-- FROM pg_stat_user_indexes
-- WHERE tablename = 'captcha_verify_log'
-- ORDER BY idx_scan DESC;
