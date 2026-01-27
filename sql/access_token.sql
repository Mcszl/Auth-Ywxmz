-- ============================================
-- Access Token 表
-- ============================================

-- 创建 access_token 表
CREATE TABLE IF NOT EXISTS tokens.access_token (
    id SERIAL PRIMARY KEY,
    access_token VARCHAR(255) NOT NULL UNIQUE,
    refresh_token_id INTEGER,
    app_id VARCHAR(50) NOT NULL,
    user_uuid INTEGER NOT NULL,
    permissions TEXT,
    status SMALLINT NOT NULL DEFAULT 1,
    validity_period INTEGER NOT NULL DEFAULT 7200,
    expires_at TIMESTAMP NOT NULL,
    client_ip VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_access_token_status CHECK (status IN (0, 1, 2))
);

-- 添加注释
COMMENT ON TABLE tokens.access_token IS 'Access Token 表';
COMMENT ON COLUMN tokens.access_token.id IS '主键ID';
COMMENT ON COLUMN tokens.access_token.access_token IS 'Access Token';
COMMENT ON COLUMN tokens.access_token.refresh_token_id IS '关联的 Refresh Token ID';
COMMENT ON COLUMN tokens.access_token.app_id IS '应用ID';
COMMENT ON COLUMN tokens.access_token.user_uuid IS '用户UUID';
COMMENT ON COLUMN tokens.access_token.permissions IS '允许的权限（逗号分隔）';
COMMENT ON COLUMN tokens.access_token.status IS '状态：0-过期，1-正常，2-用户退出登录';
COMMENT ON COLUMN tokens.access_token.validity_period IS '有效期（秒），默认2小时';
COMMENT ON COLUMN tokens.access_token.expires_at IS '过期时间';
COMMENT ON COLUMN tokens.access_token.client_ip IS '客户端IP';
COMMENT ON COLUMN tokens.access_token.user_agent IS '用户代理';
COMMENT ON COLUMN tokens.access_token.created_at IS '创建时间';
COMMENT ON COLUMN tokens.access_token.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX idx_access_token_token ON tokens.access_token(access_token);
CREATE INDEX idx_access_token_app_id ON tokens.access_token(app_id);
CREATE INDEX idx_access_token_user_uuid ON tokens.access_token(user_uuid);
CREATE INDEX idx_access_token_status ON tokens.access_token(status);
CREATE INDEX idx_access_token_expires_at ON tokens.access_token(expires_at);

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION tokens.update_access_token_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_access_token_updated_at ON tokens.access_token;
CREATE TRIGGER update_access_token_updated_at
    BEFORE UPDATE ON tokens.access_token
    FOR EACH ROW
    EXECUTE FUNCTION tokens.update_access_token_updated_at();

-- ============================================
-- Refresh Token 表
-- ============================================

-- 创建 refresh_token 表
CREATE TABLE IF NOT EXISTS tokens.refresh_token (
    id SERIAL PRIMARY KEY,
    refresh_token VARCHAR(255) NOT NULL UNIQUE,
    app_id VARCHAR(50) NOT NULL,
    user_uuid INTEGER NOT NULL,
    permissions TEXT,
    status SMALLINT NOT NULL DEFAULT 1,
    validity_period INTEGER NOT NULL DEFAULT 2592000,
    expires_at TIMESTAMP NOT NULL,
    client_ip VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_refresh_token_status CHECK (status IN (0, 1, 2))
);

-- 添加注释
COMMENT ON TABLE tokens.refresh_token IS 'Refresh Token 表';
COMMENT ON COLUMN tokens.refresh_token.id IS '主键ID';
COMMENT ON COLUMN tokens.refresh_token.refresh_token IS 'Refresh Token';
COMMENT ON COLUMN tokens.refresh_token.app_id IS '应用ID';
COMMENT ON COLUMN tokens.refresh_token.user_uuid IS '用户UUID';
COMMENT ON COLUMN tokens.refresh_token.permissions IS '允许的权限（逗号分隔）';
COMMENT ON COLUMN tokens.refresh_token.status IS '状态：0-过期，1-正常，2-用户退出登录';
COMMENT ON COLUMN tokens.refresh_token.validity_period IS '有效期（秒），默认30天';
COMMENT ON COLUMN tokens.refresh_token.expires_at IS '过期时间';
COMMENT ON COLUMN tokens.refresh_token.client_ip IS '客户端IP';
COMMENT ON COLUMN tokens.refresh_token.user_agent IS '用户代理';
COMMENT ON COLUMN tokens.refresh_token.created_at IS '创建时间';
COMMENT ON COLUMN tokens.refresh_token.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX idx_refresh_token_token ON tokens.refresh_token(refresh_token);
CREATE INDEX idx_refresh_token_app_id ON tokens.refresh_token(app_id);
CREATE INDEX idx_refresh_token_user_uuid ON tokens.refresh_token(user_uuid);
CREATE INDEX idx_refresh_token_status ON tokens.refresh_token(status);
CREATE INDEX idx_refresh_token_expires_at ON tokens.refresh_token(expires_at);

-- 创建更新时间触发器
CREATE OR REPLACE FUNCTION tokens.update_refresh_token_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_refresh_token_updated_at ON tokens.refresh_token;
CREATE TRIGGER update_refresh_token_updated_at
    BEFORE UPDATE ON tokens.refresh_token
    FOR EACH ROW
    EXECUTE FUNCTION tokens.update_refresh_token_updated_at();
