-- 头像审核配置和审核记录表
-- 配置表存放在 site_configs schema
-- 审核记录表存放在 checks schema

-- 创建 site_configs schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS site_configs;

-- 创建 checks schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS checks;

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- ============================================
-- 头像审核配置表（存放在 site_configs）
-- ============================================
CREATE TABLE IF NOT EXISTS site_configs.avatar_check_config (
    id SERIAL PRIMARY KEY,
    enabled BOOLEAN NOT NULL DEFAULT false,
    check_type VARCHAR(50) NOT NULL DEFAULT 'manual',
    api_key TEXT,
    api_secret TEXT,
    region VARCHAR(50),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 添加配置表注释
COMMENT ON TABLE site_configs.avatar_check_config IS '头像审核配置表';
COMMENT ON COLUMN site_configs.avatar_check_config.id IS '主键ID';
COMMENT ON COLUMN site_configs.avatar_check_config.enabled IS '是否启用头像审核：true-启用，false-不启用';
COMMENT ON COLUMN site_configs.avatar_check_config.check_type IS '审核方式：manual-人工审核，tencent-腾讯云内容审核，aliyun-阿里云内容审核';
COMMENT ON COLUMN site_configs.avatar_check_config.api_key IS 'API密钥（腾讯云SecretId或阿里云AccessKeyId）';
COMMENT ON COLUMN site_configs.avatar_check_config.api_secret IS 'API密钥（腾讯云SecretKey或阿里云AccessKeySecret）';
COMMENT ON COLUMN site_configs.avatar_check_config.region IS '服务区域（如：ap-guangzhou、cn-shanghai）';
COMMENT ON COLUMN site_configs.avatar_check_config.created_at IS '创建时间（北京时间）';
COMMENT ON COLUMN site_configs.avatar_check_config.updated_at IS '更新时间（北京时间）';

-- 插入默认配置（不启用审核）
INSERT INTO site_configs.avatar_check_config (enabled, check_type) 
VALUES (false, 'manual')
ON CONFLICT DO NOTHING;

-- 创建配置表更新时间触发器函数
CREATE OR REPLACE FUNCTION site_configs.update_avatar_check_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 创建配置表触发器
DROP TRIGGER IF EXISTS trigger_update_avatar_check_config_updated_at ON site_configs.avatar_check_config;
CREATE TRIGGER trigger_update_avatar_check_config_updated_at
    BEFORE UPDATE ON site_configs.avatar_check_config
    FOR EACH ROW
    EXECUTE FUNCTION site_configs.update_avatar_check_config_updated_at();

-- ============================================
-- 头像审核记录表（存放在 checks）
-- ============================================
CREATE TABLE IF NOT EXISTS checks.avatar_check (
    id SERIAL PRIMARY KEY,
    user_uuid VARCHAR(50) NOT NULL,
    old_avatar TEXT,
    new_avatar TEXT NOT NULL,
    new_avatar_filename VARCHAR(255) NOT NULL,
    storage_type VARCHAR(20) NOT NULL,
    storage_config_id INTEGER NOT NULL,
    check_type VARCHAR(50) NOT NULL,
    status INTEGER NOT NULL DEFAULT 0,
    check_message TEXT,
    reviewer_uuid VARCHAR(50),
    submitted_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 添加审核记录表注释
COMMENT ON TABLE checks.avatar_check IS '头像审核记录表';
COMMENT ON COLUMN checks.avatar_check.id IS '主键ID';
COMMENT ON COLUMN checks.avatar_check.user_uuid IS '用户UUID';
COMMENT ON COLUMN checks.avatar_check.old_avatar IS '原头像URL';
COMMENT ON COLUMN checks.avatar_check.new_avatar IS '新头像URL';
COMMENT ON COLUMN checks.avatar_check.new_avatar_filename IS '新头像文件名（用于从存储中获取）';
COMMENT ON COLUMN checks.avatar_check.storage_type IS '存储类型：local-本地存储，s3-对象存储';
COMMENT ON COLUMN checks.avatar_check.storage_config_id IS '存储配置ID（关联site_configs.storage_config表）';
COMMENT ON COLUMN checks.avatar_check.check_type IS '审核方式：manual-人工审核，tencent-腾讯云审核，aliyun-阿里云审核';
COMMENT ON COLUMN checks.avatar_check.status IS '审核状态：0-待审核，1-审核通过，2-审核不通过';
COMMENT ON COLUMN checks.avatar_check.check_message IS '审核信息（不通过原因或审核备注）';
COMMENT ON COLUMN checks.avatar_check.reviewer_uuid IS '审核人UUID（人工审核时记录）';
COMMENT ON COLUMN checks.avatar_check.submitted_at IS '提交审核时间（北京时间）';
COMMENT ON COLUMN checks.avatar_check.reviewed_at IS '审核完成时间（北京时间）';
COMMENT ON COLUMN checks.avatar_check.created_at IS '创建时间（北京时间）';
COMMENT ON COLUMN checks.avatar_check.updated_at IS '更新时间（北京时间）';

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_avatar_check_user_uuid ON checks.avatar_check(user_uuid);
CREATE INDEX IF NOT EXISTS idx_avatar_check_status ON checks.avatar_check(status);
CREATE INDEX IF NOT EXISTS idx_avatar_check_submitted_at ON checks.avatar_check(submitted_at);

-- 创建审核记录表更新时间触发器函数
CREATE OR REPLACE FUNCTION checks.update_avatar_check_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 创建审核记录表触发器
DROP TRIGGER IF EXISTS trigger_update_avatar_check_updated_at ON checks.avatar_check;
CREATE TRIGGER trigger_update_avatar_check_updated_at
    BEFORE UPDATE ON checks.avatar_check
    FOR EACH ROW
    EXECUTE FUNCTION checks.update_avatar_check_updated_at();

