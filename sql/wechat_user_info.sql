-- ============================================
-- 微信用户信息表
-- 用于存储微信登录用户的OpenID和绑定信息
-- ============================================

-- 创建 schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS auth;

-- 创建微信用户信息表
CREATE TABLE IF NOT EXISTS auth.wechat_user_info (
    id SERIAL PRIMARY KEY,
    openid VARCHAR(100) NOT NULL UNIQUE,
    user_uuid INTEGER,
    wechat_nickname VARCHAR(100),
    wechat_avatar VARCHAR(500),
    wechat_gender SMALLINT DEFAULT 0,
    wechat_country VARCHAR(50),
    wechat_province VARCHAR(50),
    wechat_city VARCHAR(50),
    unionid VARCHAR(100),
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP,
    bind_status SMALLINT DEFAULT 0,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_uuid FOREIGN KEY (user_uuid) REFERENCES users.user(uuid) ON DELETE SET NULL
);

-- 创建索引（使用 IF NOT EXISTS 避免重复创建错误）
CREATE INDEX IF NOT EXISTS idx_wechat_user_openid ON auth.wechat_user_info(openid);
CREATE INDEX IF NOT EXISTS idx_wechat_user_uuid ON auth.wechat_user_info(user_uuid);
CREATE INDEX IF NOT EXISTS idx_wechat_user_unionid ON auth.wechat_user_info(unionid);
CREATE INDEX IF NOT EXISTS idx_wechat_user_bind_status ON auth.wechat_user_info(bind_status);
CREATE INDEX IF NOT EXISTS idx_wechat_user_created_at ON auth.wechat_user_info(created_at);

-- 添加注释
COMMENT ON TABLE auth.wechat_user_info IS '微信用户信息表';
COMMENT ON COLUMN auth.wechat_user_info.id IS '主键ID';
COMMENT ON COLUMN auth.wechat_user_info.openid IS '微信 OpenID（唯一标识）';
COMMENT ON COLUMN auth.wechat_user_info.user_uuid IS '绑定的平台用户UUID';
COMMENT ON COLUMN auth.wechat_user_info.wechat_nickname IS '微信昵称';
COMMENT ON COLUMN auth.wechat_user_info.wechat_avatar IS '微信头像URL';
COMMENT ON COLUMN auth.wechat_user_info.wechat_gender IS '微信性别：0-未知，1-男，2-女';
COMMENT ON COLUMN auth.wechat_user_info.wechat_country IS '微信用户所在国家';
COMMENT ON COLUMN auth.wechat_user_info.wechat_province IS '微信用户所在省份';
COMMENT ON COLUMN auth.wechat_user_info.wechat_city IS '微信用户所在城市';
COMMENT ON COLUMN auth.wechat_user_info.unionid IS '微信 UnionID（用于多应用统一用户标识）';
COMMENT ON COLUMN auth.wechat_user_info.access_token IS '访问令牌';
COMMENT ON COLUMN auth.wechat_user_info.refresh_token IS '刷新令牌';
COMMENT ON COLUMN auth.wechat_user_info.token_expires_at IS '令牌过期时间';
COMMENT ON COLUMN auth.wechat_user_info.bind_status IS '绑定状态：0-未绑定，1-已绑定';
COMMENT ON COLUMN auth.wechat_user_info.last_login_at IS '最后登录时间';
COMMENT ON COLUMN auth.wechat_user_info.created_at IS '创建时间';
COMMENT ON COLUMN auth.wechat_user_info.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_wechat_user_info_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_wechat_user_info_updated_at
    BEFORE UPDATE ON auth.wechat_user_info
    FOR EACH ROW
    EXECUTE FUNCTION update_wechat_user_info_updated_at();
