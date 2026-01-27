-- 用户数据表
-- 用于存储用户基本信息

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- 创建 users Schema
CREATE SCHEMA IF NOT EXISTS users;

-- 创建用户表
CREATE TABLE IF NOT EXISTS users.user (
    id SERIAL PRIMARY KEY,
    uuid INTEGER NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    nickname VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    avatar VARCHAR(500),
    user_type VARCHAR(20) NOT NULL DEFAULT 'user',
    gender SMALLINT DEFAULT 0,
    birth_date DATE,
    status SMALLINT NOT NULL DEFAULT 1,
    register_ip VARCHAR(50),
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_user_type CHECK (user_type IN ('user', 'admin', 'siteadmin')),
    CONSTRAINT chk_status CHECK (status IN (0, 1, 2, 3)),
    CONSTRAINT chk_gender CHECK (gender IN (0, 1, 2))
);

-- 添加注释
COMMENT ON TABLE users.user IS '用户表';
COMMENT ON COLUMN users.user.id IS '主键ID';
COMMENT ON COLUMN users.user.uuid IS '用户UUID（从100000开始）';
COMMENT ON COLUMN users.user.username IS '用户名（唯一）';
COMMENT ON COLUMN users.user.nickname IS '昵称';
COMMENT ON COLUMN users.user.phone IS '手机号';
COMMENT ON COLUMN users.user.email IS '邮箱';
COMMENT ON COLUMN users.user.password_hash IS '密码哈希';
COMMENT ON COLUMN users.user.avatar IS '头像URL';
COMMENT ON COLUMN users.user.user_type IS '用户类型：user-普通用户，admin-全局管理员，siteadmin-站点管理员';
COMMENT ON COLUMN users.user.gender IS '性别：0-未知，1-男，2-女';
COMMENT ON COLUMN users.user.birth_date IS '出生日期';
COMMENT ON COLUMN users.user.status IS '状态：0-封禁，1-正常，2-手机号等待核验，3-邮箱等待核验';
COMMENT ON COLUMN users.user.register_ip IS '注册IP';
COMMENT ON COLUMN users.user.last_login_at IS '最后登录时间';
COMMENT ON COLUMN users.user.last_login_ip IS '最后登录IP';
COMMENT ON COLUMN users.user.created_at IS '创建时间';
COMMENT ON COLUMN users.user.updated_at IS '更新时间';

-- 创建索引
CREATE INDEX idx_user_username ON users.user(username);
CREATE INDEX idx_user_phone ON users.user(phone);
CREATE INDEX idx_user_email ON users.user(email);
CREATE INDEX idx_user_uuid ON users.user(uuid);
CREATE INDEX idx_user_status ON users.user(status);
CREATE INDEX idx_user_type ON users.user(user_type);

-- 创建 UUID 序列（从 100000 开始）
CREATE SEQUENCE IF NOT EXISTS users.user_uuid_seq
    START WITH 100000
    INCREMENT BY 1
    NO MAXVALUE
    NO MINVALUE
    CACHE 1;

-- 设置 UUID 默认值
ALTER TABLE users.user ALTER COLUMN uuid SET DEFAULT nextval('users.user_uuid_seq');

-- 创建更新时间触发器函数
CREATE OR REPLACE FUNCTION users.update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 创建触发器
DROP TRIGGER IF EXISTS update_user_updated_at ON users.user;
CREATE TRIGGER update_user_updated_at
    BEFORE UPDATE ON users.user
    FOR EACH ROW
    EXECUTE FUNCTION users.update_updated_at_column();

-- 创建用户扩展信息表
CREATE TABLE IF NOT EXISTS users.user_profile (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users.user(id) ON DELETE CASCADE,
    real_name VARCHAR(100),
    id_card VARCHAR(50),
    address TEXT,
    bio TEXT,
    website VARCHAR(500),
    company VARCHAR(200),
    position VARCHAR(100),
    extra_info JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT unique_user_profile UNIQUE (user_id)
);

-- 添加注释
COMMENT ON TABLE users.user_profile IS '用户扩展信息表';
COMMENT ON COLUMN users.user_profile.user_id IS '用户ID';
COMMENT ON COLUMN users.user_profile.real_name IS '真实姓名';
COMMENT ON COLUMN users.user_profile.id_card IS '身份证号';
COMMENT ON COLUMN users.user_profile.address IS '地址';
COMMENT ON COLUMN users.user_profile.bio IS '个人简介';
COMMENT ON COLUMN users.user_profile.website IS '个人网站';
COMMENT ON COLUMN users.user_profile.company IS '公司';
COMMENT ON COLUMN users.user_profile.position IS '职位';
COMMENT ON COLUMN users.user_profile.extra_info IS '额外信息（JSON）';

-- 创建索引
CREATE INDEX idx_user_profile_user_id ON users.user_profile(user_id);

-- 创建触发器
DROP TRIGGER IF EXISTS update_user_profile_updated_at ON users.user_profile;
CREATE TRIGGER update_user_profile_updated_at
    BEFORE UPDATE ON users.user_profile
    FOR EACH ROW
    EXECUTE FUNCTION users.update_updated_at_column();
