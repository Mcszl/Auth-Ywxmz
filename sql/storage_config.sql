-- 存储配置表
-- 用于配置文件存储方式：本地存储或兼容S3的对象存储

-- 创建 site_configs schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS site_configs;

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- ============================================
-- 存储配置表
-- ============================================
CREATE TABLE IF NOT EXISTS site_configs.storage_config (
    id SERIAL PRIMARY KEY,
    config_name VARCHAR(100) NOT NULL,
    usage_type VARCHAR(50) NOT NULL,
    storage_type VARCHAR(50) NOT NULL DEFAULT 'local',
    enabled BOOLEAN NOT NULL DEFAULT true,
    
    -- 本地存储配置
    local_path TEXT,
    local_url_prefix TEXT,
    local_auto_create_path BOOLEAN DEFAULT false,
    
    -- S3兼容存储配置
    s3_endpoint TEXT,
    s3_region VARCHAR(100),
    s3_bucket VARCHAR(255),
    s3_path TEXT,
    s3_access_key TEXT,
    s3_secret_key TEXT,
    s3_use_path_style BOOLEAN DEFAULT false,
    s3_url_prefix TEXT,
    s3_auto_create_path BOOLEAN DEFAULT false,
    
    -- 通用配置
    max_file_size BIGINT DEFAULT 5242880,
    allowed_extensions TEXT,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(usage_type)
);

-- 添加表注释
COMMENT ON TABLE site_configs.storage_config IS '存储配置表';
COMMENT ON COLUMN site_configs.storage_config.id IS '主键ID';
COMMENT ON COLUMN site_configs.storage_config.config_name IS '配置名称（便于识别）';
COMMENT ON COLUMN site_configs.storage_config.usage_type IS '用途类型：avatar-头像存储，avatar_pending-待审核头像，document-文档存储，temp-临时文件';
COMMENT ON COLUMN site_configs.storage_config.storage_type IS '存储类型：local-本地存储，s3-兼容S3的对象存储';
COMMENT ON COLUMN site_configs.storage_config.enabled IS '是否启用该存储配置';

-- 本地存储配置字段
COMMENT ON COLUMN site_configs.storage_config.local_path IS '本地存储路径（绝对路径或相对路径）';
COMMENT ON COLUMN site_configs.storage_config.local_url_prefix IS '本地存储访问URL前缀（如：https://example.com/uploads）';
COMMENT ON COLUMN site_configs.storage_config.local_auto_create_path IS '本地存储：如果路径不存在是否自动创建';

-- S3兼容存储配置字段
COMMENT ON COLUMN site_configs.storage_config.s3_endpoint IS 'S3服务端点（如：s3.amazonaws.com、oss-cn-hangzhou.aliyuncs.com）';
COMMENT ON COLUMN site_configs.storage_config.s3_region IS 'S3区域（如：us-east-1、cn-hangzhou）';
COMMENT ON COLUMN site_configs.storage_config.s3_bucket IS 'S3存储桶名称';
COMMENT ON COLUMN site_configs.storage_config.s3_path IS 'S3存储：存储桶内的路径前缀（如：avatars/、uploads/images/）';
COMMENT ON COLUMN site_configs.storage_config.s3_access_key IS 'S3访问密钥ID（AWS Access Key ID）';
COMMENT ON COLUMN site_configs.storage_config.s3_secret_key IS 'S3访问密钥Secret（AWS Secret Access Key）';
COMMENT ON COLUMN site_configs.storage_config.s3_use_path_style IS '是否使用路径样式访问（某些S3兼容服务需要）';
COMMENT ON COLUMN site_configs.storage_config.s3_url_prefix IS 'S3访问URL前缀（如：https://bucket.s3.amazonaws.com）';
COMMENT ON COLUMN site_configs.storage_config.s3_auto_create_path IS 'S3存储：如果路径不存在是否自动创建';

-- 通用配置字段
COMMENT ON COLUMN site_configs.storage_config.max_file_size IS '最大文件大小（字节），默认5MB';
COMMENT ON COLUMN site_configs.storage_config.allowed_extensions IS '允许的文件扩展名（逗号分隔，如：jpg,png,gif,webp）';

COMMENT ON COLUMN site_configs.storage_config.created_at IS '创建时间（北京时间）';
COMMENT ON COLUMN site_configs.storage_config.updated_at IS '更新时间（北京时间）';

-- 插入默认配置
-- 1. 头像存储（正式）
INSERT INTO site_configs.storage_config (
    config_name,
    usage_type,
    storage_type, 
    enabled, 
    local_path, 
    local_url_prefix,
    max_file_size,
    allowed_extensions
) 
VALUES (
    '头像存储',
    'avatar',
    'local', 
    true, 
    '/var/www/uploads/avatars', 
    'https://example.com/uploads/avatars',
    5242880,
    'jpg,jpeg,png,gif,webp'
)
ON CONFLICT (usage_type) DO NOTHING;

-- 2. 待审核头像存储
INSERT INTO site_configs.storage_config (
    config_name,
    usage_type,
    storage_type, 
    enabled, 
    local_path, 
    local_url_prefix,
    max_file_size,
    allowed_extensions
) 
VALUES (
    '待审核头像存储',
    'avatar_pending',
    'local', 
    true, 
    '/var/www/uploads/avatars_pending', 
    'https://example.com/uploads/avatars_pending',
    5242880,
    'jpg,jpeg,png,gif,webp'
)
ON CONFLICT (usage_type) DO NOTHING;

-- 3. 文档存储
INSERT INTO site_configs.storage_config (
    config_name,
    usage_type,
    storage_type, 
    enabled, 
    local_path, 
    local_url_prefix,
    max_file_size,
    allowed_extensions
) 
VALUES (
    '文档存储',
    'document',
    'local', 
    true, 
    '/var/www/uploads/documents', 
    'https://example.com/uploads/documents',
    10485760,
    'pdf,doc,docx,xls,xlsx,txt'
)
ON CONFLICT (usage_type) DO NOTHING;

-- 4. 临时文件存储
INSERT INTO site_configs.storage_config (
    config_name,
    usage_type,
    storage_type, 
    enabled, 
    local_path, 
    local_url_prefix,
    max_file_size,
    allowed_extensions
) 
VALUES (
    '临时文件存储',
    'temp',
    'local', 
    true, 
    '/var/www/uploads/temp', 
    'https://example.com/uploads/temp',
    20971520,
    'jpg,jpeg,png,gif,webp,pdf,doc,docx,zip'
)
ON CONFLICT (usage_type) DO NOTHING;

-- 创建更新时间触发器函数
CREATE OR REPLACE FUNCTION site_configs.update_storage_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 创建触发器
DROP TRIGGER IF EXISTS trigger_update_storage_config_updated_at ON site_configs.storage_config;
CREATE TRIGGER trigger_update_storage_config_updated_at
    BEFORE UPDATE ON site_configs.storage_config
    FOR EACH ROW
    EXECUTE FUNCTION site_configs.update_storage_config_updated_at();

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_storage_config_usage_type ON site_configs.storage_config(usage_type);
CREATE INDEX IF NOT EXISTS idx_storage_config_storage_type ON site_configs.storage_config(storage_type);
CREATE INDEX IF NOT EXISTS idx_storage_config_enabled ON site_configs.storage_config(enabled);
