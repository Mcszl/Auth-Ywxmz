-- GitHub用户信息表
-- 用于存储GitHub登录的用户信息和绑定关系

CREATE TABLE IF NOT EXISTS auth.github_user_info (
    id SERIAL PRIMARY KEY,
    github_id VARCHAR(100) NOT NULL UNIQUE,
    user_uuid INTEGER,
    github_login VARCHAR(255) NOT NULL,
    github_name VARCHAR(255),
    github_avatar VARCHAR(500),
    github_email VARCHAR(255),
    github_bio TEXT,
    access_token TEXT,
    bind_status SMALLINT DEFAULT 0,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_github_user_uuid FOREIGN KEY (user_uuid) REFERENCES users.user(uuid) ON DELETE SET NULL
);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_github_user_uuid ON auth.github_user_info(user_uuid);
CREATE INDEX IF NOT EXISTS idx_github_bind_status ON auth.github_user_info(bind_status);
CREATE INDEX IF NOT EXISTS idx_github_last_login ON auth.github_user_info(last_login_at);

-- 添加表注释
COMMENT ON TABLE auth.github_user_info IS 'GitHub用户信息表';
COMMENT ON COLUMN auth.github_user_info.id IS '主键ID';
COMMENT ON COLUMN auth.github_user_info.github_id IS 'GitHub用户ID';
COMMENT ON COLUMN auth.github_user_info.user_uuid IS '绑定的用户UUID';
COMMENT ON COLUMN auth.github_user_info.github_login IS 'GitHub登录名';
COMMENT ON COLUMN auth.github_user_info.github_name IS 'GitHub显示名称';
COMMENT ON COLUMN auth.github_user_info.github_avatar IS 'GitHub头像URL';
COMMENT ON COLUMN auth.github_user_info.github_email IS 'GitHub邮箱';
COMMENT ON COLUMN auth.github_user_info.github_bio IS 'GitHub个人简介';
COMMENT ON COLUMN auth.github_user_info.access_token IS 'GitHub访问令牌';
COMMENT ON COLUMN auth.github_user_info.bind_status IS '绑定状态：0-未绑定，1-已绑定';
COMMENT ON COLUMN auth.github_user_info.last_login_at IS '最后登录时间';
COMMENT ON COLUMN auth.github_user_info.created_at IS '创建时间';
COMMENT ON COLUMN auth.github_user_info.updated_at IS '更新时间';

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION update_github_user_info_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_github_user_info_updated_at
    BEFORE UPDATE ON auth.github_user_info
    FOR EACH ROW
    EXECUTE FUNCTION update_github_user_info_updated_at();
