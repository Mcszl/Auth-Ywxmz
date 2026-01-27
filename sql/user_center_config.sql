-- 用户中心配置表
-- 用于存储用户中心登录的默认配置

-- 确保 site_configs schema 存在
CREATE SCHEMA IF NOT EXISTS site_configs;

-- 创建用户中心配置表
CREATE TABLE IF NOT EXISTS site_configs.user_center_config (
    id SERIAL PRIMARY KEY,
    app_id VARCHAR(100) NOT NULL UNIQUE,
    callback_url TEXT NOT NULL,
    permissions TEXT NOT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加注释
COMMENT ON TABLE site_configs.user_center_config IS '用户中心配置表';
COMMENT ON COLUMN site_configs.user_center_config.id IS '主键ID';
COMMENT ON COLUMN site_configs.user_center_config.app_id IS '应用ID';
COMMENT ON COLUMN site_configs.user_center_config.callback_url IS '回调地址';
COMMENT ON COLUMN site_configs.user_center_config.permissions IS '所需权限（逗号分隔）';
COMMENT ON COLUMN site_configs.user_center_config.status IS '状态：0-禁用，1-启用';
COMMENT ON COLUMN site_configs.user_center_config.created_at IS '创建时间';
COMMENT ON COLUMN site_configs.user_center_config.updated_at IS '更新时间';

-- ============================================
-- 插入默认配置（已注释，由安装向导动态生成）
-- ============================================
-- INSERT INTO site_configs.user_center_config (
--     app_id,
--     callback_url,
--     permissions,
--     status,
--     created_at,
--     updated_at
-- ) VALUES (
--     'user_center',
--     'https://yourdomain.com/user/callback/',
--     'user.basic',
--     1,
--     CURRENT_TIMESTAMP,
--     CURRENT_TIMESTAMP
-- ) ON CONFLICT (app_id) DO UPDATE SET
--     callback_url = EXCLUDED.callback_url,
--     permissions = EXCLUDED.permissions,
--     status = EXCLUDED.status,
--     updated_at = CURRENT_TIMESTAMP;

-- 确保 user_center 应用在 site_config 表中存在（已注释，由安装向导动态生成）
-- INSERT INTO site_config (
--     site_name,
--     site_url,
--     site_protocol,
--     app_id,
--     secret_key,
--     status,
--     permissions,
--     callback_urls,
--     enable_register,
--     enable_login,
--     enable_password_login,
--     enable_email_code_login,
--     enable_phone_code_login,
--     created_at,
--     updated_at
-- ) VALUES (
--     '用户中心',
--     'yourdomain.com',
--     'https',
--     'user_center',
--     'user_center_secret_' || md5(random()::text),
--     1,
--     ARRAY['user.basic', 'user.email'],
--     ARRAY['https://yourdomain.com/user/callback/'],
--     true,
--     true,
--     true,
--     true,
--     true,
--     CURRENT_TIMESTAMP,
--     CURRENT_TIMESTAMP
-- ) ON CONFLICT (app_id) DO UPDATE SET
--     permissions = EXCLUDED.permissions,
--     callback_urls = EXCLUDED.callback_urls,
--     enable_login = EXCLUDED.enable_login,
--     enable_password_login = EXCLUDED.enable_password_login,
--     enable_email_code_login = EXCLUDED.enable_email_code_login,
--     enable_phone_code_login = EXCLUDED.enable_phone_code_login,
--     updated_at = CURRENT_TIMESTAMP;
