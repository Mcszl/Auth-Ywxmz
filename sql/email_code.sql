-- ============================================
-- 一碗小米周授权登录平台 - 邮箱验证码数据库脚本
-- ============================================

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- ============================================
-- 创建 EMAIL Schema
-- ============================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name = 'email'
    ) THEN
        CREATE SCHEMA email;
        RAISE NOTICE 'Schema email 已创建';
    ELSE
        RAISE NOTICE 'Schema email 已存在';
    END IF;
END
$$;

-- 设置搜索路径
SET search_path TO email, public;

-- ============================================
-- 创建邮箱验证码记录表
-- ============================================
CREATE TABLE IF NOT EXISTS code (
    -- 主键
    id BIGSERIAL PRIMARY KEY,
    
    -- 验证码ID（业务ID，用于追踪）
    code_id VARCHAR(64) UNIQUE NOT NULL,
    
    -- 邮箱地址
    email VARCHAR(255) NOT NULL,
    
    -- 验证码
    code VARCHAR(10) NOT NULL,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2, 3, 4)),
    
    -- 验证码用途
    purpose VARCHAR(50) NOT NULL,
    
    -- 有效期（秒）
    validity_period INTEGER NOT NULL DEFAULT 900,
    
    -- 过期时间（计算得出）
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- 发送渠道
    channel VARCHAR(50) NOT NULL DEFAULT 'system',
    
    -- 模板ID
    template_id VARCHAR(100),
    
    -- 发送结果
    send_result TEXT,
    
    -- 核验次数
    verify_count INTEGER NOT NULL DEFAULT 0,
    
    -- 最后核验时间
    last_verify_at TIMESTAMP WITH TIME ZONE,
    
    -- 客户端IP地址
    client_ip VARCHAR(50),
    
    -- 其他信息（JSON格式，用于存储扩展字段）
    extra_info JSONB,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE code IS '邮箱验证码记录表 - 存储所有发送的邮箱验证码信息';

-- 添加列注释
COMMENT ON COLUMN code.id IS '主键ID';
COMMENT ON COLUMN code.code_id IS '验证码ID（业务ID，唯一标识）';
COMMENT ON COLUMN code.email IS '邮箱地址';
COMMENT ON COLUMN code.code IS '验证码';
COMMENT ON COLUMN code.status IS '状态：0-已使用，1-有效（默认），2-过期，3-一次核验成功，4-二次核验成功';
COMMENT ON COLUMN code.purpose IS '验证码用途：register-注册，login-登录，reset_password-重置密码，bind_email-绑定邮箱等';
COMMENT ON COLUMN code.validity_period IS '有效期（秒），默认900秒（15分钟）';
COMMENT ON COLUMN code.expires_at IS '过期时间';
COMMENT ON COLUMN code.channel IS '发送渠道：system-系统，smtp-SMTP等';
COMMENT ON COLUMN code.template_id IS '邮件模板ID';
COMMENT ON COLUMN code.send_result IS '发送结果描述';
COMMENT ON COLUMN code.verify_count IS '核验次数';
COMMENT ON COLUMN code.last_verify_at IS '最后核验时间';
COMMENT ON COLUMN code.client_ip IS '客户端IP地址';
COMMENT ON COLUMN code.extra_info IS '其他信息（JSON格式，用于存储扩展字段）';
COMMENT ON COLUMN code.created_at IS '创建时间';
COMMENT ON COLUMN code.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_email_code_code_id ON code(code_id);
CREATE INDEX IF NOT EXISTS idx_email_code_email ON code(email);
CREATE INDEX IF NOT EXISTS idx_email_code_status ON code(status);
CREATE INDEX IF NOT EXISTS idx_email_code_purpose ON code(purpose);
CREATE INDEX IF NOT EXISTS idx_email_code_expires_at ON code(expires_at);
CREATE INDEX IF NOT EXISTS idx_email_code_created_at ON code(created_at);
CREATE INDEX IF NOT EXISTS idx_email_code_email_purpose ON code(email, purpose);
CREATE INDEX IF NOT EXISTS idx_email_code_email_status ON code(email, status);
CREATE INDEX IF NOT EXISTS idx_email_code_client_ip ON code(client_ip);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_email_code_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_email_code_updated_at ON code;
CREATE TRIGGER update_email_code_updated_at
    BEFORE UPDATE ON code
    FOR EACH ROW
    EXECUTE FUNCTION update_email_code_updated_at();

-- ============================================
-- 创建自动过期触发器
-- ============================================
CREATE OR REPLACE FUNCTION check_email_code_expiration()
RETURNS TRIGGER AS $$
BEGIN
    -- 如果当前时间超过过期时间，自动设置状态为过期
    IF NEW.status = 1 AND CURRENT_TIMESTAMP > NEW.expires_at THEN
        NEW.status = 2;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS check_email_code_expiration_trigger ON code;
CREATE TRIGGER check_email_code_expiration_trigger
    BEFORE INSERT OR UPDATE ON code
    FOR EACH ROW
    EXECUTE FUNCTION check_email_code_expiration();

-- ============================================
-- 查询验证
-- ============================================

-- 验证 EMAIL Schema 是否存在
SELECT 
    schema_name,
    schema_owner
FROM information_schema.schemata 
WHERE schema_name = 'email';

-- 验证 code 表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'email' 
AND table_name = 'code';

-- 查看 code 表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'email' 
AND table_name = 'code'
ORDER BY ordinal_position;

-- 完成提示
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE '邮箱验证码表创建完成！';
    RAISE NOTICE '========================================';
END $$;
