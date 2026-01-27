-- 头像审核表结构迁移脚本
-- 添加存储相关字段
-- 执行时间：2026-01-24

-- 设置时区为北京时间
SET timezone = 'Asia/Shanghai';

-- 检查并添加 new_avatar_filename 字段
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'checks' 
        AND table_name = 'avatar_check' 
        AND column_name = 'new_avatar_filename'
    ) THEN
        ALTER TABLE checks.avatar_check 
        ADD COLUMN new_avatar_filename VARCHAR(255);
        
        COMMENT ON COLUMN checks.avatar_check.new_avatar_filename IS '新头像文件名（用于从存储中获取）';
        
        RAISE NOTICE '已添加字段: new_avatar_filename';
    ELSE
        RAISE NOTICE '字段已存在: new_avatar_filename';
    END IF;
END $$;

-- 检查并添加 storage_type 字段
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'checks' 
        AND table_name = 'avatar_check' 
        AND column_name = 'storage_type'
    ) THEN
        ALTER TABLE checks.avatar_check 
        ADD COLUMN storage_type VARCHAR(20);
        
        COMMENT ON COLUMN checks.avatar_check.storage_type IS '存储类型：local-本地存储，s3-对象存储';
        
        RAISE NOTICE '已添加字段: storage_type';
    ELSE
        RAISE NOTICE '字段已存在: storage_type';
    END IF;
END $$;

-- 检查并添加 storage_config_id 字段
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'checks' 
        AND table_name = 'avatar_check' 
        AND column_name = 'storage_config_id'
    ) THEN
        ALTER TABLE checks.avatar_check 
        ADD COLUMN storage_config_id INTEGER;
        
        COMMENT ON COLUMN checks.avatar_check.storage_config_id IS '存储配置ID（关联site_configs.storage_config表）';
        
        RAISE NOTICE '已添加字段: storage_config_id';
    ELSE
        RAISE NOTICE '字段已存在: storage_config_id';
    END IF;
END $$;

-- 清理旧的审核记录（存储信息不完整的记录）
DO $$ 
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM checks.avatar_check 
    WHERE new_avatar_filename IS NULL 
       OR new_avatar_filename = ''
       OR storage_type IS NULL 
       OR storage_type = ''
       OR storage_config_id IS NULL 
       OR storage_config_id = 0;
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    
    IF deleted_count > 0 THEN
        RAISE NOTICE '已清理 % 条存储信息不完整的旧审核记录', deleted_count;
    ELSE
        RAISE NOTICE '没有需要清理的旧审核记录';
    END IF;
END $$;

-- 输出完成信息
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE '头像审核表结构迁移完成';
    RAISE NOTICE '========================================';
END $$;
