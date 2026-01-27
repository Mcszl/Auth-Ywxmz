-- 昵称审核系统
-- 用于管理用户昵称审核功能

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- ============================================
-- 1. 在 site_configs Schema 中创建昵称审核配置表
-- ============================================

CREATE TABLE IF NOT EXISTS site_configs.nickname_check_config (
    id SERIAL PRIMARY KEY,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,              -- 是否启用昵称审核
    auto_approve BOOLEAN NOT NULL DEFAULT FALSE,           -- 是否自动通过审核
    check_sensitive_words BOOLEAN NOT NULL DEFAULT TRUE,   -- 是否检查敏感词
    max_length INTEGER NOT NULL DEFAULT 20,                -- 昵称最大长度
    min_length INTEGER NOT NULL DEFAULT 2,                 -- 昵称最小长度
    allow_special_chars BOOLEAN NOT NULL DEFAULT FALSE,    -- 是否允许特殊字符
    guest_prefix VARCHAR(20) DEFAULT '游客-',             -- 游客昵称前缀
    description TEXT,                                      -- 配置说明
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'system',
    updated_by VARCHAR(100) DEFAULT 'system'
);

-- 添加表注释
COMMENT ON TABLE site_configs.nickname_check_config IS '昵称审核配置表';
COMMENT ON COLUMN site_configs.nickname_check_config.id IS '配置ID';
COMMENT ON COLUMN site_configs.nickname_check_config.is_enabled IS '是否启用昵称审核';
COMMENT ON COLUMN site_configs.nickname_check_config.auto_approve IS '是否自动通过审核';
COMMENT ON COLUMN site_configs.nickname_check_config.check_sensitive_words IS '是否检查敏感词';
COMMENT ON COLUMN site_configs.nickname_check_config.max_length IS '昵称最大长度';
COMMENT ON COLUMN site_configs.nickname_check_config.min_length IS '昵称最小长度';
COMMENT ON COLUMN site_configs.nickname_check_config.allow_special_chars IS '是否允许特殊字符';
COMMENT ON COLUMN site_configs.nickname_check_config.guest_prefix IS '游客昵称前缀';
COMMENT ON COLUMN site_configs.nickname_check_config.description IS '配置说明';
COMMENT ON COLUMN site_configs.nickname_check_config.created_at IS '创建时间';
COMMENT ON COLUMN site_configs.nickname_check_config.updated_at IS '更新时间';
COMMENT ON COLUMN site_configs.nickname_check_config.created_by IS '创建者';
COMMENT ON COLUMN site_configs.nickname_check_config.updated_by IS '更新者';

-- 插入默认配置
INSERT INTO site_configs.nickname_check_config (
    is_enabled, auto_approve, check_sensitive_words, 
    max_length, min_length, allow_special_chars, 
    guest_prefix, description
) VALUES (
    TRUE, FALSE, TRUE,
    20, 2, FALSE,
    '游客-', '默认昵称审核配置'
) ON CONFLICT DO NOTHING;

-- ============================================
-- 2. 创建 checks Schema
-- ============================================

CREATE SCHEMA IF NOT EXISTS checks;

-- 设置 Schema 注释
COMMENT ON SCHEMA checks IS '审核相关表';

-- ============================================
-- 3. 创建昵称审核记录表
-- ============================================

CREATE TABLE IF NOT EXISTS checks.nickname_check (
    id BIGSERIAL PRIMARY KEY,
    user_uuid BIGINT NOT NULL,                             -- 用户UUID
    old_nickname VARCHAR(50),                              -- 原昵称
    new_nickname VARCHAR(50) NOT NULL,                     -- 新昵称
    apply_reason TEXT,                                     -- 申请理由
    apply_time TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,  -- 申请时间
    apply_ip VARCHAR(50),                                  -- 申请IP
    status INTEGER NOT NULL DEFAULT 0,                     -- 审核状态：0-待审核，1-通过，2-拒绝，3-撤销
    review_time TIMESTAMP WITH TIME ZONE,                  -- 审核时间
    reviewer_uuid BIGINT,                                  -- 审核员UUID
    reviewer_name VARCHAR(50),                             -- 审核员名称
    review_comment TEXT,                                   -- 审核意见
    reject_reason TEXT,                                    -- 拒绝原因
    sensitive_words JSONB,                                 -- 检测到的敏感词
    auto_reviewed BOOLEAN DEFAULT FALSE,                   -- 是否自动审核
    apply_type VARCHAR(20) DEFAULT 'manual',               -- 申请类型：register-注册，manual-手动修改
    extra_info JSONB,                                      -- 额外信息
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE checks.nickname_check IS '昵称审核记录表';
COMMENT ON COLUMN checks.nickname_check.id IS '记录ID';
COMMENT ON COLUMN checks.nickname_check.user_uuid IS '用户UUID';
COMMENT ON COLUMN checks.nickname_check.old_nickname IS '原昵称';
COMMENT ON COLUMN checks.nickname_check.new_nickname IS '新昵称';
COMMENT ON COLUMN checks.nickname_check.apply_reason IS '申请理由';
COMMENT ON COLUMN checks.nickname_check.apply_time IS '申请时间';
COMMENT ON COLUMN checks.nickname_check.apply_ip IS '申请IP';
COMMENT ON COLUMN checks.nickname_check.status IS '审核状态：0-待审核，1-通过，2-拒绝，3-撤销';
COMMENT ON COLUMN checks.nickname_check.review_time IS '审核时间';
COMMENT ON COLUMN checks.nickname_check.reviewer_uuid IS '审核员UUID';
COMMENT ON COLUMN checks.nickname_check.reviewer_name IS '审核员名称';
COMMENT ON COLUMN checks.nickname_check.review_comment IS '审核意见';
COMMENT ON COLUMN checks.nickname_check.reject_reason IS '拒绝原因';
COMMENT ON COLUMN checks.nickname_check.sensitive_words IS '检测到的敏感词';
COMMENT ON COLUMN checks.nickname_check.auto_reviewed IS '是否自动审核';
COMMENT ON COLUMN checks.nickname_check.apply_type IS '申请类型：register-注册，manual-手动修改';
COMMENT ON COLUMN checks.nickname_check.extra_info IS '额外信息';
COMMENT ON COLUMN checks.nickname_check.created_at IS '创建时间';
COMMENT ON COLUMN checks.nickname_check.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_nickname_check_user_uuid ON checks.nickname_check(user_uuid);
CREATE INDEX IF NOT EXISTS idx_nickname_check_status ON checks.nickname_check(status);
CREATE INDEX IF NOT EXISTS idx_nickname_check_apply_time ON checks.nickname_check(apply_time DESC);
CREATE INDEX IF NOT EXISTS idx_nickname_check_apply_type ON checks.nickname_check(apply_type);
CREATE INDEX IF NOT EXISTS idx_nickname_check_reviewer ON checks.nickname_check(reviewer_uuid);

-- 创建 GIN 索引用于 JSONB 字段查询
CREATE INDEX IF NOT EXISTS idx_nickname_check_sensitive_words ON checks.nickname_check USING GIN(sensitive_words);
CREATE INDEX IF NOT EXISTS idx_nickname_check_extra_info ON checks.nickname_check USING GIN(extra_info);

-- ============================================
-- 4. 创建敏感词表（可选）
-- ============================================

CREATE TABLE IF NOT EXISTS checks.sensitive_words (
    id SERIAL PRIMARY KEY,
    word VARCHAR(100) NOT NULL UNIQUE,                     -- 敏感词
    category VARCHAR(50),                                  -- 分类：政治、色情、暴力、广告等
    severity INTEGER DEFAULT 1,                            -- 严重程度：1-低，2-中，3-高
    action VARCHAR(20) DEFAULT 'reject',                   -- 处理动作：reject-拒绝，warn-警告，replace-替换
    replacement VARCHAR(100),                              -- 替换词（如果action=replace）
    is_enabled BOOLEAN DEFAULT TRUE,                       -- 是否启用
    description TEXT,                                      -- 说明
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'system'
);

-- 添加表注释
COMMENT ON TABLE checks.sensitive_words IS '敏感词表';
COMMENT ON COLUMN checks.sensitive_words.id IS '敏感词ID';
COMMENT ON COLUMN checks.sensitive_words.word IS '敏感词';
COMMENT ON COLUMN checks.sensitive_words.category IS '分类：政治、色情、暴力、广告等';
COMMENT ON COLUMN checks.sensitive_words.severity IS '严重程度：1-低，2-中，3-高';
COMMENT ON COLUMN checks.sensitive_words.action IS '处理动作：reject-拒绝，warn-警告，replace-替换';
COMMENT ON COLUMN checks.sensitive_words.replacement IS '替换词（如果action=replace）';
COMMENT ON COLUMN checks.sensitive_words.is_enabled IS '是否启用';
COMMENT ON COLUMN checks.sensitive_words.description IS '说明';
COMMENT ON COLUMN checks.sensitive_words.created_at IS '创建时间';
COMMENT ON COLUMN checks.sensitive_words.updated_at IS '更新时间';
COMMENT ON COLUMN checks.sensitive_words.created_by IS '创建者';

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_sensitive_words_category ON checks.sensitive_words(category);
CREATE INDEX IF NOT EXISTS idx_sensitive_words_enabled ON checks.sensitive_words(is_enabled);
CREATE INDEX IF NOT EXISTS idx_sensitive_words_severity ON checks.sensitive_words(severity);

-- 插入一些示例敏感词（可根据实际需求调整）
INSERT INTO checks.sensitive_words (word, category, severity, action, description) VALUES
('管理员', 'system', 2, 'reject', '系统保留词'),
('admin', 'system', 2, 'reject', '系统保留词'),
('客服', 'system', 2, 'reject', '系统保留词'),
('官方', 'system', 2, 'reject', '系统保留词')
ON CONFLICT (word) DO NOTHING;

-- ============================================
-- 5. 创建触发器：自动更新 updated_at
-- ============================================

-- 创建更新时间触发器函数
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 为配置表添加触发器
DROP TRIGGER IF EXISTS update_nickname_check_config_updated_at ON site_configs.nickname_check_config;
CREATE TRIGGER update_nickname_check_config_updated_at
    BEFORE UPDATE ON site_configs.nickname_check_config
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- 为审核记录表添加触发器
DROP TRIGGER IF EXISTS update_nickname_check_updated_at ON checks.nickname_check;
CREATE TRIGGER update_nickname_check_updated_at
    BEFORE UPDATE ON checks.nickname_check
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- 为敏感词表添加触发器
DROP TRIGGER IF EXISTS update_sensitive_words_updated_at ON checks.sensitive_words;
CREATE TRIGGER update_sensitive_words_updated_at
    BEFORE UPDATE ON checks.sensitive_words
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================
-- 完成提示
-- ============================================

DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE '昵称审核系统创建完成！';
    RAISE NOTICE '========================================';
    RAISE NOTICE '已创建：';
    RAISE NOTICE '1. site_configs.nickname_check_config - 昵称审核配置表';
    RAISE NOTICE '2. checks Schema - 审核相关表';
    RAISE NOTICE '3. checks.nickname_check - 昵称审核记录表';
    RAISE NOTICE '4. checks.sensitive_words - 敏感词表';
    RAISE NOTICE '========================================';
END $$;
