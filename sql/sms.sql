-- ============================================
-- 一碗小米周授权登录平台 - 短信管理数据库脚本
-- ============================================

-- ============================================
-- 创建 SMS Schema
-- ============================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name = 'sms'
    ) THEN
        CREATE SCHEMA sms;
        RAISE NOTICE 'Schema sms 已创建';
    ELSE
        RAISE NOTICE 'Schema sms 已存在';
    END IF;
END
$$;

-- 设置搜索路径
SET search_path TO sms, public;

-- ============================================
-- 创建短信验证码记录表
-- ============================================
CREATE TABLE IF NOT EXISTS code (
    -- 主键
    id BIGSERIAL PRIMARY KEY,
    
    -- 短信ID（业务ID，用于追踪）
    sms_id VARCHAR(64) UNIQUE NOT NULL,
    
    -- 手机号
    phone VARCHAR(20) NOT NULL,
    
    -- 验证码
    code VARCHAR(10) NOT NULL,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2, 3, 4)),
    
    -- 短信用途
    purpose VARCHAR(50) NOT NULL,
    
    -- 有效期（秒）
    validity_period INTEGER NOT NULL DEFAULT 900,
    
    -- 过期时间（计算得出）
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- 上游短信ID（第三方平台返回的ID）
    upstream_sms_id VARCHAR(128),
    
    -- 发送渠道
    channel VARCHAR(50) NOT NULL,
    
    -- 签名
    signature VARCHAR(100),
    
    -- 模板ID
    template_id VARCHAR(100),
    
    -- 模板参数（JSON格式）
    template_params JSONB,
    
    -- 发送结果
    send_result TEXT,
    
    -- 发送状态码
    send_status_code VARCHAR(50),
    
    -- 核验次数
    verify_count INTEGER NOT NULL DEFAULT 0,
    
    -- 最后核验时间
    last_verify_at TIMESTAMP WITH TIME ZONE,
    
    -- 其他信息（JSON格式，用于存储扩展字段）
    extra_info JSONB,
    
    -- 客户端IP地址
    client_ip VARCHAR(50),
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE code IS '短信验证码记录表 - 存储所有发送的短信验证码信息';

-- 添加列注释
COMMENT ON COLUMN code.id IS '主键ID';
COMMENT ON COLUMN code.sms_id IS '短信ID（业务ID，唯一标识）';
COMMENT ON COLUMN code.phone IS '手机号';
COMMENT ON COLUMN code.code IS '验证码';
COMMENT ON COLUMN code.status IS '状态：0-已使用，1-有效（默认），2-过期，3-一次核验成功，4-二次核验成功';
COMMENT ON COLUMN code.purpose IS '短信用途：register-注册，login-登录，reset_password-重置密码，bind_phone-绑定手机等';
COMMENT ON COLUMN code.validity_period IS '有效期（秒），默认900秒（15分钟）';
COMMENT ON COLUMN code.expires_at IS '过期时间';
COMMENT ON COLUMN code.upstream_sms_id IS '上游短信ID（第三方平台返回的ID）';
COMMENT ON COLUMN code.channel IS '发送渠道：aliyun-阿里云，tencent-腾讯云，321cn-321.com.cn等';
COMMENT ON COLUMN code.signature IS '短信签名';
COMMENT ON COLUMN code.template_id IS '短信模板ID';
COMMENT ON COLUMN code.template_params IS '模板参数（JSON格式）';
COMMENT ON COLUMN code.send_result IS '发送结果描述';
COMMENT ON COLUMN code.send_status_code IS '发送状态码';
COMMENT ON COLUMN code.verify_count IS '核验次数';
COMMENT ON COLUMN code.last_verify_at IS '最后核验时间';
COMMENT ON COLUMN code.extra_info IS '其他信息（JSON格式，用于存储扩展字段）';
COMMENT ON COLUMN code.client_ip IS '客户端IP地址';
COMMENT ON COLUMN code.created_at IS '创建时间';
COMMENT ON COLUMN code.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_code_sms_id ON code(sms_id);
CREATE INDEX IF NOT EXISTS idx_code_phone ON code(phone);
CREATE INDEX IF NOT EXISTS idx_code_status ON code(status);
CREATE INDEX IF NOT EXISTS idx_code_purpose ON code(purpose);
CREATE INDEX IF NOT EXISTS idx_code_channel ON code(channel);
CREATE INDEX IF NOT EXISTS idx_code_expires_at ON code(expires_at);
CREATE INDEX IF NOT EXISTS idx_code_created_at ON code(created_at);
CREATE INDEX IF NOT EXISTS idx_code_phone_purpose ON code(phone, purpose);
CREATE INDEX IF NOT EXISTS idx_code_phone_status ON code(phone, status);
CREATE INDEX IF NOT EXISTS idx_code_client_ip ON code(client_ip);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_code_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_code_updated_at ON code;
CREATE TRIGGER update_code_updated_at
    BEFORE UPDATE ON code
    FOR EACH ROW
    EXECUTE FUNCTION update_code_updated_at();

-- ============================================
-- 创建自动过期触发器
-- ============================================
CREATE OR REPLACE FUNCTION check_code_expiration()
RETURNS TRIGGER AS $$
BEGIN
    -- 如果当前时间超过过期时间，自动设置状态为过期
    IF NEW.status = 1 AND CURRENT_TIMESTAMP > NEW.expires_at THEN
        NEW.status = 2;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS check_code_expiration_trigger ON code;
CREATE TRIGGER check_code_expiration_trigger
    BEFORE INSERT OR UPDATE ON code
    FOR EACH ROW
    EXECUTE FUNCTION check_code_expiration();

-- ============================================
-- 切换到 site_configs Schema
-- ============================================
SET search_path TO site_configs, public;

-- ============================================
-- 创建短信配置表
-- ============================================
CREATE TABLE IF NOT EXISTS sms_config (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 配置名称
    config_name VARCHAR(100) NOT NULL,
    
    -- 短信用途
    purpose VARCHAR(50) NOT NULL,
    
    -- 发送渠道
    channel VARCHAR(50) NOT NULL,
    
    -- 签名
    signature VARCHAR(100) NOT NULL,
    
    -- 模板ID
    template_id VARCHAR(100) NOT NULL,
    
    -- 模板内容（用于参考）
    template_content TEXT,
    
    -- 密钥信息（JSON格式，加密存储）
    credentials JSONB NOT NULL,
    
    -- 渠道配置（JSON格式，存储额外配置）
    channel_config JSONB,
    
    -- 是否启用
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2)),
    
    -- 优先级（数字越小优先级越高）
    priority INTEGER NOT NULL DEFAULT 100,
    
    -- 日发送限制
    daily_limit INTEGER DEFAULT 1000,
    
    -- 今日已发送数量
    daily_sent_count INTEGER NOT NULL DEFAULT 0,
    
    -- 最后重置日期
    last_reset_date DATE,
    
    -- 备注
    description TEXT,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- 唯一约束
    UNIQUE(purpose, channel, signature, template_id)
);

-- 添加表注释
COMMENT ON TABLE sms_config IS '短信配置表 - 存储短信发送的配置信息';

-- 添加列注释
COMMENT ON COLUMN sms_config.id IS '主键ID';
COMMENT ON COLUMN sms_config.config_name IS '配置名称';
COMMENT ON COLUMN sms_config.purpose IS '短信用途：register-注册，login-登录，reset_password-重置密码等';
COMMENT ON COLUMN sms_config.channel IS '发送渠道：aliyun-阿里云，tencent-腾讯云，321cn-321.com.cn等';
COMMENT ON COLUMN sms_config.signature IS '短信签名';
COMMENT ON COLUMN sms_config.template_id IS '短信模板ID';
COMMENT ON COLUMN sms_config.template_content IS '模板内容（用于参考）';
COMMENT ON COLUMN sms_config.credentials IS '密钥信息（JSON格式）：{"access_key":"xxx","secret_key":"xxx"}';
COMMENT ON COLUMN sms_config.channel_config IS '渠道配置（JSON格式）：额外的渠道特定配置';
COMMENT ON COLUMN sms_config.is_enabled IS '是否启用';
COMMENT ON COLUMN sms_config.status IS '状态：0-禁用，1-正常（默认），2-维护中';
COMMENT ON COLUMN sms_config.priority IS '优先级（数字越小优先级越高）';
COMMENT ON COLUMN sms_config.daily_limit IS '日发送限制';
COMMENT ON COLUMN sms_config.daily_sent_count IS '今日已发送数量';
COMMENT ON COLUMN sms_config.last_reset_date IS '最后重置日期';
COMMENT ON COLUMN sms_config.description IS '备注说明';
COMMENT ON COLUMN sms_config.created_at IS '创建时间';
COMMENT ON COLUMN sms_config.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_sms_config_purpose ON sms_config(purpose);
CREATE INDEX IF NOT EXISTS idx_sms_config_channel ON sms_config(channel);
CREATE INDEX IF NOT EXISTS idx_sms_config_is_enabled ON sms_config(is_enabled);
CREATE INDEX IF NOT EXISTS idx_sms_config_status ON sms_config(status);
CREATE INDEX IF NOT EXISTS idx_sms_config_priority ON sms_config(priority);
CREATE INDEX IF NOT EXISTS idx_sms_config_purpose_channel ON sms_config(purpose, channel);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_sms_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_sms_config_updated_at ON sms_config;
CREATE TRIGGER update_sms_config_updated_at
    BEFORE UPDATE ON sms_config
    FOR EACH ROW
    EXECUTE FUNCTION update_sms_config_updated_at();

-- ============================================
-- 创建每日计数重置触发器
-- ============================================
CREATE OR REPLACE FUNCTION reset_daily_count()
RETURNS TRIGGER AS $$
BEGIN
    -- 如果日期变化，重置计数
    IF NEW.last_reset_date IS NULL OR NEW.last_reset_date < CURRENT_DATE THEN
        NEW.daily_sent_count = 0;
        NEW.last_reset_date = CURRENT_DATE;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS reset_daily_count_trigger ON sms_config;
CREATE TRIGGER reset_daily_count_trigger
    BEFORE INSERT OR UPDATE ON sms_config
    FOR EACH ROW
    EXECUTE FUNCTION reset_daily_count();

-- ============================================
-- 插入默认短信配置数据
-- ============================================

-- 阿里云短信配置示例
INSERT INTO sms_config (
    config_name,
    purpose,
    channel,
    signature,
    template_id,
    template_content,
    credentials,
    channel_config,
    is_enabled,
    status,
    priority,
    daily_limit,
    description
) VALUES (
    '阿里云-注册验证码',
    'register',
    'aliyun',
    '一碗小米周',
    'SMS_123456789',
    '您的注册验证码是：${code}，有效期${minutes}分钟。',
    '{"access_key":"YOUR_ACCESS_KEY","secret_key":"YOUR_SECRET_KEY"}'::jsonb,
    '{"region":"cn-hangzhou","endpoint":"dysmsapi.aliyuncs.com"}'::jsonb,
    FALSE,
    1,
    10,
    1000,
    '阿里云短信服务 - 用于用户注册'
) ON CONFLICT DO NOTHING;

-- 腾讯云短信配置示例
INSERT INTO sms_config (
    config_name,
    purpose,
    channel,
    signature,
    template_id,
    template_content,
    credentials,
    channel_config,
    is_enabled,
    status,
    priority,
    daily_limit,
    description
) VALUES (
    '腾讯云-登录验证码',
    'login',
    'tencent',
    '一碗小米周',
    '987654',
    '您的登录验证码是{1}，有效期{2}分钟。',
    '{"secret_id":"YOUR_SECRET_ID","secret_key":"YOUR_SECRET_KEY","sdk_app_id":"YOUR_SDK_APP_ID"}'::jsonb,
    '{"region":"ap-guangzhou"}'::jsonb,
    FALSE,
    1,
    20,
    1000,
    '腾讯云短信服务 - 用于用户登录'
) ON CONFLICT DO NOTHING;

-- 321.com.cn短信配置示例
INSERT INTO sms_config (
    config_name,
    purpose,
    channel,
    signature,
    template_id,
    template_content,
    credentials,
    channel_config,
    is_enabled,
    status,
    priority,
    daily_limit,
    description
) VALUES (
    '321短信-重置密码',
    'reset_password',
    '321cn',
    '一碗小米周',
    'TPL_321_001',
    '您正在重置密码，验证码：#code#，有效期#minutes#分钟。',
    '{"api_key":"YOUR_API_KEY","api_secret":"YOUR_API_SECRET"}'::jsonb,
    '{"api_url":"https://api.321.com.cn/sms/send"}'::jsonb,
    FALSE,
    1,
    30,
    500,
    '321短信平台 - 用于密码重置'
) ON CONFLICT DO NOTHING;

-- ============================================
-- 查询验证
-- ============================================

-- 验证 SMS Schema 是否存在
SELECT 
    schema_name,
    schema_owner
FROM information_schema.schemata 
WHERE schema_name = 'sms';

-- 验证 code 表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'sms' 
AND table_name = 'code';

-- 查看 code 表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'sms' 
AND table_name = 'code'
ORDER BY ordinal_position;

-- 验证 sms_config 表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'site_configs' 
AND table_name = 'sms_config';

-- 查看 sms_config 表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'site_configs' 
AND table_name = 'sms_config'
ORDER BY ordinal_position;

-- 查看默认配置数据
SELECT 
    id,
    config_name,
    purpose,
    channel,
    signature,
    template_id,
    is_enabled,
    status,
    priority
FROM site_configs.sms_config
ORDER BY priority;

-- ============================================
-- 常用查询示例
-- ============================================

-- 查询有效的验证码
-- SELECT * FROM sms.code 
-- WHERE phone = '13800138000' 
-- AND purpose = 'login' 
-- AND status = 1 
-- AND expires_at > CURRENT_TIMESTAMP
-- ORDER BY created_at DESC 
-- LIMIT 1;

-- 查询指定用途的可用短信配置
-- SELECT * FROM site_configs.sms_config 
-- WHERE purpose = 'register' 
-- AND is_enabled = TRUE 
-- AND status = 1
-- ORDER BY priority ASC;

-- 统计今日发送量
-- SELECT 
--     channel,
--     purpose,
--     COUNT(*) as sent_count
-- FROM sms.code
-- WHERE DATE(created_at) = CURRENT_DATE
-- GROUP BY channel, purpose;

-- 查询过期的验证码
-- SELECT * FROM sms.code 
-- WHERE status = 1 
-- AND expires_at < CURRENT_TIMESTAMP;

