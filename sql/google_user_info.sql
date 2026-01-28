-- Google用户信息表
-- 用于存储Google登录的用户信息和绑定关系

CREATE TABLE IF NOT EXISTS auth.google_user_info (
    id SERIAL PRIMARY KEY,
    google_id VARCHAR(100) NOT NULL UNIQUE,
    user_uuid INTEGER,
    google_email VARCHAR(255) NOT NULL,
    google_verified_email BOOLEAN DEFAULT FALSE,
    google_name VARCHAR(255),
    google_avatar VARCHAR(500),
    google_given_name VARCHAR(255),
    google_family_name VARCHAR(255),
    google_locale VARCHAR(10),
    access_token TEXT,
    refresh_token TEXT,
    bind_status SMALLINT DEFAULT 0,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_google_user_uuid FOREIGN KEY (user_uuid) REFERENCES users.user(uuid) ON DELETE SET NULL
);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_google_user_uuid ON auth.google_user_info(user_uuid);
CREATE INDEX IF NOT EXISTS idx_google_email ON auth.google_user_info(google_email);
CREATE INDEX IF NOT EXISTS idx_google_bind_status ON auth.google_user_info(bind_status);
CREATE INDEX IF NOT EXISTS idx_google_last_login ON auth.google_user_info(last_login_at);

-- 添加表注释
COMMENT ON TABLE auth.google_user_info IS 'Google用户信息表';
COMMENT ON COLUMN auth.google_user_info.id IS '主键ID';
COMMENT ON COLUMN auth.google_user_info.google_id IS 'Google用户ID（sub）';
COMMENT ON COLUMN auth.google_user_info.user_uuid IS '绑定的用户UUID';
COMMENT ON COLUMN auth.google_user_info.google_email IS 'Google邮箱';
COMMENT ON COLUMN auth.google_user_info.google_verified_email IS 'Google邮箱是否已验证';
COMMENT ON COLUMN auth.google_user_info.google_name IS 'Google显示名称';
COMMENT ON COLUMN auth.google_user_info.google_avatar IS 'Google头像URL';
COMMENT ON COLUMN auth.google_user_info.google_given_name IS 'Google名字';
COMMENT ON COLUMN auth.google_user_info.google_family_name IS 'Google姓氏';
COMMENT ON COLUMN auth.google_user_info.google_locale IS 'Google语言区域';
COMMENT ON COLUMN auth.google_user_info.access_token IS 'Google访问令牌';
COMMENT ON COLUMN auth.google_user_info.refresh_token IS 'Google刷新令牌';
COMMENT ON COLUMN auth.google_user_info.bind_status IS '绑定状态：0-未绑定，1-已绑定';
COMMENT ON COLUMN auth.google_user_info.last_login_at IS '最后登录时间';
COMMENT ON COLUMN auth.google_user_info.created_at IS '创建时间';
COMMENT ON COLUMN auth.google_user_info.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_google_user_info_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_google_user_info_updated_at
    BEFORE UPDATE ON auth.google_user_info
    FOR EACH ROW
    EXECUTE FUNCTION update_google_user_info_updated_at();
