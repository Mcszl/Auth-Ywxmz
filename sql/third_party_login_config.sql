-- 第三方登录配置表
-- 用于管理各种第三方登录平台的配置信息

-- 创建 schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS auth;

-- 创建第三方登录配置表
CREATE TABLE IF NOT EXISTS auth.third_party_login_config (
    id SERIAL PRIMARY KEY,
    config_name VARCHAR(100) NOT NULL,
    platform VARCHAR(50) NOT NULL,
    app_id VARCHAR(255) NOT NULL,
    app_secret TEXT NOT NULL,
    callback_url VARCHAR(500) NOT NULL,
    scopes TEXT,
    status SMALLINT DEFAULT 1,
    is_enabled BOOLEAN DEFAULT true,
    priority INTEGER DEFAULT 100,
    extra_config JSONB,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(platform, app_id)
);

-- 创建索引
CREATE INDEX idx_third_party_platform ON auth.third_party_login_config(platform);
CREATE INDEX idx_third_party_status ON auth.third_party_login_config(status);
CREATE INDEX idx_third_party_enabled ON auth.third_party_login_config(is_enabled);
CREATE INDEX idx_third_party_priority ON auth.third_party_login_config(priority);

-- 添加注释
COMMENT ON TABLE auth.third_party_login_config IS '第三方登录配置表';
COMMENT ON COLUMN auth.third_party_login_config.id IS '主键ID';
COMMENT ON COLUMN auth.third_party_login_config.config_name IS '配置名称';
COMMENT ON COLUMN auth.third_party_login_config.platform IS '第三方平台标识';
COMMENT ON COLUMN auth.third_party_login_config.app_id IS '应用ID';
COMMENT ON COLUMN auth.third_party_login_config.app_secret IS '应用密钥（加密存储）';
COMMENT ON COLUMN auth.third_party_login_config.callback_url IS '授权回调地址';
COMMENT ON COLUMN auth.third_party_login_config.scopes IS '授权范围';
COMMENT ON COLUMN auth.third_party_login_config.status IS '配置状态';
COMMENT ON COLUMN auth.third_party_login_config.is_enabled IS '是否启用';
COMMENT ON COLUMN auth.third_party_login_config.priority IS '优先级';
COMMENT ON COLUMN auth.third_party_login_config.extra_config IS '额外配置';
COMMENT ON COLUMN auth.third_party_login_config.description IS '配置说明';
COMMENT ON COLUMN auth.third_party_login_config.created_at IS '创建时间';
COMMENT ON COLUMN auth.third_party_login_config.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_third_party_login_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_third_party_login_config_updated_at
    BEFORE UPDATE ON auth.third_party_login_config
    FOR EACH ROW
    EXECUTE FUNCTION update_third_party_login_config_updated_at();

-- 插入示例数据（可选）
-- 微信登录配置示例
INSERT INTO auth.third_party_login_config (
    config_name, 
    platform, 
    app_id, 
    app_secret, 
    callback_url, 
    scopes,
    status,
    is_enabled,
    priority,
    extra_config,
    description
) VALUES (
    '微信开放平台登录',
    'wechat',
    'wx1234567890abcdef',
    'your_app_secret_here',
    'https://your-domain.com/auth/callback/wechat',
    '["snsapi_login"]',
    1,
    false,
    100,
    '{"auth_url": "https://open.weixin.qq.com/connect/qrconnect", "token_url": "https://api.weixin.qq.com/sns/oauth2/access_token", "userinfo_url": "https://api.weixin.qq.com/sns/userinfo"}',
    '微信开放平台网站应用登录配置'
) ON CONFLICT (platform, app_id) DO NOTHING;

-- GitHub登录配置示例
INSERT INTO auth.third_party_login_config (
    config_name, 
    platform, 
    app_id, 
    app_secret, 
    callback_url, 
    scopes,
    status,
    is_enabled,
    priority,
    extra_config,
    description
) VALUES (
    'GitHub OAuth登录',
    'github',
    'your_github_client_id',
    'your_github_client_secret',
    'https://your-domain.com/auth/callback/github',
    '["user:email"]',
    1,
    false,
    100,
    '{"auth_url": "https://github.com/login/oauth/authorize", "token_url": "https://github.com/login/oauth/access_token", "userinfo_url": "https://api.github.com/user"}',
    'GitHub OAuth应用登录配置'
) ON CONFLICT (platform, app_id) DO NOTHING;
