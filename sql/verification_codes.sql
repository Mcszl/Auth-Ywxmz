-- 验证码表
-- 用于存储各种类型的验证码

CREATE TABLE IF NOT EXISTS auth.verification_codes (
    id SERIAL PRIMARY KEY,
    user_uuid INTEGER,
    code VARCHAR(10) NOT NULL,
    code_type VARCHAR(50) NOT NULL,
    target VARCHAR(255) NOT NULL,
    target_type VARCHAR(20) NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    used_at TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_verification_user_uuid FOREIGN KEY (user_uuid) REFERENCES users.user(uuid) ON DELETE CASCADE
);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_verification_user_uuid ON auth.verification_codes(user_uuid);
CREATE INDEX IF NOT EXISTS idx_verification_code_type ON auth.verification_codes(code_type);
CREATE INDEX IF NOT EXISTS idx_verification_target ON auth.verification_codes(target);
CREATE INDEX IF NOT EXISTS idx_verification_expires_at ON auth.verification_codes(expires_at);
CREATE INDEX IF NOT EXISTS idx_verification_used ON auth.verification_codes(used);

-- 添加表注释
COMMENT ON TABLE auth.verification_codes IS '验证码表';
COMMENT ON COLUMN auth.verification_codes.id IS '主键ID';
COMMENT ON COLUMN auth.verification_codes.user_uuid IS '用户UUID';
COMMENT ON COLUMN auth.verification_codes.code IS '验证码';
COMMENT ON COLUMN auth.verification_codes.code_type IS '验证码类型：register-注册, login-登录, password_reset-密码重置, bind_phone-绑定手机, bind_email-绑定邮箱';
COMMENT ON COLUMN auth.verification_codes.target IS '目标（手机号或邮箱）';
COMMENT ON COLUMN auth.verification_codes.target_type IS '目标类型：phone-手机号, email-邮箱';
COMMENT ON COLUMN auth.verification_codes.used IS '是否已使用';
COMMENT ON COLUMN auth.verification_codes.used_at IS '使用时间';
COMMENT ON COLUMN auth.verification_codes.expires_at IS '过期时间';
COMMENT ON COLUMN auth.verification_codes.created_at IS '创建时间';
