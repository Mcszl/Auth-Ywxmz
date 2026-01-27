-- ============================================
-- 用户 OpenID 表
-- ============================================

-- 创建 user schema（如果不存在）
CREATE SCHEMA IF NOT EXISTS users;

-- 创建 openid 表
CREATE TABLE IF NOT EXISTS users.openid (
    id SERIAL PRIMARY KEY,
    openid VARCHAR(100) NOT NULL UNIQUE,
    user_uuid INTEGER NOT NULL,
    app_id VARCHAR(50) NOT NULL,
    tags TEXT,
    group_name VARCHAR(100),
    status SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_openid_status CHECK (status IN (0, 1)),
    CONSTRAINT uk_user_app UNIQUE (user_uuid, app_id)
);

-- 添加注释
COMMENT ON TABLE users.openid IS '用户 OpenID 表';
COMMENT ON COLUMN users.openid.id IS '主键ID';
COMMENT ON COLUMN users.openid.openid IS 'OpenID（唯一标识）';
COMMENT ON COLUMN users.openid.user_uuid IS '用户UUID';
COMMENT ON COLUMN users.openid.app_id IS '应用ID';
COMMENT ON COLUMN users.openid.tags IS '标签信息（JSON格式）';
COMMENT ON COLUMN users.openid.group_name IS '分组名称';
COMMENT ON COLUMN users.openid.status IS '状态：0-禁用，1-正常';
COMMENT ON COLUMN users.openid.created_at IS '创建时间';
COMMENT ON COLUMN users.openid.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX idx_openid_openid ON users.openid(openid);
CREATE INDEX idx_openid_user_uuid ON users.openid(user_uuid);
CREATE INDEX idx_openid_app_id ON users.openid(app_id);
CREATE INDEX idx_openid_status ON users.openid(status);

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION users.update_openid_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_openid_updated_at ON users.openid;
CREATE TRIGGER update_openid_updated_at
    BEFORE UPDATE ON users.openid
    FOR EACH ROW
    EXECUTE FUNCTION users.update_openid_updated_at();
