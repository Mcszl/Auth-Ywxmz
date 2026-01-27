# 一碗小米周开放平台 - 安装向导

## 功能说明

本安装向导提供了一个友好的图形界面，帮助您快速完成系统的初始化配置。

## 安装步骤

### 1. 环境检查
- 检查 PHP 版本（要求 7.4+）
- 检查 PDO 扩展
- 检查 PDO PostgreSQL 驱动
- 检查 Redis 扩展
- 检查 config 目录权限
- 检测是否已有配置文件

### 2. 数据库配置
- 配置 PostgreSQL 数据库连接信息
- 配置 Redis 连接信息
- 测试数据库连接
- 保存配置文件到 `config/` 目录

### 3. 站点配置
- 选择站点协议（HTTP/HTTPS）
- 输入站点域名（例如：auth.ywxmz.com）
- 输入站点名称
- 系统自动生成回调地址：`{协议}://{域名}/user/callback`

### 4. 数据库安装
- 检查 23 个 SQL 文件完整性
- 创建必要的 schema
- 按顺序执行所有 SQL 文件
- 根据站点配置动态创建默认应用
- 创建数据表和索引

### 5. 创建管理员账号
- 输入管理员账号（邮箱或手机号）
- 设置管理员密码（至少 8 位）
- 写入 `users.user` 和 `users.user_profile` 表

### 6. 完成安装
- 生成安装锁文件 `config/install.lock`
- 显示安全提示和下一步操作

## 使用方法

1. 访问安装页面：
   ```
   http://your-domain/auth/install/
   ```

2. 按照页面提示完成 6 个步骤

3. 安装完成后，建议：
   - 删除或重命名 `install` 目录
   - 修改默认应用的 `secret_key`
   - 配置第三方登录服务

## 默认应用

安装完成后会根据您输入的站点信息自动创建两个默认应用：

### DEFAULT_LOGIN_APP
- **用途**：默认登录应用
- **站点地址**：您输入的站点域名和协议
- **回调地址**：`{协议}://{域名}/user/callback`
- **权限**：user.basic, user.email, user.phone
- **功能**：启用登录、注册、所有第三方登录

### DEFAULT_USER_CENTER
- **用途**：默认用户中心应用
- **站点地址**：您输入的站点域名和协议
- **回调地址**：`{协议}://{域名}/user/callback`
- **权限**：user.basic, user.email, user.phone, user.profile
- **功能**：仅启用登录（密码登录和验证码登录）

⚠️ **安全提示**：
- 请立即修改这两个应用的 `secret_key`，确保系统安全！
- 两个应用的 `app_id` 分别为：`DEFAULT_LOGIN_APP` 和 `DEFAULT_USER_CENTER`
- 回调地址已根据您的站点配置自动设置

## 文件说明

- `index.php` - 主安装页面和后端处理
- `install_handler.php` - 安装逻辑处理类
- `install.js` - 前端交互逻辑
- `install.css` - 样式文件

## 技术栈

- **后端**：PHP 7.4+, PDO, PostgreSQL
- **前端**：原生 JavaScript, CSS3
- **缓存**：Redis
- **数据库**：PostgreSQL 12+

## 常见问题

### 1. 环境检查失败
- 确保 PHP 版本 >= 7.4
- 安装 PDO 和 pdo_pgsql 扩展
- 安装 Redis 扩展
- 确保 config 目录可写

### 2. 数据库连接失败
- 检查 PostgreSQL 服务是否启动
- 确认数据库名称、用户名、密码正确
- 检查防火墙设置
- 确认 PostgreSQL 允许远程连接

### 3. Redis 连接失败
- 检查 Redis 服务是否启动
- 确认 Redis 端口和密码正确
- 检查防火墙设置

### 4. SQL 文件缺失
- 下载完整的安装包
- 确保 `sql/` 目录包含所有 23 个 SQL 文件

### 5. 重复安装
- 如果需要重新安装，删除 `config/install.lock` 文件
- 清空数据库或使用新的数据库

## 安全建议

1. 安装完成后立即删除或重命名 `install` 目录
2. 修改默认应用的 `app_secret`
3. 使用强密码作为管理员密码
4. 定期备份数据库
5. 启用 HTTPS
6. 配置防火墙规则

## 技术支持

如有问题，请联系技术支持或查看项目文档。
