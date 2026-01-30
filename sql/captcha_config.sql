-- 人机验证配置表
-- 用于存储各种人机验证服务的配置信息

CREATE TABLE IF NOT EXISTS site_configs.captcha_config (
    id SERIAL PRIMARY KEY,
    site_id INTEGER NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 验证配置
    captcha_id VARCHAR(255),
    captcha_key VARCHAR(255),
    app_id VARCHAR(255),
    app_secret VARCHAR(255),
    site_key VARCHAR(255),
    secret_key VARCHAR(255),
    
    -- 使用场景
    scenes JSONB DEFAULT '["register", "login", "reset_password"]'::jsonb,
    
    -- 验证配置
    config JSONB DEFAULT '{}'::jsonb,
    
    -- 优先级和限制
    priority INTEGER NOT NULL DEFAULT 0,
    daily_limit INTEGER DEFAULT 10000,
    daily_verify_count INTEGER DEFAULT 0,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1,
    
    -- 时间戳
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- 索引
    CONSTRAINT unique_site_provider UNIQUE (site_id, provider, name)
);

-- 添加注释
COMMENT ON TABLE site_configs.captcha_config IS '人机验证配置表';
COMMENT ON COLUMN site_configs.captcha_config.id IS '主键ID';
COMMENT ON COLUMN site_configs.captcha_config.site_id IS '站点ID';
COMMENT ON COLUMN site_configs.captcha_config.name IS '配置名称';
COMMENT ON COLUMN site_configs.captcha_config.provider IS '验证提供商：geetest, recaptcha, hcaptcha, turnstile';
COMMENT ON COLUMN site_configs.captcha_config.is_enabled IS '是否启用';
COMMENT ON COLUMN site_configs.captcha_config.captcha_id IS '验证ID（极验的captcha_id）';
COMMENT ON COLUMN site_configs.captcha_config.captcha_key IS '验证密钥（极验的captcha_key）';
COMMENT ON COLUMN site_configs.captcha_config.app_id IS '应用ID（通用字段）';
COMMENT ON COLUMN site_configs.captcha_config.app_secret IS '应用密钥（通用字段）';
COMMENT ON COLUMN site_configs.captcha_config.site_key IS '站点密钥（reCAPTCHA等）';
COMMENT ON COLUMN site_configs.captcha_config.secret_key IS '服务端密钥（reCAPTCHA等）';
COMMENT ON COLUMN site_configs.captcha_config.scenes IS '使用场景';
COMMENT ON COLUMN site_configs.captcha_config.config IS '其他配置参数';
COMMENT ON COLUMN site_configs.captcha_config.priority IS '优先级，数字越小优先级越高';
COMMENT ON COLUMN site_configs.captcha_config.daily_limit IS '每日验证次数限制';
COMMENT ON COLUMN site_configs.captcha_config.daily_verify_count IS '今日已验证次数';
COMMENT ON COLUMN site_configs.captcha_config.status IS '状态：0-禁用，1-启用';
COMMENT ON COLUMN site_configs.captcha_config.created_at IS '创建时间';
COMMENT ON COLUMN site_configs.captcha_config.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX idx_captcha_site_enabled ON site_configs.captcha_config(site_id, is_enabled, status);
CREATE INDEX idx_captcha_provider ON site_configs.captcha_config(provider);
CREATE INDEX idx_captcha_scenes ON site_configs.captcha_config USING gin(scenes);

-- 插入示例配置（极验）
INSERT INTO site_configs.captcha_config (
    site_id, name, provider, is_enabled,
    captcha_id, captcha_key,
    scenes, priority, status
) VALUES (
    1,
    '极验行为验证',
    'geetest',
    FALSE,
    'your_captcha_id_here',
    'your_captcha_key_here',
    '["register", "login", "reset_password", "send_sms"]'::jsonb,
    1,
    1
) ON CONFLICT (site_id, provider, name) DO NOTHING;

-- 插入示例配置（reCAPTCHA v3）
INSERT INTO site_configs.captcha_config (
    site_id, name, provider, is_enabled,
    site_key, secret_key,
    scenes, config, priority, status
) VALUES (
    1,
    'Google reCAPTCHA v3',
    'recaptcha',
    FALSE,
    'your_site_key_here',
    'your_secret_key_here',
    '["register", "login"]'::jsonb,
    '{"version": "v3", "score_threshold": 0.5}'::jsonb,
    2,
    1
) ON CONFLICT (site_id, provider, name) DO NOTHING;

-- 创建验证记录表
CREATE TABLE IF NOT EXISTS site_configs.captcha_verify_log (
    id BIGSERIAL PRIMARY KEY,
    config_id INTEGER REFERENCES site_configs.captcha_config(id),
    
    -- 验证信息
    scene VARCHAR(50) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    
    -- 极验相关字段
    lot_number TEXT,
    captcha_output TEXT,
    pass_token TEXT,
    gen_time VARCHAR(50),
    
    -- 通用字段
    challenge TEXT,
    validate VARCHAR(255),
    seccode TEXT,
    
    -- 验证结果
    verify_success BOOLEAN NOT NULL DEFAULT FALSE,
    verify_result JSONB,
    error_message TEXT,
    
    -- 客户端信息
    client_ip VARCHAR(50),
    user_agent TEXT,
    
    -- 关联信息
    phone VARCHAR(20),
    email VARCHAR(255),
    user_id INTEGER,
    
    -- 时间戳
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP
);

-- 添加注释
COMMENT ON TABLE site_configs.captcha_verify_log IS '人机验证记录表';
COMMENT ON COLUMN site_configs.captcha_verify_log.id IS '主键ID';
COMMENT ON COLUMN site_configs.captcha_verify_log.config_id IS '配置ID';
COMMENT ON COLUMN site_configs.captcha_verify_log.scene IS '验证场景';
COMMENT ON COLUMN site_configs.captcha_verify_log.provider IS '验证提供商';
COMMENT ON COLUMN site_configs.captcha_verify_log.lot_number IS '极验流水号（TEXT类型，支持长字符串）';
COMMENT ON COLUMN site_configs.captcha_verify_log.captcha_output IS '极验输出';
COMMENT ON COLUMN site_configs.captcha_verify_log.pass_token IS '极验通过令牌（TEXT类型，支持长字符串）';
COMMENT ON COLUMN site_configs.captcha_verify_log.gen_time IS '极验生成时间';
COMMENT ON COLUMN site_configs.captcha_verify_log.challenge IS '挑战码（Turnstile/reCAPTCHA/hCaptcha 的 token，TEXT类型支持长字符串）';
COMMENT ON COLUMN site_configs.captcha_verify_log.validate IS '验证码';
COMMENT ON COLUMN site_configs.captcha_verify_log.seccode IS '安全码';
COMMENT ON COLUMN site_configs.captcha_verify_log.verify_success IS '验证是否成功';
COMMENT ON COLUMN site_configs.captcha_verify_log.verify_result IS '验证结果详情';
COMMENT ON COLUMN site_configs.captcha_verify_log.error_message IS '错误信息';
COMMENT ON COLUMN site_configs.captcha_verify_log.client_ip IS '客户端IP';
COMMENT ON COLUMN site_configs.captcha_verify_log.user_agent IS '用户代理';
COMMENT ON COLUMN site_configs.captcha_verify_log.phone IS '关联手机号';
COMMENT ON COLUMN site_configs.captcha_verify_log.email IS '关联邮箱';
COMMENT ON COLUMN site_configs.captcha_verify_log.user_id IS '关联用户ID';
COMMENT ON COLUMN site_configs.captcha_verify_log.created_at IS '创建时间';
COMMENT ON COLUMN site_configs.captcha_verify_log.expires_at IS '过期时间（用于二次验证）';

-- 创建索引
CREATE INDEX idx_captcha_log_scene ON site_configs.captcha_verify_log(scene);
CREATE INDEX idx_captcha_log_ip ON site_configs.captcha_verify_log(client_ip);
CREATE INDEX idx_captcha_log_phone ON site_configs.captcha_verify_log(phone);
CREATE INDEX idx_captcha_log_created ON site_configs.captcha_verify_log(created_at);
CREATE INDEX idx_captcha_log_expires ON site_configs.captcha_verify_log(expires_at);
