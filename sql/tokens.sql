-- ============================================
-- 一碗小米周授权登录平台 - Token 数据库脚本
-- ============================================

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- ============================================
-- 创建 tokens Schema
-- ============================================
CREATE SCHEMA IF NOT EXISTS tokens;

-- 设置搜索路径
SET search_path TO tokens, public;

-- ============================================
-- 创建登录 Token 表
-- ============================================
CREATE TABLE IF NOT EXISTS login_token (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- Token 信息
    token VARCHAR(255) UNIQUE NOT NULL,
    
    -- 用户信息
    user_id INTEGER NOT NULL,
    user_uuid INTEGER NOT NULL,
    username VARCHAR(50) NOT NULL,
    
    -- 应用信息
    app_id VARCHAR(100) NOT NULL,
    
    -- Token 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2, 3)),
    
    -- 登录信息
    login_method VARCHAR(50) NOT NULL,
    login_ip VARCHAR(100),
    login_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- 过期信息
    validity_period INTEGER NOT NULL DEFAULT 900,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- 回调信息
    callback_url TEXT NOT NULL,
    permissions TEXT,
    
    -- 其他信息
    extra_info JSONB,
    
    -- 使用信息
    used_at TIMESTAMP WITH TIME ZONE,
    used_ip VARCHAR(100),
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE login_token IS '登录Token表 - 存储用户登录后生成的临时Token';

-- 添加列注释
COMMENT ON COLUMN login_token.id IS '主键ID';
COMMENT ON COLUMN login_token.token IS 'Token字符串（唯一）';
COMMENT ON COLUMN login_token.user_id IS '用户ID';
COMMENT ON COLUMN login_token.user_uuid IS '用户UUID';
COMMENT ON COLUMN login_token.username IS '用户名';
COMMENT ON COLUMN login_token.app_id IS '应用ID';
COMMENT ON COLUMN login_token.status IS 'Token状态：1-正常，0-已换取access_token，2-过期，3-强制关闭';
COMMENT ON COLUMN login_token.login_method IS '登录方式：password-密码登录，sms-短信验证码，email-邮箱验证码';
COMMENT ON COLUMN login_token.login_ip IS '登录IP地址';
COMMENT ON COLUMN login_token.login_time IS '登录时间';
COMMENT ON COLUMN login_token.validity_period IS '有效期（秒）';
COMMENT ON COLUMN login_token.expires_at IS '过期时间';
COMMENT ON COLUMN login_token.callback_url IS '回调地址';
COMMENT ON COLUMN login_token.permissions IS '所需权限';
COMMENT ON COLUMN login_token.extra_info IS '其他信息（JSON格式）';
COMMENT ON COLUMN login_token.used_at IS '使用时间（换取access_token的时间）';
COMMENT ON COLUMN login_token.used_ip IS '使用IP（换取access_token的IP）';
COMMENT ON COLUMN login_token.created_at IS '创建时间';
COMMENT ON COLUMN login_token.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_login_token_token ON login_token(token);
CREATE INDEX IF NOT EXISTS idx_login_token_user_id ON login_token(user_id);
CREATE INDEX IF NOT EXISTS idx_login_token_user_uuid ON login_token(user_uuid);
CREATE INDEX IF NOT EXISTS idx_login_token_app_id ON login_token(app_id);
CREATE INDEX IF NOT EXISTS idx_login_token_status ON login_token(status);
CREATE INDEX IF NOT EXISTS idx_login_token_expires_at ON login_token(expires_at);
CREATE INDEX IF NOT EXISTS idx_login_token_login_time ON login_token(login_time);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_login_token_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_login_token_updated_at ON login_token;
CREATE TRIGGER update_login_token_updated_at
    BEFORE UPDATE ON login_token
    FOR EACH ROW
    EXECUTE FUNCTION update_login_token_updated_at();

-- ============================================
-- 创建自动过期触发器
-- ============================================
CREATE OR REPLACE FUNCTION check_login_token_expiry()
RETURNS TRIGGER AS $$
BEGIN
    -- 如果Token已过期且状态仍为正常，自动标记为过期
    IF NEW.expires_at < CURRENT_TIMESTAMP AND NEW.status = 1 THEN
        NEW.status = 2;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS check_login_token_expiry_trigger ON login_token;
CREATE TRIGGER check_login_token_expiry_trigger
    BEFORE INSERT OR UPDATE ON login_token
    FOR EACH ROW
    EXECUTE FUNCTION check_login_token_expiry();

-- ============================================
-- 查询验证
-- ============================================

-- 验证 tokens Schema 是否创建成功
SELECT 
    schema_name
FROM information_schema.schemata 
WHERE schema_name = 'tokens';

-- 查看 login_token 表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'tokens' 
AND table_name = 'login_token'
ORDER BY ordinal_position;

-- 完成提示
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Tokens Schema 和 login_token 表创建完成！';
    RAISE NOTICE '========================================';
END $$;
