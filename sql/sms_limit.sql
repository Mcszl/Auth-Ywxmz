-- ============================================
-- 短信发送频率限制配置表
-- ============================================

SET search_path TO sms, public;

-- ============================================
-- 创建发送限制配置表
-- ============================================
CREATE TABLE IF NOT EXISTS send_limit (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 限制名称
    limit_name VARCHAR(100) NOT NULL,
    
    -- 模板ID（关联到 sms_config 的 template_id）
    template_id VARCHAR(100) NOT NULL,
    
    -- 短信用途
    purpose VARCHAR(50) NOT NULL,
    
    -- 限制类型
    limit_type VARCHAR(50) NOT NULL CHECK (limit_type IN ('phone', 'ip', 'phone_template', 'ip_template', 'global')),
    
    -- 时间窗口（秒）
    time_window INTEGER NOT NULL,
    
    -- 最大次数
    max_count INTEGER NOT NULL,
    
    -- 是否启用
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 优先级（数字越小优先级越高）
    priority INTEGER NOT NULL DEFAULT 100,
    
    -- 描述
    description TEXT,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE send_limit IS '短信发送频率限制配置表';

-- 添加列注释
COMMENT ON COLUMN send_limit.id IS '主键ID';
COMMENT ON COLUMN send_limit.limit_name IS '限制名称';
COMMENT ON COLUMN send_limit.template_id IS '模板ID';
COMMENT ON COLUMN send_limit.purpose IS '短信用途';
COMMENT ON COLUMN send_limit.limit_type IS '限制类型：phone-手机号，ip-IP地址，phone_template-手机号+模板，ip_template-IP+模板，global-全局';
COMMENT ON COLUMN send_limit.time_window IS '时间窗口（秒）';
COMMENT ON COLUMN send_limit.max_count IS '时间窗口内最大发送次数';
COMMENT ON COLUMN send_limit.is_enabled IS '是否启用';
COMMENT ON COLUMN send_limit.priority IS '优先级（数字越小优先级越高）';
COMMENT ON COLUMN send_limit.description IS '描述';
COMMENT ON COLUMN send_limit.created_at IS '创建时间';
COMMENT ON COLUMN send_limit.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_send_limit_template_id ON send_limit(template_id);
CREATE INDEX IF NOT EXISTS idx_send_limit_purpose ON send_limit(purpose);
CREATE INDEX IF NOT EXISTS idx_send_limit_type ON send_limit(limit_type);
CREATE INDEX IF NOT EXISTS idx_send_limit_is_enabled ON send_limit(is_enabled);
CREATE INDEX IF NOT EXISTS idx_send_limit_priority ON send_limit(priority);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_send_limit_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_send_limit_updated_at ON send_limit;
CREATE TRIGGER update_send_limit_updated_at
    BEFORE UPDATE ON send_limit
    FOR EACH ROW
    EXECUTE FUNCTION update_send_limit_updated_at();

-- ============================================
-- 创建白名单表
-- ============================================
CREATE TABLE IF NOT EXISTS whitelist (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 手机号
    phone VARCHAR(20) UNIQUE NOT NULL,
    
    -- 原因/备注
    reason TEXT,
    
    -- 是否启用
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 过期时间（NULL表示永久）
    expires_at TIMESTAMP WITH TIME ZONE,
    
    -- 创建人
    created_by VARCHAR(100),
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE whitelist IS '短信白名单表 - 白名单中的手机号不受频率限制';

-- 添加列注释
COMMENT ON COLUMN whitelist.id IS '主键ID';
COMMENT ON COLUMN whitelist.phone IS '手机号';
COMMENT ON COLUMN whitelist.reason IS '加入白名单的原因';
COMMENT ON COLUMN whitelist.is_enabled IS '是否启用';
COMMENT ON COLUMN whitelist.expires_at IS '过期时间（NULL表示永久有效）';
COMMENT ON COLUMN whitelist.created_by IS '创建人';
COMMENT ON COLUMN whitelist.created_at IS '创建时间';
COMMENT ON COLUMN whitelist.updated_at IS '更新时间';

-- ============================================
-- 创建黑名单表
-- ============================================
CREATE TABLE IF NOT EXISTS blacklist (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 手机号
    phone VARCHAR(20) UNIQUE NOT NULL,
    
    -- 原因/备注
    reason TEXT NOT NULL,
    
    -- 是否启用
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 过期时间（NULL表示永久）
    expires_at TIMESTAMP WITH TIME ZONE,
    
    -- 创建人
    created_by VARCHAR(100),
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE blacklist IS '短信黑名单表 - 黑名单中的手机号禁止发送短信';

-- 添加列注释
COMMENT ON COLUMN blacklist.id IS '主键ID';
COMMENT ON COLUMN blacklist.phone IS '手机号';
COMMENT ON COLUMN blacklist.reason IS '加入黑名单的原因';
COMMENT ON COLUMN blacklist.is_enabled IS '是否启用';
COMMENT ON COLUMN blacklist.expires_at IS '过期时间（NULL表示永久封禁）';
COMMENT ON COLUMN blacklist.created_by IS '创建人';
COMMENT ON COLUMN blacklist.created_at IS '创建时间';
COMMENT ON COLUMN blacklist.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_whitelist_phone ON whitelist(phone);
CREATE INDEX IF NOT EXISTS idx_whitelist_is_enabled ON whitelist(is_enabled);
CREATE INDEX IF NOT EXISTS idx_whitelist_expires_at ON whitelist(expires_at);

CREATE INDEX IF NOT EXISTS idx_blacklist_phone ON blacklist(phone);
CREATE INDEX IF NOT EXISTS idx_blacklist_is_enabled ON blacklist(is_enabled);
CREATE INDEX IF NOT EXISTS idx_blacklist_expires_at ON blacklist(expires_at);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_whitelist_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_whitelist_updated_at ON whitelist;
CREATE TRIGGER update_whitelist_updated_at
    BEFORE UPDATE ON whitelist
    FOR EACH ROW
    EXECUTE FUNCTION update_whitelist_updated_at();

CREATE OR REPLACE FUNCTION update_blacklist_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_blacklist_updated_at ON blacklist;
CREATE TRIGGER update_blacklist_updated_at
    BEFORE UPDATE ON blacklist
    FOR EACH ROW
    EXECUTE FUNCTION update_blacklist_updated_at();

-- ============================================
-- 插入默认限制配置
-- ============================================

-- 手机号级别限制：60秒内最多1次
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    '手机号60秒限制',
    '*',
    '*',
    'phone',
    60,
    1,
    TRUE,
    10,
    '同一手机号60秒内最多发送1次'
) ON CONFLICT DO NOTHING;

-- 手机号级别限制：1小时内最多3次
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    '手机号1小时限制',
    '*',
    '*',
    'phone',
    3600,
    3,
    TRUE,
    20,
    '同一手机号1小时内最多发送3次'
) ON CONFLICT DO NOTHING;

-- 手机号级别限制：24小时内最多10次
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    '手机号24小时限制',
    '*',
    '*',
    'phone',
    86400,
    10,
    TRUE,
    30,
    '同一手机号24小时内最多发送10次'
) ON CONFLICT DO NOTHING;

-- IP级别限制：60秒内最多5次
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    'IP 60秒限制',
    '*',
    '*',
    'ip',
    60,
    5,
    TRUE,
    40,
    '同一IP 60秒内最多发送5次'
) ON CONFLICT DO NOTHING;

-- IP级别限制：1小时内最多20次
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    'IP 1小时限制',
    '*',
    '*',
    'ip',
    3600,
    20,
    TRUE,
    50,
    '同一IP 1小时内最多发送20次'
) ON CONFLICT DO NOTHING;

-- 手机号+模板限制：针对特定模板的限制
INSERT INTO send_limit (
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority,
    description
) VALUES (
    '注册模板手机号限制',
    'SMS_123456789',
    'register',
    'phone_template',
    60,
    1,
    TRUE,
    5,
    '注册模板：同一手机号60秒内最多1次'
) ON CONFLICT DO NOTHING;

-- ============================================
-- 查询验证
-- ============================================

-- 查看所有限制配置
SELECT 
    id,
    limit_name,
    template_id,
    purpose,
    limit_type,
    time_window,
    max_count,
    is_enabled,
    priority
FROM send_limit
ORDER BY priority ASC;

-- 查看白名单
SELECT * FROM whitelist WHERE is_enabled = TRUE;

-- 查看黑名单
SELECT * FROM blacklist WHERE is_enabled = TRUE;
