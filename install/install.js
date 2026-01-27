// 安装向导JavaScript
let currentStep = 1;
let dbConfig = {};
let siteConfig = {};

// 初始化
document.addEventListener('DOMContentLoaded', function() {
    loadStep(1);
});

// 加载步骤
function loadStep(step) {
    currentStep = step;
    updateStepIndicator();
    
    const content = document.getElementById('install-content');
    
    switch(step) {
        case 1:
            loadEnvironmentCheck();
            break;
        case 2:
            loadDatabaseConfig();
            break;
        case 3:
            loadSiteConfig();
            break;
        case 4:
            loadDatabaseInstall();
            break;
        case 5:
            loadAdminCreate();
            break;
        case 6:
            loadFinish();
            break;
    }
}

// 更新步骤指示器
function updateStepIndicator() {
    document.querySelectorAll('.step').forEach((el, index) => {
        el.classList.remove('active', 'completed');
        if (index + 1 < currentStep) {
            el.classList.add('completed');
        } else if (index + 1 === currentStep) {
            el.classList.add('active');
        }
    });
}

// 步骤1：环境检查
function loadEnvironmentCheck() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>环境检查</h2>
        <p>正在检查系统环境...</p>
        <div class="loading"></div>
    `;
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=check_environment'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showEnvironmentResult(data);
        } else {
            showError('环境检查失败');
        }
    });
}

function showEnvironmentResult(data) {
    const content = document.getElementById('install-content');
    let html = '<h2>环境检查</h2>';
    
    html += '<ul class="check-list">';
    for (let key in data.checks) {
        const check = data.checks[key];
        const statusClass = check.status ? 'pass' : 'fail';
        const statusText = check.status ? '✓ 通过' : '✗ 失败';
        html += `
            <li class="check-item">
                <div>
                    <strong>${check.name}</strong><br>
                    <small>要求: ${check.required} | 当前: ${check.current}</small>
                </div>
                <div class="status ${statusClass}">${statusText}</div>
            </li>
        `;
    }
    html += '</ul>';
    
    if (data.all_passed) {
        if (data.config_exists) {
            html += '<div class="alert alert-success">检测到已有配置文件，将读取现有配置。</div>';
        }
        html += '<div class="button-group">';
        html += '<button class="btn btn-primary" onclick="loadStep(2)">下一步：配置数据库</button>';
        html += '</div>';
    } else {
        html += '<div class="alert alert-error">环境检查未通过，请先安装必需的扩展。</div>';
    }
    
    content.innerHTML = html;
}

// 步骤2：数据库配置
function loadDatabaseConfig() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>数据库配置</h2>
        <form id="db-config-form" class="config-form">
            <div class="form-section">
                <h3>PostgreSQL 数据库配置</h3>
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>数据库端口</label>
                    <input type="text" name="db_port" value="5432" required>
                </div>
                <div class="form-group">
                    <label>数据库名称</label>
                    <input type="text" name="db_name" value="auth_db" required>
                </div>
                <div class="form-group">
                    <label>数据库用户</label>
                    <input type="text" name="db_user" value="postgres" required>
                </div>
                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="db_password" required>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Redis 配置</h3>
                <div class="form-group">
                    <label>Redis 主机</label>
                    <input type="text" name="redis_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Redis 端口</label>
                    <input type="text" name="redis_port" value="6379" required>
                </div>
                <div class="form-group">
                    <label>Redis 密码（可选）</label>
                    <input type="password" name="redis_password">
                </div>
            </div>
            
            <div class="button-group">
                <button type="button" class="btn btn-secondary" onclick="loadStep(1)">上一步</button>
                <button type="button" class="btn btn-primary" onclick="testDatabaseConnection()">测试连接</button>
                <button type="button" class="btn btn-primary" onclick="saveDatabaseConfig()">保存并继续</button>
            </div>
        </form>
        <div id="db-test-result"></div>
    `;
}

// 测试数据库连接
function testDatabaseConnection() {
    const form = document.getElementById('db-config-form');
    const formData = new FormData(form);
    const resultDiv = document.getElementById('db-test-result');
    
    resultDiv.innerHTML = '<div class="loading"></div><p>正在测试连接...</p>';
    
    const data = {
        action: 'test_database',
        db_host: formData.get('db_host'),
        db_port: formData.get('db_port'),
        db_name: formData.get('db_name'),
        db_user: formData.get('db_user'),
        db_password: formData.get('db_password'),
        redis_host: formData.get('redis_host'),
        redis_port: formData.get('redis_port'),
        redis_password: formData.get('redis_password')
    };
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            resultDiv.innerHTML = '<div class="alert alert-success">' + result.message + '</div>';
            dbConfig = data;
        } else {
            resultDiv.innerHTML = '<div class="alert alert-error">' + result.message + '</div>';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = '<div class="alert alert-error">测试失败：' + err.message + '</div>';
    });
}

// 保存数据库配置
function saveDatabaseConfig() {
    const form = document.getElementById('db-config-form');
    const formData = new FormData(form);
    const resultDiv = document.getElementById('db-test-result');
    
    if (!dbConfig.db_host) {
        resultDiv.innerHTML = '<div class="alert alert-error">请先测试数据库连接</div>';
        return;
    }
    
    resultDiv.innerHTML = '<div class="loading"></div><p>正在保存配置...</p>';
    
    const data = {
        action: 'save_database_config',
        db_host: formData.get('db_host'),
        db_port: formData.get('db_port'),
        db_name: formData.get('db_name'),
        db_user: formData.get('db_user'),
        db_password: formData.get('db_password'),
        redis_host: formData.get('redis_host'),
        redis_port: formData.get('redis_port'),
        redis_password: formData.get('redis_password')
    };
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            resultDiv.innerHTML = '<div class="alert alert-success">' + result.message + '</div>';
            setTimeout(() => loadStep(3), 1000);
        } else {
            resultDiv.innerHTML = '<div class="alert alert-error">' + result.message + '</div>';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = '<div class="alert alert-error">保存失败：' + err.message + '</div>';
    });
}

// 步骤3：站点配置
function loadSiteConfig() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>站点配置</h2>
        <form id="site-config-form" class="config-form">
            <div class="form-section">
                <h3>站点信息</h3>
                <div class="form-group">
                    <label>站点协议</label>
                    <select name="site_protocol" required>
                        <option value="https">HTTPS（推荐）</option>
                        <option value="http">HTTP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>站点域名</label>
                    <input type="text" name="site_domain" value="auth.ywxmz.com" required placeholder="例如：auth.ywxmz.com">
                    <small>请输入完整域名，不包含协议和路径</small>
                </div>
                <div class="form-group">
                    <label>站点名称</label>
                    <input type="text" name="site_name" value="一碗小米周开放平台" required>
                </div>
            </div>
            
            <div class="alert alert-info">
                <p><strong>说明：</strong></p>
                <ul>
                    <li>系统将自动创建两个默认应用</li>
                    <li>回调地址将设置为：<code>{协议}://{域名}/user/callback</code></li>
                    <li>请确保域名可以正常访问</li>
                </ul>
            </div>
            
            <div class="button-group">
                <button type="button" class="btn btn-secondary" onclick="loadStep(2)">上一步</button>
                <button type="button" class="btn btn-primary" onclick="saveSiteConfig()">保存并继续</button>
            </div>
        </form>
        <div id="site-config-result"></div>
    `;
}

// 保存站点配置
function saveSiteConfig() {
    const form = document.getElementById('site-config-form');
    const formData = new FormData(form);
    const resultDiv = document.getElementById('site-config-result');
    
    const protocol = formData.get('site_protocol');
    const domain = formData.get('site_domain');
    const siteName = formData.get('site_name');
    
    // 验证域名格式
    if (!domain || domain.trim() === '') {
        resultDiv.innerHTML = '<div class="alert alert-error">请输入站点域名</div>';
        return;
    }
    
    // 保存到全局变量
    siteConfig = {
        site_protocol: protocol,
        site_domain: domain.trim(),
        site_name: siteName,
        site_url: protocol + '://' + domain.trim(),
        callback_url: protocol + '://' + domain.trim() + '/user/callback'
    };
    
    resultDiv.innerHTML = '<div class="alert alert-success">站点配置已保存</div>';
    setTimeout(() => loadStep(4), 1000);
}

// 步骤4：数据库安装
function loadDatabaseInstall() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>数据库安装</h2>
        <p>正在检查SQL文件...</p>
        <div class="loading"></div>
    `;
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=check_sql_files'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSqlCheckResult(data);
        } else {
            showError('SQL文件检查失败：' + data.message);
            if (data.missing_files) {
                content.innerHTML += '<ul>';
                data.missing_files.forEach(file => {
                    content.innerHTML += '<li>' + file + '</li>';
                });
                content.innerHTML += '</ul>';
            }
        }
    });
}

function showSqlCheckResult(data) {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>数据库安装</h2>
        <div class="alert alert-success">SQL文件检查通过，共 ${data.total_files} 个文件</div>
        <div id="install-progress">
            <p>准备安装数据表...</p>
        </div>
        <div class="button-group">
            <button class="btn btn-secondary" onclick="loadStep(3)">上一步</button>
            <button class="btn btn-primary" onclick="startDatabaseInstall()">开始安装</button>
        </div>
    `;
}

function startDatabaseInstall() {
    const progressDiv = document.getElementById('install-progress');
    progressDiv.innerHTML = '<div class="loading"></div><p>正在安装数据表，请稍候...</p>';
    
    const data = {
        action: 'install_database',
        site_protocol: siteConfig.site_protocol,
        site_domain: siteConfig.site_domain,
        site_name: siteConfig.site_name,
        site_url: siteConfig.site_url,
        callback_url: siteConfig.callback_url
    };
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            progressDiv.innerHTML = `
                <div class="alert alert-success">${result.message}</div>
                <p>已执行 ${result.executed_files.length} 个SQL文件</p>
            `;
            setTimeout(() => loadStep(5), 1500);
        } else {
            progressDiv.innerHTML = '<div class="alert alert-error">' + result.message + '</div>';
        }
    })
    .catch(err => {
        progressDiv.innerHTML = '<div class="alert alert-error">安装失败：' + err.message + '</div>';
    });
}

// 步骤5：创建管理员账号
function loadAdminCreate() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>创建管理员账号</h2>
        <form id="admin-form" class="config-form">
            <div class="form-group">
                <label>管理员账号（邮箱或手机号）</label>
                <input type="text" name="account" required placeholder="admin@example.com 或 13800138000">
            </div>
            <div class="form-group">
                <label>管理员密码</label>
                <input type="password" name="password" required minlength="8" placeholder="至少8位字符">
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="password_confirm" required minlength="8">
            </div>
            <div class="button-group">
                <button type="button" class="btn btn-secondary" onclick="loadStep(4)">上一步</button>
                <button type="button" class="btn btn-primary" onclick="createAdmin()">创建管理员</button>
            </div>
        </form>
        <div id="admin-result"></div>
    `;
}

function createAdmin() {
    const form = document.getElementById('admin-form');
    const formData = new FormData(form);
    const resultDiv = document.getElementById('admin-result');
    
    const account = formData.get('account');
    const password = formData.get('password');
    const passwordConfirm = formData.get('password_confirm');
    
    // 验证密码
    if (password !== passwordConfirm) {
        resultDiv.innerHTML = '<div class="alert alert-error">两次输入的密码不一致</div>';
        return;
    }
    
    if (password.length < 8) {
        resultDiv.innerHTML = '<div class="alert alert-error">密码长度至少8位</div>';
        return;
    }
    
    // 验证账号格式
    const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(account);
    const isPhone = /^1[3-9]\d{9}$/.test(account);
    
    if (!isEmail && !isPhone) {
        resultDiv.innerHTML = '<div class="alert alert-error">请输入有效的邮箱或手机号</div>';
        return;
    }
    
    resultDiv.innerHTML = '<div class="loading"></div><p>正在创建管理员账号...</p>';
    
    const data = {
        action: 'create_admin',
        account: account,
        password: password
    };
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            resultDiv.innerHTML = '<div class="alert alert-success">' + result.message + '</div>';
            setTimeout(() => loadStep(6), 1500);
        } else {
            resultDiv.innerHTML = '<div class="alert alert-error">' + result.message + '</div>';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = '<div class="alert alert-error">创建失败：' + err.message + '</div>';
    });
}

// 步骤6：完成安装
function loadFinish() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>完成安装</h2>
        <p>正在完成安装...</p>
        <div class="loading"></div>
    `;
    
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=finish_install'
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showFinishPage();
        } else {
            showError('完成安装失败：' + result.message);
        }
    });
}

function showFinishPage() {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>🎉 安装完成</h2>
        <div class="alert alert-success">
            <p><strong>恭喜！一碗小米周开放平台安装成功！</strong></p>
        </div>
        
        <div class="info-box">
            <h3>重要提示</h3>
            <ul>
                <li>系统已创建两个默认应用：
                    <ul>
                        <li><strong>DEFAULT_LOGIN_APP</strong> - 默认登录应用</li>
                        <li><strong>DEFAULT_USER_CENTER</strong> - 默认用户中心应用</li>
                    </ul>
                </li>
                <li>请立即修改这两个应用的 <code>app_secret</code>，确保系统安全</li>
                <li>建议删除或重命名 <code>install</code> 目录，防止重复安装</li>
                <li>请妥善保管管理员账号和密码</li>
            </ul>
        </div>
        
        <div class="info-box">
            <h3>下一步操作</h3>
            <ol>
                <li>访问用户中心：<a href="../user/" target="_blank">../user/</a></li>
                <li>访问登录页面：<a href="../login/" target="_blank">../login/</a></li>
                <li>配置第三方登录（QQ、微信、微博、GitHub、Google）</li>
                <li>配置短信服务和邮件服务</li>
                <li>配置人机验证服务</li>
            </ol>
        </div>
        
        <div class="button-group">
            <a href="../user/" class="btn btn-primary">进入用户中心</a>
            <a href="../login/" class="btn btn-secondary">进入登录页面</a>
        </div>
    `;
}

// 显示错误信息
function showError(message) {
    const content = document.getElementById('install-content');
    content.innerHTML = `
        <h2>安装错误</h2>
        <div class="alert alert-error">${message}</div>
        <div class="button-group">
            <button class="btn btn-primary" onclick="loadStep(1)">返回首页</button>
        </div>
    `;
}
