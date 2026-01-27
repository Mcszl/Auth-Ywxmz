-- ============================================
-- 一碗小米周授权登录平台 - 权限管理数据库脚本
-- ============================================

-- 检查并创建 Schema
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name = 'site_configs'
    ) THEN
        CREATE SCHEMA site_configs;
        RAISE NOTICE 'Schema site_configs 已创建';
    ELSE
        RAISE NOTICE 'Schema site_configs 已存在';
    END IF;
END
$$;

-- 设置搜索路径
SET search_path TO site_configs, public;

-- ============================================
-- 创建权限表
-- ============================================
CREATE TABLE IF NOT EXISTS authority (
    -- 主键
    id SERIAL PRIMARY KEY,
    
    -- 权限代码（唯一标识）
    permission_code VARCHAR(100) UNIQUE NOT NULL,
    
    -- 权限名称
    permission_name VARCHAR(255) NOT NULL,
    
    -- 权限描述
    permission_description TEXT,
    
    -- 权限分类
    permission_category VARCHAR(50) NOT NULL DEFAULT 'user',
    
    -- 是否启用
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- 排序顺序
    sort_order INTEGER NOT NULL DEFAULT 0,
    
    -- 时间戳
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 添加表注释
COMMENT ON TABLE authority IS '权限表 - 存储系统所有可用权限';

-- 添加列注释
COMMENT ON COLUMN authority.id IS '主键ID';
COMMENT ON COLUMN authority.permission_code IS '权限代码（唯一标识）';
COMMENT ON COLUMN authority.permission_name IS '权限名称';
COMMENT ON COLUMN authority.permission_description IS '权限描述';
COMMENT ON COLUMN authority.permission_category IS '权限分类（user-用户相关，system-系统相关，data-数据相关）';
COMMENT ON COLUMN authority.is_enabled IS '是否启用';
COMMENT ON COLUMN authority.sort_order IS '排序顺序';
COMMENT ON COLUMN authority.created_at IS '创建时间';
COMMENT ON COLUMN authority.updated_at IS '更新时间';

-- ============================================
-- 创建索引
-- ============================================
CREATE INDEX IF NOT EXISTS idx_authority_permission_code ON authority(permission_code);
CREATE INDEX IF NOT EXISTS idx_authority_category ON authority(permission_category);
CREATE INDEX IF NOT EXISTS idx_authority_is_enabled ON authority(is_enabled);
CREATE INDEX IF NOT EXISTS idx_authority_sort_order ON authority(sort_order);

-- ============================================
-- 创建更新时间触发器
-- ============================================
CREATE OR REPLACE FUNCTION update_authority_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_authority_updated_at ON authority;
CREATE TRIGGER update_authority_updated_at
    BEFORE UPDATE ON authority
    FOR EACH ROW
    EXECUTE FUNCTION update_authority_updated_at();

-- ============================================
-- 插入默认权限数据
-- ============================================

-- 用户基本信息权限
INSERT INTO authority (permission_code, permission_name, permission_description, permission_category, sort_order) VALUES
('user.basic', '基本信息', '获取用户的基本信息（昵称、头像等）', 'user', 1),
('user.email', '邮箱地址', '获取用户的邮箱地址', 'user', 2),
('user.phone', '手机号码', '获取用户的手机号码', 'user', 3),
('user.profile', '详细资料', '获取用户的详细个人资料', 'user', 4),
('user.avatar', '头像', '获取用户的头像信息', 'user', 5)
ON CONFLICT (permission_code) DO NOTHING;

-- 用户操作权限
INSERT INTO authority (permission_code, permission_name, permission_description, permission_category, sort_order) VALUES
('user.update', '更新信息', '允许更新用户信息', 'user', 10),
('user.delete', '删除账号', '允许删除用户账号', 'user', 11)
ON CONFLICT (permission_code) DO NOTHING;

-- 数据访问权限
INSERT INTO authority (permission_code, permission_name, permission_description, permission_category, sort_order) VALUES
('data.read', '读取数据', '允许读取用户相关数据', 'data', 20),
('data.write', '写入数据', '允许写入用户相关数据', 'data', 21),
('data.export', '导出数据', '允许导出用户数据', 'data', 22)
ON CONFLICT (permission_code) DO NOTHING;

-- 系统权限
INSERT INTO authority (permission_code, permission_name, permission_description, permission_category, sort_order) VALUES
('system.login', '登录权限', '允许用户登录系统', 'system', 30),
('system.logout', '登出权限', '允许用户登出系统', 'system', 31),
('system.register', '注册权限', '允许用户注册账号', 'system', 32)
ON CONFLICT (permission_code) DO NOTHING;

-- 第三方服务权限
INSERT INTO authority (permission_code, permission_name, permission_description, permission_category, sort_order) VALUES
('third.qq', 'QQ授权', '允许通过QQ进行授权登录', 'third_party', 40),
('third.wechat', '微信授权', '允许通过微信进行授权登录', 'third_party', 41),
('third.weibo', '微博授权', '允许通过微博进行授权登录', 'third_party', 42),
('third.github', 'GitHub授权', '允许通过GitHub进行授权登录', 'third_party', 43),
('third.google', 'Google授权', '允许通过Google进行授权登录', 'third_party', 44)
ON CONFLICT (permission_code) DO NOTHING;

-- ============================================
-- 查询验证
-- ============================================

-- 验证表是否创建成功
SELECT 
    table_schema,
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'site_configs' 
AND table_name = 'authority';

-- 查看表结构
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_schema = 'site_configs' 
AND table_name = 'authority'
ORDER BY ordinal_position;

-- 查看所有权限数据
SELECT 
    permission_code,
    permission_name,
    permission_category,
    permission_description,
    is_enabled,
    sort_order
FROM site_configs.authority
ORDER BY permission_category, sort_order;

-- 按分类统计权限数量
SELECT 
    permission_category,
    COUNT(*) as permission_count
FROM site_configs.authority
WHERE is_enabled = TRUE
GROUP BY permission_category
ORDER BY permission_category;
