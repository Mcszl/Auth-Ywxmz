-- 系统日志表
-- 用于记录系统运行过程中的各种日志信息

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- 创建 logs Schema
CREATE SCHEMA IF NOT EXISTS logs;

-- 设置 Schema 注释
COMMENT ON SCHEMA logs IS '系统日志相关表';

-- 创建系统日志表
CREATE TABLE IF NOT EXISTS logs.system_logs (
    id BIGSERIAL PRIMARY KEY,
    log_level VARCHAR(20) NOT NULL,                    -- 日志级别：DEBUG, INFO, WARNING, ERROR, CRITICAL
    log_type VARCHAR(50) NOT NULL,                     -- 日志类型：captcha, sms, auth, database, api 等
    message TEXT NOT NULL,                             -- 日志消息
    context JSONB,                                     -- 上下文信息（JSON格式）
    stack_trace TEXT,                                  -- 堆栈跟踪（错误时）
    request_method VARCHAR(10),                        -- 请求方法：GET, POST 等
    request_uri TEXT,                                  -- 请求URI
    request_params JSONB,                              -- 请求参数
    client_ip VARCHAR(50),                             -- 客户端IP
    user_agent TEXT,                                   -- 用户代理
    user_id BIGINT,                                    -- 用户ID（如果有）
    session_id VARCHAR(100),                           -- 会话ID
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,  -- 创建时间
    created_by VARCHAR(100) DEFAULT 'system'           -- 创建者
);

-- 添加表注释
COMMENT ON TABLE logs.system_logs IS '系统日志表';

-- 添加字段注释
COMMENT ON COLUMN logs.system_logs.id IS '日志ID';
COMMENT ON COLUMN logs.system_logs.log_level IS '日志级别：DEBUG, INFO, WARNING, ERROR, CRITICAL';
COMMENT ON COLUMN logs.system_logs.log_type IS '日志类型：captcha, sms, auth, database, api 等';
COMMENT ON COLUMN logs.system_logs.message IS '日志消息';
COMMENT ON COLUMN logs.system_logs.context IS '上下文信息（JSON格式）';
COMMENT ON COLUMN logs.system_logs.stack_trace IS '堆栈跟踪（错误时）';
COMMENT ON COLUMN logs.system_logs.request_method IS '请求方法：GET, POST 等';
COMMENT ON COLUMN logs.system_logs.request_uri IS '请求URI';
COMMENT ON COLUMN logs.system_logs.request_params IS '请求参数';
COMMENT ON COLUMN logs.system_logs.client_ip IS '客户端IP';
COMMENT ON COLUMN logs.system_logs.user_agent IS '用户代理';
COMMENT ON COLUMN logs.system_logs.user_id IS '用户ID（如果有）';
COMMENT ON COLUMN logs.system_logs.session_id IS '会话ID';
COMMENT ON COLUMN logs.system_logs.created_at IS '创建时间';
COMMENT ON COLUMN logs.system_logs.created_by IS '创建者';

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_system_logs_log_level ON logs.system_logs(log_level);
CREATE INDEX IF NOT EXISTS idx_system_logs_log_type ON logs.system_logs(log_type);
CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON logs.system_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_system_logs_client_ip ON logs.system_logs(client_ip);
CREATE INDEX IF NOT EXISTS idx_system_logs_user_id ON logs.system_logs(user_id);

-- 创建 GIN 索引用于 JSONB 字段查询
CREATE INDEX IF NOT EXISTS idx_system_logs_context ON logs.system_logs USING GIN(context);
CREATE INDEX IF NOT EXISTS idx_system_logs_request_params ON logs.system_logs USING GIN(request_params);

-- 完成提示
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE '系统日志表创建完成！';
    RAISE NOTICE '========================================';
END $$;
