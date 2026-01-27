-- ============================================
-- 一碗小米周授权登录平台 - 网站配置数据库脚本
-- ============================================

-- 检查并创建 Schema
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name = 'site_configs'
    ) THEN
        CREATE SCHEMA site_configs;
        RAISE NOTICE 'Schema site_configs 已创建';
    ELSE
        RAISE NOTICE 'Schema site_configs 已存在';
    END IF;
END
$$;

-- 设置搜索路径
SET search_path TO site_configs, public;

-- ============================================
-- 创建网站配置表
-- ============================================
CREATE TABLE IF NOT EXISTS site_config (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 网站基本信息
    site_name VARCHAR(255) NOT NULL,
    site_url VARCHAR(500) NOT NULL,
    site_protocol VARCHAR(10) NOT NULL DEFAULT 'https' CHECK (site_protocol IN ('http', 'https')),
    
    -- 应用认证信息
    app_id VARCHAR(64) UNIQUE NOT NULL,
    secret_key VARCHAR(128) NOT NULL,
    app_icon_url TEXT,
    
    -- 状态信息
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2)),
    
    -- 许可权限（存储权限代码数组）
    permissions TEXT[] DEFAULT '{}',
    
    -- 许可回调域（存储回调地址数组）
    callback_urls TEXT[] DEFAULT '{}',
    
    -- 回调匹配模式
    callback_mode VARCHAR(20) NOT NULL DEFAULT 'strict' CHECK (callback_mode IN ('strict', 'moderate', 'loose')),
    
    -- 注册配置
    enable_register BOOLEAN NOT NULL DEFAULT TRUE,
    enable_phone_register BOOLEAN NOT NULL DEFAULT TRUE,
    enable_email_register BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 登录配置
    enable_login BOOLEAN NOT NULL DEFAULT TRUE,
    enable_password_login BOOLEAN NOT NULL DEFAULT TRUE,
    enable_email_code_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_phone_code_login BOOLEAN NOT NULL DEFAULT FALSE,
    
    -- 第三方登录配置
    enable_third_party_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_qq_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_wechat_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_weibo_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_github_login BOOLEAN NOT NULL DEFAULT FALSE,
    enable_google_login BOOLEAN NOT NULL DEFAULT FALSE,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- 备注
    description TEXT
);

-- 添加表注释
COMMENT ON TABLE site_config IS '网站配置表 - 存储网站的各项配置信息';

-- 添加列注释
COMMENT ON COLUMN site_config.id IS '主键ID';
COMMENT ON COLUMN site_config.site_name IS '网站名称';
COMMENT ON COLUMN site_config.site_url IS '网站地址';
COMMENT ON COLUMN site_config.site_protocol IS '网站协议（http/https）';
COMMENT ON COLUMN site_config.app_id IS '应用ID（唯一标识）';
COMMENT ON COLUMN site_config.secret_key IS '应用密钥';
COMMENT ON COLUMN site_config.app_icon_url IS '应用图标URL';
COMMENT ON COLUMN site_config.status IS '状态：0-封禁，1-正常（默认），2-等待审核';
COMMENT ON COLUMN site_config.permissions IS '许可权限代码数组';
COMMENT ON COLUMN site_config.callback_urls IS '许可回调域数组';
COMMENT ON COLUMN site_config.callback_mode IS '回调匹配模式：strict-严格匹配，moderate-中等匹配，loose-宽松匹配';
COMMENT ON COLUMN site_config.enable_register IS '是否启用注册功能';
COMMENT ON COLUMN site_config.enable_phone_register IS '是否启用手机号注册';
COMMENT ON COLUMN site_config.enable_email_register IS '是否启用邮箱注册';
COMMENT ON COLUMN site_config.enable_login IS '是否启用登录功能';
COMMENT ON COLUMN site_config.enable_password_login IS '是否启用账号密码登录';
COMMENT ON COLUMN site_config.enable_email_code_login IS '是否启用邮箱验证码登录';
COMMENT ON COLUMN site_config.enable_phone_code_login IS '是否启用手机号验证码登录';
COMMENT ON COLUMN site_config.enable_third_party_login IS '是否启用第三方登录';
COMMENT ON COLUMN site_config.enable_qq_login IS '是否启用QQ登录';
COMMENT ON COLUMN site_config.enable_wechat_login IS '是否启用微信登录';
COMMENT ON COLUMN site_config.enable_weibo_login IS '是否启用微博登录';
COMMENT ON COLUMN site_config.enable_github_login IS '是否启用GitHub登录';
COMMENT ON COLUMN site_config.enable_google_login IS '是否启用Google登录';
COMMENT ON COLUMN site_config.created_at IS '创建时间';
COMMENT ON COLUMN site_config.updated_at IS '更新时间';
COMMENT ON COLUMN site_config.description IS '配置描述';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_site_config_site_url ON site_config(site_url);
CREATE INDEX IF NOT EXISTS idx_site_config_app_id ON site_config(app_id);
CREATE INDEX IF NOT EXISTS idx_site_config_status ON site_config(status);
CREATE INDEX IF NOT EXISTS idx_site_config_created_at ON site_config(created_at);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 为 site_config 表创建触发器
DROP TRIGGER IF EXISTS update_site_config_updated_at ON site_config;
CREATE TRIGGER update_site_config_updated_at
    BEFORE UPDATE ON site_config
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================
-- 插入默认配置数据（已注释，由安装向导动态生成）
-- ============================================
-- INSERT INTO site_config (
--     site_name,
--     site_url,
--     site_protocol,
--     app_id,
--     secret_key,
--     status,
--     permissions,
--     callback_urls,
--     callback_mode,
--     enable_register,
--     enable_phone_register,
--     enable_email_register,
--     enable_login,
--     enable_password_login,
--     enable_email_code_login,
--     enable_phone_code_login,
--     enable_third_party_login,
--     enable_qq_login,
--     enable_wechat_login,
--     enable_weibo_login,
--     enable_github_login,
--     enable_google_login,
--     description
-- ) VALUES (
--     '一碗小米周授权登录平台',
--     'https://auth.example.com',
--     'https',
--     'APP_' || LPAD(FLOOR(RANDOM() * 1000000000)::TEXT, 10, '0'),
--     MD5(RANDOM()::TEXT || CLOCK_TIMESTAMP()::TEXT),
--     1,
--     ARRAY['user.basic', 'user.email'],
--     ARRAY['https://auth.example.com/callback'],
--     'strict',
--     TRUE,
--     TRUE,
--     TRUE,
--     TRUE,
--     TRUE,
--     FALSE,
--     FALSE,
--     FALSE,
--     FALSE,
--     FALSE,
--     FALSE,
--     FALSE,
--     FALSE,
--     '默认网站配置'
-- ) ON CONFLICT DO NOTHING;

-- ============================================
-- 查询验证
-- ============================================
-- 验证 Schema 是否存在
SELECT 
    schema_name,
    schema_owner
FROM information_schema.schemata 
WHERE schema_name = 'site_configs';

-- 验证表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'site_configs' 
AND table_name = 'site_config';

-- 查看表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'site_configs' 
AND table_name = 'site_config'
ORDER BY ordinal_position;

-- 查看默认配置数据
SELECT * FROM site_configs.site_config;
