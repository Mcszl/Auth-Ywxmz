-- ============================================
-- QQ用户信息表
-- 用于存储QQ登录用户的OpenID和绑定信息
-- ============================================

-- 创建 schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS auth;

-- 创建QQ用户信息表
CREATE TABLE IF NOT EXISTS auth.qq_user_info (
    id SERIAL PRIMARY KEY,
    openid VARCHAR(100) NOT NULL UNIQUE,
    user_uuid INTEGER,
    qq_nickname VARCHAR(100),
    qq_avatar VARCHAR(500),
    qq_gender VARCHAR(10),
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

-- 创建索引
CREATE INDEX idx_qq_user_openid ON auth.qq_user_info(openid);
CREATE INDEX idx_qq_user_uuid ON auth.qq_user_info(user_uuid);
CREATE INDEX idx_qq_user_bind_status ON auth.qq_user_info(bind_status);
CREATE INDEX idx_qq_user_created_at ON auth.qq_user_info(created_at);

-- 添加注释
COMMENT ON TABLE auth.qq_user_info IS 'QQ用户信息表';
COMMENT ON COLUMN auth.qq_user_info.id IS '主键ID';
COMMENT ON COLUMN auth.qq_user_info.openid IS 'QQ OpenID（唯一标识）';
COMMENT ON COLUMN auth.qq_user_info.user_uuid IS '绑定的平台用户UUID';
COMMENT ON COLUMN auth.qq_user_info.qq_nickname IS 'QQ昵称';
COMMENT ON COLUMN auth.qq_user_info.qq_avatar IS 'QQ头像URL';
COMMENT ON COLUMN auth.qq_user_info.qq_gender IS 'QQ性别';
COMMENT ON COLUMN auth.qq_user_info.unionid IS 'QQ UnionID（可选）';
COMMENT ON COLUMN auth.qq_user_info.access_token IS '访问令牌';
COMMENT ON COLUMN auth.qq_user_info.refresh_token IS '刷新令牌';
COMMENT ON COLUMN auth.qq_user_info.token_expires_at IS '令牌过期时间';
COMMENT ON COLUMN auth.qq_user_info.bind_status IS '绑定状态：0-未绑定，1-已绑定';
COMMENT ON COLUMN auth.qq_user_info.last_login_at IS '最后登录时间';
COMMENT ON COLUMN auth.qq_user_info.created_at IS '创建时间';
COMMENT ON COLUMN auth.qq_user_info.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_qq_user_info_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_qq_user_info_updated_at
    BEFORE UPDATE ON auth.qq_user_info
    FOR EACH ROW
    EXECUTE FUNCTION update_qq_user_info_updated_at();
