-- 微博用户信息表
-- 用于存储微博登录用户的信息和绑定关系

-- 创建 schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS auth;

-- 删除已存在的表（如果存在）
DROP TABLE IF EXISTS auth.weibo_user_info CASCADE;

-- 创建微博用户信息表
CREATE TABLE auth.weibo_user_info (
    id SERIAL PRIMARY KEY,
    uid VARCHAR(50) NOT NULL UNIQUE,
    user_uuid INTEGER,
    weibo_nickname VARCHAR(100),
    weibo_avatar VARCHAR(500),
    weibo_gender VARCHAR(10),
    weibo_location VARCHAR(100),
    weibo_description TEXT,
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP,
    bind_status SMALLINT DEFAULT 0,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 创建索引
CREATE INDEX idx_weibo_user_uid ON auth.weibo_user_info(uid);
CREATE INDEX idx_weibo_user_uuid ON auth.weibo_user_info(user_uuid);
CREATE INDEX idx_weibo_bind_status ON auth.weibo_user_info(bind_status);
CREATE INDEX idx_weibo_created_at ON auth.weibo_user_info(created_at);
CREATE INDEX idx_weibo_last_login ON auth.weibo_user_info(last_login_at);

-- 添加外键约束
ALTER TABLE auth.weibo_user_info 
ADD CONSTRAINT fk_weibo_user_uuid 
FOREIGN KEY (user_uuid) 
REFERENCES users.user(uuid) 
ON DELETE SET NULL;

-- 添加注释
COMMENT ON TABLE auth.weibo_user_info IS '微博用户信息表';
COMMENT ON COLUMN auth.weibo_user_info.id IS '主键ID';
COMMENT ON COLUMN auth.weibo_user_info.uid IS '微博用户ID（唯一标识）';
COMMENT ON COLUMN auth.weibo_user_info.user_uuid IS '绑定的平台用户UUID';
COMMENT ON COLUMN auth.weibo_user_info.weibo_nickname IS '微博昵称';
COMMENT ON COLUMN auth.weibo_user_info.weibo_avatar IS '微博头像URL';
COMMENT ON COLUMN auth.weibo_user_info.weibo_gender IS '性别：m-男，f-女，n-未知';
COMMENT ON COLUMN auth.weibo_user_info.weibo_location IS '所在地';
COMMENT ON COLUMN auth.weibo_user_info.weibo_description IS '个人描述';
COMMENT ON COLUMN auth.weibo_user_info.access_token IS '访问令牌';
COMMENT ON COLUMN auth.weibo_user_info.refresh_token IS '刷新令牌';
COMMENT ON COLUMN auth.weibo_user_info.token_expires_at IS '令牌过期时间';
COMMENT ON COLUMN auth.weibo_user_info.bind_status IS '绑定状态：0-未绑定，1-已绑定';
COMMENT ON COLUMN auth.weibo_user_info.last_login_at IS '最后登录时间';
COMMENT ON COLUMN auth.weibo_user_info.created_at IS '创建时间';
COMMENT ON COLUMN auth.weibo_user_info.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_weibo_user_info_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_weibo_user_info_updated_at
    BEFORE UPDATE ON auth.weibo_user_info
    FOR EACH ROW
    EXECUTE FUNCTION update_weibo_user_info_updated_at();
