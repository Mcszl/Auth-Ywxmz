-- ============================================
-- 一碗小米周授权登录平台 - 邮件配置数据库脚本
-- ============================================

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- 设置搜索路径
SET search_path TO site_configs, public;

-- ============================================
-- 创建邮件配置表
-- ============================================
CREATE TABLE IF NOT EXISTS email_config (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 配置名称
    config_name VARCHAR(100) NOT NULL,
    
    -- 邮箱配置
    email VARCHAR(255) NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    
    -- SMTP 服务器配置
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INTEGER NOT NULL DEFAULT 465,
    encryption VARCHAR(10) NOT NULL DEFAULT 'ssl' CHECK (encryption IN ('none', 'ssl', 'tls')),
    
    -- 适用场景（JSON数组）
    scenes JSONB DEFAULT '["register", "login", "reset_password"]'::jsonb,
    
    -- 限制配置
    daily_limit INTEGER DEFAULT 1000,
    daily_sent_count INTEGER NOT NULL DEFAULT 0,
    last_reset_date DATE,
    
    -- 回复地址
    reply_to VARCHAR(255),
    
    -- 邮件签名配置
    enable_signature BOOLEAN NOT NULL DEFAULT FALSE,
    signature_cert TEXT,
    signature_key TEXT,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2)),
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 优先级（数字越小优先级越高）
    priority INTEGER NOT NULL DEFAULT 100,
    
    -- 备注
    description TEXT,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE email_config IS '邮件配置表 - 存储邮件发送的配置信息';

-- 添加列注释
COMMENT ON COLUMN email_config.id IS '主键ID';
COMMENT ON COLUMN email_config.config_name IS '配置名称';
COMMENT ON COLUMN email_config.email IS '发件邮箱地址';
COMMENT ON COLUMN email_config.sender_name IS '发件人名称';
COMMENT ON COLUMN email_config.username IS '邮箱账户（用于SMTP认证）';
COMMENT ON COLUMN email_config.password IS '邮箱密码或授权码';
COMMENT ON COLUMN email_config.smtp_host IS 'SMTP服务器地址';
COMMENT ON COLUMN email_config.smtp_port IS 'SMTP服务器端口';
COMMENT ON COLUMN email_config.encryption IS '加密方式：none-不加密，ssl-SSL加密，tls-TLS加密';
COMMENT ON COLUMN email_config.scenes IS '适用场景（JSON数组）：register-注册，login-登录，reset_password-重置密码等';
COMMENT ON COLUMN email_config.daily_limit IS '每日发送限制';
COMMENT ON COLUMN email_config.daily_sent_count IS '今日已发送数量';
COMMENT ON COLUMN email_config.last_reset_date IS '最后重置日期';
COMMENT ON COLUMN email_config.reply_to IS '回复地址';
COMMENT ON COLUMN email_config.enable_signature IS '是否启用邮件签名';
COMMENT ON COLUMN email_config.signature_cert IS '签名证书';
COMMENT ON COLUMN email_config.signature_key IS '签名密钥';
COMMENT ON COLUMN email_config.status IS '状态：0-禁用，1-正常（默认），2-维护中';
COMMENT ON COLUMN email_config.is_enabled IS '是否启用';
COMMENT ON COLUMN email_config.priority IS '优先级（数字越小优先级越高）';
COMMENT ON COLUMN email_config.description IS '备注说明';
COMMENT ON COLUMN email_config.created_at IS '创建时间';
COMMENT ON COLUMN email_config.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_email_config_email ON email_config(email);
CREATE INDEX IF NOT EXISTS idx_email_config_is_enabled ON email_config(is_enabled);
CREATE INDEX IF NOT EXISTS idx_email_config_status ON email_config(status);
CREATE INDEX IF NOT EXISTS idx_email_config_priority ON email_config(priority);
CREATE INDEX IF NOT EXISTS idx_email_config_scenes ON email_config USING gin(scenes);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_email_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_email_config_updated_at ON email_config;
CREATE TRIGGER update_email_config_updated_at
    BEFORE UPDATE ON email_config
    FOR EACH ROW
    EXECUTE FUNCTION update_email_config_updated_at();

-- ============================================
-- 创建每日计数重置触发器
-- ============================================
CREATE OR REPLACE FUNCTION reset_email_daily_count()
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

DROP TRIGGER IF EXISTS reset_email_daily_count_trigger ON email_config;
CREATE TRIGGER reset_email_daily_count_trigger
    BEFORE INSERT OR UPDATE ON email_config
    FOR EACH ROW
    EXECUTE FUNCTION reset_email_daily_count();

-- ============================================
-- 创建邮件模板表
-- ============================================
CREATE TABLE IF NOT EXISTS email_template (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 模板标识
    template_code VARCHAR(100) UNIQUE NOT NULL,
    
    -- 模板名称
    template_name VARCHAR(200) NOT NULL,
    
    -- 适用场景
    scene VARCHAR(50) NOT NULL,
    
    -- 邮件主题
    subject VARCHAR(500) NOT NULL,
    
    -- 模板内容（HTML格式）
    template_content TEXT NOT NULL,
    
    -- 模板变量（JSON数组）
    template_variables JSONB DEFAULT '[]'::jsonb,
    
    -- 模板变量说明（JSON对象）
    variable_descriptions JSONB DEFAULT '{}'::jsonb,
    
    -- 状态
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (0, 1, 2)),
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 优先级（数字越小优先级越高）
    priority INTEGER NOT NULL DEFAULT 100,
    
    -- 备注
    description TEXT,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE email_template IS '邮件模板表 - 存储各种场景的邮件模板';

-- 添加列注释
COMMENT ON COLUMN email_template.id IS '主键ID';
COMMENT ON COLUMN email_template.template_code IS '模板标识（唯一）';
COMMENT ON COLUMN email_template.template_name IS '模板名称';
COMMENT ON COLUMN email_template.scene IS '适用场景：register-注册，login-登录，reset_password-重置密码等';
COMMENT ON COLUMN email_template.subject IS '邮件主题';
COMMENT ON COLUMN email_template.template_content IS '模板内容（HTML格式）';
COMMENT ON COLUMN email_template.template_variables IS '模板变量（JSON数组）：["username", "code", "expire_time"]';
COMMENT ON COLUMN email_template.variable_descriptions IS '模板变量说明（JSON对象）：{"username": "用户名", "code": "验证码"}';
COMMENT ON COLUMN email_template.status IS '状态：0-禁用，1-正常（默认），2-草稿';
COMMENT ON COLUMN email_template.is_enabled IS '是否启用';
COMMENT ON COLUMN email_template.priority IS '优先级（数字越小优先级越高）';
COMMENT ON COLUMN email_template.description IS '备注说明';
COMMENT ON COLUMN email_template.created_at IS '创建时间';
COMMENT ON COLUMN email_template.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_email_template_code ON email_template(template_code);
CREATE INDEX IF NOT EXISTS idx_email_template_scene ON email_template(scene);
CREATE INDEX IF NOT EXISTS idx_email_template_is_enabled ON email_template(is_enabled);
CREATE INDEX IF NOT EXISTS idx_email_template_status ON email_template(status);
CREATE INDEX IF NOT EXISTS idx_email_template_priority ON email_template(priority);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_email_template_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_email_template_updated_at ON email_template;
CREATE TRIGGER update_email_template_updated_at
    BEFORE UPDATE ON email_template
    FOR EACH ROW
    EXECUTE FUNCTION update_email_template_updated_at();

-- ============================================
-- 插入默认邮件配置数据
-- ============================================

-- 示例配置 1：QQ邮箱
INSERT INTO email_config (
    config_name,
    email,
    sender_name,
    username,
    password,
    smtp_host,
    smtp_port,
    encryption,
    scenes,
    daily_limit,
    reply_to,
    enable_signature,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    'QQ邮箱-注册验证',
    'noreply@example.com',
    '一碗小米周授权平台',
    'noreply@example.com',
    'your_password_or_auth_code',
    'smtp.qq.com',
    465,
    'ssl',
    '["register", "reset_password"]'::jsonb,
    1000,
    'support@example.com',
    FALSE,
    1,
    TRUE,
    10,
    'QQ邮箱配置 - 用于注册和密码重置'
) ON CONFLICT DO NOTHING;

-- 示例配置 2：163邮箱
INSERT INTO email_config (
    config_name,
    email,
    sender_name,
    username,
    password,
    smtp_host,
    smtp_port,
    encryption,
    scenes,
    daily_limit,
    reply_to,
    enable_signature,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    '163邮箱-登录通知',
    'noreply@163.com',
    '一碗小米周授权平台',
    'noreply@163.com',
    'your_password_or_auth_code',
    'smtp.163.com',
    465,
    'ssl',
    '["login", "security_alert"]'::jsonb,
    500,
    'support@example.com',
    FALSE,
    1,
    FALSE,
    20,
    '163邮箱配置 - 用于登录通知和安全警报'
) ON CONFLICT DO NOTHING;

-- ============================================
-- 插入默认邮件模板数据
-- ============================================

-- 注册验证码模板
INSERT INTO email_template (
    template_code,
    template_name,
    scene,
    subject,
    template_content,
    template_variables,
    variable_descriptions,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    'REGISTER_CODE',
    '注册验证码邮件',
    'register',
    '【一碗小米周】注册验证码',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .code-box { background: white; border: 2px dashed #007bff; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
        .code { font-size: 32px; font-weight: bold; color: #007bff; letter-spacing: 5px; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>欢迎注册</h1>
        </div>
        <div class="content">
            <p>尊敬的用户 <strong>{{username}}</strong>，您好！</p>
            <p>感谢您注册一碗小米周授权登录平台。您的验证码是：</p>
            <div class="code-box">
                <div class="code">{{code}}</div>
            </div>
            <p>验证码有效期为 <strong>{{expire_minutes}}</strong> 分钟，请尽快完成验证。</p>
            <p>如果这不是您本人的操作，请忽略此邮件。</p>
        </div>
        <div class="footer">
            <p>此邮件由系统自动发送，请勿回复。</p>
            <p>© 2026 一碗小米周授权登录平台. All rights reserved.</p>
        </div>
    </div>
</body>
</html>',
    '["username", "code", "expire_minutes"]'::jsonb,
    '{"username": "用户名", "code": "验证码", "expire_minutes": "过期时间（分钟）"}'::jsonb,
    1,
    TRUE,
    10,
    '用户注册时发送的验证码邮件模板'
) ON CONFLICT (template_code) DO NOTHING;

-- 登录验证码模板
INSERT INTO email_template (
    template_code,
    template_name,
    scene,
    subject,
    template_content,
    template_variables,
    variable_descriptions,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    'LOGIN_CODE',
    '登录验证码邮件',
    'login',
    '【一碗小米周】登录验证码',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .code-box { background: white; border: 2px dashed #28a745; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
        .code { font-size: 32px; font-weight: bold; color: #28a745; letter-spacing: 5px; }
        .info-box { background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>登录验证</h1>
        </div>
        <div class="content">
            <p>尊敬的用户 <strong>{{username}}</strong>，您好！</p>
            <p>您正在登录一碗小米周授权登录平台。您的验证码是：</p>
            <div class="code-box">
                <div class="code">{{code}}</div>
            </div>
            <p>验证码有效期为 <strong>{{expire_minutes}}</strong> 分钟，请尽快完成验证。</p>
            <div class="info-box">
                <p><strong>登录信息：</strong></p>
                <p>登录时间：{{login_time}}</p>
                <p>登录IP：{{login_ip}}</p>
                <p>登录地点：{{login_location}}</p>
            </div>
            <p>如果这不是您本人的操作，请立即修改密码并联系客服。</p>
        </div>
        <div class="footer">
            <p>此邮件由系统自动发送，请勿回复。</p>
            <p>© 2026 一碗小米周授权登录平台. All rights reserved.</p>
        </div>
    </div>
</body>
</html>',
    '["username", "code", "expire_minutes", "login_time", "login_ip", "login_location"]'::jsonb,
    '{"username": "用户名", "code": "验证码", "expire_minutes": "过期时间（分钟）", "login_time": "登录时间", "login_ip": "登录IP", "login_location": "登录地点"}'::jsonb,
    1,
    TRUE,
    10,
    '用户登录时发送的验证码邮件模板'
) ON CONFLICT (template_code) DO NOTHING;

-- 重置密码验证码模板
INSERT INTO email_template (
    template_code,
    template_name,
    scene,
    subject,
    template_content,
    template_variables,
    variable_descriptions,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    'RESET_PASSWORD_CODE',
    '重置密码验证码邮件',
    'reset_password',
    '【一碗小米周】重置密码验证码',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .code-box { background: white; border: 2px dashed #dc3545; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
        .code { font-size: 32px; font-weight: bold; color: #dc3545; letter-spacing: 5px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>重置密码</h1>
        </div>
        <div class="content">
            <p>尊敬的用户 <strong>{{username}}</strong>，您好！</p>
            <p>您正在重置密码。您的验证码是：</p>
            <div class="code-box">
                <div class="code">{{code}}</div>
            </div>
            <p>验证码有效期为 <strong>{{expire_minutes}}</strong> 分钟，请尽快完成验证。</p>
            <div class="warning-box">
                <p><strong>安全提示：</strong></p>
                <p>• 请勿将验证码告诉任何人</p>
                <p>• 如果这不是您本人的操作，请立即联系客服</p>
                <p>• 建议定期修改密码以保护账户安全</p>
            </div>
        </div>
        <div class="footer">
            <p>此邮件由系统自动发送，请勿回复。</p>
            <p>© 2026 一碗小米周授权登录平台. All rights reserved.</p>
        </div>
    </div>
</body>
</html>',
    '["username", "code", "expire_minutes"]'::jsonb,
    '{"username": "用户名", "code": "验证码", "expire_minutes": "过期时间（分钟）"}'::jsonb,
    1,
    TRUE,
    10,
    '用户重置密码时发送的验证码邮件模板'
) ON CONFLICT (template_code) DO NOTHING;

-- 欢迎邮件模板
INSERT INTO email_template (
    template_code,
    template_name,
    scene,
    subject,
    template_content,
    template_variables,
    variable_descriptions,
    status,
    is_enabled,
    priority,
    description
) VALUES (
    'WELCOME_EMAIL',
    '欢迎邮件',
    'register',
    '欢迎加入一碗小米周授权登录平台',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .welcome-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .feature-list { list-style: none; padding: 0; }
        .feature-list li { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .feature-list li:last-child { border-bottom: none; }
        .btn { display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 欢迎加入！</h1>
        </div>
        <div class="content">
            <div class="welcome-box">
                <p>尊敬的 <strong>{{username}}</strong>，您好！</p>
                <p>欢迎加入一碗小米周授权登录平台！您的账户已成功创建。</p>
                <p><strong>账户信息：</strong></p>
                <ul>
                    <li>用户名：{{username}}</li>
                    <li>昵称：{{nickname}}</li>
                    <li>注册时间：{{register_time}}</li>
                </ul>
            </div>
            <p><strong>平台特色功能：</strong></p>
            <ul class="feature-list">
                <li>🔐 统一身份认证 - 一次登录，畅游所有应用</li>
                <li>🛡️ 安全可靠 - 多重安全防护，保障账户安全</li>
                <li>⚡ 快速便捷 - 简化登录流程，提升使用体验</li>
                <li>🎨 个性化设置 - 自定义个人信息和偏好</li>
            </ul>
            <div style="text-align: center;">
                <a href="{{platform_url}}" class="btn">立即体验</a>
            </div>
            <p>如有任何问题，欢迎随时联系我们的客服团队。</p>
        </div>
        <div class="footer">
            <p>此邮件由系统自动发送，请勿回复。</p>
            <p>© 2026 一碗小米周授权登录平台. All rights reserved.</p>
        </div>
    </div>
</body>
</html>',
    '["username", "nickname", "register_time", "platform_url"]'::jsonb,
    '{"username": "用户名", "nickname": "昵称", "register_time": "注册时间", "platform_url": "平台URL"}'::jsonb,
    1,
    TRUE,
    20,
    '用户注册成功后发送的欢迎邮件模板'
) ON CONFLICT (template_code) DO NOTHING;

-- ============================================
-- 查询验证
-- ============================================

-- 验证邮件配置表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'site_configs' 
AND table_name IN ('email_config', 'email_template');

-- 查看邮件配置表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'site_configs' 
AND table_name = 'email_config'
ORDER BY ordinal_position;

-- 查看邮件模板表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'site_configs' 
AND table_name = 'email_template'
ORDER BY ordinal_position;

-- 查看默认配置数据
SELECT 
    id,
    config_name,
    email,
    sender_name,
    smtp_host,
    smtp_port,
    scenes,
    is_enabled,
    status
FROM site_configs.email_config
ORDER BY priority;

-- 查看默认模板数据
SELECT 
    id,
    template_code,
    template_name,
    scene,
    subject,
    is_enabled,
    status
FROM site_configs.email_template
ORDER BY priority;

-- 完成提示
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE '邮件配置和模板表创建完成！';
    RAISE NOTICE '========================================';
END $$;
