// 管理后台脚本

// 全局变量
let adminInfo = null;
let permissionCheckInterval = null;
let allAppsData = []; // 存储所有应用数据，用于更改默认应用弹窗

// ============================================
// 工具函数
// ============================================

/**
 * HTML转义函数，防止XSS攻击
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

/**
 * 显示Toast提示
 */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icon = type === 'success' ? 'check-circle' : 
                 type === 'error' ? 'times-circle' : 
                 type === 'warning' ? 'exclamation-triangle' : 'info-circle';
    
    toast.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    // 显示动画
    setTimeout(() => toast.classList.add('show'), 10);
    
    // 3秒后自动关闭
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * 显示确认对话框
 * @param {string} message - 确认消息
 * @param {function} onConfirm - 确认回调函数
 * @param {function} onCancel - 取消回调函数（可选）
 */
function showConfirmDialog(message, onConfirm, onCancel = null) {
    const modal = document.getElementById('confirm-dialog-modal');
    if (!modal) {
        console.error('确认对话框元素不存在');
        return;
    }
    
    // 设置消息
    document.getElementById('confirm-dialog-message').textContent = message;
    
    // 移除旧的事件监听器
    const confirmBtn = document.getElementById('confirm-dialog-confirm');
    const cancelBtn = document.getElementById('confirm-dialog-cancel');
    
    const newConfirmBtn = confirmBtn.cloneNode(true);
    const newCancelBtn = cancelBtn.cloneNode(true);
    
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
    
    // 添加新的事件监听器
    newConfirmBtn.addEventListener('click', () => {
        modal.classList.remove('show');
        if (onConfirm) onConfirm();
    });
    
    newCancelBtn.addEventListener('click', () => {
        modal.classList.remove('show');
        if (onCancel) onCancel();
    });
    
    // 显示弹窗
    modal.classList.add('show');
}

document.addEventListener('DOMContentLoaded', function() {
    init();
});

async function init() {
    // 检查管理员权限
    await checkAdminPermission();
    
    // 加载管理员信息
    await loadAdminInfo();
    
    // 初始化导航
    initNavigation();
    
    // 初始化导航组
    initNavGroups();
    
    // 初始化退出登录
    initLogout();
    
    // 初始化侧边栏折叠
    initSidebarToggle();
    
    // 初始化用户列表筛选器
    initUserListFilters();
    
    // 初始化管理员列表筛选器
    initAdminListFilters();
    
    // 初始化邮件配置筛选器
    initEmailConfigFilters();
    
    // 初始化授权应用管理
    initOAuthApps();
    
    // 加载站点概览数据
    loadOverviewStats();
    
    // 启动定时权限检查（每3分钟）
    startPermissionCheck();
    
    // 全局防护：监听所有点击事件，在点击时检查搜索框
    document.addEventListener('click', function(e) {
        // 延迟检查，确保浏览器的自动填充已经触发
        setTimeout(function() {
            const searchInput = document.getElementById('oauth-app-search');
            if (searchInput && searchInput.value && searchInput.value.includes('@')) {
                console.warn('全局点击检测：搜索框被填充邮箱，已清空:', searchInput.value);
                searchInput.value = '';
                if (typeof oauthAppSearchKeyword !== 'undefined') {
                    oauthAppSearchKeyword = '';
                }
            }
        }, 10);
    }, true); // 使用捕获阶段
}

/**
 * 启动定时权限检查
 */
function startPermissionCheck() {
    // 清除已存在的定时器
    if (permissionCheckInterval) {
        clearInterval(permissionCheckInterval);
    }
    
    // 每3分钟检查一次权限
    permissionCheckInterval = setInterval(async () => {
        console.log('执行定时权限检查...');
        await checkAdminPermission(true);
    }, 3 * 60 * 1000); // 3分钟 = 180000毫秒
}

/**
 * 检查管理员权限
 */
async function checkAdminPermission(isSilent = false) {
    try {
        // 调用管理员权限检查 API
        const response = await fetch('/admin/api/CheckAdminPermission.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            // 权限检查失败
            if (!isSilent) {
                showError(result.message || '权限验证失败', '权限不足');
            }
            
            // 延迟跳转到登录页
            setTimeout(() => {
                window.location.href = '/user/login.php';
            }, 2000);
            return false;
        }
        
        return true;
        
    } catch (error) {
        console.error('检查管理员权限失败:', error);
        
        if (!isSilent) {
            showError('网络错误，请稍后重试');
        }
        
        // 网络错误也跳转到登录页
        setTimeout(() => {
            window.location.href = '/user/login.php';
        }, 2000);
        return false;
    }
}

/**
 * 加载管理员信息
 */
async function loadAdminInfo() {
    try {
        const response = await fetch('/user/api/GetUserInfo.php', {
            method: 'GET',
            credentials: 'include'
        });
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载管理员信息失败');
            return;
        }
        
        adminInfo = result.data;
        
        // 更新页面显示
        updateAdminDisplay();
        
    } catch (error) {
        console.error('加载管理员信息失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 更新管理员显示
 */
function updateAdminDisplay() {
    if (!adminInfo) return;
    
    // 更新导航栏
    const navAvatar = document.getElementById('nav-avatar');
    const navUsername = document.getElementById('nav-username');
    
    if (navAvatar) {
        navAvatar.src = adminInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
        navAvatar.onerror = function() {
            this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
            this.onerror = null; // 防止无限循环
        };
    }
    
    if (navUsername) {
        navUsername.textContent = adminInfo.nickname || adminInfo.username;
    }
}

/**
 * 初始化导航
 */
function initNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    const pages = document.querySelectorAll('.page-content');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            const pageName = this.getAttribute('data-page');
            
            // 更新导航状态
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            
            // 展开父级导航组
            const parentGroup = this.closest('.nav-group');
            if (parentGroup) {
                parentGroup.classList.add('expanded');
            }
            
            // 切换页面
            pages.forEach(page => page.classList.remove('active'));
            const targetPage = document.getElementById(`page-${pageName}`);
            if (targetPage) {
                targetPage.classList.add('active');
                
                // 根据页面加载对应数据
                loadPageData(pageName);
            }
            
            // 更新 URL hash
            window.location.hash = pageName;
        });
    });
    
    // 根据 URL hash 显示对应页面
    const hash = window.location.hash.substring(1);
    if (hash) {
        const targetNav = document.querySelector(`[data-page="${hash}"]`);
        if (targetNav) {
            targetNav.click();
        }
    }
}

/**
 * 初始化导航组
 */
function initNavGroups() {
    const navGroups = document.querySelectorAll('.nav-group');
    
    navGroups.forEach(group => {
        const title = group.querySelector('.nav-group-title');
        
        if (title) {
            title.addEventListener('click', function() {
                // 切换展开/收起状态
                group.classList.toggle('expanded');
            });
        }
    });
}

/**
 * 初始化侧边栏折叠
 */
function initSidebarToggle() {
    const toggleBtn = document.getElementById('btn-toggle-sidebar');
    const sidebar = document.querySelector('.sidebar');
    
    if (toggleBtn && sidebar) {
        // 从localStorage读取折叠状态
        const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            
            // 保存折叠状态到localStorage
            const collapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebar-collapsed', collapsed);
        });
    }
}

/**
 * 初始化退出登录
 */
function initLogout() {
    const logoutBtn = document.getElementById('btn-logout');
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async function() {
            const confirmed = await showConfirm(
                '退出登录',
                '确定要退出登录吗？'
            );
            
            if (!confirmed) {
                return;
            }
            
            try {
                // 调用退出登录 API
                const response = await fetch('/user/api/Logout.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 显示提示
                    showSuccessToast('已退出登录', '退出成功');
                    
                    // 跳转到登录页
                    setTimeout(() => {
                        window.location.href = '/user/login.php';
                    }, 1500);
                } else {
                    showError(result.message || '退出登录失败');
                }
            } catch (error) {
                console.error('退出登录失败:', error);
                showError('网络错误，请稍后重试');
            }
        });
    }
}

/**
 * 加载页面数据
 */
function loadPageData(pageName) {
    switch (pageName) {
        case 'overview':
            loadOverviewStats();
            break;
        case 'user-list':
            loadUserList();
            break;
        case 'admin-list':
            loadAdminList();
            break;
        case 'email-config':
            loadEmailConfigList();
            break;
        case 'email-template':
            initEmailTemplateFilters();
            loadEmailTemplateList();
            break;
        case 'sms-config':
            initSmsConfigFilters();
            loadSmsConfigList();
            break;
        case 'sms-limit-config':
            initSmsLimitConfigTabs();
            loadSendLimitList();
            break;
        case 'captcha-config':
            initCaptchaConfigFilters();
            loadCaptchaConfigList();
            break;
        case 'third-party-login-config':
            initThirdPartyLoginConfigFilters();
            loadThirdPartyLoginConfigList();
            break;
        case 'nickname-check-config':
            loadNicknameCheckConfig();
            initSensitiveWordFilters();
            loadSensitiveWordList();
            break;
        case 'avatar-check-config':
            loadAvatarCheckConfig();
            break;
        case 'storage-config':
            loadStorageConfigList();
            break;
        case 'nickname-review':
            loadNicknameCheckList();
            break;
        case 'avatar-review':
            initAvatarCheckFilters();
            loadAvatarCheckList();
            break;
        case 'oauth-apps':
            loadOAuthApps();
            break;
        case 'user-center-apps':
            loadUserCenterConfig();
            break;
        case 'captcha-logs':
            initCaptchaLogFilters();
            loadCaptchaLogs();
            break;
        case 'system-logs':
            initSystemLogFilters();
            loadSystemLogs();
            break;
        case 'sms-logs':
            initSmsLogFilters();
            loadSmsLogs();
            break;
        case 'email-logs':
            initEmailLogFilters();
            loadEmailLogs();
            break;
        case 'login-logs':
            initLoginLogFilters();
            loadLoginLogs();
            break;
        // 其他页面...
    }
}

/**
 * 加载用户列表
 */
let currentPage = 1;
let currentPageSize = 20;
let currentSearch = '';
let currentUserType = '';
let currentStatus = '';

async function loadUserList(page = 1) {
    currentPage = page;
    
    const loadingEl = document.getElementById('user-list-loading');
    const emptyEl = document.getElementById('user-list-empty');
    const tableEl = document.getElementById('user-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentPage,
            page_size: currentPageSize
        });
        
        if (currentSearch) params.append('search', currentSearch);
        if (currentUserType) params.append('user_type', currentUserType);
        if (currentStatus !== '') params.append('status', currentStatus);
        
        const response = await fetch(`/admin/api/GetUserList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showError(result.message || '加载用户列表失败');
            emptyEl.style.display = 'block';
            return;
        }
        
        const users = result.data.users || [];
        const pagination = result.data.pagination;
        
        if (users.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示用户列表
            tableEl.style.display = 'block';
            renderUserList(users);
            renderPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载用户列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showError('网络错误，请稍后重试');
    }
}

/**
 * 渲染用户列表
 */
function renderUserList(users) {
    const tbody = document.getElementById('user-list-tbody');
    tbody.innerHTML = '';
    
    users.forEach(user => {
        const tr = document.createElement('tr');
        
        // 用户类型文本
        const userTypeText = {
            'user': '普通用户',
            'admin': '管理员',
            'siteadmin': '站点管理员'
        }[user.user_type] || '未知';
        
        // 状态文本
        const statusText = {
            0: '已封禁',
            1: '正常',
            2: '手机号待核验',
            3: '邮箱待核验'
        }[user.status] || '未知';
        
        // 状态样式类
        const statusClass = {
            0: 'status-banned',
            1: 'status-normal',
            2: 'status-pending',
            3: 'status-pending'
        }[user.status] || '';
        
        tr.innerHTML = `
            <td>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <img src="${user.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png'}" 
                         alt="${user.username}" 
                         class="user-avatar-small"
                         onerror="this.src='https://avatar.ywxmz.com/user-6380868_1920.png';this.onerror=null;">
                    <span>${user.username}</span>
                </div>
            </td>
            <td>${user.nickname || '-'}</td>
            <td>
                ${user.phone ? `<div><i class="fas fa-phone"></i> ${user.phone}</div>` : ''}
                ${user.email ? `<div><i class="fas fa-envelope"></i> ${user.email}</div>` : ''}
                ${!user.phone && !user.email ? '-' : ''}
            </td>
            <td>
                <span class="user-type-badge type-${user.user_type}">${userTypeText}</span>
            </td>
            <td>
                <span class="user-status-badge ${statusClass}">${statusText}</span>
            </td>
            <td>${formatDateTime(user.created_at)}</td>
            <td>${user.last_login_at ? formatDateTime(user.last_login_at) : '从未登录'}</td>
            <td>
                <button class="btn-action" onclick="viewUser('${user.uuid}')">
                    <i class="fas fa-eye"></i> 查看
                </button>
                ${user.status === 1 ? 
                    `<button class="btn-action danger" onclick="banUser('${user.uuid}', '${user.username}')">
                        <i class="fas fa-ban"></i> 封禁
                    </button>` :
                    `<button class="btn-action" onclick="unbanUser('${user.uuid}', '${user.username}')">
                        <i class="fas fa-check"></i> 解封
                    </button>`
                }
                <button class="btn-action danger" onclick="deleteUser('${user.uuid}', '${user.username}')">
                    <i class="fas fa-trash"></i> 删除
                </button>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染分页
 */
function renderPagination(pagination) {
    const container = document.getElementById('user-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadUserList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadUserList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 加载管理员列表
 */
let currentAdminPage = 1;
let currentAdminPageSize = 20;
let currentAdminSearch = '';

async function loadAdminList(page = 1) {
    currentAdminPage = page;
    
    const loadingEl = document.getElementById('admin-list-loading');
    const emptyEl = document.getElementById('admin-list-empty');
    const tableEl = document.getElementById('admin-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentAdminPage,
            page_size: currentAdminPageSize
        });
        
        if (currentAdminSearch) params.append('search', currentAdminSearch);
        
        const response = await fetch(`/admin/api/GetAdminList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showError(result.message || '加载管理员列表失败');
            emptyEl.style.display = 'block';
            return;
        }
        
        const admins = result.data.admins || [];
        const pagination = result.data.pagination;
        
        if (admins.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示管理员列表
            tableEl.style.display = 'block';
            renderAdminList(admins);
            renderAdminPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载管理员列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showError('网络错误，请稍后重试');
    }
}

/**
 * 渲染管理员列表
 */
function renderAdminList(admins) {
    const tbody = document.getElementById('admin-list-tbody');
    tbody.innerHTML = '';
    
    admins.forEach(admin => {
        const tr = document.createElement('tr');
        
        // 用户类型文本
        const userTypeText = {
            'admin': '管理员',
            'siteadmin': '站点管理员'
        }[admin.user_type] || '未知';
        
        // 状态文本
        const statusText = {
            0: '已封禁',
            1: '正常',
            2: '手机号待核验',
            3: '邮箱待核验'
        }[admin.status] || '未知';
        
        // 状态样式类
        const statusClass = {
            0: 'status-banned',
            1: 'status-normal',
            2: 'status-pending',
            3: 'status-pending'
        }[admin.status] || '';
        
        tr.innerHTML = `
            <td>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <img src="${admin.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png'}" 
                         alt="${admin.username}" 
                         class="user-avatar-small"
                         onerror="this.src='https://avatar.ywxmz.com/user-6380868_1920.png';this.onerror=null;">
                    <span>${admin.username}</span>
                </div>
            </td>
            <td>${admin.nickname || '-'}</td>
            <td>
                ${admin.phone ? `<div><i class="fas fa-phone"></i> ${admin.phone}</div>` : ''}
                ${admin.email ? `<div><i class="fas fa-envelope"></i> ${admin.email}</div>` : ''}
                ${!admin.phone && !admin.email ? '-' : ''}
            </td>
            <td>
                <span class="user-type-badge type-${admin.user_type}">${userTypeText}</span>
            </td>
            <td>
                <span class="user-status-badge ${statusClass}">${statusText}</span>
            </td>
            <td>${formatDateTime(admin.created_at)}</td>
            <td>${admin.last_login_at ? formatDateTime(admin.last_login_at) : '从未登录'}</td>
            <td>
                <button class="btn-action" onclick="viewUser('${admin.uuid}')">
                    <i class="fas fa-eye"></i> 查看
                </button>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染管理员分页
 */
function renderAdminPagination(pagination) {
    const container = document.getElementById('admin-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadAdminList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadAdminList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 初始化管理员列表搜索
 */
function initAdminListFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-admin-search');
    const searchInput = document.getElementById('admin-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentAdminSearch = searchInput.value.trim();
            loadAdminList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentAdminSearch = searchInput.value.trim();
                loadAdminList(1);
            }
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-admin-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentAdminSearch = '';
            searchInput.value = '';
            loadAdminList(1);
        });
    }
}

/**
 * 初始化用户列表搜索和筛选
 */
function initUserListFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-user-search');
    const searchInput = document.getElementById('user-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentSearch = searchInput.value.trim();
            loadUserList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentSearch = searchInput.value.trim();
                loadUserList(1);
            }
        });
    }
    
    // 用户类型筛选
    const filterUserType = document.getElementById('filter-user-type');
    if (filterUserType) {
        filterUserType.addEventListener('change', function() {
            currentUserType = this.value;
            loadUserList(1);
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentStatus = this.value;
            loadUserList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentSearch = '';
            currentUserType = '';
            currentStatus = '';
            searchInput.value = '';
            filterUserType.value = '';
            filterStatus.value = '';
            loadUserList(1);
        });
    }
}

/**
 * 查看用户详情
 */
let currentUserUuid = null;

async function viewUser(uuid) {
    currentUserUuid = uuid;
    
    // 显示弹出框
    const modal = document.getElementById('user-detail-modal');
    const loading = document.getElementById('user-detail-loading');
    const content = document.getElementById('user-detail-content');
    
    modal.classList.add('show');
    loading.style.display = 'block';
    content.style.display = 'none';
    
    try {
        // 调用 API 获取用户详情
        const response = await fetch(`/admin/api/GetUserDetail.php?uuid=${uuid}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '获取用户详情失败');
            closeUserDetailModal();
            return;
        }
        
        // 显示用户详情
        displayUserDetail(result.data);
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (error) {
        console.error('获取用户详情失败:', error);
        showError('网络错误，请稍后重试');
        closeUserDetailModal();
    }
}

/**
 * 显示用户详情
 */
function displayUserDetail(user) {
    // 头像和基本信息
    const detailAvatar = document.getElementById('detail-avatar');
    detailAvatar.src = user.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
    detailAvatar.onerror = function() {
        this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
        this.onerror = null; // 防止无限循环
    };
    document.getElementById('detail-nickname').textContent = user.nickname || user.username;
    document.getElementById('detail-username').textContent = user.username;
    
    // 用户类型标签
    const typeText = {
        'user': '普通用户',
        'admin': '管理员',
        'siteadmin': '站点管理员'
    }[user.user_type] || '未知';
    
    const typeBadge = document.getElementById('detail-type-badge');
    typeBadge.textContent = typeText;
    typeBadge.className = `user-type-badge type-${user.user_type}`;
    
    // 状态标签
    const statusText = {
        0: '已封禁',
        1: '正常',
        2: '手机号待核验',
        3: '邮箱待核验'
    }[user.status] || '未知';
    
    const statusClass = {
        0: 'status-banned',
        1: 'status-normal',
        2: 'status-pending',
        3: 'status-pending'
    }[user.status] || '';
    
    const statusBadge = document.getElementById('detail-status-badge');
    statusBadge.textContent = statusText;
    statusBadge.className = `user-status-badge ${statusClass}`;
    
    // 表单字段
    document.getElementById('detail-uuid').value = user.uuid;
    document.getElementById('detail-username-input').value = user.username;
    document.getElementById('detail-nickname-input').value = user.nickname || '';
    
    // 用户类型选择框 - 根据权限控制
    const userTypeSelect = document.getElementById('detail-user-type');
    userTypeSelect.innerHTML = '';
    
    // 根据权限添加选项
    const permissions = user.permissions || {};
    
    userTypeSelect.innerHTML = `
        <option value="user">普通用户</option>
        ${permissions.can_set_admin ? '<option value="admin">管理员</option>' : ''}
        <option value="siteadmin">站点管理员</option>
    `;
    
    userTypeSelect.value = user.user_type;
    
    // 如果是ID为1的用户，禁用用户类型修改
    if (permissions.is_user_id_1) {
        userTypeSelect.disabled = true;
    } else {
        userTypeSelect.disabled = false;
    }
    
    document.getElementById('detail-phone').value = user.phone || '';
    document.getElementById('detail-email').value = user.email || '';
    document.getElementById('detail-gender').value = user.gender || '';
    document.getElementById('detail-birth-date').value = user.birth_date || '';
    document.getElementById('detail-status').value = user.status;
    document.getElementById('detail-authorized-count').value = user.authorized_count || 0;
    
    // 注册信息
    document.getElementById('detail-created-at').value = formatDateTime(user.created_at);
    document.getElementById('detail-register-ip').value = user.register_ip || '-';
    document.getElementById('detail-last-login').value = user.last_login_at ? formatDateTime(user.last_login_at) : '从未登录';
    document.getElementById('detail-last-ip').value = user.last_login_ip || '-';
    
    // 根据权限控制保存按钮
    const saveBtn = document.getElementById('btn-save-user');
    if (!permissions.can_edit) {
        saveBtn.style.display = 'none';
    } else {
        saveBtn.style.display = 'flex';
    }
}

/**
 * 关闭用户详情弹出框
 */
function closeUserDetailModal() {
    const modal = document.getElementById('user-detail-modal');
    modal.classList.remove('show');
    currentUserUuid = null;
}

/**
 * 保存用户修改
 */
async function saveUserChanges() {
    if (!currentUserUuid) {
        showError('无效的用户UUID');
        return;
    }
    
    // 获取表单数据
    const data = {
        uuid: currentUserUuid,
        nickname: document.getElementById('detail-nickname-input').value.trim(),
        phone: document.getElementById('detail-phone').value.trim(),
        email: document.getElementById('detail-email').value.trim(),
        user_type: document.getElementById('detail-user-type').value,
        status: parseInt(document.getElementById('detail-status').value),
        gender: document.getElementById('detail-gender').value,
        birth_date: document.getElementById('detail-birth-date').value
    };
    
    // 禁用保存按钮
    const saveBtn = document.getElementById('btn-save-user');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    try {
        const response = await fetch('/admin/api/UpdateUser.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '保存失败');
            return;
        }
        
        showSuccessToast('用户信息已更新', '保存成功');
        
        // 关闭弹出框
        closeUserDetailModal();
        
        // 刷新用户列表
        loadUserList(currentPage);
        
    } catch (error) {
        console.error('保存用户信息失败:', error);
        showError('网络错误，请稍后重试');
    } finally {
        // 恢复保存按钮
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

/**
 * 显示确认弹窗
 */
function showConfirm(title, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('confirm-modal');
        const titleEl = document.getElementById('confirm-title');
        const messageEl = document.getElementById('confirm-message');
        const cancelBtn = document.getElementById('confirm-cancel');
        const okBtn = document.getElementById('confirm-ok');
        
        titleEl.textContent = title;
        messageEl.textContent = message;
        
        modal.classList.add('show');
        
        // 取消按钮
        const handleCancel = () => {
            modal.classList.remove('show');
            cancelBtn.removeEventListener('click', handleCancel);
            okBtn.removeEventListener('click', handleOk);
            resolve(false);
        };
        
        // 确定按钮
        const handleOk = () => {
            modal.classList.remove('show');
            cancelBtn.removeEventListener('click', handleCancel);
            okBtn.removeEventListener('click', handleOk);
            resolve(true);
        };
        
        cancelBtn.addEventListener('click', handleCancel);
        okBtn.addEventListener('click', handleOk);
    });
}

/**
 * 封禁用户
 */
async function banUser(uuid, username) {
    const confirmed = await showConfirm(
        '封禁用户',
        `确定要封禁用户 "${username}" 吗？\n\n封禁后该用户将无法登录系统。`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/BanUser.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                uuid: uuid,
                action: 'ban'
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '封禁失败');
            return;
        }
        
        showSuccessToast(`用户 "${username}" 已被封禁`, '封禁成功');
        
        // 刷新用户列表
        loadUserList(currentPage);
        
    } catch (error) {
        console.error('封禁用户失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解封用户
 */
async function unbanUser(uuid, username) {
    const confirmed = await showConfirm(
        '解封用户',
        `确定要解封用户 "${username}" 吗？\n\n解封后该用户将恢复正常状态。`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/BanUser.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                uuid: uuid,
                action: 'unban'
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '解封失败');
            return;
        }
        
        showSuccessToast(`用户 "${username}" 已解封`, '解封成功');
        
        // 刷新用户列表
        loadUserList(currentPage);
        
    } catch (error) {
        console.error('解封用户失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 删除用户
 */
async function deleteUser(uuid, username) {
    const confirmed = await showConfirm(
        '删除用户',
        `确定要删除用户 "${username}" 吗？\n\n此操作不可恢复，将永久删除该用户的所有数据！`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/DeleteUser.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                uuid: uuid
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '删除失败');
            return;
        }
        
        showSuccessToast(`用户 "${username}" 已被删除`, '删除成功');
        
        // 刷新用户列表
        loadUserList(currentPage);
        
    } catch (error) {
        console.error('删除用户失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 加载站点概览统计数据
 */
async function loadOverviewStats() {
    try {
        const response = await fetch('/admin/api/GetStatistics.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载统计数据失败');
            return;
        }
        
        const stats = result.data;
        
        // 更新显示
        document.getElementById('stat-total-users').textContent = stats.total_users || 0;
        document.getElementById('stat-today-register').textContent = stats.today_register || 0;
        document.getElementById('stat-today-login').textContent = stats.today_login || 0;
        document.getElementById('stat-total-apps').textContent = stats.total_apps || 0;
        document.getElementById('stat-pending-avatars').textContent = stats.pending_avatars || 0;
        document.getElementById('stat-pending-nicknames').textContent = stats.pending_nicknames || 0;
        
    } catch (error) {
        console.error('加载统计数据失败:', error);
        showError('加载统计数据失败');
    }
}

/**
 * 显示错误信息
 */
function showError(message, title = '错误') {
    showErrorToast(message, title);
}

/**
 * 显示成功 Toast
 */
function showSuccessToast(message, title = '成功') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast success';
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(toast);
    
    // 显示动画
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // 5秒后自动消失
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
}

/**
 * 显示错误 Toast
 */
function showErrorToast(message, title = '错误') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast error';
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(toast);
    
    // 显示动画
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // 5秒后自动消失
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
}

/**
 * 显示信息 Toast
 */
function showInfo(message, title = '提示') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast info';
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(toast);
    
    // 显示动画
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // 5秒后自动消失
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
}

/**
 * 格式化日期
 */
function formatDate(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
}

/**
 * 格式化日期时间
 */
function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return '-';
    const date = new Date(dateTimeStr);
    
    // 检查日期是否有效
    if (isNaN(date.getTime())) return '-';
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

// ============================================
// 邮件配置管理
// ============================================

let currentEmailConfigPage = 1;
let currentEmailConfigPageSize = 20;
let currentEmailConfigSearch = '';
let currentEmailConfigStatus = '';

/**
 * 初始化邮件配置筛选器
 */
function initEmailConfigFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-email-config-search');
    const searchInput = document.getElementById('email-config-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentEmailConfigSearch = searchInput.value.trim();
            loadEmailConfigList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentEmailConfigSearch = searchInput.value.trim();
                loadEmailConfigList(1);
            }
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-email-config-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentEmailConfigStatus = this.value;
            loadEmailConfigList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-email-config-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentEmailConfigSearch = '';
            currentEmailConfigStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterStatus) filterStatus.value = '';
            loadEmailConfigList(1);
        });
    }
    
    // 新增按钮
    const btnAdd = document.getElementById('btn-add-email-config');
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            showEmailConfigModal();
        });
    }
    
    // 保存按钮
    const btnSave = document.getElementById('btn-save-email-config');
    if (btnSave) {
        btnSave.addEventListener('click', function() {
            saveEmailConfig();
        });
    }
    
    // 监听签名启用状态变化
    const enableSignatureSelect = document.getElementById('email-config-enable-signature');
    if (enableSignatureSelect) {
        enableSignatureSelect.addEventListener('change', function() {
            toggleSignatureFields();
        });
    }
}

/**
 * 切换签名字段显示/隐藏
 */
function toggleSignatureFields() {
    const enableSignature = document.getElementById('email-config-enable-signature').value === '1';
    const signatureFields = document.getElementById('signature-fields');
    
    if (enableSignature) {
        signatureFields.style.display = 'block';
    } else {
        signatureFields.style.display = 'none';
    }
}

/**
 * 加载邮件配置列表
 */
async function loadEmailConfigList(page = 1) {
    currentEmailConfigPage = page;
    
    const loadingEl = document.getElementById('email-config-list-loading');
    const emptyEl = document.getElementById('email-config-list-empty');
    const tableEl = document.getElementById('email-config-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentEmailConfigPage,
            page_size: currentEmailConfigPageSize
        });
        
        if (currentEmailConfigSearch) params.append('search', currentEmailConfigSearch);
        if (currentEmailConfigStatus !== '') params.append('status', currentEmailConfigStatus);
        
        const response = await fetch(`/admin/api/GetEmailConfigList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showError(result.message || '加载邮件配置列表失败');
            emptyEl.style.display = 'block';
            return;
        }
        
        const configs = result.data.configs || [];
        const pagination = result.data.pagination;
        
        if (configs.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示配置列表
            tableEl.style.display = 'block';
            renderEmailConfigList(configs);
            renderEmailConfigPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载邮件配置列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showError('网络错误，请稍后重试');
    }
}

/**
 * 渲染邮件配置列表
 */
function renderEmailConfigList(configs) {
    const tbody = document.getElementById('email-config-list-tbody');
    tbody.innerHTML = '';
    
    configs.forEach(config => {
        const tr = document.createElement('tr');
        
        // 状态文本
        const statusText = {
            0: '禁用',
            1: '正常',
            2: '维护中'
        }[config.status] || '未知';
        
        // 状态样式类
        const statusClass = {
            0: 'status-banned',
            1: 'status-normal',
            2: 'status-pending'
        }[config.status] || '';
        
        // 适用场景
        const scenes = Array.isArray(config.scenes) ? config.scenes : [];
        const sceneText = scenes.map(scene => {
            const sceneMap = {
                'register': '注册',
                'login': '登录',
                'reset_password': '重置密码',
                'security_alert': '安全警报'
            };
            return sceneMap[scene] || scene;
        }).join(', ') || '-';
        
        tr.innerHTML = `
            <td>${config.id}</td>
            <td>${config.config_name}</td>
            <td>${config.email}</td>
            <td>${config.sender_name}</td>
            <td>${config.smtp_host}:${config.smtp_port}</td>
            <td>${sceneText}</td>
            <td>${config.daily_limit}</td>
            <td>${config.daily_sent_count}</td>
            <td>
                <div style="display: flex; gap: 5px; align-items: center; flex-wrap: nowrap; white-space: nowrap;">
                    <span class="user-status-badge ${statusClass}">${statusText}</span>
                    ${config.is_enabled ? '<span class="badge-success">已启用</span>' : '<span class="badge-secondary">未启用</span>'}
                </div>
            </td>
            <td>${config.priority}</td>
            <td>
                <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                    <button class="btn-action" onclick="viewEmailConfig(${config.id})">
                        <i class="fas fa-eye"></i> 查看
                    </button>
                    <button class="btn-action" onclick="editEmailConfig(${config.id})">
                        <i class="fas fa-edit"></i> 编辑
                    </button>
                    <button class="btn-action danger" onclick="deleteEmailConfig(${config.id}, '${config.config_name}')">
                        <i class="fas fa-trash"></i> 删除
                    </button>
                </div>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染邮件配置分页
 */
function renderEmailConfigPagination(pagination) {
    const container = document.getElementById('email-config-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadEmailConfigList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadEmailConfigList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 显示邮件配置弹出框（新增）
 */
function showEmailConfigModal() {
    const modal = document.getElementById('email-config-modal');
    const title = document.getElementById('email-config-modal-title');
    
    // 重置表单
    document.getElementById('email-config-form').reset();
    document.getElementById('email-config-id').value = '';
    document.getElementById('email-config-priority').value = '100';
    document.getElementById('email-config-smtp-port').value = '465';
    document.getElementById('email-config-encryption').value = 'ssl';
    document.getElementById('email-config-daily-limit').value = '1000';
    document.getElementById('email-config-status').value = '1';
    document.getElementById('email-config-is-enabled').value = '1';
    document.getElementById('email-config-enable-signature').value = '0';
    
    // 清空场景选择
    document.querySelectorAll('input[name="email-config-scenes"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // 隐藏签名字段
    document.getElementById('signature-fields').style.display = 'none';
    
    title.textContent = '新增邮件配置';
    modal.classList.add('show');
}

/**
 * 查看邮件配置
 */
async function viewEmailConfig(configId) {
    await editEmailConfig(configId, true);
}

/**
 * 编辑邮件配置
 */
async function editEmailConfig(configId, viewOnly = false) {
    try {
        const response = await fetch(`/admin/api/GetEmailConfigDetail.php?id=${configId}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '获取配置详情失败');
            return;
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('email-config-id').value = config.id;
        document.getElementById('email-config-name').value = config.config_name;
        document.getElementById('email-config-email').value = config.email;
        document.getElementById('email-config-sender-name').value = config.sender_name;
        document.getElementById('email-config-username').value = config.username;
        document.getElementById('email-config-password').value = config.password;
        document.getElementById('email-config-smtp-host').value = config.smtp_host;
        document.getElementById('email-config-smtp-port').value = config.smtp_port;
        document.getElementById('email-config-encryption').value = config.encryption;
        document.getElementById('email-config-daily-limit').value = config.daily_limit;
        document.getElementById('email-config-reply-to').value = config.reply_to || '';
        document.getElementById('email-config-status').value = config.status;
        document.getElementById('email-config-is-enabled').value = config.is_enabled ? '1' : '0';
        document.getElementById('email-config-priority').value = config.priority;
        document.getElementById('email-config-description').value = config.description || '';
        
        // 设置签名相关字段
        document.getElementById('email-config-enable-signature').value = config.enable_signature ? '1' : '0';
        document.getElementById('email-config-signature-cert').value = config.signature_cert || '';
        document.getElementById('email-config-signature-key').value = config.signature_key || '';
        
        // 根据签名启用状态显示/隐藏签名字段
        toggleSignatureFields();
        
        // 设置场景选择
        const scenes = Array.isArray(config.scenes) ? config.scenes : [];
        document.querySelectorAll('input[name="email-config-scenes"]').forEach(checkbox => {
            checkbox.checked = scenes.includes(checkbox.value);
        });
        
        // 显示弹出框
        const modal = document.getElementById('email-config-modal');
        const title = document.getElementById('email-config-modal-title');
        title.textContent = viewOnly ? '查看邮件配置' : '编辑邮件配置';
        modal.classList.add('show');
        
        // 如果是查看模式，禁用所有输入
        if (viewOnly) {
            document.querySelectorAll('#email-config-form input, #email-config-form select, #email-config-form textarea').forEach(el => {
                el.disabled = true;
            });
            document.getElementById('btn-save-email-config').style.display = 'none';
        } else {
            document.querySelectorAll('#email-config-form input, #email-config-form select, #email-config-form textarea').forEach(el => {
                el.disabled = false;
            });
            document.getElementById('btn-save-email-config').style.display = 'flex';
        }
        
    } catch (error) {
        console.error('获取配置详情失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 保存邮件配置
 */
async function saveEmailConfig() {
    // 获取表单数据
    const configId = document.getElementById('email-config-id').value;
    const configName = document.getElementById('email-config-name').value.trim();
    const email = document.getElementById('email-config-email').value.trim();
    const senderName = document.getElementById('email-config-sender-name').value.trim();
    const username = document.getElementById('email-config-username').value.trim();
    const password = document.getElementById('email-config-password').value.trim();
    const smtpHost = document.getElementById('email-config-smtp-host').value.trim();
    const smtpPort = parseInt(document.getElementById('email-config-smtp-port').value);
    const encryption = document.getElementById('email-config-encryption').value;
    const dailyLimit = parseInt(document.getElementById('email-config-daily-limit').value);
    const replyTo = document.getElementById('email-config-reply-to').value.trim();
    const status = parseInt(document.getElementById('email-config-status').value);
    const isEnabled = document.getElementById('email-config-is-enabled').value === '1';
    const priority = parseInt(document.getElementById('email-config-priority').value);
    const description = document.getElementById('email-config-description').value.trim();
    
    // 获取签名相关字段
    const enableSignature = document.getElementById('email-config-enable-signature').value === '1';
    const signatureCert = document.getElementById('email-config-signature-cert').value.trim();
    const signatureKey = document.getElementById('email-config-signature-key').value.trim();
    
    // 获取选中的场景
    const scenes = [];
    document.querySelectorAll('input[name="email-config-scenes"]:checked').forEach(checkbox => {
        scenes.push(checkbox.value);
    });
    
    // 验证必填字段
    if (!configName || !email || !senderName || !username || !password || !smtpHost) {
        showError('请填写完整信息');
        return;
    }
    
    if (scenes.length === 0) {
        showError('请至少选择一个适用场景');
        return;
    }
    
    // 如果启用签名，验证证书和私钥
    if (enableSignature && (!signatureCert || !signatureKey)) {
        showError('启用签名后必须填写签名证书和私钥');
        return;
    }
    
    // 禁用保存按钮
    const saveBtn = document.getElementById('btn-save-email-config');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    try {
        const response = await fetch('/admin/api/SaveEmailConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                id: configId ? parseInt(configId) : 0,
                config_name: configName,
                email: email,
                sender_name: senderName,
                username: username,
                password: password,
                smtp_host: smtpHost,
                smtp_port: smtpPort,
                encryption: encryption,
                scenes: scenes,
                daily_limit: dailyLimit,
                reply_to: replyTo,
                enable_signature: enableSignature,
                signature_cert: signatureCert,
                signature_key: signatureKey,
                status: status,
                is_enabled: isEnabled,
                priority: priority,
                description: description
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '保存失败');
            return;
        }
        
        showSuccessToast(configId ? '配置已更新' : '配置已新增', '保存成功');
        
        // 关闭弹出框
        closeEmailConfigModal();
        
        // 刷新列表
        loadEmailConfigList(currentEmailConfigPage);
        
    } catch (error) {
        console.error('保存邮件配置失败:', error);
        showError('网络错误，请稍后重试');
    } finally {
        // 恢复保存按钮
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

/**
 * 删除邮件配置
 */
async function deleteEmailConfig(configId, configName) {
    const confirmed = await showConfirm(
        '删除邮件配置',
        `确定要删除配置 "${configName}" 吗？\n\n此操作不可恢复！`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/DeleteEmailConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                id: configId
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '删除失败');
            return;
        }
        
        showSuccessToast(`配置 "${configName}" 已删除`, '删除成功');
        
        // 刷新列表
        loadEmailConfigList(currentEmailConfigPage);
        
    } catch (error) {
        console.error('删除邮件配置失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 关闭邮件配置弹出框
 */
function closeEmailConfigModal() {
    const modal = document.getElementById('email-config-modal');
    modal.classList.remove('show');
    
    // 恢复表单状态
    document.querySelectorAll('#email-config-form input, #email-config-form select, #email-config-form textarea').forEach(el => {
        el.disabled = false;
    });
    document.getElementById('btn-save-email-config').style.display = 'flex';
}

// ============================================
// 邮件模板管理
// ============================================

// 当前邮件模板页面状态
let currentEmailTemplatePage = 1;
let currentEmailTemplateSearch = '';
let currentEmailTemplateScene = '';
let currentEmailTemplateStatus = '';

/**
 * 初始化邮件模板筛选器
 */
function initEmailTemplateFilters() {
    const searchInput = document.getElementById('email-template-search');
    const btnSearch = document.getElementById('btn-email-template-search');
    const filterScene = document.getElementById('filter-email-template-scene');
    const filterStatus = document.getElementById('filter-email-template-status');
    const btnReset = document.getElementById('btn-reset-email-template-filter');
    const btnAdd = document.getElementById('btn-add-email-template');
    
    // 搜索按钮
    if (btnSearch) {
        btnSearch.addEventListener('click', function() {
            currentEmailTemplateSearch = searchInput.value.trim();
            loadEmailTemplateList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentEmailTemplateSearch = searchInput.value.trim();
                loadEmailTemplateList(1);
            }
        });
    }
    
    // 场景筛选
    if (filterScene) {
        filterScene.addEventListener('change', function() {
            currentEmailTemplateScene = this.value;
            loadEmailTemplateList(1);
        });
    }
    
    // 状态筛选
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentEmailTemplateStatus = this.value;
            loadEmailTemplateList(1);
        });
    }
    
    // 重置按钮
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentEmailTemplateSearch = '';
            currentEmailTemplateScene = '';
            currentEmailTemplateStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterScene) filterScene.value = '';
            if (filterStatus) filterStatus.value = '';
            loadEmailTemplateList(1);
        });
    }
    
    // 新增按钮
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            showEmailTemplateModal();
        });
    }
}

/**
 * 加载邮件模板列表
 */
async function loadEmailTemplateList(page = 1) {
    currentEmailTemplatePage = page;
    
    const loadingEl = document.getElementById('email-template-list-loading');
    const emptyEl = document.getElementById('email-template-list-empty');
    const tableEl = document.getElementById('email-template-list-table');
    
    // 显示加载状态
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: page,
            pageSize: 10
        });
        
        if (currentEmailTemplateSearch) {
            params.append('search', currentEmailTemplateSearch);
        }
        if (currentEmailTemplateScene) {
            params.append('scene', currentEmailTemplateScene);
        }
        if (currentEmailTemplateStatus !== '') {
            params.append('status', currentEmailTemplateStatus);
        }
        
        const response = await fetch(`/admin/api/GetEmailTemplateList.php?${params.toString()}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取模板列表失败');
        }
        
        const { list, total, page: currentPage, totalPages } = result.data;
        
        // 隐藏加载状态
        loadingEl.style.display = 'none';
        
        if (list.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示表格
            tableEl.style.display = 'block';
            renderEmailTemplateList(list);
            renderEmailTemplatePagination({ total, page: currentPage, totalPages });
        }
        
    } catch (error) {
        console.error('加载邮件模板列表失败:', error);
        loadingEl.style.display = 'none';
        showError(error.message || '加载失败，请稍后重试');
    }
}

/**
 * 渲染邮件模板列表
 */
function renderEmailTemplateList(templates) {
    const tbody = document.getElementById('email-template-list-tbody');
    
    tbody.innerHTML = templates.map(template => {
        // 状态徽章
        let statusBadge = '';
        if (template.status === 1) {
            statusBadge = '<span class="user-status-badge status-normal">正常</span>';
        } else if (template.status === 0) {
            statusBadge = '<span class="user-status-badge status-banned">禁用</span>';
        } else if (template.status === 2) {
            statusBadge = '<span class="user-status-badge status-pending">草稿</span>';
        }
        
        // 启用状态
        const enabledBadge = template.is_enabled 
            ? '<span class="badge-success">已启用</span>' 
            : '<span class="badge-secondary">未启用</span>';
        
        // 模板变量
        const variables = template.template_variables && template.template_variables.length > 0
            ? template.template_variables.join(', ')
            : '-';
        
        return `
            <tr>
                <td>${template.id}</td>
                <td><code>${template.template_code}</code></td>
                <td>${template.template_name}</td>
                <td>${template.scene_name}</td>
                <td>${template.subject}</td>
                <td><small>${variables}</small></td>
                <td>
                    <div style="display: flex; gap: 5px; align-items: center; flex-wrap: nowrap; white-space: nowrap;">
                        ${statusBadge}
                        ${enabledBadge}
                    </div>
                </td>
                <td>${template.priority}</td>
                <td>
                    <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                        <button class="btn-action" onclick="editEmailTemplate(${template.id})">
                            <i class="fas fa-edit"></i> 编辑
                        </button>
                        <button class="btn-action danger" onclick="deleteEmailTemplate(${template.id}, '${template.template_name}')">
                            <i class="fas fa-trash"></i> 删除
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染邮件模板分页
 */
function renderEmailTemplatePagination(pagination) {
    const paginationEl = document.getElementById('email-template-pagination');
    
    const hasPrev = pagination.page > 1;
    const hasNext = pagination.page < pagination.totalPages;
    
    paginationEl.innerHTML = `
        <div class="pagination-info">
            共 ${pagination.total} 条记录，第 ${pagination.page} / ${pagination.totalPages} 页
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!hasPrev ? 'disabled' : ''} 
                    onclick="loadEmailTemplateList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!hasNext ? 'disabled' : ''} 
                    onclick="loadEmailTemplateList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 显示邮件模板弹出框（新增）
 */
function showEmailTemplateModal() {
    const modal = document.getElementById('email-template-modal');
    const title = document.getElementById('email-template-modal-title');
    const form = document.getElementById('email-template-form');
    
    // 重置表单
    form.reset();
    document.getElementById('email-template-id').value = '';
    
    // 设置标题
    title.textContent = '新增邮件模板';
    
    // 显示弹出框
    modal.classList.add('show');
}

/**
 * 编辑邮件模板
 */
async function editEmailTemplate(templateId) {
    try {
        const response = await fetch(`/admin/api/GetEmailTemplateDetail.php?id=${templateId}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取模板详情失败');
        }
        
        const template = result.data;
        
        // 填充表单
        document.getElementById('email-template-id').value = template.id;
        document.getElementById('email-template-code').value = template.template_code;
        document.getElementById('email-template-name').value = template.template_name;
        document.getElementById('email-template-scene').value = template.scene;
        document.getElementById('email-template-subject').value = template.subject;
        document.getElementById('email-template-content').value = template.template_content;
        document.getElementById('email-template-priority').value = template.priority;
        document.getElementById('email-template-status').value = template.status;
        document.getElementById('email-template-is-enabled').value = template.is_enabled ? '1' : '0';
        document.getElementById('email-template-description').value = template.description || '';
        
        // 填充JSON字段
        if (template.template_variables && template.template_variables.length > 0) {
            document.getElementById('email-template-variables').value = JSON.stringify(template.template_variables, null, 2);
        }
        if (template.variable_descriptions && Object.keys(template.variable_descriptions).length > 0) {
            document.getElementById('email-template-variable-descriptions').value = JSON.stringify(template.variable_descriptions, null, 2);
        }
        
        // 设置标题
        document.getElementById('email-template-modal-title').textContent = '编辑邮件模板';
        
        // 显示弹出框
        document.getElementById('email-template-modal').classList.add('show');
        
    } catch (error) {
        console.error('加载模板详情失败:', error);
        showError(error.message || '加载失败，请稍后重试');
    }
}

/**
 * 保存邮件模板
 */
async function saveEmailTemplate() {
    try {
        // 获取表单数据
        const templateId = document.getElementById('email-template-id').value;
        const templateCode = document.getElementById('email-template-code').value.trim();
        const templateName = document.getElementById('email-template-name').value.trim();
        const scene = document.getElementById('email-template-scene').value;
        const subject = document.getElementById('email-template-subject').value.trim();
        const templateContent = document.getElementById('email-template-content').value.trim();
        const priority = document.getElementById('email-template-priority').value;
        const status = document.getElementById('email-template-status').value;
        const isEnabled = document.getElementById('email-template-is-enabled').value === '1';
        const description = document.getElementById('email-template-description').value.trim();
        
        // 验证必填字段
        if (!templateCode || !templateName || !scene || !subject || !templateContent) {
            showError('请填写所有必填字段');
            return;
        }
        
        // 解析JSON字段
        let templateVariables = [];
        let variableDescriptions = {};
        
        const variablesText = document.getElementById('email-template-variables').value.trim();
        if (variablesText) {
            try {
                templateVariables = JSON.parse(variablesText);
                if (!Array.isArray(templateVariables)) {
                    throw new Error('模板变量必须是数组格式');
                }
            } catch (e) {
                showError('模板变量格式错误：' + e.message);
                return;
            }
        }
        
        const descriptionsText = document.getElementById('email-template-variable-descriptions').value.trim();
        if (descriptionsText) {
            try {
                variableDescriptions = JSON.parse(descriptionsText);
                if (typeof variableDescriptions !== 'object' || Array.isArray(variableDescriptions)) {
                    throw new Error('变量说明必须是对象格式');
                }
            } catch (e) {
                showError('变量说明格式错误：' + e.message);
                return;
            }
        }
        
        // 构建请求数据
        const data = {
            template_code: templateCode,
            template_name: templateName,
            scene: scene,
            subject: subject,
            template_content: templateContent,
            template_variables: templateVariables,
            variable_descriptions: variableDescriptions,
            priority: parseInt(priority),
            status: parseInt(status),
            is_enabled: isEnabled,
            description: description
        };
        
        if (templateId) {
            data.id = parseInt(templateId);
        }
        
        // 发送请求
        const response = await fetch('/admin/api/SaveEmailTemplate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast(templateId ? '模板更新成功' : '模板创建成功', '保存成功');
        
        // 关闭弹出框
        closeEmailTemplateModal();
        
        // 刷新列表
        loadEmailTemplateList(currentEmailTemplatePage);
        
    } catch (error) {
        console.error('保存邮件模板失败:', error);
        showError(error.message || '保存失败，请稍后重试');
    }
}

/**
 * 删除邮件模板
 */
async function deleteEmailTemplate(templateId, templateName) {
    if (!confirm(`确定要删除模板 "${templateName}" 吗？\n\n此操作不可恢复！`)) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/DeleteEmailTemplate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: templateId })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '删除失败');
        }
        
        showSuccessToast(`模板 "${templateName}" 已删除`, '删除成功');
        
        // 刷新列表
        loadEmailTemplateList(currentEmailTemplatePage);
        
    } catch (error) {
        console.error('删除邮件模板失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 关闭邮件模板弹出框
 */
function closeEmailTemplateModal() {
    const modal = document.getElementById('email-template-modal');
    modal.classList.remove('show');
}

// 绑定保存按钮事件
document.addEventListener('DOMContentLoaded', function() {
    const btnSave = document.getElementById('btn-save-email-template');
    if (btnSave) {
        btnSave.addEventListener('click', saveEmailTemplate);
    }
});

// ============================================
// 短信配置管理
// ============================================

// 当前短信配置页面状态
let currentSmsConfigPage = 1;
let currentSmsConfigSearch = '';
let currentSmsConfigPurpose = '';
let currentSmsConfigChannel = '';
let currentSmsConfigStatus = '';

/**
 * 初始化短信配置筛选器
 */
function initSmsConfigFilters() {
    const searchInput = document.getElementById('sms-config-search');
    const btnSearch = document.getElementById('btn-sms-config-search');
    const filterPurpose = document.getElementById('filter-sms-config-purpose');
    const filterChannel = document.getElementById('filter-sms-config-channel');
    const filterStatus = document.getElementById('filter-sms-config-status');
    const btnReset = document.getElementById('btn-reset-sms-config-filter');
    const btnAdd = document.getElementById('btn-add-sms-config');
    
    // 搜索按钮
    if (btnSearch) {
        btnSearch.addEventListener('click', function() {
            currentSmsConfigSearch = searchInput.value.trim();
            loadSmsConfigList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentSmsConfigSearch = searchInput.value.trim();
                loadSmsConfigList(1);
            }
        });
    }
    
    // 用途筛选
    if (filterPurpose) {
        filterPurpose.addEventListener('change', function() {
            currentSmsConfigPurpose = this.value;
            loadSmsConfigList(1);
        });
    }
    
    // 渠道筛选
    if (filterChannel) {
        filterChannel.addEventListener('change', function() {
            currentSmsConfigChannel = this.value;
            loadSmsConfigList(1);
        });
    }
    
    // 状态筛选
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentSmsConfigStatus = this.value;
            loadSmsConfigList(1);
        });
    }
    
    // 重置按钮
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentSmsConfigSearch = '';
            currentSmsConfigPurpose = '';
            currentSmsConfigChannel = '';
            currentSmsConfigStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterPurpose) filterPurpose.value = '';
            if (filterChannel) filterChannel.value = '';
            if (filterStatus) filterStatus.value = '';
            loadSmsConfigList(1);
        });
    }
    
    // 新增按钮
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            showSmsConfigModal();
        });
    }
}

/**
 * 加载短信配置列表
 */
async function loadSmsConfigList(page = 1) {
    currentSmsConfigPage = page;
    
    const loadingEl = document.getElementById('sms-config-list-loading');
    const emptyEl = document.getElementById('sms-config-list-empty');
    const tableEl = document.getElementById('sms-config-list-table');
    
    // 显示加载状态
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: page,
            pageSize: 10
        });
        
        if (currentSmsConfigSearch) {
            params.append('search', currentSmsConfigSearch);
        }
        if (currentSmsConfigPurpose) {
            params.append('purpose', currentSmsConfigPurpose);
        }
        if (currentSmsConfigChannel) {
            params.append('channel', currentSmsConfigChannel);
        }
        if (currentSmsConfigStatus !== '') {
            params.append('status', currentSmsConfigStatus);
        }
        
        const response = await fetch(`/admin/api/GetSmsConfigList.php?${params.toString()}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取配置列表失败');
        }
        
        const { list, total, page: currentPage, totalPages } = result.data;
        
        // 隐藏加载状态
        loadingEl.style.display = 'none';
        
        if (list.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示表格
            tableEl.style.display = 'block';
            renderSmsConfigList(list);
            renderSmsConfigPagination({ total, page: currentPage, totalPages });
        }
        
    } catch (error) {
        console.error('加载短信配置列表失败:', error);
        loadingEl.style.display = 'none';
        showError(error.message || '加载失败，请稍后重试');
    }
}

/**
 * 渲染短信配置列表
 */
function renderSmsConfigList(configs) {
    const tbody = document.getElementById('sms-config-list-tbody');
    
    tbody.innerHTML = configs.map(config => {
        // 状态徽章
        let statusBadge = '';
        if (config.status === 1) {
            statusBadge = '<span class="user-status-badge status-normal">正常</span>';
        } else if (config.status === 0) {
            statusBadge = '<span class="user-status-badge status-banned">禁用</span>';
        } else if (config.status === 2) {
            statusBadge = '<span class="user-status-badge status-pending">维护中</span>';
        }
        
        // 启用状态
        const enabledBadge = config.is_enabled 
            ? '<span class="badge-success">已启用</span>' 
            : '<span class="badge-secondary">未启用</span>';
        
        return `
            <tr>
                <td>${config.id}</td>
                <td>${config.config_name}</td>
                <td>${config.purpose_name}</td>
                <td>${config.channel_name}</td>
                <td>${config.signature}</td>
                <td><code>${config.template_id}</code></td>
                <td>${config.daily_limit}</td>
                <td>${config.daily_sent_count}</td>
                <td>${config.remaining_quota}</td>
                <td>
                    <div style="display: flex; gap: 5px; align-items: center; flex-wrap: nowrap; white-space: nowrap;">
                        ${statusBadge}
                        ${enabledBadge}
                    </div>
                </td>
                <td>${config.priority}</td>
                <td>
                    <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                        <button class="btn-action" onclick="editSmsConfig(${config.id})">
                            <i class="fas fa-edit"></i> 编辑
                        </button>
                        <button class="btn-action danger" onclick="deleteSmsConfig(${config.id}, '${config.config_name}')">
                            <i class="fas fa-trash"></i> 删除
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染短信配置分页
 */
function renderSmsConfigPagination(pagination) {
    const paginationEl = document.getElementById('sms-config-pagination');
    
    const hasPrev = pagination.page > 1;
    const hasNext = pagination.page < pagination.totalPages;
    
    paginationEl.innerHTML = `
        <div class="pagination-info">
            共 ${pagination.total} 条记录，第 ${pagination.page} / ${pagination.totalPages} 页
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!hasPrev ? 'disabled' : ''} 
                    onclick="loadSmsConfigList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!hasNext ? 'disabled' : ''} 
                    onclick="loadSmsConfigList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 显示短信配置弹出框（新增）
 */
function showSmsConfigModal() {
    const modal = document.getElementById('sms-config-modal');
    const title = document.getElementById('sms-config-modal-title');
    const form = document.getElementById('sms-config-form');
    
    // 重置表单
    form.reset();
    document.getElementById('sms-config-id').value = '';
    
    // 设置标题
    title.textContent = '新增短信配置';
    
    // 显示弹出框
    modal.classList.add('show');
}

/**
 * 编辑短信配置
 */
async function editSmsConfig(configId) {
    try {
        const response = await fetch(`/admin/api/GetSmsConfigDetail.php?id=${configId}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取配置详情失败');
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('sms-config-id').value = config.id;
        document.getElementById('sms-config-name').value = config.config_name;
        document.getElementById('sms-config-purpose').value = config.purpose;
        document.getElementById('sms-config-channel').value = config.channel;
        document.getElementById('sms-config-signature').value = config.signature;
        document.getElementById('sms-config-template-id').value = config.template_id;
        document.getElementById('sms-config-template-content').value = config.template_content || '';
        document.getElementById('sms-config-priority').value = config.priority;
        document.getElementById('sms-config-daily-limit').value = config.daily_limit;
        document.getElementById('sms-config-status').value = config.status;
        document.getElementById('sms-config-is-enabled').value = config.is_enabled ? '1' : '0';
        document.getElementById('sms-config-description').value = config.description || '';
        
        // 填充JSON字段
        if (config.credentials && Object.keys(config.credentials).length > 0) {
            document.getElementById('sms-config-credentials').value = JSON.stringify(config.credentials, null, 2);
        }
        if (config.channel_config && Object.keys(config.channel_config).length > 0) {
            document.getElementById('sms-config-channel-config').value = JSON.stringify(config.channel_config, null, 2);
        }
        
        // 设置标题
        document.getElementById('sms-config-modal-title').textContent = '编辑短信配置';
        
        // 显示弹出框
        document.getElementById('sms-config-modal').classList.add('show');
        
    } catch (error) {
        console.error('加载配置详情失败:', error);
        showError(error.message || '加载失败，请稍后重试');
    }
}

/**
 * 保存短信配置
 */
async function saveSmsConfig() {
    try {
        // 获取表单数据
        const configId = document.getElementById('sms-config-id').value;
        const configName = document.getElementById('sms-config-name').value.trim();
        const purpose = document.getElementById('sms-config-purpose').value;
        const channel = document.getElementById('sms-config-channel').value;
        const signature = document.getElementById('sms-config-signature').value.trim();
        const templateId = document.getElementById('sms-config-template-id').value.trim();
        const templateContent = document.getElementById('sms-config-template-content').value.trim();
        const priority = document.getElementById('sms-config-priority').value;
        const dailyLimit = document.getElementById('sms-config-daily-limit').value;
        const status = document.getElementById('sms-config-status').value;
        const isEnabled = document.getElementById('sms-config-is-enabled').value === '1';
        const description = document.getElementById('sms-config-description').value.trim();
        
        // 验证必填字段
        if (!configName || !purpose || !channel || !signature || !templateId) {
            showError('请填写所有必填字段');
            return;
        }
        
        // 解析JSON字段
        let credentials = {};
        let channelConfig = {};
        
        const credentialsText = document.getElementById('sms-config-credentials').value.trim();
        if (credentialsText) {
            try {
                credentials = JSON.parse(credentialsText);
                if (typeof credentials !== 'object' || Array.isArray(credentials)) {
                    throw new Error('密钥信息必须是对象格式');
                }
            } catch (e) {
                showError('密钥信息格式错误：' + e.message);
                return;
            }
        } else {
            showError('密钥信息不能为空');
            return;
        }
        
        const channelConfigText = document.getElementById('sms-config-channel-config').value.trim();
        if (channelConfigText) {
            try {
                channelConfig = JSON.parse(channelConfigText);
                if (typeof channelConfig !== 'object' || Array.isArray(channelConfig)) {
                    throw new Error('渠道配置必须是对象格式');
                }
            } catch (e) {
                showError('渠道配置格式错误：' + e.message);
                return;
            }
        }
        
        // 构建请求数据
        const data = {
            config_name: configName,
            purpose: purpose,
            channel: channel,
            signature: signature,
            template_id: templateId,
            template_content: templateContent,
            credentials: credentials,
            channel_config: channelConfig,
            priority: parseInt(priority),
            daily_limit: parseInt(dailyLimit),
            status: parseInt(status),
            is_enabled: isEnabled,
            description: description
        };
        
        if (configId) {
            data.id = parseInt(configId);
        }
        
        // 发送请求
        const response = await fetch('/admin/api/SaveSmsConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast(configId ? '配置更新成功' : '配置创建成功', '保存成功');
        
        // 关闭弹出框
        closeSmsConfigModal();
        
        // 刷新列表
        loadSmsConfigList(currentSmsConfigPage);
        
    } catch (error) {
        console.error('保存短信配置失败:', error);
        showError(error.message || '保存失败，请稍后重试');
    }
}

/**
 * 删除短信配置
 */
async function deleteSmsConfig(configId, configName) {
    if (!confirm(`确定要删除配置 "${configName}" 吗？\n\n此操作不可恢复！`)) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/DeleteSmsConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: configId })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '删除失败');
        }
        
        showSuccessToast(`配置 "${configName}" 已删除`, '删除成功');
        
        // 刷新列表
        loadSmsConfigList(currentSmsConfigPage);
        
    } catch (error) {
        console.error('删除短信配置失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 关闭短信配置弹出框
 */
function closeSmsConfigModal() {
    const modal = document.getElementById('sms-config-modal');
    modal.classList.remove('show');
}

// 绑定保存按钮事件
document.addEventListener('DOMContentLoaded', function() {
    const btnSave = document.getElementById('btn-save-sms-config');
    if (btnSave) {
        btnSave.addEventListener('click', saveSmsConfig);
    }
});

// ==================== 人机验证配置管理 ====================

/**
 * 人机验证配置列表相关变量
 */
let currentCaptchaConfigPage = 1;
let currentCaptchaConfigPageSize = 10;
let currentCaptchaConfigSearch = '';
let currentCaptchaConfigType = '';
let currentCaptchaConfigProvider = '';
let currentCaptchaConfigStatus = '';

/**
 * 初始化人机验证配置筛选器
 */
function initCaptchaConfigFilters() {
    const searchInput = document.getElementById('captcha-config-search');
    const btnSearch = document.getElementById('btn-captcha-config-search');
    const btnAdd = document.getElementById('btn-add-captcha-config');
    const btnReset = document.getElementById('btn-reset-captcha-config-filter');
    const filterType = document.getElementById('filter-captcha-config-type');
    const filterProvider = document.getElementById('filter-captcha-config-provider');
    const filterStatus = document.getElementById('filter-captcha-config-status');
    
    // 搜索按钮
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentCaptchaConfigSearch = searchInput.value.trim();
            loadCaptchaConfigList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentCaptchaConfigSearch = searchInput.value.trim();
                loadCaptchaConfigList(1);
            }
        });
    }
    
    // 新增按钮
    if (btnAdd) {
        btnAdd.addEventListener('click', showAddCaptchaConfigModal);
    }
    
    // 重置按钮
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentCaptchaConfigSearch = '';
            currentCaptchaConfigType = '';
            currentCaptchaConfigProvider = '';
            currentCaptchaConfigStatus = '';
            
            if (searchInput) searchInput.value = '';
            if (filterType) filterType.value = '';
            if (filterProvider) filterProvider.value = '';
            if (filterStatus) filterStatus.value = '';
            
            loadCaptchaConfigList(1);
        });
    }
    
    // 类型筛选
    if (filterType) {
        filterType.addEventListener('change', function() {
            currentCaptchaConfigType = this.value;
            loadCaptchaConfigList(1);
        });
    }
    
    // 服务商筛选
    if (filterProvider) {
        filterProvider.addEventListener('change', function() {
            currentCaptchaConfigProvider = this.value;
            loadCaptchaConfigList(1);
        });
    }
    
    // 状态筛选
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentCaptchaConfigStatus = this.value;
            loadCaptchaConfigList(1);
        });
    }
}

/**
 * 加载人机验证配置列表
 */
async function loadCaptchaConfigList(page = 1) {
    currentCaptchaConfigPage = page;
    
    const loadingEl = document.getElementById('captcha-config-list-loading');
    const emptyEl = document.getElementById('captcha-config-list-empty');
    const tableEl = document.getElementById('captcha-config-list-table');
    
    // 显示加载中
    if (loadingEl) loadingEl.style.display = 'flex';
    if (emptyEl) emptyEl.style.display = 'none';
    if (tableEl) tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentCaptchaConfigPage,
            pageSize: currentCaptchaConfigPageSize
        });
        
        if (currentCaptchaConfigSearch) params.append('search', currentCaptchaConfigSearch);
        if (currentCaptchaConfigType) params.append('type', currentCaptchaConfigType);
        if (currentCaptchaConfigProvider) params.append('provider', currentCaptchaConfigProvider);
        if (currentCaptchaConfigStatus !== '') params.append('status', currentCaptchaConfigStatus);
        
        const response = await fetch(`/admin/api/GetCaptchaConfigList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        if (loadingEl) loadingEl.style.display = 'none';
        
        if (!result.success) {
            showError(result.message || '加载配置列表失败');
            if (emptyEl) emptyEl.style.display = 'flex';
            return;
        }
        
        const configs = result.data.list || [];
        
        if (configs.length === 0) {
            // 显示空状态
            if (emptyEl) emptyEl.style.display = 'flex';
        } else {
            // 显示配置列表
            if (tableEl) tableEl.style.display = 'block';
            renderCaptchaConfigList(configs);
            renderCaptchaConfigPagination(result.data);
        }
        
    } catch (error) {
        console.error('加载人机验证配置列表失败:', error);
        if (loadingEl) loadingEl.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'flex';
        showError('网络错误，请稍后重试');
    }
}

/**
 * 渲染人机验证配置列表
 */
function renderCaptchaConfigList(configs) {
    const tbody = document.getElementById('captcha-config-list-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    configs.forEach(config => {
        const tr = document.createElement('tr');
        
        // 状态样式
        const statusClass = {
            0: 'status-disabled',
            1: 'status-normal',
            2: 'status-maintenance'
        }[config.status] || '';
        
        tr.innerHTML = `
            <td>${config.id}</td>
            <td>${config.name}</td>
            <td>${config.provider_name}</td>
            <td>${config.provider_name}</td>
            <td>${config.scene_names || '-'}</td>
            <td><span class="status-badge ${statusClass}">${config.status_name}</span></td>
            <td>${config.priority}</td>
            <td>
                <button class="btn-action" onclick="showEditCaptchaConfigModal(${config.id})">
                    <i class="fas fa-edit"></i> 编辑
                </button>
                <button class="btn-action danger" onclick="deleteCaptchaConfig(${config.id}, '${config.name.replace(/'/g, "\\'")}')">
                    <i class="fas fa-trash"></i> 删除
                </button>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染人机验证配置分页
 */
function renderCaptchaConfigPagination(data) {
    const container = document.getElementById('captcha-config-pagination');
    if (!container) return;
    
    const { page, pageSize, total, totalPages } = data;
    const startItem = (page - 1) * pageSize + 1;
    const endItem = Math.min(page * pageSize, total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${page <= 1 ? 'disabled' : ''} 
                    onclick="loadCaptchaConfigList(${page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${page}</button>
            <button class="btn-page" ${page >= totalPages ? 'disabled' : ''} 
                    onclick="loadCaptchaConfigList(${page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 显示新增人机验证配置弹窗
 */
function showAddCaptchaConfigModal() {
    const modal = document.getElementById('captcha-config-modal');
    const title = document.getElementById('captcha-config-modal-title');
    const form = document.getElementById('captcha-config-form');
    
    if (!modal || !title || !form) return;
    
    // 设置标题
    title.textContent = '新增人机验证配置';
    
    // 重置表单
    form.reset();
    document.getElementById('captcha-config-id').value = '';
    
    // 清除场景复选框
    document.querySelectorAll('.captcha-scene-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // 显示弹窗
    modal.classList.add('show');
}

/**
 * 显示编辑人机验证配置弹窗
 */
async function showEditCaptchaConfigModal(configId) {
    const modal = document.getElementById('captcha-config-modal');
    const title = document.getElementById('captcha-config-modal-title');
    
    if (!modal || !title) return;
    
    // 设置标题
    title.textContent = '编辑人机验证配置';
    
    try {
        // 获取配置详情
        const response = await fetch(`/admin/api/GetCaptchaConfigDetail.php?id=${configId}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取配置详情失败');
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('captcha-config-id').value = config.id;
        document.getElementById('captcha-config-name').value = config.name;
        document.getElementById('captcha-config-provider').value = config.provider;
        document.getElementById('captcha-config-status').value = config.status;
        document.getElementById('captcha-config-priority').value = config.priority;
        
        // 根据服务商类型填充密钥字段
        if (config.provider === 'geetest') {
            document.getElementById('captcha-config-app-id').value = config.captcha_id || '';
            document.getElementById('captcha-config-app-secret').value = config.captcha_key || '';
        } else {
            document.getElementById('captcha-config-app-id').value = config.site_key || config.app_id || '';
            document.getElementById('captcha-config-app-secret').value = config.secret_key || config.app_secret || '';
        }
        
        // 设置场景复选框
        document.querySelectorAll('.captcha-scene-checkbox').forEach(checkbox => {
            checkbox.checked = config.scenes.includes(checkbox.value);
        });
        
        // 显示弹窗
        modal.classList.add('show');
        
    } catch (error) {
        console.error('获取人机验证配置详情失败:', error);
        showError(error.message || '获取配置详情失败');
    }
}

/**
 * 保存人机验证配置
 */
async function saveCaptchaConfig() {
    try {
        // 获取表单数据
        const id = document.getElementById('captcha-config-id').value;
        const name = document.getElementById('captcha-config-name').value.trim();
        const provider = document.getElementById('captcha-config-provider').value;
        const appId = document.getElementById('captcha-config-app-id').value.trim();
        const appSecret = document.getElementById('captcha-config-app-secret').value.trim();
        const status = document.getElementById('captcha-config-status').value;
        const priority = document.getElementById('captcha-config-priority').value;
        
        // 获取选中的场景
        const scenes = [];
        document.querySelectorAll('.captcha-scene-checkbox:checked').forEach(checkbox => {
            scenes.push(checkbox.value);
        });
        
        // 验证必填字段
        if (!name) {
            throw new Error('请输入配置名称');
        }
        if (!provider) {
            throw new Error('请选择服务商');
        }
        if (scenes.length === 0) {
            throw new Error('请至少选择一个适用场景');
        }
        if (!priority) {
            throw new Error('请输入优先级');
        }
        
        // 构建请求数据
        const data = {
            name: name,
            provider: provider,
            scenes: scenes,
            status: parseInt(status),
            priority: parseInt(priority)
        };
        
        // 根据不同的服务商，设置不同的字段
        if (provider === 'geetest') {
            data.captcha_id = appId;
            data.captcha_key = appSecret;
        } else {
            data.site_key = appId;
            data.secret_key = appSecret;
        }
        
        if (id) {
            data.id = parseInt(id);
        }
        
        // 发送请求
        const response = await fetch('/admin/api/SaveCaptchaConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast(result.message || '保存成功', '成功');
        
        // 关闭弹出框
        closeCaptchaConfigModal();
        
        // 刷新列表
        loadCaptchaConfigList(currentCaptchaConfigPage);
        
    } catch (error) {
        console.error('保存人机验证配置失败:', error);
        showError(error.message || '保存失败，请稍后重试');
    }
}

/**
 * 删除人机验证配置
 */
async function deleteCaptchaConfig(configId, configName) {
    const confirmed = await showConfirm('删除配置', `确定要删除配置 "${configName}" 吗？\n\n此操作不可恢复！`);
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/admin/api/DeleteCaptchaConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ id: configId })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '删除失败');
        }
        
        showSuccessToast(`配置 "${configName}" 已删除`, '删除成功');
        
        // 刷新列表
        loadCaptchaConfigList(currentCaptchaConfigPage);
        
    } catch (error) {
        console.error('删除人机验证配置失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 关闭人机验证配置弹出框
 */
function closeCaptchaConfigModal() {
    const modal = document.getElementById('captcha-config-modal');
    if (modal) {
        modal.classList.remove('show');
    }
}

// 绑定保存按钮事件
document.addEventListener('DOMContentLoaded', function() {
    const btnSave = document.getElementById('btn-save-captcha-config');
    if (btnSave) {
        btnSave.addEventListener('click', saveCaptchaConfig);
    }
});


/**
 * ============================================
 * 昵称审核配置管理
 * ============================================
 */

/**
 * 加载昵称审核配置
 */
async function loadNicknameCheckConfig() {
    try {
        const response = await fetch('/admin/api/GetNicknameCheckConfig.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '加载配置失败');
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('nickname-is-enabled').checked = config.is_enabled;
        document.getElementById('nickname-auto-approve').checked = config.auto_approve;
        document.getElementById('nickname-check-sensitive').checked = config.check_sensitive_words;
        document.getElementById('nickname-allow-special').checked = config.allow_special_chars;
        document.getElementById('nickname-min-length').value = config.min_length;
        document.getElementById('nickname-max-length').value = config.max_length;
        document.getElementById('nickname-guest-prefix').value = config.guest_prefix || '游客-';
        document.getElementById('nickname-description').value = config.description || '';
        
    } catch (error) {
        console.error('加载昵称审核配置失败:', error);
        showError(error.message || '加载配置失败');
    }
}

/**
 * 保存昵称审核配置
 */
async function saveNicknameCheckConfig() {
    try {
        // 获取表单数据
        const isEnabled = document.getElementById('nickname-is-enabled').checked;
        const autoApprove = document.getElementById('nickname-auto-approve').checked;
        const checkSensitive = document.getElementById('nickname-check-sensitive').checked;
        const allowSpecial = document.getElementById('nickname-allow-special').checked;
        const minLength = parseInt(document.getElementById('nickname-min-length').value);
        const maxLength = parseInt(document.getElementById('nickname-max-length').value);
        const guestPrefix = document.getElementById('nickname-guest-prefix').value.trim();
        const description = document.getElementById('nickname-description').value.trim();
        
        // 验证
        if (minLength < 1 || minLength > 50) {
            throw new Error('最小长度必须在 1-50 之间');
        }
        
        if (maxLength < minLength || maxLength > 100) {
            throw new Error('最大长度必须大于等于最小长度，且不超过 100');
        }
        
        // 构建请求数据
        const data = {
            is_enabled: isEnabled,
            auto_approve: autoApprove,
            check_sensitive_words: checkSensitive,
            allow_special_chars: allowSpecial,
            min_length: minLength,
            max_length: maxLength,
            guest_prefix: guestPrefix,
            description: description
        };
        
        // 发送请求
        const response = await fetch('/admin/api/SaveNicknameCheckConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast('配置已保存', '保存成功');
        
        // 重新加载配置
        loadNicknameCheckConfig();
        
    } catch (error) {
        console.error('保存昵称审核配置失败:', error);
        showError(error.message || '保存配置失败');
    }
}


/**
 * ============================================
 * 敏感词管理
 * ============================================
 */

let currentSensitiveWordPage = 1;
let currentSensitiveWordPageSize = 20;
let currentSensitiveWordSearch = '';
let currentSensitiveWordCategory = '';
let currentSensitiveWordEnabled = '';

/**
 * 初始化敏感词筛选器
 */
function initSensitiveWordFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-sensitive-word-search');
    const searchInput = document.getElementById('sensitive-word-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentSensitiveWordSearch = searchInput.value.trim();
            loadSensitiveWordList(1);
        });
        
        // 回车搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentSensitiveWordSearch = searchInput.value.trim();
                loadSensitiveWordList(1);
            }
        });
    }
    
    // 分类筛选
    const filterCategory = document.getElementById('filter-sensitive-category');
    if (filterCategory) {
        filterCategory.addEventListener('change', function() {
            currentSensitiveWordCategory = this.value;
            loadSensitiveWordList(1);
        });
    }
    
    // 状态筛选
    const filterEnabled = document.getElementById('filter-sensitive-enabled');
    if (filterEnabled) {
        filterEnabled.addEventListener('change', function() {
            currentSensitiveWordEnabled = this.value;
            loadSensitiveWordList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-sensitive-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentSensitiveWordSearch = '';
            currentSensitiveWordCategory = '';
            currentSensitiveWordEnabled = '';
            if (searchInput) searchInput.value = '';
            if (filterCategory) filterCategory.value = '';
            if (filterEnabled) filterEnabled.value = '';
            loadSensitiveWordList(1);
        });
    }
}

/**
 * 加载敏感词列表
 */
async function loadSensitiveWordList(page = 1) {
    currentSensitiveWordPage = page;
    
    const loadingEl = document.getElementById('sensitive-word-list-loading');
    const emptyEl = document.getElementById('sensitive-word-list-empty');
    const tableEl = document.getElementById('sensitive-word-list-table');
    
    if (!loadingEl || !emptyEl || !tableEl) return;
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentSensitiveWordPage,
            page_size: currentSensitiveWordPageSize
        });
        
        if (currentSensitiveWordSearch) params.append('search', currentSensitiveWordSearch);
        if (currentSensitiveWordCategory) params.append('category', currentSensitiveWordCategory);
        if (currentSensitiveWordEnabled !== '') params.append('is_enabled', currentSensitiveWordEnabled);
        
        const response = await fetch(`/admin/api/GetSensitiveWordsList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            throw new Error(result.message || '加载敏感词列表失败');
        }
        
        const words = result.data.words || [];
        
        if (words.length === 0) {
            // 显示空状态
            emptyEl.style.display = 'block';
        } else {
            // 显示敏感词列表
            tableEl.style.display = 'block';
            renderSensitiveWordList(words);
            renderSensitiveWordPagination(result.data);
        }
        
    } catch (error) {
        console.error('加载敏感词列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showError(error.message || '加载敏感词列表失败');
    }
}

/**
 * 渲染敏感词列表
 */
function renderSensitiveWordList(words) {
    const tbody = document.getElementById('sensitive-word-list-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    words.forEach(word => {
        const tr = document.createElement('tr');
        
        // 状态样式
        const statusClass = word.is_enabled ? 'status-normal' : 'status-disabled';
        
        // 严重程度样式
        const severityColors = {
            1: '#28a745',
            2: '#ffc107',
            3: '#dc3545'
        };
        const severityColor = severityColors[word.severity] || '#6c757d';
        
        tr.innerHTML = `
            <td>
                <input type="checkbox" class="sensitive-word-checkbox" value="${word.id}" style="width: 18px; height: 18px; cursor: pointer;">
            </td>
            <td>${word.id}</td>
            <td><strong>${word.word}</strong></td>
            <td>${word.category_name}</td>
            <td>
                <span style="color: ${severityColor}; font-weight: 500;">
                    ${word.severity_name}
                </span>
            </td>
            <td>${word.action_name}</td>
            <td>${word.replacement || '-'}</td>
            <td><span class="status-badge ${statusClass}">${word.status_name}</span></td>
            <td>${word.description || '-'}</td>
            <td>
                <button class="btn-action" onclick="editSensitiveWord(${word.id})">
                    <i class="fas fa-edit"></i> 编辑
                </button>
                <button class="btn-action danger" onclick="deleteSensitiveWord(${word.id}, '${word.word.replace(/'/g, "\\'")}')">
                    <i class="fas fa-trash"></i> 删除
                </button>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
    
    // 初始化全选复选框
    initSelectAllCheckbox();
}

/**
 * 渲染敏感词分页
 */
function renderSensitiveWordPagination(data) {
    const container = document.getElementById('sensitive-word-pagination');
    if (!container) return;
    
    const { page, pageSize, total, totalPages } = data;
    const startItem = (page - 1) * pageSize + 1;
    const endItem = Math.min(page * pageSize, total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${page <= 1 ? 'disabled' : ''} 
                    onclick="loadSensitiveWordList(${page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${page}</button>
            <button class="btn-page" ${page >= totalPages ? 'disabled' : ''} 
                    onclick="loadSensitiveWordList(${page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 显示新增敏感词弹窗
 */
function showAddSensitiveWordModal() {
    const modal = document.getElementById('add-sensitive-word-modal');
    if (!modal) return;
    
    // 重置表单
    document.getElementById('add-word').value = '';
    document.getElementById('add-category').value = '';
    document.getElementById('add-severity').value = '1';
    document.getElementById('add-action').value = 'reject';
    document.getElementById('add-replacement').value = '';
    document.getElementById('add-description').value = '';
    
    // 显示弹窗
    modal.classList.add('show');
    
    // 绑定保存按钮事件
    const btnSave = document.getElementById('btn-add-sensitive-word');
    if (btnSave) {
        btnSave.onclick = addSensitiveWord;
    }
}

/**
 * 关闭新增敏感词弹窗
 */
function closeAddSensitiveWordModal() {
    const modal = document.getElementById('add-sensitive-word-modal');
    if (modal) {
        modal.classList.remove('show');
    }
}

/**
 * 新增敏感词
 */
async function addSensitiveWord() {
    try {
        const word = document.getElementById('add-word').value.trim();
        const category = document.getElementById('add-category').value;
        const severity = parseInt(document.getElementById('add-severity').value);
        const action = document.getElementById('add-action').value;
        const replacement = document.getElementById('add-replacement').value.trim();
        const description = document.getElementById('add-description').value.trim();
        
        // 验证
        if (!word) {
            throw new Error('请输入敏感词');
        }
        
        if (!category) {
            throw new Error('请选择分类');
        }
        
        // 构建请求数据
        const data = {
            word: word,
            category: category,
            severity: severity,
            action: action,
            replacement: replacement || null,
            description: description
        };
        
        // 发送请求
        const response = await fetch('/admin/api/AddSensitiveWord.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '新增失败');
        }
        
        showSuccessToast('新增敏感词成功', '操作成功');
        
        // 关闭弹窗
        closeAddSensitiveWordModal();
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('新增敏感词失败:', error);
        showError(error.message || '新增敏感词失败');
    }
}

/**
 * 编辑敏感词
 */
async function editSensitiveWord(id) {
    try {
        // 获取敏感词详情
        const response = await fetch(`/admin/api/GetSensitiveWordsList.php?page=1&page_size=1000`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '获取敏感词详情失败');
        }
        
        // 查找对应的敏感词
        const word = result.data.words.find(w => w.id === id);
        
        if (!word) {
            throw new Error('敏感词不存在');
        }
        
        // 显示编辑弹窗
        const modal = document.getElementById('edit-sensitive-word-modal');
        if (!modal) return;
        
        // 填充表单
        document.getElementById('edit-word-id').value = word.id;
        document.getElementById('edit-word').value = word.word;
        document.getElementById('edit-category').value = word.category;
        document.getElementById('edit-severity').value = word.severity;
        document.getElementById('edit-action').value = word.action;
        document.getElementById('edit-replacement').value = word.replacement || '';
        document.getElementById('edit-is-enabled').checked = word.is_enabled;
        document.getElementById('edit-description').value = word.description || '';
        
        // 显示弹窗
        modal.classList.add('show');
        
        // 绑定保存按钮事件
        const btnSave = document.getElementById('btn-edit-sensitive-word');
        if (btnSave) {
            btnSave.onclick = updateSensitiveWord;
        }
        
    } catch (error) {
        console.error('编辑敏感词失败:', error);
        showError(error.message || '编辑敏感词失败');
    }
}

/**
 * 关闭编辑敏感词弹窗
 */
function closeEditSensitiveWordModal() {
    const modal = document.getElementById('edit-sensitive-word-modal');
    if (modal) {
        modal.classList.remove('show');
    }
}

/**
 * 更新敏感词
 */
async function updateSensitiveWord() {
    try {
        const id = parseInt(document.getElementById('edit-word-id').value);
        const word = document.getElementById('edit-word').value.trim();
        const category = document.getElementById('edit-category').value;
        const severity = parseInt(document.getElementById('edit-severity').value);
        const action = document.getElementById('edit-action').value;
        const replacement = document.getElementById('edit-replacement').value.trim();
        const isEnabled = document.getElementById('edit-is-enabled').checked;
        const description = document.getElementById('edit-description').value.trim();
        
        // 验证
        if (!word) {
            throw new Error('请输入敏感词');
        }
        
        if (!category) {
            throw new Error('请选择分类');
        }
        
        // 构建请求数据
        const data = {
            id: id,
            word: word,
            category: category,
            severity: severity,
            action: action,
            replacement: replacement || null,
            is_enabled: isEnabled,
            description: description
        };
        
        // 发送请求
        const response = await fetch('/admin/api/UpdateSensitiveWord.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '更新失败');
        }
        
        showSuccessToast('更新敏感词成功', '操作成功');
        
        // 关闭弹窗
        closeEditSensitiveWordModal();
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('更新敏感词失败:', error);
        showError(error.message || '更新敏感词失败');
    }
}

/**
 * 删除敏感词
 */
async function deleteSensitiveWord(id, word) {
    try {
        const confirmed = await showConfirm('删除敏感词', `确定要删除敏感词 "${word}" 吗？\n\n此操作不可恢复！`);
        if (!confirmed) {
            return;
        }
        
        // 发送请求
        const response = await fetch('/admin/api/DeleteSensitiveWord.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ id: id })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '删除失败');
        }
        
        showSuccessToast('删除敏感词成功', '操作成功');
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('删除敏感词失败:', error);
        showError(error.message || '删除敏感词失败');
    }
}


/**
 * 初始化全选复选框
 */
function initSelectAllCheckbox() {
    const selectAll = document.getElementById('select-all-sensitive-words');
    if (!selectAll) return;
    
    selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
}

/**
 * 显示批量导入弹窗
 */
function showBatchImportModal() {
    const modal = document.getElementById('batch-import-modal');
    if (!modal) return;
    
    // 重置表单
    document.getElementById('batch-import-category').value = '';
    document.getElementById('batch-import-words').value = '';
    document.getElementById('batch-import-description').value = '';
    
    // 显示弹窗
    modal.classList.add('show');
    
    // 绑定导入按钮事件
    const btnImport = document.getElementById('btn-batch-import');
    if (btnImport) {
        btnImport.onclick = batchImportSensitiveWords;
    }
}

/**
 * 关闭批量导入弹窗
 */
function closeBatchImportModal() {
    const modal = document.getElementById('batch-import-modal');
    if (modal) {
        modal.classList.remove('show');
    }
}

/**
 * 批量导入敏感词
 */
async function batchImportSensitiveWords() {
    try {
        const category = document.getElementById('batch-import-category').value;
        const wordsText = document.getElementById('batch-import-words').value.trim();
        const description = document.getElementById('batch-import-description').value.trim();
        
        // 验证
        if (!category) {
            throw new Error('请选择分类');
        }
        
        if (!wordsText) {
            throw new Error('请输入敏感词');
        }
        
        // 分割敏感词（支持中英文逗号）
        const words = wordsText.split(/[,，]/).map(w => w.trim()).filter(w => w);
        
        if (words.length === 0) {
            throw new Error('没有有效的敏感词');
        }
        
        // 去重
        const uniqueWords = [...new Set(words)];
        
        // 构建请求数据
        const data = {
            words: uniqueWords,
            category: category,
            description: description
        };
        
        // 发送请求
        const response = await fetch('/admin/api/BatchImportSensitiveWords.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '批量导入失败');
        }
        
        showSuccessToast(result.message, '操作成功');
        
        // 关闭弹窗
        closeBatchImportModal();
        
        // 重新加载列表
        loadSensitiveWordList(1);
        
    } catch (error) {
        console.error('批量导入敏感词失败:', error);
        showError(error.message || '批量导入失败');
    }
}

/**
 * 批量删除敏感词
 */
async function batchDeleteSensitiveWords() {
    try {
        // 获取选中的复选框
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox:checked');
        
        if (checkboxes.length === 0) {
            throw new Error('请至少选择一个敏感词');
        }
        
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        
        const confirmed = await showConfirm(
            '批量删除敏感词',
            `确定要删除选中的 ${ids.length} 个敏感词吗？\n\n此操作不可恢复！`
        );
        
        if (!confirmed) {
            return;
        }
        
        // 发送请求
        const response = await fetch('/admin/api/BatchDeleteSensitiveWords.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ ids: ids })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '批量删除失败');
        }
        
        showSuccessToast(result.message, '操作成功');
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('批量删除敏感词失败:', error);
        showError(error.message || '批量删除失败');
    }
}


/**
 * 批量启用敏感词
 */
async function batchEnableSensitiveWords() {
    try {
        // 获取选中的复选框
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox:checked');
        
        if (checkboxes.length === 0) {
            throw new Error('请至少选择一个敏感词');
        }
        
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        
        const confirmed = await showConfirm(
            '批量启用敏感词',
            `确定要启用选中的 ${ids.length} 个敏感词吗？`
        );
        
        if (!confirmed) {
            return;
        }
        
        // 发送请求
        const response = await fetch('/admin/api/BatchUpdateSensitiveWords.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ 
                ids: ids,
                action: 'enable'
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '批量启用失败');
        }
        
        showSuccessToast(result.message, '操作成功');
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('批量启用敏感词失败:', error);
        showError(error.message || '批量启用失败');
    }
}

/**
 * 批量禁用敏感词
 */
async function batchDisableSensitiveWords() {
    try {
        // 获取选中的复选框
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox:checked');
        
        if (checkboxes.length === 0) {
            throw new Error('请至少选择一个敏感词');
        }
        
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        
        const confirmed = await showConfirm(
            '批量禁用敏感词',
            `确定要禁用选中的 ${ids.length} 个敏感词吗？`
        );
        
        if (!confirmed) {
            return;
        }
        
        // 发送请求
        const response = await fetch('/admin/api/BatchUpdateSensitiveWords.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ 
                ids: ids,
                action: 'disable'
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '批量禁用失败');
        }
        
        showSuccessToast(result.message, '操作成功');
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('批量禁用敏感词失败:', error);
        showError(error.message || '批量禁用失败');
    }
}

/**
 * 显示批量更改分类弹窗
 */
function showBatchChangeCategoryModal() {
    try {
        // 获取选中的复选框
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox:checked');
        
        if (checkboxes.length === 0) {
            throw new Error('请至少选择一个敏感词');
        }
        
        const modal = document.getElementById('batch-change-category-modal');
        if (!modal) return;
        
        // 重置表单
        document.getElementById('batch-change-category').value = '';
        
        // 显示弹窗
        modal.classList.add('show');
        
        // 绑定确定按钮事件
        const btnChange = document.getElementById('btn-batch-change-category');
        if (btnChange) {
            btnChange.onclick = batchChangeCategory;
        }
        
    } catch (error) {
        console.error('显示批量更改分类弹窗失败:', error);
        showError(error.message || '操作失败');
    }
}

/**
 * 关闭批量更改分类弹窗
 */
function closeBatchChangeCategoryModal() {
    const modal = document.getElementById('batch-change-category-modal');
    if (modal) {
        modal.classList.remove('show');
    }
}

/**
 * 批量更改分类
 */
async function batchChangeCategory() {
    try {
        const category = document.getElementById('batch-change-category').value;
        
        if (!category) {
            throw new Error('请选择新分类');
        }
        
        // 获取选中的复选框
        const checkboxes = document.querySelectorAll('.sensitive-word-checkbox:checked');
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        
        // 发送请求
        const response = await fetch('/admin/api/BatchUpdateSensitiveWords.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ 
                ids: ids,
                action: 'change_category',
                category: category
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '批量更改分类失败');
        }
        
        showSuccessToast(result.message, '操作成功');
        
        // 关闭弹窗
        closeBatchChangeCategoryModal();
        
        // 重新加载列表
        loadSensitiveWordList(currentSensitiveWordPage);
        
    } catch (error) {
        console.error('批量更改分类失败:', error);
        showError(error.message || '批量更改分类失败');
    }
}


/**
 * ============================================
 * 头像审核配置管理
 * ============================================
 */

/**
 * 加载头像审核配置
 */
async function loadAvatarCheckConfig() {
    try {
        const response = await fetch('/admin/api/GetAvatarCheckConfig.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '加载头像审核配置失败');
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('avatar-is-enabled').checked = config.enabled || false;
        document.getElementById('avatar-check-type').value = config.check_type || 'manual';
        document.getElementById('avatar-api-key').value = config.api_key || '';
        document.getElementById('avatar-api-secret').value = config.api_secret || '';
        document.getElementById('avatar-region').value = config.region || '';
        
        // 根据审核方式显示/隐藏第三方配置
        toggleThirdPartyConfig();
        
        // 监听审核方式变化
        const checkTypeSelect = document.getElementById('avatar-check-type');
        if (checkTypeSelect) {
            checkTypeSelect.addEventListener('change', toggleThirdPartyConfig);
        }
        
    } catch (error) {
        console.error('加载头像审核配置失败:', error);
        showError(error.message || '加载头像审核配置失败');
    }
}

/**
 * 切换第三方配置显示
 */
function toggleThirdPartyConfig() {
    const checkType = document.getElementById('avatar-check-type').value;
    const thirdPartyConfig = document.getElementById('third-party-config');
    
    if (thirdPartyConfig) {
        if (checkType === 'manual') {
            thirdPartyConfig.style.display = 'none';
        } else {
            thirdPartyConfig.style.display = 'block';
        }
    }
}

/**
 * 保存头像审核配置
 */
async function saveAvatarCheckConfig() {
    try {
        const enabled = document.getElementById('avatar-is-enabled').checked;
        const checkType = document.getElementById('avatar-check-type').value;
        const apiKey = document.getElementById('avatar-api-key').value.trim();
        const apiSecret = document.getElementById('avatar-api-secret').value.trim();
        const region = document.getElementById('avatar-region').value.trim();
        
        // 验证
        if (enabled && checkType !== 'manual') {
            if (!apiKey) {
                throw new Error('请输入API密钥');
            }
            if (!apiSecret) {
                throw new Error('请输入API密钥');
            }
        }
        
        // 构建请求数据
        const data = {
            enabled: enabled,
            check_type: checkType,
            api_key: apiKey,
            api_secret: apiSecret,
            region: region
        };
        
        // 发送请求
        const response = await fetch('/admin/api/SaveAvatarCheckConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast('配置已保存', '保存成功');
        
        // 重新加载配置
        loadAvatarCheckConfig();
        
    } catch (error) {
        console.error('保存头像审核配置失败:', error);
        showError(error.message || '保存配置失败');
    }
}

/**
 * ========================================
 * 存储策略配置相关函数
 * ========================================
 */

/**
 * 加载存储配置列表
 */
async function loadStorageConfigList() {
    const loadingEl = document.getElementById('storage-config-list-loading');
    const emptyEl = document.getElementById('storage-config-list-empty');
    const tableEl = document.getElementById('storage-config-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'flex';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        const response = await fetch('/admin/api/GetStorageConfigList.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showError(result.message || '加载存储配置列表失败');
            emptyEl.style.display = 'flex';
            return;
        }
        
        const configs = result.data || [];
        
        if (configs.length === 0) {
            emptyEl.style.display = 'flex';
        } else {
            tableEl.style.display = 'block';
            renderStorageConfigList(configs);
        }
        
    } catch (error) {
        console.error('加载存储配置列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'flex';
        showError('网络错误，请稍后重试');
    }
}

/**
 * 渲染存储配置列表
 */
function renderStorageConfigList(configs) {
    const tbody = document.getElementById('storage-config-list-tbody');
    tbody.innerHTML = '';
    
    configs.forEach(config => {
        const tr = document.createElement('tr');
        
        // 状态样式
        const statusClass = config.enabled ? 'status-normal' : 'status-disabled';
        const statusText = config.enabled ? '启用' : '禁用';
        
        tr.innerHTML = `
            <td>${config.id}</td>
            <td>${config.config_name}</td>
            <td>${config.usage_type_name}</td>
            <td>${config.storage_type_name}</td>
            <td>
                <span class="user-status-badge ${statusClass}">${statusText}</span>
            </td>
            <td>${config.max_file_size_mb} MB</td>
            <td>${config.allowed_extensions || '不限制'}</td>
            <td>
                <button class="btn-action" onclick="showEditStorageConfigModal(${config.id})">
                    <i class="fas fa-edit"></i> 编辑
                </button>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 显示编辑存储配置弹窗
 */
async function showEditStorageConfigModal(id) {
    const modal = document.getElementById('edit-storage-config-modal');
    const title = document.getElementById('storage-config-modal-title');
    
    // 重置表单
    document.getElementById('storage-config-id').value = '';
    document.getElementById('storage-config-name').value = '';
    document.getElementById('storage-usage-type').value = '';
    document.getElementById('storage-type').value = 'local';
    document.getElementById('storage-enabled').value = '1';
    document.getElementById('local-path').value = '';
    document.getElementById('local-url-prefix').value = '';
    document.getElementById('auto-create-path').checked = false;
    document.getElementById('s3-endpoint').value = '';
    document.getElementById('s3-region').value = '';
    document.getElementById('s3-bucket').value = '';
    document.getElementById('s3-path').value = '';
    document.getElementById('s3-auto-create-path').checked = false;
    document.getElementById('s3-access-key').value = '';
    document.getElementById('s3-secret-key').value = '';
    document.getElementById('s3-use-path-style').value = '0';
    document.getElementById('s3-url-prefix').value = '';
    document.getElementById('max-file-size').value = '5';
    document.getElementById('allowed-extensions').value = '';
    
    if (id) {
        // 编辑模式
        title.textContent = '编辑存储配置';
        
        try {
            const response = await fetch('/admin/api/GetStorageConfigList.php', {
                method: 'GET',
                credentials: 'include'
            });
            
            const result = await response.json();
            
            if (!result.success) {
                showError(result.message || '获取配置详情失败');
                return;
            }
            
            const config = result.data.find(c => c.id === id);
            
            if (!config) {
                showError('配置不存在');
                return;
            }
            
            // 填充表单
            document.getElementById('storage-config-id').value = config.id;
            document.getElementById('storage-config-name').value = config.config_name;
            document.getElementById('storage-usage-type').value = config.usage_type;
            document.getElementById('storage-usage-type').disabled = true; // 用途类型不允许修改
            document.getElementById('storage-type').value = config.storage_type;
            document.getElementById('storage-enabled').value = config.enabled ? '1' : '0';
            document.getElementById('local-path').value = config.local_path || '';
            document.getElementById('local-url-prefix').value = config.local_url_prefix || '';
            document.getElementById('auto-create-path').checked = config.local_auto_create_path || false;
            document.getElementById('s3-endpoint').value = config.s3_endpoint || '';
            document.getElementById('s3-region').value = config.s3_region || '';
            document.getElementById('s3-bucket').value = config.s3_bucket || '';
            document.getElementById('s3-path').value = config.s3_path || '';
            document.getElementById('s3-access-key').value = config.s3_access_key || '';
            document.getElementById('s3-secret-key').value = config.s3_secret_key || '';
            document.getElementById('s3-use-path-style').value = config.s3_use_path_style ? '1' : '0';
            document.getElementById('s3-url-prefix').value = config.s3_url_prefix || '';
            document.getElementById('s3-auto-create-path').checked = config.s3_auto_create_path || false;
            document.getElementById('max-file-size').value = config.max_file_size_mb;
            document.getElementById('allowed-extensions').value = config.allowed_extensions || '';
            
        } catch (error) {
            console.error('获取配置详情失败:', error);
            showError('网络错误，请稍后重试');
            return;
        }
    } else {
        // 新增模式
        title.textContent = '新增存储配置';
        document.getElementById('storage-usage-type').disabled = false;
    }
    
    // 切换存储类型字段显示
    toggleStorageTypeFields();
    
    // 显示弹窗
    modal.classList.add('show');
}

/**
 * 关闭编辑存储配置弹窗
 */
function closeEditStorageConfigModal() {
    const modal = document.getElementById('edit-storage-config-modal');
    modal.classList.remove('show');
}

/**
 * 切换存储类型字段显示
 */
function toggleStorageTypeFields() {
    const storageType = document.getElementById('storage-type').value;
    const localFields = document.getElementById('local-storage-fields');
    const s3Fields = document.getElementById('s3-storage-fields');
    
    if (storageType === 'local') {
        localFields.style.display = 'block';
        s3Fields.style.display = 'none';
    } else if (storageType === 's3') {
        localFields.style.display = 'none';
        s3Fields.style.display = 'block';
    }
}

/**
 * 保存存储配置
 */
async function saveStorageConfig() {
    const id = document.getElementById('storage-config-id').value;
    const configName = document.getElementById('storage-config-name').value.trim();
    const usageType = document.getElementById('storage-usage-type').value;
    const storageType = document.getElementById('storage-type').value;
    const enabled = document.getElementById('storage-enabled').value === '1';
    const maxFileSizeMB = parseFloat(document.getElementById('max-file-size').value);
    const allowedExtensions = document.getElementById('allowed-extensions').value.trim();
    
    // 验证必填字段
    if (!configName) {
        showError('请输入配置名称');
        return;
    }
    
    if (!usageType) {
        showError('请选择用途类型');
        return;
    }
    
    if (!maxFileSizeMB || maxFileSizeMB <= 0) {
        showError('请输入有效的最大文件大小');
        return;
    }
    
    // 构建数据
    const data = {
        config_name: configName,
        usage_type: usageType,
        storage_type: storageType,
        enabled: enabled,
        max_file_size: Math.round(maxFileSizeMB * 1048576), // 转换为字节
        allowed_extensions: allowedExtensions
    };
    
    if (id) {
        data.id = parseInt(id);
    }
    
    // 根据存储类型添加相应字段
    if (storageType === 'local') {
        const localPath = document.getElementById('local-path').value.trim();
        const localUrlPrefix = document.getElementById('local-url-prefix').value.trim();
        const localAutoCreatePath = document.getElementById('auto-create-path').checked;
        
        if (!localPath) {
            showError('请输入本地存储路径');
            return;
        }
        
        if (!localUrlPrefix) {
            showError('请输入URL前缀');
            return;
        }
        
        data.local_path = localPath;
        data.local_url_prefix = localUrlPrefix;
        data.local_auto_create_path = localAutoCreatePath;
        data.s3_endpoint = '';
        data.s3_region = '';
        data.s3_bucket = '';
        data.s3_path = '';
        data.s3_access_key = '';
        data.s3_secret_key = '';
        data.s3_use_path_style = false;
        data.s3_url_prefix = '';
        data.s3_auto_create_path = false;
        
    } else if (storageType === 's3') {
        const s3Endpoint = document.getElementById('s3-endpoint').value.trim();
        const s3Region = document.getElementById('s3-region').value.trim();
        const s3Bucket = document.getElementById('s3-bucket').value.trim();
        const s3Path = document.getElementById('s3-path').value.trim();
        const s3AccessKey = document.getElementById('s3-access-key').value.trim();
        const s3SecretKey = document.getElementById('s3-secret-key').value.trim();
        const s3UsePathStyle = document.getElementById('s3-use-path-style').value === '1';
        const s3UrlPrefix = document.getElementById('s3-url-prefix').value.trim();
        const s3AutoCreatePath = document.getElementById('s3-auto-create-path').checked;
        
        if (!s3Endpoint) {
            showError('请输入S3服务端点');
            return;
        }
        
        if (!s3Bucket) {
            showError('请输入存储桶名称');
            return;
        }
        
        if (!s3AccessKey) {
            showError('请输入访问密钥');
            return;
        }
        
        if (!s3SecretKey) {
            showError('请输入密钥');
            return;
        }
        
        data.local_path = '';
        data.local_url_prefix = '';
        data.local_auto_create_path = false;
        data.s3_endpoint = s3Endpoint;
        data.s3_region = s3Region;
        data.s3_bucket = s3Bucket;
        data.s3_path = s3Path;
        data.s3_access_key = s3AccessKey;
        data.s3_secret_key = s3SecretKey;
        data.s3_use_path_style = s3UsePathStyle;
        data.s3_url_prefix = s3UrlPrefix;
        data.s3_auto_create_path = s3AutoCreatePath;
    }
    
    // 禁用保存按钮
    const saveBtn = document.getElementById('btn-save-storage-config');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    try {
        const response = await fetch('/admin/api/SaveStorageConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '保存失败');
        }
        
        showSuccessToast('配置已保存', '保存成功');
        
        // 关闭弹窗
        closeEditStorageConfigModal();
        
        // 重新加载列表
        loadStorageConfigList();
        
    } catch (error) {
        console.error('保存存储配置失败:', error);
        showError(error.message || '保存配置失败');
    } finally {
        // 恢复保存按钮
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

// ============================================
// 昵称审核管理
// ============================================

let currentNicknameCheckPage = 1;
let currentNicknameCheckPageSize = 20;
let currentNicknameCheckId = null;

/**
 * 加载昵称审核列表
 */
async function loadNicknameCheckList(page = 1) {
    currentNicknameCheckPage = page;
    
    const status = document.getElementById('nickname-status-filter').value;
    const tbody = document.getElementById('nickname-check-list');
    
    // 显示加载中
    tbody.innerHTML = '<tr><td colspan="8" class="text-center"><i class="fas fa-spinner fa-spin"></i> 加载中...</td></tr>';
    
    try {
        const params = new URLSearchParams({
            page: currentNicknameCheckPage,
            page_size: currentNicknameCheckPageSize
        });
        
        if (status !== '') {
            params.append('status', status);
        }
        
        const response = await fetch(`/admin/api/GetNicknameCheckList.php?${params}`);
        const result = await response.json();
        
        if (result.success) {
            renderNicknameCheckList(result.data.list);
            renderNicknamePagination(result.data.pagination);
        } else {
            showToast(result.message || '加载失败', 'error');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">加载失败</td></tr>';
        }
    } catch (error) {
        console.error('加载昵称审核列表失败:', error);
        showToast('加载失败', 'error');
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">加载失败</td></tr>';
    }
}

/**
 * 渲染昵称审核列表
 */
function renderNicknameCheckList(list) {
    const tbody = document.getElementById('nickname-check-list');
    
    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">暂无数据</td></tr>';
        return;
    }
    
    tbody.innerHTML = list.map(item => {
        const statusClass = item.status === 0 ? 'pending' : (item.status === 1 ? 'approved' : 'rejected');
        const statusText = item.status === 0 ? '待审核' : (item.status === 1 ? '已通过' : '已拒绝');
        const statusBadge = item.status === 0 ? 'badge-warning' : (item.status === 1 ? 'badge-success' : 'badge-danger');
        
        // 处理敏感词
        let sensitiveWordsHtml = '-';
        if (item.sensitive_words && item.sensitive_words.length > 0) {
            sensitiveWordsHtml = '<div class="sensitive-words-preview">' +
                item.sensitive_words.slice(0, 3).map(word => 
                    `<span class="sensitive-word-mini">${escapeHtml(word)}</span>`
                ).join('') +
                (item.sensitive_words.length > 3 ? `<span class="sensitive-word-mini">+${item.sensitive_words.length - 3}</span>` : '') +
                '</div>';
        }
        
        // 操作按钮
        let actionButtons = '';
        if (item.status === 0) {
            actionButtons = `
                <button class="btn-sm btn-primary" onclick="openNicknameReviewModal(${item.id})">
                    <i class="fas fa-eye"></i> 审核
                </button>
            `;
        } else {
            actionButtons = `
                <button class="btn-sm btn-secondary" onclick="viewNicknameCheckDetail(${item.id})">
                    <i class="fas fa-info-circle"></i> 详情
                </button>
            `;
        }
        
        return `
            <tr class="${statusClass}">
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <img src="${escapeHtml(item.avatar)}" alt="头像" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                        <div>
                            <div style="font-weight: 500;">${escapeHtml(item.username || '未知用户')}</div>
                            <div style="font-size: 12px; color: #666;">UUID: ${item.user_uuid}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="nickname-badge old">${escapeHtml(item.old_nickname || '-')}</span>
                </td>
                <td>
                    <span class="nickname-badge new">${escapeHtml(item.new_nickname)}</span>
                </td>
                <td>
                    <div>${formatDateTime(item.apply_time)}</div>
                    <div style="font-size: 12px; color: #666;">${escapeHtml(item.apply_ip || '-')}</div>
                </td>
                <td>
                    <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(item.apply_reason || '-')}">
                        ${escapeHtml(item.apply_reason || '-')}
                    </div>
                </td>
                <td>${sensitiveWordsHtml}</td>
                <td>
                    <span class="badge ${statusBadge}">${statusText}</span>
                    ${item.auto_reviewed ? '<br><span class="badge badge-info" style="margin-top: 5px;">自动审核</span>' : ''}
                </td>
                <td>${actionButtons}</td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染分页
 */
function renderNicknamePagination(pagination) {
    const container = document.getElementById('nickname-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination">';
    
    // 上一页
    if (pagination.page > 1) {
        html += `<button class="page-btn" onclick="loadNicknameCheckList(${pagination.page - 1})">上一页</button>`;
    }
    
    // 页码
    const startPage = Math.max(1, pagination.page - 2);
    const endPage = Math.min(pagination.total_pages, pagination.page + 2);
    
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="loadNicknameCheckList(1)">1</button>`;
        if (startPage > 2) {
            html += '<span class="page-ellipsis">...</span>';
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === pagination.page ? 'active' : ''}" onclick="loadNicknameCheckList(${i})">${i}</button>`;
    }
    
    if (endPage < pagination.total_pages) {
        if (endPage < pagination.total_pages - 1) {
            html += '<span class="page-ellipsis">...</span>';
        }
        html += `<button class="page-btn" onclick="loadNicknameCheckList(${pagination.total_pages})">${pagination.total_pages}</button>`;
    }
    
    // 下一页
    if (pagination.page < pagination.total_pages) {
        html += `<button class="page-btn" onclick="loadNicknameCheckList(${pagination.page + 1})">下一页</button>`;
    }
    
    html += '</div>';
    html += `<div class="pagination-info">共 ${pagination.total} 条记录，第 ${pagination.page}/${pagination.total_pages} 页</div>`;
    
    container.innerHTML = html;
}

/**
 * 打开昵称审核弹窗
 */
async function openNicknameReviewModal(checkId) {
    currentNicknameCheckId = checkId;
    
    try {
        // 获取审核详情
        const params = new URLSearchParams({
            status: '0',
            page: 1,
            page_size: 100
        });
        
        const response = await fetch(`/admin/api/GetNicknameCheckList.php?${params}`);
        const result = await response.json();
        
        if (result.success) {
            const item = result.data.list.find(i => i.id === checkId);
            if (!item) {
                showToast('审核记录不存在', 'error');
                return;
            }
            
            // 填充数据
            document.getElementById('review-user-avatar').src = item.avatar;
            document.getElementById('review-username').textContent = item.username || '未知用户';
            document.getElementById('review-apply-time').innerHTML = `<i class="fas fa-clock"></i> ${formatDateTime(item.apply_time)}`;
            document.getElementById('review-apply-ip').innerHTML = `<i class="fas fa-map-marker-alt"></i> ${item.apply_ip || '-'}`;
            document.getElementById('review-old-nickname').textContent = item.old_nickname || '-';
            document.getElementById('review-new-nickname').textContent = item.new_nickname;
            document.getElementById('review-apply-reason').textContent = item.apply_reason || '无';
            
            // 处理敏感词
            const sensitiveWordsGroup = document.getElementById('review-sensitive-words-group');
            if (item.sensitive_words && item.sensitive_words.length > 0) {
                sensitiveWordsGroup.style.display = 'block';
                document.getElementById('review-sensitive-words').innerHTML = item.sensitive_words.map(word => 
                    `<span class="sensitive-word-tag"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(word)}</span>`
                ).join('');
            } else {
                sensitiveWordsGroup.style.display = 'none';
            }
            
            // 清空输入
            document.getElementById('review-comment').value = '';
            document.getElementById('reject-reason').value = '';
            document.getElementById('reject-reason-group').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('nickname-review-modal').classList.add('active');
        } else {
            showToast(result.message || '加载失败', 'error');
        }
    } catch (error) {
        console.error('加载审核详情失败:', error);
        showToast('加载失败', 'error');
    }
}

/**
 * 关闭昵称审核弹窗
 */
function closeNicknameReviewModal() {
    document.getElementById('nickname-review-modal').classList.remove('active');
    currentNicknameCheckId = null;
}

/**
 * 处理昵称审核
 */
async function handleNicknameReview(action) {
    if (!currentNicknameCheckId) {
        showToast('无效的审核ID', 'error');
        return;
    }
    
    const reviewComment = document.getElementById('review-comment').value.trim();
    const rejectReason = document.getElementById('reject-reason').value.trim();
    
    // 如果是拒绝操作，显示拒绝原因输入框
    if (action === 'reject') {
        const rejectReasonGroup = document.getElementById('reject-reason-group');
        if (rejectReasonGroup.style.display === 'none') {
            rejectReasonGroup.style.display = 'block';
            document.getElementById('reject-reason').focus();
            return;
        }
        
        if (!rejectReason) {
            showToast('请填写拒绝原因', 'warning');
            document.getElementById('reject-reason').focus();
            return;
        }
    }
    
    // 确认操作
    const confirmTitle = action === 'approve' ? '通过审核' : '拒绝审核';
    const confirmMessage = action === 'approve' ? '确认通过此昵称审核？' : '确认拒绝此昵称审核？';
    const confirmed = await showConfirm(confirmTitle, confirmMessage);
    if (!confirmed) return;
    
    try {
        const response = await fetch('/admin/api/ApproveNickname.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                check_id: currentNicknameCheckId,
                action: action,
                review_comment: reviewComment,
                reject_reason: rejectReason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message || '操作成功', 'success');
            closeNicknameReviewModal();
            loadNicknameCheckList(currentNicknameCheckPage);
        } else {
            showToast(result.message || '操作失败', 'error');
        }
    } catch (error) {
        console.error('审核操作失败:', error);
        showToast('操作失败', 'error');
    }
}

/**
 * 查看昵称审核详情
 */
function viewNicknameCheckDetail(checkId) {
    showToast('详情查看功能开发中', 'info');
}

// ============================================
// 头像审核管理
// ============================================

let currentAvatarCheckPage = 1;
let currentAvatarCheckPageSize = 20;
let currentAvatarCheckId = null;

/**
 * 初始化头像审核筛选器
 */
function initAvatarCheckFilters() {
    const statusFilter = document.getElementById('avatar-status-filter');
    
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            loadAvatarCheckList(1);
        });
    }
}

/**
 * 生成头像代理URL
 * 用于访问需要权限验证的头像
 * 所有待审核的头像都必须通过代理API访问
 * 
 * @param {number} checkId 审核记录ID
 * @param {string} avatarUrl 头像URL（用于判断是否是默认头像）
 * @return {string} 代理URL或默认头像URL
 */
function getAvatarProxyUrl(checkId, avatarUrl) {
    if (!avatarUrl) return 'https://avatar.ywxmz.com/user-6380868_1920.png';
    
    // 如果是默认头像，直接返回
    if (avatarUrl.includes('avatar.ywxmz.com')) {
        return avatarUrl;
    }
    
    // 如果没有审核记录ID，返回默认头像
    if (!checkId) {
        return 'https://avatar.ywxmz.com/user-6380868_1920.png';
    }
    
    // 通过审核记录ID访问头像
    // 后端会从数据库查询存储配置，然后获取图片内容
    return `/admin/api/GetAvatarImage.php?check_id=${checkId}`;
}

/**
 * 加载头像审核列表
 */
async function loadAvatarCheckList(page = 1) {
    currentAvatarCheckPage = page;
    
    const status = document.getElementById('avatar-status-filter').value;
    const tbody = document.getElementById('avatar-check-list');
    
    // 显示加载中
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i> 加载中...</td></tr>';
    
    try {
        const params = new URLSearchParams({
            page: currentAvatarCheckPage,
            page_size: currentAvatarCheckPageSize
        });
        
        if (status !== '') {
            params.append('status', status);
        }
        
        const response = await fetch(`/admin/api/GetAvatarCheckList.php?${params}`);
        const result = await response.json();
        
        if (result.success) {
            renderAvatarCheckList(result.data.list);
            renderAvatarPagination(result.data.pagination);
        } else {
            showToast(result.message || '加载失败', 'error');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">加载失败</td></tr>';
        }
    } catch (error) {
        console.error('加载头像审核列表失败:', error);
        showToast('加载失败', 'error');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">加载失败</td></tr>';
    }
}

/**
 * 渲染头像审核列表
 */
function renderAvatarCheckList(list) {
    const tbody = document.getElementById('avatar-check-list');
    
    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无数据</td></tr>';
        return;
    }
    
    tbody.innerHTML = list.map(item => {
        const statusClass = item.status === 0 ? 'pending' : (item.status === 1 ? 'approved' : 'rejected');
        const statusText = item.status === 0 ? '待审核' : (item.status === 1 ? '已通过' : '已拒绝');
        const statusBadge = item.status === 0 ? 'badge-warning' : (item.status === 1 ? 'badge-success' : 'badge-danger');
        
        // 操作按钮
        let actionButtons = '';
        if (item.status === 0) {
            actionButtons = `
                <button class="btn-sm btn-primary" onclick="openAvatarReviewModal(${item.id})">
                    <i class="fas fa-eye"></i> 审核
                </button>
            `;
        } else if (item.status === 2) {
            // 已拒绝的可以删除
            actionButtons = `
                <button class="btn-sm btn-secondary" onclick="viewAvatarCheckDetail(${item.id})">
                    <i class="fas fa-info-circle"></i> 详情
                </button>
                <button class="btn-sm btn-danger" onclick="deleteRejectedAvatar(${item.id}, '${escapeHtml(item.username)}')">
                    <i class="fas fa-trash"></i> 删除
                </button>
            `;
        } else {
            actionButtons = `
                <button class="btn-sm btn-secondary" onclick="viewAvatarCheckDetail(${item.id})">
                    <i class="fas fa-info-circle"></i> 详情
                </button>
            `;
        }
        
        return `
            <tr class="${statusClass}">
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <img src="${getAvatarProxyUrl(item.id, item.current_avatar)}" alt="头像" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://avatar.ywxmz.com/user-6380868_1920.png';this.onerror=null;">
                        <div>
                            <div style="font-weight: 500;">${escapeHtml(item.nickname || item.username || '未知用户')}</div>
                            <div style="font-size: 12px; color: #666;">UUID: ${item.user_uuid}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <img src="${getAvatarProxyUrl(item.id, item.new_avatar)}" alt="新头像" 
                         style="width: 60px; height: 60px; border-radius: 8px; object-fit: cover; cursor: pointer;"
                         onclick="previewImage('${getAvatarProxyUrl(item.id, item.new_avatar)}')"
                         onerror="this.src='https://avatar.ywxmz.com/user-6380868_1920.png';this.onerror=null;">
                </td>
                <td>
                    <div>${formatDateTime(item.upload_time)}</div>
                    <div style="font-size: 12px; color: #666;">${escapeHtml(item.upload_ip || '-')}</div>
                </td>
                <td>
                    ${item.auto_reviewed ? '<span class="badge badge-info">自动审核</span>' : '<span class="badge badge-secondary">人工审核</span>'}
                </td>
                <td>
                    <span class="badge ${statusBadge}">${statusText}</span>
                </td>
                <td>${actionButtons}</td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染分页
 */
function renderAvatarPagination(pagination) {
    const container = document.getElementById('avatar-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination">';
    
    // 上一页
    if (pagination.page > 1) {
        html += `<button class="page-btn" onclick="loadAvatarCheckList(${pagination.page - 1})">上一页</button>`;
    }
    
    // 页码
    const startPage = Math.max(1, pagination.page - 2);
    const endPage = Math.min(pagination.total_pages, pagination.page + 2);
    
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="loadAvatarCheckList(1)">1</button>`;
        if (startPage > 2) {
            html += '<span class="page-ellipsis">...</span>';
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === pagination.page ? 'active' : ''}" onclick="loadAvatarCheckList(${i})">${i}</button>`;
    }
    
    if (endPage < pagination.total_pages) {
        if (endPage < pagination.total_pages - 1) {
            html += '<span class="page-ellipsis">...</span>';
        }
        html += `<button class="page-btn" onclick="loadAvatarCheckList(${pagination.total_pages})">${pagination.total_pages}</button>`;
    }
    
    // 下一页
    if (pagination.page < pagination.total_pages) {
        html += `<button class="page-btn" onclick="loadAvatarCheckList(${pagination.page + 1})">下一页</button>`;
    }
    
    html += '</div>';
    html += `<div class="pagination-info">共 ${pagination.total} 条记录，第 ${pagination.page}/${pagination.total_pages} 页</div>`;
    
    container.innerHTML = html;
}

/**
 * 打开头像审核弹窗
 */
async function openAvatarReviewModal(checkId) {
    currentAvatarCheckId = checkId;
    
    try {
        // 获取审核详情
        const params = new URLSearchParams({
            status: '0',
            page: 1,
            page_size: 100
        });
        
        const response = await fetch(`/admin/api/GetAvatarCheckList.php?${params}`);
        const result = await response.json();
        
        if (result.success) {
            const item = result.data.list.find(i => i.id === checkId);
            if (!item) {
                showToast('审核记录不存在', 'error');
                return;
            }
            
            // 填充数据
            document.getElementById('review-avatar-user-avatar').src = getAvatarProxyUrl(item.id, item.current_avatar);
            document.getElementById('review-avatar-username').textContent = item.nickname || item.username || '未知用户';
            document.getElementById('review-avatar-upload-time').innerHTML = `<i class="fas fa-clock"></i> ${formatDateTime(item.upload_time)}`;
            document.getElementById('review-avatar-upload-ip').innerHTML = `<i class="fas fa-map-marker-alt"></i> ${item.upload_ip || '-'}`;
            document.getElementById('review-avatar-old').src = getAvatarProxyUrl(item.id, item.old_avatar || item.current_avatar);
            document.getElementById('review-avatar-new').src = getAvatarProxyUrl(item.id, item.new_avatar);
            
            // 填充存储信息
            const storageTypeText = item.storage_type === 'local' ? '本地存储' : item.storage_type === 's3' ? 'S3对象存储' : '未知';
            document.getElementById('review-avatar-storage-type').textContent = storageTypeText;
            document.getElementById('review-avatar-storage-name').textContent = item.storage_name || '-';
            
            // 显示文件路径（相对路径）和存储配置ID
            const filenameText = item.new_avatar_filename || '-';
            const storageConfigId = item.storage_config_id || '-';
            document.getElementById('review-avatar-filename').textContent = `${filenameText} (配置ID: ${storageConfigId})`;
            
            // 清空输入
            document.getElementById('review-avatar-comment').value = '';
            document.getElementById('reject-avatar-reason').value = '';
            document.getElementById('reject-avatar-reason-group').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('avatar-review-modal').classList.add('active');
        } else {
            showToast(result.message || '加载失败', 'error');
        }
    } catch (error) {
        console.error('加载审核详情失败:', error);
        showToast('加载失败', 'error');
    }
}

/**
 * 关闭头像审核弹窗
 */
function closeAvatarReviewModal() {
    document.getElementById('avatar-review-modal').classList.remove('active');
    currentAvatarCheckId = null;
}

/**
 * 处理头像审核
 */
async function handleAvatarReview(action) {
    if (!currentAvatarCheckId) {
        showToast('无效的审核ID', 'error');
        return;
    }
    
    const reviewComment = document.getElementById('review-avatar-comment').value.trim();
    const rejectReason = document.getElementById('reject-avatar-reason').value.trim();
    
    // 如果是拒绝操作，显示拒绝原因输入框
    if (action === 'reject') {
        const rejectReasonGroup = document.getElementById('reject-avatar-reason-group');
        if (rejectReasonGroup.style.display === 'none') {
            rejectReasonGroup.style.display = 'block';
            document.getElementById('reject-avatar-reason').focus();
            return;
        }
        
        if (!rejectReason) {
            showToast('请填写拒绝原因', 'warning');
            document.getElementById('reject-avatar-reason').focus();
            return;
        }
    }
    
    // 确认操作
    const confirmTitle = action === 'approve' ? '通过审核' : '拒绝审核';
    let confirmMessage = action === 'approve' ? '确认通过此头像审核？头像将更新为新头像。' : '确认拒绝此头像审核？';
    
    // 如果是拒绝操作，询问是否删除头像
    let deleteAvatar = false;
    if (action === 'reject') {
        const deleteConfirmed = await showConfirm(
            '删除头像',
            '是否同时删除已上传的头像文件？\n\n点击"确定"将删除文件，点击"取消"将保留文件。'
        );
        deleteAvatar = deleteConfirmed;
    }
    
    const confirmed = await showConfirm(confirmTitle, confirmMessage);
    if (!confirmed) return;
    
    try {
        const response = await fetch('/admin/api/ApproveAvatar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                check_id: currentAvatarCheckId,
                action: action,
                review_comment: reviewComment,
                message: rejectReason,
                delete_avatar: deleteAvatar
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message || '操作成功', 'success');
            closeAvatarReviewModal();
            loadAvatarCheckList(currentAvatarCheckPage);
        } else {
            showToast(result.message || '操作失败', 'error');
        }
    } catch (error) {
        console.error('审核操作失败:', error);
        showToast('操作失败', 'error');
    }
}

/**
 * 删除已拒绝的头像
 */
async function deleteRejectedAvatar(checkId, username) {
    const confirmed = await showConfirm(
        '删除头像',
        `确认删除用户 "${username}" 被拒绝的头像吗？\n\n此操作将从存储中删除该头像文件。`
    );
    
    if (!confirmed) return;
    
    try {
        const response = await fetch('/admin/api/DeleteRejectedAvatar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                check_id: checkId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('删除成功', 'success');
            loadAvatarCheckList(currentAvatarCheckPage);
        } else {
            showToast(result.message || '删除失败', 'error');
        }
    } catch (error) {
        console.error('删除头像失败:', error);
        showToast('删除失败', 'error');
    }
}

/**
 * 查看头像审核详情
 */
function viewAvatarCheckDetail(checkId) {
    showToast('详情查看功能开发中', 'info');
}

/**
 * 预览图片
 */
function previewImage(url) {
    window.open(url, '_blank');
}


// ============================================
// 授权应用管理
// ============================================

let currentOAuthAppId = null;
let currentOAuthAppPage = 1;
let oauthAppSearchKeyword = '';
let oauthAppStatusFilter = '';
let isLoadingOAuthAppList = false; // 防止重复请求应用列表
let currentDefaultAppId = null; // 当前默认用户中心应用ID

/**
 * 初始化授权应用管理
 */
function initOAuthApps() {
    // 搜索框
    const searchInput = document.getElementById('oauth-app-search');
    if (searchInput) {
        // 防止浏览器自动填充 - 强化版
        searchInput.setAttribute('readonly', 'readonly');
        searchInput.value = '';
        
        setTimeout(() => {
            searchInput.removeAttribute('readonly');
            searchInput.value = ''; // 再次确保清空
        }, 500);
        
        // 监听输入事件
        searchInput.addEventListener('input', debounce(function() {
            const value = this.value.trim();
            // 如果输入包含@符号，认为是邮箱，立即清空
            if (value.includes('@')) {
                console.warn('检测到输入邮箱格式，已清空');
                this.value = '';
                oauthAppSearchKeyword = '';
                return;
            }
            oauthAppSearchKeyword = value;
            currentOAuthAppPage = 1;
            loadOAuthApps();
        }, 500));
        
        // 阻止浏览器的自动填充行为
        searchInput.addEventListener('focus', function() {
            // 聚焦时立即检查并清空邮箱
            if (this.value && this.value.includes('@')) {
                console.warn('聚焦时检测到邮箱，已清空:', this.value);
                this.value = '';
                oauthAppSearchKeyword = '';
            }
            if (this.hasAttribute('data-autofilled')) {
                this.value = '';
                this.removeAttribute('data-autofilled');
            }
        });
        
        // 监听blur事件，失焦时也检查
        searchInput.addEventListener('blur', function() {
            if (this.value && this.value.includes('@')) {
                console.warn('失焦时检测到邮箱，已清空:', this.value);
                this.value = '';
                oauthAppSearchKeyword = '';
            }
        });
        
        // 监听change事件
        searchInput.addEventListener('change', function() {
            if (this.value && this.value.includes('@')) {
                console.warn('change事件检测到邮箱，已清空:', this.value);
                this.value = '';
                oauthAppSearchKeyword = '';
            }
        });
        
        // 使用 setInterval 定期检查搜索框的值（更频繁）
        setInterval(function() {
            if (!searchInput) return;
            const value = searchInput.value;
            if (value && value.includes('@')) {
                console.warn('定期检查：检测到搜索框被自动填充邮箱，已清空:', value);
                searchInput.value = '';
                oauthAppSearchKeyword = '';
            }
        }, 50); // 从100ms改为50ms，更频繁地检查
    }

    // 状态筛选
    const statusFilter = document.getElementById('oauth-app-status-filter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            oauthAppStatusFilter = this.value;
            currentOAuthAppPage = 1;
            loadOAuthApps();
        });
    }

    // 创建应用按钮
    const btnCreate = document.getElementById('btn-create-oauth-app');
    if (btnCreate) {
        btnCreate.addEventListener('click', openCreateOAuthAppModal);
    }

    // 标签页切换
    initOAuthAppTabs();

    // 加载应用列表
    loadOAuthApps();
}

/**
 * 加载授权应用列表
 */
async function loadOAuthApps() {
    // 防止重复请求
    if (isLoadingOAuthAppList) {
        console.warn('正在加载应用列表，忽略重复请求');
        return;
    }
    
    isLoadingOAuthAppList = true;
    
    // 先加载默认应用信息
    await loadDefaultUserCenterApp();
    
    try {
        // 在加载前先检查并清空搜索框中的邮箱
        const searchInput = document.getElementById('oauth-app-search');
        if (searchInput && searchInput.value && searchInput.value.includes('@')) {
            console.warn('loadOAuthApps: 检测到搜索框被填充邮箱，已清空:', searchInput.value);
            searchInput.value = '';
            oauthAppSearchKeyword = '';
        }
        
        const loading = document.getElementById('oauth-apps-loading');
        const empty = document.getElementById('oauth-apps-empty');
        const list = document.getElementById('oauth-apps-list');
        const pagination = document.getElementById('oauth-apps-pagination');

        loading.style.display = 'block';
        empty.style.display = 'none';
        list.style.display = 'none';
        pagination.style.display = 'none';

        const params = new URLSearchParams({
            page: currentOAuthAppPage,
            page_size: 12
        });

        if (oauthAppSearchKeyword) {
            params.append('keyword', oauthAppSearchKeyword);
        }

        if (oauthAppStatusFilter) {
            params.append('status', oauthAppStatusFilter);
        }

        const response = await fetch(`api/GetOAuthAppList.php?${params}`);
        const data = await response.json();

        loading.style.display = 'none';

        if (!data.success) {
            showToast(data.message || '加载失败', 'error');
            return;
        }

        if (data.data.apps.length === 0) {
            empty.style.display = 'block';
            return;
        }

        list.innerHTML = `
            <div class="table-scroll-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>应用名称</th>
                            <th>应用ID</th>
                            <th>网站地址</th>
                            <th>授权用户</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.data.apps.map(app => {
                            // 状态文本和样式
                            const statusConfig = {
                                1: { text: '正常', class: 'status-normal' },
                                0: { text: '封禁', class: 'status-banned' },
                                2: { text: '待审核', class: 'status-pending' }
                            };
                            const status = statusConfig[app.status] || { text: '未知', class: 'status-unknown' };
                            
                            // 检查是否为默认应用
                            const isDefault = currentDefaultAppId === app.app_id;
                            
                            return `
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="app-icon-small">
                                                ${app.app_icon_url ? 
                                                    `<img src="${escapeHtml(app.app_icon_url)}" alt="应用图标" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-cube\\'></i>'">` : 
                                                    `<i class="fas fa-cube"></i>`
                                                }
                                            </div>
                                            <div>
                                                <div>${escapeHtml(app.site_name)}</div>
                                                ${isDefault ? '<span class="badge-default"><i class="fas fa-star"></i> 默认应用</span>' : ''}
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="font-size: 12px; color: #666;">${escapeHtml(app.app_id)}</code>
                                    </td>
                                    <td>${escapeHtml(app.site_url || '-')}</td>
                                    <td>
                                        <span style="color: #1976d2; font-weight: 500;">
                                            <i class="fas fa-users"></i> ${app.authorized_users || 0}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="app-status-badge ${status.class}">${status.text}</span>
                                    </td>
                                    <td>${formatDateTime(app.created_at)}</td>
                                    <td>
                                        <button class="btn-action btn-view-app" data-app-id="${escapeHtml(app.app_id)}">
                                            <i class="fas fa-eye"></i> 查看
                                        </button>
                                        <button class="btn-action btn-edit-app" data-app-id="${escapeHtml(app.app_id)}">
                                            <i class="fas fa-edit"></i> 编辑
                                        </button>
                                        ${app.status === 1 ? `
                                            ${!isDefault ? `
                                                <button class="btn-action btn-set-default" data-app-id="${escapeHtml(app.app_id)}" title="设为用户中心默认应用">
                                                    <i class="fas fa-star"></i> 设为默认
                                                </button>
                                            ` : ''}
                                            <button class="btn-action danger btn-toggle-status" data-app-id="${escapeHtml(app.app_id)}" data-status="0">
                                                <i class="fas fa-ban"></i> 封禁
                                            </button>
                                        ` : `
                                            <button class="btn-action btn-toggle-status" data-app-id="${escapeHtml(app.app_id)}" data-status="1">
                                                <i class="fas fa-check"></i> 启用
                                            </button>
                                        `}
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;

        list.style.display = 'block';

        // 移除旧的事件监听器（如果存在）
        const oldList = document.getElementById('oauth-apps-list');
        if (oldList && oldList._clickHandler) {
            oldList.removeEventListener('click', oldList._clickHandler);
        }

        // 创建新的事件处理器
        const clickHandler = function(e) {
            const target = e.target.closest('button');
            if (!target) return;

            e.stopPropagation();
            e.preventDefault();

            // 在处理按钮点击前，检查并清空搜索框中的邮箱
            const searchInput = document.getElementById('oauth-app-search');
            if (searchInput && searchInput.value && searchInput.value.includes('@')) {
                console.warn('按钮点击时检测到搜索框被填充邮箱，已清空:', searchInput.value);
                searchInput.value = '';
                oauthAppSearchKeyword = '';
            }

            const appId = target.dataset.appId;
            
            if (target.classList.contains('btn-view-app')) {
                console.log('查看按钮被点击，appId:', appId);
                viewOAuthApp(appId);
            } else if (target.classList.contains('btn-edit-app')) {
                console.log('编辑按钮被点击，appId:', appId);
                editOAuthApp(appId);
            } else if (target.classList.contains('btn-toggle-status')) {
                const status = parseInt(target.dataset.status);
                console.log('状态切换按钮被点击，appId:', appId, 'status:', status);
                toggleAppStatus(appId, status);
            } else if (target.classList.contains('btn-set-default')) {
                console.log('设为默认按钮被点击，appId:', appId);
                setDefaultUserCenterApp(appId);
            }
        };

        // 保存事件处理器引用，以便后续移除
        list._clickHandler = clickHandler;
        
        // 添加事件监听器
        list.addEventListener('click', clickHandler);

        // 渲染分页
        renderOAuthAppPagination(data.data);
        pagination.style.display = 'flex';

    } catch (error) {
        const loading = document.getElementById('oauth-apps-loading');
        if (loading) loading.style.display = 'none';
        console.error('加载应用列表失败:', error);
        showToast('加载失败，请重试', 'error');
    } finally {
        // 请求完成后重置标志
        isLoadingOAuthAppList = false;
    }
}

/**
 * 渲染分页
 */
function renderOAuthAppPagination(data) {
    document.getElementById('oauth-apps-total').textContent = data.total;

    const pagesContainer = document.getElementById('oauth-apps-pages');
    const totalPages = data.total_pages;

    if (totalPages <= 1) {
        pagesContainer.innerHTML = '';
        return;
    }

    let html = '';

    // 上一页
    if (currentOAuthAppPage > 1) {
        html += `<button class="page-btn" onclick="gotoOAuthAppPage(${currentOAuthAppPage - 1})">上一页</button>`;
    }

    // 页码
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentOAuthAppPage - 2 && i <= currentOAuthAppPage + 2)) {
            html += `<button class="page-btn ${i === currentOAuthAppPage ? 'active' : ''}" onclick="gotoOAuthAppPage(${i})">${i}</button>`;
        } else if (i === currentOAuthAppPage - 3 || i === currentOAuthAppPage + 3) {
            html += `<span class="page-ellipsis">...</span>`;
        }
    }

    // 下一页
    if (currentOAuthAppPage < totalPages) {
        html += `<button class="page-btn" onclick="gotoOAuthAppPage(${currentOAuthAppPage + 1})">下一页</button>`;
    }

    pagesContainer.innerHTML = html;
}

/**
 * 跳转到指定页
 */
function gotoOAuthAppPage(page) {
    currentOAuthAppPage = page;
    loadOAuthApps();
}

/**
 * 打开创建应用模态框
 */
function openCreateOAuthAppModal() {
    currentOAuthAppId = null;
    document.getElementById('oauth-app-modal-title').textContent = '创建应用';
    document.getElementById('btn-delete-app').style.display = 'none';
    document.getElementById('btn-reset-secret').style.display = 'none';
    document.getElementById('tab-stats').style.display = 'none';

    // 清空表单
    document.getElementById('app-site-name').value = '';
    document.getElementById('app-id').value = '自动生成';
    document.getElementById('app-secret-key').value = '自动生成';
    document.getElementById('app-site-url').value = '';
    document.getElementById('app-site-protocol').value = 'https';
    document.getElementById('app-icon-url').value = '';
    document.getElementById('app-description').value = '';
    document.getElementById('app-status').value = '1';
    document.getElementById('app-callback-mode').value = 'strict';

    // 清空权限
    document.querySelectorAll('#app-permissions input[type="checkbox"]').forEach(cb => {
        cb.checked = cb.value === 'user.basic';
    });

    // 清空回调地址
    document.getElementById('callback-urls-list').innerHTML = '';
    addCallbackUrl();

    // 默认登录配置
    document.getElementById('app-enable-register').checked = true;
    document.getElementById('app-enable-phone-register').checked = true;
    document.getElementById('app-enable-email-register').checked = true;
    document.getElementById('app-enable-login').checked = true;
    document.getElementById('app-enable-password-login').checked = true;
    document.getElementById('app-enable-email-code-login').checked = false;
    document.getElementById('app-enable-phone-code-login').checked = false;
    document.getElementById('app-enable-third-party-login').checked = false;
    document.getElementById('app-enable-qq-login').checked = false;
    document.getElementById('app-enable-wechat-login').checked = false;
    document.getElementById('app-enable-weibo-login').checked = false;
    document.getElementById('app-enable-github-login').checked = false;
    document.getElementById('app-enable-google-login').checked = false;

    // 切换到基本信息标签页
    switchOAuthAppTab('basic');

    document.getElementById('oauth-app-modal').classList.add('show');
}

/**
 * 查看应用详情
 */
async function viewOAuthApp(appId) {
    console.log('=== viewOAuthApp 开始 ===');
    console.log('appId:', appId);
    
    if (!appId || appId === 'undefined' || appId === 'null') {
        console.error('无效的 appId:', appId);
        showToast('应用ID无效', 'error');
        return;
    }
    
    // 直接调用 editOAuthApp，不需要额外的逻辑
    editOAuthApp(appId, true);
    console.log('=== viewOAuthApp 结束 ===');
}

// 防止重复请求的标志
let isLoadingAppDetail = false;
let lastLoadedAppId = null;

/**
 * 编辑应用
 */
async function editOAuthApp(appId, viewOnly = false) {
    console.log('=== editOAuthApp 开始 ===');
    console.log('appId:', appId, 'viewOnly:', viewOnly);
    
    if (!appId || appId === 'undefined' || appId === 'null') {
        console.error('无效的 appId:', appId);
        showToast('应用ID无效', 'error');
        return;
    }
    
    // 防止重复请求
    if (isLoadingAppDetail) {
        console.warn('正在加载应用详情，忽略重复请求');
        return;
    }
    
    // 如果是同一个应用且弹窗已经打开，直接返回
    const modal = document.getElementById('oauth-app-modal');
    if (lastLoadedAppId === appId && modal.classList.contains('show')) {
        console.log('应用详情已加载，直接显示弹窗');
        return;
    }
    
    isLoadingAppDetail = true;
    lastLoadedAppId = appId;
    
    currentOAuthAppId = appId;
    document.getElementById('oauth-app-modal-title').textContent = viewOnly ? '应用详情' : '编辑应用';
    document.getElementById('btn-delete-app').style.display = viewOnly ? 'none' : 'inline-block';
    document.getElementById('btn-reset-secret').style.display = 'inline-block';
    document.getElementById('tab-stats').style.display = 'block';
    document.getElementById('btn-save-app').style.display = viewOnly ? 'none' : 'inline-block';

    try {
        const url = `api/GetOAuthAppDetail.php?app_id=${encodeURIComponent(appId)}`;
        console.log('正在请求:', url);
        
        const response = await fetch(url);
        console.log('响应状态:', response.status);
        
        const data = await response.json();
        console.log('API 响应数据:', data);

        if (!data.success) {
            console.error('API 返回失败:', data.message);
            showToast(data.message || '加载失败', 'error');
            isLoadingAppDetail = false;
            return;
        }

        const app = data.data;
        console.log('应用数据:', app);

        // 填充基本信息
        document.getElementById('app-site-name').value = app.site_name || '';
        document.getElementById('app-id').value = app.app_id || '';
        document.getElementById('app-secret-key').value = app.secret_key || '';
        document.getElementById('app-site-url').value = app.site_url || '';
        document.getElementById('app-site-protocol').value = app.site_protocol || 'https';
        document.getElementById('app-icon-url').value = app.app_icon_url || '';
        document.getElementById('app-description').value = app.description || '';
        document.getElementById('app-status').value = app.status || 1;
        document.getElementById('app-callback-mode').value = app.callback_mode || 'strict';

        // 填充权限
        document.querySelectorAll('#app-permissions input[type="checkbox"]').forEach(cb => {
            cb.checked = app.permissions && Array.isArray(app.permissions) && app.permissions.includes(cb.value);
        });

        // 填充回调地址
        const callbackList = document.getElementById('callback-urls-list');
        callbackList.innerHTML = '';
        if (app.callback_urls && Array.isArray(app.callback_urls) && app.callback_urls.length > 0) {
            app.callback_urls.forEach(url => addCallbackUrl(url));
        } else {
            addCallbackUrl();
        }

        // 填充登录配置
        document.getElementById('app-enable-register').checked = app.enable_register || false;
        document.getElementById('app-enable-phone-register').checked = app.enable_phone_register || false;
        document.getElementById('app-enable-email-register').checked = app.enable_email_register || false;
        document.getElementById('app-enable-login').checked = app.enable_login || false;
        document.getElementById('app-enable-password-login').checked = app.enable_password_login || false;
        document.getElementById('app-enable-email-code-login').checked = app.enable_email_code_login || false;
        document.getElementById('app-enable-phone-code-login').checked = app.enable_phone_code_login || false;
        document.getElementById('app-enable-third-party-login').checked = app.enable_third_party_login || false;
        document.getElementById('app-enable-qq-login').checked = app.enable_qq_login || false;
        document.getElementById('app-enable-wechat-login').checked = app.enable_wechat_login || false;
        document.getElementById('app-enable-weibo-login').checked = app.enable_weibo_login || false;
        document.getElementById('app-enable-github-login').checked = app.enable_github_login || false;
        document.getElementById('app-enable-google-login').checked = app.enable_google_login || false;

        // 填充统计信息
        document.getElementById('app-authorized-users').textContent = app.authorized_users || 0;
        document.getElementById('app-created-at').textContent = app.created_at ? formatDateTime(app.created_at) : '-';
        document.getElementById('app-updated-at').textContent = app.updated_at ? formatDateTime(app.updated_at) : '-';

        // 如果是查看模式，禁用所有输入
        if (viewOnly) {
            document.querySelectorAll('#oauth-app-modal input, #oauth-app-modal select, #oauth-app-modal textarea').forEach(el => {
                el.disabled = true;
            });
        } else {
            // 启用所有输入
            document.querySelectorAll('#oauth-app-modal input, #oauth-app-modal select, #oauth-app-modal textarea').forEach(el => {
                el.disabled = false;
            });
        }

        // 切换到基本信息标签页
        console.log('切换到基本信息标签页');
        switchOAuthAppTab('basic');

        // 显示弹窗
        console.log('弹窗元素:', modal);
        console.log('弹窗当前类名:', modal.className);
        
        modal.classList.add('show');
        
        console.log('添加 show 类后的类名:', modal.className);
        console.log('弹窗显示状态:', window.getComputedStyle(modal).display);
        console.log('=== editOAuthApp 结束 ===');

    } catch (error) {
        console.error('=== editOAuthApp 发生错误 ===');
        console.error('错误详情:', error);
        console.error('错误堆栈:', error.stack);
        showToast('加载失败，请重试', 'error');
    } finally {
        // 请求完成后重置标志
        isLoadingAppDetail = false;
    }
}

/**
 * 保存应用
 */
async function saveOAuthApp() {
    const siteName = document.getElementById('app-site-name').value.trim();
    const siteUrl = document.getElementById('app-site-url').value.trim();

    if (!siteName) {
        showToast('请输入应用名称', 'warning');
        return;
    }

    if (!siteUrl) {
        showToast('请输入网站地址', 'warning');
        return;
    }

    // 收集回调地址
    const callbackUrls = [];
    document.querySelectorAll('.callback-url-input').forEach(input => {
        const url = input.value.trim();
        if (url) callbackUrls.push(url);
    });

    // 收集权限
    const permissions = [];
    document.querySelectorAll('#app-permissions input[type="checkbox"]:checked').forEach(cb => {
        permissions.push(cb.value);
    });

    const data = {
        site_name: siteName,
        site_url: siteUrl,
        site_protocol: document.getElementById('app-site-protocol').value,
        app_icon_url: document.getElementById('app-icon-url').value.trim(),
        description: document.getElementById('app-description').value.trim(),
        permissions: permissions,
        callback_urls: callbackUrls,
        callback_mode: document.getElementById('app-callback-mode').value,
        enable_register: document.getElementById('app-enable-register').checked,
        enable_phone_register: document.getElementById('app-enable-phone-register').checked,
        enable_email_register: document.getElementById('app-enable-email-register').checked,
        enable_login: document.getElementById('app-enable-login').checked,
        enable_password_login: document.getElementById('app-enable-password-login').checked,
        enable_email_code_login: document.getElementById('app-enable-email-code-login').checked,
        enable_phone_code_login: document.getElementById('app-enable-phone-code-login').checked,
        enable_third_party_login: document.getElementById('app-enable-third-party-login').checked,
        enable_qq_login: document.getElementById('app-enable-qq-login').checked,
        enable_wechat_login: document.getElementById('app-enable-wechat-login').checked,
        enable_weibo_login: document.getElementById('app-enable-weibo-login').checked,
        enable_github_login: document.getElementById('app-enable-github-login').checked,
        enable_google_login: document.getElementById('app-enable-google-login').checked
    };

    try {
        let url, method;
        if (currentOAuthAppId) {
            // 更新应用
            url = 'api/UpdateOAuthApp.php';
            data.app_id = currentOAuthAppId;
            data.status = document.getElementById('app-status').value;
        } else {
            // 创建应用
            url = 'api/CreateOAuthApp.php';
        }

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // 如果是创建，显示应用ID和密钥
            if (!currentOAuthAppId && result.data) {
                showAppCreatedSuccess(result.data.app_id, result.data.secret_key);
            } else {
                showToast(result.message || '保存成功', 'success');
            }
            
            closeOAuthAppModal();
            loadOAuthApps();
        } else {
            showToast(result.message || '保存失败', 'error');
        }
    } catch (error) {
        console.error('保存应用失败:', error);
        showToast('保存失败，请重试', 'error');
    }
}

/**
 * 删除应用
 */
async function deleteOAuthApp() {
    if (!currentOAuthAppId) return;

    showConfirmDialog('确定要删除此应用吗？此操作不可恢复！', async () => {
        try {
            const response = await fetch('api/DeleteOAuthApp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    app_id: currentOAuthAppId,
                    force: false
                })
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '删除成功', 'success');
                closeOAuthAppModal();
                loadOAuthApps();
            } else {
                // 如果有授权用户，询问是否强制删除
                if (result.data && result.data.authorized_users > 0) {
                    showConfirmDialog(`${result.message}\n\n是否强制删除？这将同时删除所有授权记录。`, async () => {
                        await forceDeleteOAuthApp();
                    });
                } else {
                    showToast(result.message || '删除失败', 'error');
                }
            }
        } catch (error) {
            console.error('删除应用失败:', error);
            showToast('删除失败，请重试', 'error');
        }
    });
}

/**
 * 强制删除应用
 */
async function forceDeleteOAuthApp() {
    try {
        const response = await fetch('api/DeleteOAuthApp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                app_id: currentOAuthAppId,
                force: true
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(result.message || '删除成功', 'success');
            closeOAuthAppModal();
            loadOAuthApps();
        } else {
            showToast(result.message || '删除失败', 'error');
        }
    } catch (error) {
        console.error('强制删除应用失败:', error);
        showToast('删除失败，请重试', 'error');
    }
}

/**
 * 切换应用状态
 */
async function toggleAppStatus(appId, status) {
    const statusText = status === 1 ? '启用' : '封禁';
    const message = status === 1 ? '确定要启用此应用吗？' : '确定要封禁此应用吗？封禁后该应用将无法使用。';
    
    // 使用自定义确认弹窗
    showConfirmDialog(message, async () => {
        try {
            const response = await fetch('api/UpdateOAuthAppStatus.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    app_id: appId,
                    status: status
                })
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || '操作成功', 'success');
                loadOAuthApps();
            } else {
                showToast(result.message || '操作失败', 'error');
            }
        } catch (error) {
            console.error('更新应用状态失败:', error);
            showToast('操作失败，请重试', 'error');
        }
    });
}

/**
 * 重置应用密钥
 */
async function resetAppSecret() {
    if (!currentOAuthAppId) return;

    showConfirmDialog('确定要重置应用密钥吗？旧密钥将立即失效！', async () => {
        try {
            const response = await fetch('api/ResetOAuthAppSecret.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    app_id: currentOAuthAppId
                })
            });

            const result = await response.json();

            if (result.success) {
                document.getElementById('app-secret-key').value = result.data.secret_key;
                showToast('密钥已重置', 'success');
                
                // 使用自定义弹窗显示新密钥
                showConfirmDialog(
                    `新密钥: ${result.data.secret_key}\n\n请妥善保管并及时通知应用开发者更新密钥！`,
                    () => {}, // 确定按钮不需要额外操作
                    () => {}  // 取消按钮不需要额外操作
                );
            } else {
                showToast(result.message || '重置失败', 'error');
            }
        } catch (error) {
            console.error('重置密钥失败:', error);
            showToast('重置失败，请重试', 'error');
        }
    });
}

/**
 * 切换密钥可见性
 */
function toggleSecretVisibility() {
    const input = document.getElementById('app-secret-key');
    const icon = document.getElementById('secret-eye-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

/**
 * 添加回调地址输入框
 */
function addCallbackUrl(url = '') {
    const container = document.getElementById('callback-urls-list');
    const div = document.createElement('div');
    div.className = 'callback-url-item';
    div.innerHTML = `
        <input type="url" class="form-control callback-url-input" placeholder="https://example.com/callback" value="${escapeHtml(url)}">
        <button class="btn btn-danger btn-sm" onclick="removeCallbackUrl(this)">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(div);
}

/**
 * 移除回调地址输入框
 */
function removeCallbackUrl(btn) {
    btn.parentElement.remove();
}

/**
 * 初始化标签页切换
 */
function initOAuthAppTabs() {
    document.querySelectorAll('.tab-header').forEach(header => {
        header.addEventListener('click', function() {
            const tab = this.dataset.tab;
            switchOAuthAppTab(tab);
        });
    });
}

/**
 * 切换标签页
 */
function switchOAuthAppTab(tab) {
    // 切换标签头
    document.querySelectorAll('.tab-header').forEach(h => h.classList.remove('active'));
    document.querySelector(`.tab-header[data-tab="${tab}"]`).classList.add('active');

    // 切换标签内容
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector(`.tab-content[data-tab="${tab}"]`).classList.add('active');
}

/**
 * 关闭模态框
 */
function closeOAuthAppModal() {
    document.getElementById('oauth-app-modal').classList.remove('show');
    currentOAuthAppId = null;
    
    // 恢复所有输入的启用状态
    document.querySelectorAll('#oauth-app-modal input, #oauth-app-modal select, #oauth-app-modal textarea').forEach(el => {
        el.disabled = false;
    });
}

/**
 * 显示应用创建成功弹窗
 */
function showAppCreatedSuccess(appId, secretKey) {
    document.getElementById('created-app-id').value = appId;
    document.getElementById('created-app-secret').value = secretKey;
    document.getElementById('app-created-success-modal').classList.add('show');
}

/**
 * 关闭应用创建成功弹窗
 */
function closeAppCreatedSuccessModal() {
    document.getElementById('app-created-success-modal').classList.remove('show');
    // 清空内容
    document.getElementById('created-app-id').value = '';
    document.getElementById('created-app-secret').value = '';
}

/**
 * 复制到剪贴板
 */
function copyToClipboard(elementId, label) {
    const element = document.getElementById(elementId);
    const text = element.value;
    
    // 使用现代的 Clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showToast(`${label}已复制到剪贴板`, 'success');
        }).catch(err => {
            console.error('复制失败:', err);
            // 降级到传统方法
            fallbackCopyToClipboard(element, label);
        });
    } else {
        // 降级到传统方法
        fallbackCopyToClipboard(element, label);
    }
}

/**
 * 降级的复制方法（兼容旧浏览器）
 */
function fallbackCopyToClipboard(element, label) {
    element.select();
    element.setSelectionRange(0, 99999); // 移动端兼容
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            showToast(`${label}已复制到剪贴板`, 'success');
        } else {
            showToast('复制失败，请手动复制', 'error');
        }
    } catch (err) {
        console.error('复制失败:', err);
        showToast('复制失败，请手动复制', 'error');
    }
    
    // 取消选择
    window.getSelection().removeAllRanges();
}

// 防抖函数
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const context = this;
        const later = () => {
            clearTimeout(timeout);
            func.apply(context, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}


/**
 * 加载默认用户中心应用
 */
async function loadDefaultUserCenterApp() {
    try {
        const response = await fetch('api/GetDefaultUserCenterApp.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success && result.data) {
            currentDefaultAppId = result.data.app_id;
        } else {
            currentDefaultAppId = null;
        }
    } catch (error) {
        console.error('加载默认用户中心应用失败:', error);
        currentDefaultAppId = null;
    }
}

/**
 * 设置默认用户中心应用（从应用列表中点击"设为默认"按钮）
 */
async function setDefaultUserCenterApp(appId) {
    if (!appId) return;
    
    // 直接打开更改默认应用弹窗，并预选中指定的应用
    await openChangeDefaultAppModalWithPreselect(appId);
}

/**
 * 打开更改默认应用弹窗并预选中指定应用
 */
async function openChangeDefaultAppModalWithPreselect(preselectedAppId = null) {
    const modal = document.getElementById('change-default-app-modal');
    const loading = document.getElementById('change-app-loading');
    const form = document.getElementById('change-app-form');
    
    modal.classList.add('show');
    loading.style.display = 'block';
    form.style.display = 'none';
    
    try {
        // 获取当前默认应用
        const currentAppResponse = await fetch('api/GetDefaultUserCenterApp.php', {
            method: 'GET',
            credentials: 'include'
        });
        const currentAppResult = await currentAppResponse.json();
        const currentAppId = currentAppResult.success && currentAppResult.data ? currentAppResult.data.app_id : null;
        
        // 加载所有正常状态的应用
        const response = await fetch('api/GetOAuthAppList.php?status=1&page_size=100', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        loading.style.display = 'none';
        
        if (!result.success || !result.data.apps || result.data.apps.length === 0) {
            showToast('没有可用的应用', 'warning');
            closeChangeDefaultAppModal();
            return;
        }
        
        // 保存所有应用数据
        allAppsData = result.data.apps.map(app => ({
            ...app,
            isCurrentDefault: app.app_id === currentAppId
        }));
        
        // 使用预选中的应用ID，如果没有则使用当前默认应用ID
        const selectedAppId = preselectedAppId || currentAppId;
        
        // 初始化自定义下拉框
        initCustomSelectWithPreselect(selectedAppId);
        
        form.style.display = 'block';
        
    } catch (error) {
        console.error('加载应用列表失败:', error);
        loading.style.display = 'none';
        showToast('加载失败，请重试', 'error');
        closeChangeDefaultAppModal();
    }
}

/**
 * 初始化自定义下拉框并预选中指定应用
 */
function initCustomSelectWithPreselect(selectedAppId) {
    const searchInput = document.getElementById('app-search-input');
    const dropdown = document.getElementById('app-dropdown');
    const optionsContainer = document.getElementById('app-options');
    const hiddenInput = document.getElementById('select-default-app');
    
    // 渲染所有选项
    renderAppOptions(allAppsData, selectedAppId);
    
    // 如果有选中的应用，设置搜索框显示
    if (selectedAppId) {
        const selectedApp = allAppsData.find(app => app.app_id === selectedAppId);
        if (selectedApp) {
            searchInput.value = `${selectedApp.site_name} (${selectedApp.app_id})`;
            hiddenInput.value = selectedAppId;
            // 触发选择事件显示应用信息
            setTimeout(() => {
                handleAppSelectionFromCustom(selectedApp);
            }, 0);
        }
    }
    
    // 移除旧的事件监听器
    const newSearchInput = searchInput.cloneNode(true);
    searchInput.parentNode.replaceChild(newSearchInput, searchInput);
    
    // 搜索框获得焦点时显示下拉列表
    newSearchInput.addEventListener('focus', function() {
        dropdown.style.display = 'block';
        renderAppOptions(allAppsData, hiddenInput.value);
    });
    
    // 搜索框输入时过滤选项
    newSearchInput.addEventListener('input', function() {
        const keyword = this.value.toLowerCase().trim();
        
        if (!keyword) {
            renderAppOptions(allAppsData, hiddenInput.value);
        } else {
            const filtered = allAppsData.filter(app => 
                app.site_name.toLowerCase().includes(keyword) || 
                app.app_id.toLowerCase().includes(keyword)
            );
            renderAppOptions(filtered, hiddenInput.value);
        }
        
        dropdown.style.display = 'block';
    });
    
    // 点击外部关闭下拉列表
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-select-wrapper')) {
            dropdown.style.display = 'none';
        }
    });
}


/**
 * 加载用户中心配置
 */
async function loadUserCenterConfig() {
    const loading = document.getElementById('user-center-config-loading');
    const empty = document.getElementById('user-center-config-empty');
    const info = document.getElementById('user-center-config-info');

    loading.style.display = 'block';
    empty.style.display = 'none';
    info.style.display = 'none';

    try {
        const response = await fetch('api/GetDefaultUserCenterApp.php', {
            method: 'GET',
            credentials: 'include'
        });

        const result = await response.json();

        loading.style.display = 'none';

        if (!result.success || !result.data) {
            empty.style.display = 'block';
            return;
        }

        const app = result.data;

        // 显示应用信息
        document.getElementById('user-center-app-name').textContent = app.site_name || '未命名应用';
        document.getElementById('user-center-app-id').textContent = `应用ID: ${app.app_id}`;
        document.getElementById('user-center-site-url').value = app.site_url || '-';
        document.getElementById('user-center-site-protocol').value = app.site_protocol || 'https';
        document.getElementById('user-center-callback-url').value = app.callback_url || '-';
        document.getElementById('user-center-permissions').value = app.permissions || '-';

        // 显示应用图标
        const iconElement = document.getElementById('user-center-app-icon');
        if (app.app_icon_url) {
            iconElement.innerHTML = `<img src="${escapeHtml(app.app_icon_url)}" alt="应用图标" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-cube\\'></i>'">`;
        } else {
            iconElement.innerHTML = '<i class="fas fa-cube"></i>';
        }

        info.style.display = 'block';

    } catch (error) {
        console.error('加载用户中心配置失败:', error);
        loading.style.display = 'none';
        empty.style.display = 'block';
        showToast('加载失败，请重试', 'error');
    }
}

/**
 * 刷新用户中心配置
 */
function refreshUserCenterConfig() {
    loadUserCenterConfig();
    showToast('配置已刷新', 'success');
}


/**
 * 打开更改默认应用弹窗
 */
async function openChangeDefaultAppModal() {
    // 调用带预选功能的函数，不传入预选ID则自动选中当前默认应用
    await openChangeDefaultAppModalWithPreselect(null);
}

/**
 * 渲染应用选项
 */
function renderAppOptions(apps, selectedAppId) {
    const optionsContainer = document.getElementById('app-options');
    
    if (apps.length === 0) {
        optionsContainer.innerHTML = `
            <div class="custom-select-empty">
                <i class="fas fa-search"></i>
                <div>未找到匹配的应用</div>
            </div>
        `;
        return;
    }
    
    optionsContainer.innerHTML = apps.map(app => {
        const isSelected = app.app_id === selectedAppId;
        const isCurrentDefault = app.isCurrentDefault;
        const classes = ['custom-select-option'];
        
        if (isSelected) classes.push('selected');
        if (isCurrentDefault) classes.push('current-default');
        
        return `
            <div class="${classes.join(' ')}" data-app-id="${escapeHtml(app.app_id)}" data-app-data='${JSON.stringify(app).replace(/'/g, '&apos;')}'>
                <div class="option-content">
                    <div class="option-name">${escapeHtml(app.site_name)}</div>
                    <div class="option-id">${escapeHtml(app.app_id)}</div>
                </div>
                ${isCurrentDefault ? '<span class="option-badge current">⭐ 当前默认</span>' : ''}
            </div>
        `;
    }).join('');
    
    // 添加点击事件
    optionsContainer.querySelectorAll('.custom-select-option').forEach(option => {
        option.addEventListener('click', function() {
            const appData = JSON.parse(this.dataset.appData);
            selectApp(appData);
        });
    });
}

/**
 * 选择应用
 */
function selectApp(app) {
    const searchInput = document.getElementById('app-search-input');
    const dropdown = document.getElementById('app-dropdown');
    const hiddenInput = document.getElementById('select-default-app');
    
    // 更新显示和值
    searchInput.value = `${app.site_name} (${app.app_id})`;
    hiddenInput.value = app.app_id;
    
    // 关闭下拉列表
    dropdown.style.display = 'none';
    
    // 更新选项样式
    renderAppOptions(allAppsData, app.app_id);
    
    // 触发应用选择处理
    handleAppSelectionFromCustom(app);
}

/**
 * 处理自定义下拉框的应用选择
 */
function handleAppSelectionFromCustom(app) {
    const confirmBtn = document.getElementById('btn-confirm-change-app');
    const appInfo = document.getElementById('selected-app-info');
    const callbackWarning = document.getElementById('callback-warning');
    
    if (!app) {
        confirmBtn.disabled = true;
        appInfo.style.display = 'none';
        callbackWarning.style.display = 'none';
        return;
    }
    
    // 显示应用信息
    document.getElementById('selected-app-name').textContent = app.site_name;
    document.getElementById('selected-app-id').textContent = app.app_id;
    document.getElementById('selected-app-url').textContent = app.site_url || '-';
    appInfo.style.display = 'block';
    
    // 检查是否需要添加回调地址
    const protocol = window.location.protocol;
    const host = window.location.host;
    const userCenterCallback = `${protocol}//${host}/user/callback/`;
    
    let callbackUrls = app.callback_urls || [];
    if (typeof callbackUrls === 'string') {
        try {
            callbackUrls = JSON.parse(callbackUrls);
        } catch (e) {
            callbackUrls = [callbackUrls];
        }
    }
    
    const needAddCallback = !callbackUrls.includes(userCenterCallback);
    
    if (needAddCallback) {
        document.getElementById('callback-warning-text').textContent = 
            `该应用的回调地址列表中不包含用户中心回调地址：${userCenterCallback}。确认后将自动添加此回调地址到应用配置中。`;
        callbackWarning.style.display = 'block';
    } else {
        callbackWarning.style.display = 'none';
    }
    
    confirmBtn.disabled = false;
}

/**
 * 处理应用选择
 */
function handleAppSelection(e) {
    const select = e.target;
    const selectedOption = select.options[select.selectedIndex];
    const confirmBtn = document.getElementById('btn-confirm-change-app');
    const appInfo = document.getElementById('selected-app-info');
    const callbackWarning = document.getElementById('callback-warning');
    
    if (!selectedOption.value) {
        confirmBtn.disabled = true;
        appInfo.style.display = 'none';
        callbackWarning.style.display = 'none';
        return;
    }
    
    const app = JSON.parse(selectedOption.dataset.appData);
    
    // 显示应用信息
    document.getElementById('selected-app-name').textContent = app.site_name;
    document.getElementById('selected-app-id').textContent = app.app_id;
    document.getElementById('selected-app-url').textContent = app.site_url || '-';
    appInfo.style.display = 'block';
    
    // 检查是否需要添加回调地址
    const protocol = window.location.protocol;
    const host = window.location.host;
    const userCenterCallback = `${protocol}//${host}/user/callback/`;
    
    let callbackUrls = app.callback_urls || [];
    if (typeof callbackUrls === 'string') {
        try {
            callbackUrls = JSON.parse(callbackUrls);
        } catch (e) {
            callbackUrls = [callbackUrls];
        }
    }
    
    const needAddCallback = !callbackUrls.includes(userCenterCallback);
    
    if (needAddCallback) {
        document.getElementById('callback-warning-text').innerHTML = `
            该应用的回调地址列表中不包含用户中心回调地址：<br>
            <code style="background: #fff; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 8px;">${userCenterCallback}</code><br>
            <span style="margin-top: 8px; display: inline-block;">确认后将自动添加此回调地址到应用配置中。</span>
        `;
        callbackWarning.style.display = 'block';
    } else {
        callbackWarning.style.display = 'none';
    }
    
    confirmBtn.disabled = false;
}

/**
 * 确认更改默认应用
 */
async function confirmChangeDefaultApp() {
    const select = document.getElementById('select-default-app');
    const appId = select.value;
    
    if (!appId) {
        showToast('请选择应用', 'warning');
        return;
    }
    
    const confirmBtn = document.getElementById('btn-confirm-change-app');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
    
    try {
        // 第一次请求：检查是否需要添加回调地址
        const checkResponse = await fetch('api/SetDefaultUserCenterApp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ app_id: appId })
        });
        
        const checkResult = await checkResponse.json();
        
        // 如果需要确认添加回调地址
        if (checkResult.success && checkResult.data && checkResult.data.need_confirm) {
            // 第二次请求：确认添加回调地址
            const confirmResponse = await fetch('api/SetDefaultUserCenterApp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    app_id: appId,
                    confirm_add_callback: true
                })
            });
            
            const confirmResult = await confirmResponse.json();
            
            if (confirmResult.success) {
                showToast('默认应用已更改', 'success');
                closeChangeDefaultAppModal();
                loadUserCenterConfig();
            } else {
                showToast(confirmResult.message || '更改失败', 'error');
            }
        } else if (checkResult.success) {
            // 不需要添加回调地址，直接成功
            showToast('默认应用已更改', 'success');
            closeChangeDefaultAppModal();
            loadUserCenterConfig();
        } else {
            showToast(checkResult.message || '更改失败', 'error');
        }
        
    } catch (error) {
        console.error('更改默认应用失败:', error);
        showToast('更改失败，请重试', 'error');
    } finally {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> 确认更改';
    }
}

/**
 * 关闭更改默认应用弹窗
 */
function closeChangeDefaultAppModal() {
    const modal = document.getElementById('change-default-app-modal');
    modal.classList.remove('show');
    
    // 重置表单
    document.getElementById('select-default-app').value = '';
    document.getElementById('selected-app-info').style.display = 'none';
    document.getElementById('callback-warning').style.display = 'none';
    document.getElementById('btn-confirm-change-app').disabled = true;
}


// ============================================
// 人机验证日志管理
// ============================================

/**
 * 当前人机验证日志分页信息
 */
let captchaLogCurrentPage = 1;
let captchaLogPageSize = 20;

/**
 * 初始化人机验证日志筛选器
 */
function initCaptchaLogFilters() {
    // 设置默认日期为今天
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('captcha-log-start-date').value = today;
    document.getElementById('captcha-log-end-date').value = today;
}

/**
 * 重置人机验证日志筛选条件
 */
function resetCaptchaLogFilters() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('captcha-log-start-date').value = today;
    document.getElementById('captcha-log-end-date').value = today;
    document.getElementById('captcha-log-scene').value = '';
    document.getElementById('captcha-log-success-filter').value = '';
    document.getElementById('captcha-log-provider').value = '';
    document.getElementById('captcha-log-ip').value = '';
    
    captchaLogCurrentPage = 1;
    loadCaptchaLogs();
}

/**
 * 加载人机验证日志列表
 */
async function loadCaptchaLogs(page = 1) {
    const tbody = document.getElementById('captcha-log-list-tbody');
    
    // 显示加载动画
    tbody.innerHTML = `
        <tr>
            <td colspan="8" style="text-align: center; padding: 60px 20px;">
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #667eea; margin-bottom: 20px;"></i>
                    <p style="font-size: 16px; color: #666; margin: 0;">加载中...</p>
                </div>
            </td>
        </tr>
    `;
    
    try {
        captchaLogCurrentPage = page;
        
        // 获取筛选条件
        const startDate = document.getElementById('captcha-log-start-date').value;
        const endDate = document.getElementById('captcha-log-end-date').value;
        const scene = document.getElementById('captcha-log-scene').value;
        const verifySuccess = document.getElementById('captcha-log-success-filter').value;
        const provider = document.getElementById('captcha-log-provider').value;
        const clientIp = document.getElementById('captcha-log-ip').value;
        
        // 调试日志
        console.log('筛选条件:', { startDate, endDate, scene, verifySuccess, provider, clientIp });
        
        // 构建查询参数
        const params = new URLSearchParams({
            page: page,
            page_size: captchaLogPageSize
        });
        
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (scene) params.append('scene', scene);
        // 修复：确保 verifySuccess 正确传递
        if (verifySuccess !== '' && verifySuccess !== 'undefined' && verifySuccess !== null) {
            params.append('verify_success', verifySuccess);
            console.log('添加 verify_success 参数:', verifySuccess);
        }
        if (provider) params.append('provider', provider);
        if (clientIp) params.append('client_ip', clientIp);
        
        console.log('请求URL:', `api/GetCaptchaLogList.php?${params.toString()}`);
        
        const response = await fetch(`api/GetCaptchaLogList.php?${params.toString()}`);
        const result = await response.json();
        
        console.log('API响应:', result);
        
        if (result.success) {
            renderCaptchaLogList(result.data.logs);
            renderCaptchaLogPagination(result.data.pagination);
            updateCaptchaLogStats(result.data.stats);
            renderSceneDistribution(result.data.scene_distribution);
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 60px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <i class="fas fa-exclamation-circle" style="font-size: 64px; color: #f5576c; margin-bottom: 20px;"></i>
                            <p style="font-size: 16px; color: #f5576c; margin: 0;">${escapeHtml(result.message || '加载日志失败')}</p>
                        </div>
                    </td>
                </tr>
            `;
            showToast(result.message || '加载日志失败', 'error');
        }
    } catch (error) {
        console.error('加载人机验证日志失败:', error);
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-exclamation-circle" style="font-size: 64px; color: #f5576c; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #f5576c; margin: 0;">获取日志失败</p>
                        <p style="font-size: 14px; color: #999; margin-top: 10px;">请检查网络连接或稍后重试</p>
                    </div>
                </td>
            </tr>
        `;
        showToast('加载日志失败', 'error');
    }
}

/**
 * 渲染人机验证日志列表
 */
function renderCaptchaLogList(logs) {
    const tbody = document.getElementById('captcha-log-list-tbody');
    
    if (!logs || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-inbox" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #999; margin: 0;">暂无日志数据</p>
                        <p style="font-size: 14px; color: #bbb; margin-top: 10px;">请调整筛选条件后重试</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const sceneText = getSceneText(log.scene);
        const providerText = getProviderText(log.provider);
        const statusBadge = log.verify_success 
            ? '<span class="badge badge-success"><i class="fas fa-check"></i> 成功</span>'
            : '<span class="badge badge-danger"><i class="fas fa-times"></i> 失败</span>';
        
        // 合并手机号和邮箱显示
        let contactInfo = '';
        if (log.phone && log.email) {
            contactInfo = `
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <div><i class="fas fa-phone" style="color: #667eea; width: 16px;"></i> ${escapeHtml(log.phone)}</div>
                    <div><i class="fas fa-envelope" style="color: #667eea; width: 16px;"></i> ${escapeHtml(log.email)}</div>
                </div>
            `;
        } else if (log.phone) {
            contactInfo = `<div><i class="fas fa-phone" style="color: #667eea; width: 16px;"></i> ${escapeHtml(log.phone)}</div>`;
        } else if (log.email) {
            contactInfo = `<div><i class="fas fa-envelope" style="color: #667eea; width: 16px;"></i> ${escapeHtml(log.email)}</div>`;
        } else {
            contactInfo = '-';
        }
        
        return `
            <tr>
                <td>${escapeHtml(log.id)}</td>
                <td>${escapeHtml(sceneText)}</td>
                <td>${escapeHtml(providerText)}</td>
                <td>${statusBadge}</td>
                <td>${escapeHtml(log.client_ip || '-')}</td>
                <td>${contactInfo}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewCaptchaLogDetail(${log.id})">
                        <i class="fas fa-eye"></i> 详情
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染人机验证日志分页
 */
function renderCaptchaLogPagination(pagination) {
    const container = document.getElementById('captcha-log-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination-info">';
    html += `共 ${pagination.total} 条记录，第 ${pagination.page} / ${pagination.total_pages} 页`;
    html += '</div><div class="pagination-buttons">';
    
    // 上一页
    if (pagination.page > 1) {
        html += `<button class="btn btn-sm" onclick="loadCaptchaLogs(${pagination.page - 1})">上一页</button>`;
    }
    
    // 页码
    const startPage = Math.max(1, pagination.page - 2);
    const endPage = Math.min(pagination.total_pages, pagination.page + 2);
    
    if (startPage > 1) {
        html += `<button class="btn btn-sm" onclick="loadCaptchaLogs(1)">1</button>`;
        if (startPage > 2) {
            html += '<span>...</span>';
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === pagination.page ? 'btn-primary' : '';
        html += `<button class="btn btn-sm ${activeClass}" onclick="loadCaptchaLogs(${i})">${i}</button>`;
    }
    
    if (endPage < pagination.total_pages) {
        if (endPage < pagination.total_pages - 1) {
            html += '<span>...</span>';
        }
        html += `<button class="btn btn-sm" onclick="loadCaptchaLogs(${pagination.total_pages})">${pagination.total_pages}</button>`;
    }
    
    // 下一页
    if (pagination.page < pagination.total_pages) {
        html += `<button class="btn btn-sm" onclick="loadCaptchaLogs(${pagination.page + 1})">下一页</button>`;
    }
    
    html += '</div>';
    container.innerHTML = html;
}

/**
 * 更新人机验证日志统计信息
 */
function updateCaptchaLogStats(stats) {
    document.getElementById('captcha-log-total').textContent = stats.total_count.toLocaleString();
    document.getElementById('captcha-log-success').textContent = stats.success_count.toLocaleString();
    document.getElementById('captcha-log-fail').textContent = stats.fail_count.toLocaleString();
    document.getElementById('captcha-log-rate').textContent = stats.success_rate + '%';
}

/**
 * 渲染场景分布
 */
function renderSceneDistribution(distribution) {
    const container = document.getElementById('captcha-scene-chart');
    const wrapper = document.getElementById('captcha-scene-distribution');
    
    if (!distribution || distribution.length === 0) {
        wrapper.style.display = 'none';
        return;
    }
    
    wrapper.style.display = 'block';
    
    container.innerHTML = distribution.map(item => {
        const sceneText = getSceneText(item.scene);
        const successRate = item.count > 0 ? Math.round(item.success_count / item.count * 100) : 0;
        
        return `
            <div class="scene-stat-card" style="flex: 1; min-width: 150px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 8px; background: #f9f9f9;">
                <div style="font-weight: 500; margin-bottom: 5px;">${escapeHtml(sceneText)}</div>
                <div style="font-size: 24px; font-weight: bold; color: #667eea; margin-bottom: 5px;">${item.count}</div>
                <div style="font-size: 12px; color: #666;">成功率: ${successRate}%</div>
            </div>
        `;
    }).join('');
}

/**
 * 查看人机验证日志详情
 */
async function viewCaptchaLogDetail(logId) {
    try {
        const response = await fetch(`api/GetCaptchaLogDetail.php?id=${logId}`);
        const result = await response.json();
        
        if (result.success) {
            showCaptchaLogDetailModal(result.data);
        } else {
            showToast(result.message || '获取日志详情失败', 'error');
        }
    } catch (error) {
        console.error('获取日志详情失败:', error);
        showToast('获取日志详情失败', 'error');
    }
}

/**
 * 显示人机验证日志详情弹窗
 */
function showCaptchaLogDetailModal(log) {
    const sceneText = getSceneText(log.scene);
    const providerText = getProviderText(log.provider);
    const statusText = log.verify_success ? '成功' : '失败';
    const statusClass = log.verify_success ? 'success' : 'danger';
    
    let detailHtml = `
        <div class="modal-detail">
            <div class="detail-row">
                <span class="detail-label">日志ID:</span>
                <span class="detail-value">${escapeHtml(log.id)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">验证场景:</span>
                <span class="detail-value">${escapeHtml(sceneText)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">服务商:</span>
                <span class="detail-value">${escapeHtml(providerText)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">验证结果:</span>
                <span class="detail-value"><span class="badge badge-${statusClass}">${statusText}</span></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">流水号:</span>
                <span class="detail-value">${escapeHtml(log.lot_number || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">IP地址:</span>
                <span class="detail-value">${escapeHtml(log.client_ip || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">User Agent:</span>
                <span class="detail-value" style="word-break: break-all;">${escapeHtml(log.user_agent || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">手机号:</span>
                <span class="detail-value">${escapeHtml(log.phone || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">邮箱:</span>
                <span class="detail-value">${escapeHtml(log.email || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">用户ID:</span>
                <span class="detail-value">${escapeHtml(log.user_id || '-')}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">验证时间:</span>
                <span class="detail-value">${escapeHtml(log.created_at)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">过期时间:</span>
                <span class="detail-value">${escapeHtml(log.expires_at || '-')}</span>
            </div>
    `;
    
    if (log.error_message) {
        detailHtml += `
            <div class="detail-row">
                <span class="detail-label">错误信息:</span>
                <span class="detail-value" style="color: #f5576c;">${escapeHtml(log.error_message)}</span>
            </div>
        `;
    }
    
    if (log.verify_result) {
        detailHtml += `
            <div class="detail-row">
                <span class="detail-label">验证结果详情:</span>
                <span class="detail-value"><pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">${escapeHtml(JSON.stringify(log.verify_result, null, 2))}</pre></span>
            </div>
        `;
    }
    
    detailHtml += '</div>';
    
    showModal('日志详情', detailHtml, [
        {
            text: '关闭',
            className: 'btn-secondary',
            onClick: () => closeModal()
        }
    ]);
}

/**
 * 获取场景文本
 */
function getSceneText(scene) {
    const sceneMap = {
        'register': '注册',
        'login': '登录',
        'reset_password': '重置密码',
        'send_code': '发送验证码',
        'send_sms': '发送短信',
        'send_email': '发送邮件',
        'change_password': '修改密码',
        'bind_phone': '绑定手机',
        'bind_email': '绑定邮箱',
        'unbind_phone': '解绑手机',
        'unbind_email': '解绑邮箱',
        'update_profile': '更新资料',
        'delete_account': '删除账号'
    };
    return sceneMap[scene] || scene;
}

/**
 * 获取服务商文本
 */
function getProviderText(provider) {
    const providerMap = {
        'geetest': '极验',
        'recaptcha': 'reCAPTCHA',
        'hcaptcha': 'hCaptcha',
        'cloudflare': 'Cloudflare Turnstile'
    };
    return providerMap[provider] || provider;
}


// ============================================
// 系统日志管理
// ============================================

let systemLogCurrentPage = 1;
let systemLogPageSize = 20;

/**
 * 初始化系统日志筛选器
 */
function initSystemLogFilters() {
    // 设置默认日期为今天
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('system-log-start-date').value = today;
    document.getElementById('system-log-end-date').value = today;
}

/**
 * 重置系统日志筛选条件
 */
function resetSystemLogFilters() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('system-log-start-date').value = today;
    document.getElementById('system-log-end-date').value = today;
    document.getElementById('system-log-level').value = '';
    document.getElementById('system-log-type').value = '';
    document.getElementById('system-log-keyword').value = '';
    
    systemLogCurrentPage = 1;
    loadSystemLogs();
}

/**
 * 加载系统日志列表
 */
async function loadSystemLogs(page = 1) {
    const tbody = document.getElementById('system-log-list-tbody');
    
    // 显示加载动画
    tbody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align: center; padding: 60px 20px;">
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #667eea; margin-bottom: 20px;"></i>
                    <p style="font-size: 16px; color: #666; margin: 0;">加载中...</p>
                </div>
            </td>
        </tr>
    `;
    
    try {
        systemLogCurrentPage = page;
        
        // 获取筛选条件
        const startDate = document.getElementById('system-log-start-date').value;
        const endDate = document.getElementById('system-log-end-date').value;
        const logLevel = document.getElementById('system-log-level').value;
        const logType = document.getElementById('system-log-type').value;
        const keyword = document.getElementById('system-log-keyword').value;
        
        // 构建查询参数
        const params = new URLSearchParams({
            page: page,
            page_size: systemLogPageSize
        });
        
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (logLevel) params.append('log_level', logLevel);
        if (logType) params.append('log_type', logType);
        if (keyword) params.append('keyword', keyword);
        
        const response = await fetch(`api/GetSystemLogList.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success) {
            renderSystemLogList(result.data.logs);
            renderSystemLogPagination(result.data.pagination);
            updateSystemLogStats(result.data.stats);
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 60px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <i class="fas fa-exclamation-circle" style="font-size: 64px; color: #f5576c; margin-bottom: 20px;"></i>
                            <p style="font-size: 16px; color: #f5576c; margin: 0;">${escapeHtml(result.message || '加载日志失败')}</p>
                        </div>
                    </td>
                </tr>
            `;
            showToast(result.message || '加载日志失败', 'error');
        }
    } catch (error) {
        console.error('加载系统日志失败:', error);
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-exclamation-circle" style="font-size: 64px; color: #f5576c; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #f5576c; margin: 0;">获取日志失败</p>
                        <p style="font-size: 14px; color: #999; margin-top: 10px;">请检查网络连接或稍后重试</p>
                    </div>
                </td>
            </tr>
        `;
        showToast('加载日志失败', 'error');
    }
}

/**
 * 渲染系统日志列表
 */
function renderSystemLogList(logs) {
    const tbody = document.getElementById('system-log-list-tbody');
    
    if (!logs || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-inbox" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #999; margin: 0;">暂无日志数据</p>
                        <p style="font-size: 14px; color: #bbb; margin-top: 10px;">请调整筛选条件后重试</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const levelBadge = getLogLevelBadge(log.log_level);
        const typeText = getLogTypeText(log.log_type);
        
        // 从 context 中提取 module 和 username
        const context = log.context || {};
        const module = context.module || '-';
        const username = context.username || log.created_by || '-';
        
        // 截断消息长度
        const message = log.message.length > 50 
            ? log.message.substring(0, 50) + '...' 
            : log.message;
        
        return `
            <tr>
                <td>${escapeHtml(log.id)}</td>
                <td>${levelBadge}</td>
                <td>${escapeHtml(typeText)}</td>
                <td>${escapeHtml(module)}</td>
                <td title="${escapeHtml(log.message)}">${escapeHtml(message)}</td>
                <td>${escapeHtml(username)}</td>
                <td>${escapeHtml(log.client_ip || '-')}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewSystemLogDetail(${log.id})">
                        <i class="fas fa-eye"></i> 详情
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 获取日志级别徽章
 */
function getLogLevelBadge(level) {
    const levelLower = (level || '').toLowerCase();
    const badges = {
        'debug': '<span class="badge" style="background: #95a5a6;"><i class="fas fa-bug"></i> 调试</span>',
        'info': '<span class="badge badge-info"><i class="fas fa-info-circle"></i> 信息</span>',
        'warning': '<span class="badge" style="background: #f39c12;"><i class="fas fa-exclamation-triangle"></i> 警告</span>',
        'error': '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> 错误</span>',
        'critical': '<span class="badge" style="background: #c0392b;"><i class="fas fa-skull-crossbones"></i> 严重</span>'
    };
    return badges[levelLower] || level;
}

/**
 * 获取日志类型文本
 */
function getLogTypeText(type) {
    const typeMap = {
        'system': '系统',
        'security': '安全',
        'operation': '操作',
        'api': 'API',
        'database': '数据库',
        'email': '邮件',
        'sms': '短信',
        'captcha': '人机验证'
    };
    return typeMap[type] || type;
}

/**
 * 渲染系统日志分页
 */
function renderSystemLogPagination(pagination) {
    const container = document.getElementById('system-log-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    let paginationHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
    `;
    
    // 上一页按钮
    if (pagination.page > 1) {
        paginationHTML += `
            <button class="btn-page" onclick="loadSystemLogs(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
        `;
    }
    
    // 页码按钮
    const maxPages = 5;
    let startPage = Math.max(1, pagination.page - Math.floor(maxPages / 2));
    let endPage = Math.min(pagination.total_pages, startPage + maxPages - 1);
    
    if (endPage - startPage < maxPages - 1) {
        startPage = Math.max(1, endPage - maxPages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        if (i === pagination.page) {
            paginationHTML += `<button class="btn-page active">${i}</button>`;
        } else {
            paginationHTML += `<button class="btn-page" onclick="loadSystemLogs(${i})">${i}</button>`;
        }
    }
    
    // 下一页按钮
    if (pagination.page < pagination.total_pages) {
        paginationHTML += `
            <button class="btn-page" onclick="loadSystemLogs(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        `;
    }
    
    paginationHTML += '</div>';
    container.innerHTML = paginationHTML;
}

/**
 * 更新系统日志统计信息
 */
function updateSystemLogStats(stats) {
    document.getElementById('system-log-total').textContent = stats.total_count || 0;
    document.getElementById('system-log-error').textContent = stats.error_count || 0;
    document.getElementById('system-log-warning').textContent = stats.warning_count || 0;
    document.getElementById('system-log-info').textContent = stats.info_count || 0;
}

/**
 * 查看系统日志详情
 */
async function viewSystemLogDetail(logId) {
    try {
        const response = await fetch(`api/GetSystemLogDetail.php?id=${logId}`);
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取日志详情失败', 'error');
            return;
        }
        
        const log = result.data;
        
        // 从 context 中提取信息
        const context = log.context || {};
        const module = context.module || '-';
        const action = context.action || '-';
        const username = context.username || log.created_by || '-';
        
        // 构建详情HTML
        let detailsHTML = `
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 20px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                    <i class="fas fa-file-alt"></i> 日志详情
                </h3>
                
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; margin-bottom: 20px;">
                    <div style="font-weight: bold; color: #666;">日志ID:</div>
                    <div>${escapeHtml(log.id)}</div>
                    
                    <div style="font-weight: bold; color: #666;">日志级别:</div>
                    <div>${getLogLevelBadge(log.log_level)}</div>
                    
                    <div style="font-weight: bold; color: #666;">日志类型:</div>
                    <div>${escapeHtml(getLogTypeText(log.log_type))}</div>
                    
                    <div style="font-weight: bold; color: #666;">模块:</div>
                    <div>${escapeHtml(module)}</div>
                    
                    <div style="font-weight: bold; color: #666;">操作:</div>
                    <div>${escapeHtml(action)}</div>
                    
                    <div style="font-weight: bold; color: #666;">消息:</div>
                    <div style="word-break: break-all;">${escapeHtml(log.message)}</div>
                    
                    <div style="font-weight: bold; color: #666;">用户:</div>
                    <div>${escapeHtml(username)} ${log.user_id ? '(ID: ' + log.user_id + ')' : ''}</div>
                    
                    <div style="font-weight: bold; color: #666;">IP地址:</div>
                    <div>${escapeHtml(log.client_ip || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">请求方法:</div>
                    <div>${escapeHtml(log.request_method || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">请求URI:</div>
                    <div style="word-break: break-all;">${escapeHtml(log.request_uri || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">会话ID:</div>
                    <div>${escapeHtml(log.session_id || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">创建者:</div>
                    <div>${escapeHtml(log.created_by || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">创建时间:</div>
                    <div>${escapeHtml(log.created_at)}</div>
                </div>
        `;
        
        // 上下文信息
        if (log.context && Object.keys(log.context).length > 0) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-info-circle"></i> 上下文信息
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px;">${escapeHtml(JSON.stringify(log.context, null, 2))}</pre>
            `;
        }
        
        // 堆栈跟踪
        if (log.stack_trace) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-bug"></i> 堆栈跟踪
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px; font-size: 12px;">${escapeHtml(log.stack_trace)}</pre>
            `;
        }
        
        // 请求参数
        if (log.request_params) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-code"></i> 请求参数
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px;">${escapeHtml(JSON.stringify(log.request_params, null, 2))}</pre>
            `;
        }
        
        // User Agent
        if (log.user_agent) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-desktop"></i> User Agent
                </h4>
                <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; word-break: break-all;">
                    ${escapeHtml(log.user_agent)}
                </div>
            `;
        }
        
        detailsHTML += '</div>';
        
        // 显示模态框
        showModal('系统日志详情', detailsHTML);
        
    } catch (error) {
        console.error('获取系统日志详情失败:', error);
        showToast('获取日志详情失败', 'error');
    }
}

/**
 * 显示模态框
 */
function showModal(title, content) {
    // 创建模态框
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
    
    modal.innerHTML = `
        <div style="background: white; border-radius: 10px; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; z-index: 1;">
                <h2 style="margin: 0; color: #333;">${title}</h2>
                <button onclick="this.closest('.modal').remove()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div>${content}</div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // 点击背景关闭
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
}


// ==================== 短信日志管理 ====================

/**
 * 短信日志相关变量
 */
let smsLogCurrentPage = 1;
let smsLogCurrentPageSize = 20;

/**
 * 初始化短信日志筛选器
 */
function initSmsLogFilters() {
    // 设置默认日期为今天
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('sms-log-start-date').value = today;
    document.getElementById('sms-log-end-date').value = today;
    
    // 搜索按钮
    const btnSearch = document.getElementById('btn-sms-log-search');
    if (btnSearch) {
        btnSearch.addEventListener('click', function() {
            smsLogCurrentPage = 1;
            loadSmsLogs();
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-sms-log-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            document.getElementById('sms-log-start-date').value = today;
            document.getElementById('sms-log-end-date').value = today;
            document.getElementById('sms-log-purpose').value = '';
            document.getElementById('sms-log-status').value = '';
            document.getElementById('sms-log-channel').value = '';
            document.getElementById('sms-log-phone').value = '';
            document.getElementById('sms-log-keyword').value = '';
            smsLogCurrentPage = 1;
            loadSmsLogs();
        });
    }
    
    smsLogCurrentPage = 1;
    loadSmsLogs();
}

/**
 * 加载短信日志列表
 */
async function loadSmsLogs(page = 1) {
    const tbody = document.getElementById('sms-log-list-tbody');
    
    if (!tbody) {
        console.error('短信日志表格元素不存在');
        return;
    }
    
    smsLogCurrentPage = page;
    
    const loadingEl = document.getElementById('sms-log-list-loading');
    const errorEl = document.getElementById('sms-log-list-error');
    const emptyEl = document.getElementById('sms-log-list-empty');
    const tableEl = document.getElementById('sms-log-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'flex';
    errorEl.style.display = 'none';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: smsLogCurrentPage,
            page_size: smsLogCurrentPageSize
        });
        
        const startDate = document.getElementById('sms-log-start-date').value;
        const endDate = document.getElementById('sms-log-end-date').value;
        const purpose = document.getElementById('sms-log-purpose').value;
        const status = document.getElementById('sms-log-status').value;
        const channel = document.getElementById('sms-log-channel').value;
        const phone = document.getElementById('sms-log-phone').value;
        const keyword = document.getElementById('sms-log-keyword').value;
        
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (purpose) params.append('purpose', purpose);
        if (status !== '') params.append('status', status);
        if (channel) params.append('channel', channel);
        if (phone) params.append('phone', phone);
        if (keyword) params.append('keyword', keyword);
        
        const response = await fetch(`api/GetSmsLogList.php?${params.toString()}`);
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (result.success) {
            renderSmsLogList(result.data.logs);
            renderSmsLogPagination(result.data.pagination);
            updateSmsLogStats(result.data.stats);
            
            if (result.data.logs.length === 0) {
                emptyEl.style.display = 'flex';
            } else {
                tableEl.style.display = 'block';
            }
        } else {
            errorEl.style.display = 'flex';
            document.getElementById('sms-log-error-message').textContent = result.message || '加载失败';
        }
        
    } catch (error) {
        console.error('加载短信日志失败:', error);
        loadingEl.style.display = 'none';
        errorEl.style.display = 'flex';
        document.getElementById('sms-log-error-message').textContent = '网络错误，请稍后重试';
    }
}

/**
 * 渲染短信日志列表
 */
function renderSmsLogList(logs) {
    const tbody = document.getElementById('sms-log-list-tbody');
    
    if (!logs || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-inbox" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #999; margin: 0;">暂无短信日志</p>
                        <p style="font-size: 14px; color: #bbb; margin-top: 10px;">请调整筛选条件后重试</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const statusBadge = getSmsStatusBadge(log.status);
        const purposeText = getSmsPurposeText(log.purpose);
        const channelText = getSmsChannelText(log.channel);
        
        return `
            <tr>
                <td>${escapeHtml(log.id)}</td>
                <td>${escapeHtml(log.phone_masked || log.phone)}</td>
                <td>${escapeHtml(purposeText)}</td>
                <td>${escapeHtml(channelText)}</td>
                <td>${statusBadge}</td>
                <td>${escapeHtml(log.verify_count)}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>${escapeHtml(log.expires_at)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewSmsLogDetail(${log.id})">
                        <i class="fas fa-eye"></i> 详情
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 获取短信状态徽章
 */
function getSmsStatusBadge(status) {
    const badges = {
        0: '<span class="badge badge-success"><i class="fas fa-check"></i> 已使用</span>',
        1: '<span class="badge badge-info"><i class="fas fa-clock"></i> 有效</span>',
        2: '<span class="badge" style="background: #95a5a6;"><i class="fas fa-hourglass-end"></i> 已过期</span>',
        3: '<span class="badge" style="background: #3498db;"><i class="fas fa-check-circle"></i> 一次核验成功</span>',
        4: '<span class="badge" style="background: #2ecc71;"><i class="fas fa-check-double"></i> 二次核验成功</span>'
    };
    return badges[status] || status;
}

/**
 * 获取短信用途文本
 */
function getSmsPurposeText(purpose) {
    const purposeMap = {
        'register': '注册',
        'login': '登录',
        'reset_password': '重置密码',
        'bind_phone': '绑定手机',
        'change_phone': '更换手机',
        'verify': '验证'
    };
    return purposeMap[purpose] || purpose;
}

/**
 * 获取短信渠道文本
 */
function getSmsChannelText(channel) {
    const channelMap = {
        'aliyun': '阿里云',
        'tencent': '腾讯云',
        '321cn': '321短信'
    };
    return channelMap[channel] || channel;
}

/**
 * 渲染短信日志分页
 */
function renderSmsLogPagination(pagination) {
    const container = document.getElementById('sms-log-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    let paginationHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
    `;
    
    // 上一页按钮
    if (pagination.page > 1) {
        paginationHTML += `
            <button class="btn-page" onclick="loadSmsLogs(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
        `;
    }
    
    // 页码按钮
    const maxPages = 5;
    let startPage = Math.max(1, pagination.page - Math.floor(maxPages / 2));
    let endPage = Math.min(pagination.total_pages, startPage + maxPages - 1);
    
    if (endPage - startPage < maxPages - 1) {
        startPage = Math.max(1, endPage - maxPages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        if (i === pagination.page) {
            paginationHTML += `<button class="btn-page active">${i}</button>`;
        } else {
            paginationHTML += `<button class="btn-page" onclick="loadSmsLogs(${i})">${i}</button>`;
        }
    }
    
    // 下一页按钮
    if (pagination.page < pagination.total_pages) {
        paginationHTML += `
            <button class="btn-page" onclick="loadSmsLogs(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        `;
    }
    
    paginationHTML += '</div>';
    container.innerHTML = paginationHTML;
}

/**
 * 更新短信日志统计信息
 */
function updateSmsLogStats(stats) {
    document.getElementById('sms-log-total').textContent = stats.total_count.toLocaleString();
    document.getElementById('sms-log-used').textContent = stats.used_count.toLocaleString();
    document.getElementById('sms-log-valid').textContent = stats.valid_count.toLocaleString();
    document.getElementById('sms-log-expired').textContent = stats.expired_count.toLocaleString();
}

/**
 * 查看短信日志详情
 */
async function viewSmsLogDetail(logId) {
    try {
        const response = await fetch(`api/GetSmsLogDetail.php?id=${logId}`);
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取日志详情失败', 'error');
            return;
        }
        
        const log = result.data;
        
        // 构建详情HTML
        let detailsHTML = `
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 20px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                    <i class="fas fa-sms"></i> 短信日志详情
                </h3>
                
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; margin-bottom: 20px;">
                    <div style="font-weight: bold; color: #666;">日志ID:</div>
                    <div>${escapeHtml(log.id)}</div>
                    
                    <div style="font-weight: bold; color: #666;">短信ID:</div>
                    <div>${escapeHtml(log.sms_id)}</div>
                    
                    <div style="font-weight: bold; color: #666;">手机号:</div>
                    <div>${escapeHtml(log.phone_masked || log.phone)}</div>
                    
                    <div style="font-weight: bold; color: #666;">验证码:</div>
                    <div>${log.code ? '******（已隐藏）' : '-'}</div>
                    
                    <div style="font-weight: bold; color: #666;">状态:</div>
                    <div>${getSmsStatusBadge(log.status)}</div>
                    
                    <div style="font-weight: bold; color: #666;">用途:</div>
                    <div>${escapeHtml(getSmsPurposeText(log.purpose))}</div>
                    
                    <div style="font-weight: bold; color: #666;">渠道:</div>
                    <div>${escapeHtml(getSmsChannelText(log.channel))}</div>
                    
                    <div style="font-weight: bold; color: #666;">签名:</div>
                    <div>${escapeHtml(log.signature || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">模板ID:</div>
                    <div>${escapeHtml(log.template_id || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">上游短信ID:</div>
                    <div>${escapeHtml(log.upstream_sms_id || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">有效期:</div>
                    <div>${log.validity_period} 秒（${Math.floor(log.validity_period / 60)} 分钟）</div>
                    
                    <div style="font-weight: bold; color: #666;">核验次数:</div>
                    <div>${escapeHtml(log.verify_count)}</div>
                    
                    <div style="font-weight: bold; color: #666;">最后核验时间:</div>
                    <div>${escapeHtml(log.last_verify_at || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">客户端IP:</div>
                    <div>${escapeHtml(log.client_ip || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">发送时间:</div>
                    <div>${escapeHtml(log.created_at)}</div>
                    
                    <div style="font-weight: bold; color: #666;">过期时间:</div>
                    <div>${escapeHtml(log.expires_at)}</div>
                    
                    <div style="font-weight: bold; color: #666;">更新时间:</div>
                    <div>${escapeHtml(log.updated_at)}</div>
                </div>
        `;
        
        // 发送结果
        if (log.send_result) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-paper-plane"></i> 发送结果
                </h4>
                <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; word-break: break-all;">
                    ${escapeHtml(log.send_result)}
                </div>
            `;
        }
        
        // 发送状态码
        if (log.send_status_code) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-code"></i> 状态码
                </h4>
                <div style="background: #f5f5f5; padding: 15px; border-radius: 5px;">
                    ${escapeHtml(log.send_status_code)}
                </div>
            `;
        }
        
        // 模板参数
        if (log.template_params && Object.keys(log.template_params).length > 0) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-code"></i> 模板参数
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px;">${escapeHtml(JSON.stringify(log.template_params, null, 2))}</pre>
            `;
        }
        
        // 额外信息
        if (log.extra_info && Object.keys(log.extra_info).length > 0) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-info-circle"></i> 额外信息
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px;">${escapeHtml(JSON.stringify(log.extra_info, null, 2))}</pre>
            `;
        }
        
        detailsHTML += '</div>';
        
        // 显示模态框
        showModal('短信日志详情', detailsHTML);
        
    } catch (error) {
        console.error('获取短信日志详情失败:', error);
        showToast('获取日志详情失败', 'error');
    }
}


// ==================== 邮件日志管理 ====================

/**
 * 邮件日志相关变量
 */
let emailLogCurrentPage = 1;
let emailLogCurrentPageSize = 20;

/**
 * 初始化邮件日志筛选器
 */
function initEmailLogFilters() {
    // 设置默认日期为今天
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('email-log-start-date').value = today;
    document.getElementById('email-log-end-date').value = today;
    
    // 搜索按钮
    const btnSearch = document.getElementById('btn-email-log-search');
    if (btnSearch) {
        btnSearch.addEventListener('click', function() {
            emailLogCurrentPage = 1;
            loadEmailLogs();
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-email-log-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            document.getElementById('email-log-start-date').value = today;
            document.getElementById('email-log-end-date').value = today;
            document.getElementById('email-log-purpose').value = '';
            document.getElementById('email-log-status').value = '';
            document.getElementById('email-log-channel').value = '';
            document.getElementById('email-log-email').value = '';
            document.getElementById('email-log-keyword').value = '';
            emailLogCurrentPage = 1;
            loadEmailLogs();
        });
    }
    
    emailLogCurrentPage = 1;
    loadEmailLogs();
}

/**
 * 加载邮件日志列表
 */
async function loadEmailLogs(page = 1) {
    const tbody = document.getElementById('email-log-list-tbody');
    
    if (!tbody) {
        console.error('邮件日志表格元素不存在');
        return;
    }
    
    emailLogCurrentPage = page;
    
    const loadingEl = document.getElementById('email-log-list-loading');
    const errorEl = document.getElementById('email-log-list-error');
    const emptyEl = document.getElementById('email-log-list-empty');
    const tableEl = document.getElementById('email-log-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'flex';
    errorEl.style.display = 'none';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: emailLogCurrentPage,
            page_size: emailLogCurrentPageSize
        });
        
        const startDate = document.getElementById('email-log-start-date').value;
        const endDate = document.getElementById('email-log-end-date').value;
        const purpose = document.getElementById('email-log-purpose').value;
        const status = document.getElementById('email-log-status').value;
        const channel = document.getElementById('email-log-channel').value;
        const email = document.getElementById('email-log-email').value;
        const keyword = document.getElementById('email-log-keyword').value;
        
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (purpose) params.append('purpose', purpose);
        if (status !== '') params.append('status', status);
        if (channel) params.append('channel', channel);
        if (email) params.append('email', email);
        if (keyword) params.append('keyword', keyword);
        
        const response = await fetch(`api/GetEmailLogList.php?${params.toString()}`);
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (result.success) {
            renderEmailLogList(result.data.logs);
            renderEmailLogPagination(result.data.pagination);
            updateEmailLogStats(result.data.stats);
            
            if (result.data.logs.length === 0) {
                emptyEl.style.display = 'flex';
            } else {
                tableEl.style.display = 'block';
            }
        } else {
            errorEl.style.display = 'flex';
            document.getElementById('email-log-error-message').textContent = result.message || '加载失败';
        }
        
    } catch (error) {
        console.error('加载邮件日志失败:', error);
        loadingEl.style.display = 'none';
        errorEl.style.display = 'flex';
        document.getElementById('email-log-error-message').textContent = '网络错误，请稍后重试';
    }
}

/**
 * 渲染邮件日志列表
 */
function renderEmailLogList(logs) {
    const tbody = document.getElementById('email-log-list-tbody');
    
    if (!logs || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 60px 20px;">
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <i class="fas fa-inbox" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                        <p style="font-size: 16px; color: #999; margin: 0;">暂无邮件日志</p>
                        <p style="font-size: 14px; color: #bbb; margin-top: 10px;">请调整筛选条件后重试</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const statusBadge = getEmailStatusBadge(log.status);
        const purposeText = getEmailPurposeText(log.purpose);
        const channelText = getEmailChannelText(log.channel);
        
        return `
            <tr>
                <td>${escapeHtml(log.id)}</td>
                <td>${escapeHtml(log.email_masked || log.email)}</td>
                <td>${escapeHtml(purposeText)}</td>
                <td>${escapeHtml(channelText)}</td>
                <td>${statusBadge}</td>
                <td>${escapeHtml(log.verify_count)}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>${escapeHtml(log.expires_at)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewEmailLogDetail(${log.id})">
                        <i class="fas fa-eye"></i> 详情
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 获取邮件状态徽章
 */
function getEmailStatusBadge(status) {
    const badges = {
        0: '<span class="badge badge-success"><i class="fas fa-check"></i> 已使用</span>',
        1: '<span class="badge badge-info"><i class="fas fa-clock"></i> 有效</span>',
        2: '<span class="badge" style="background: #95a5a6;"><i class="fas fa-hourglass-end"></i> 已过期</span>',
        3: '<span class="badge" style="background: #3498db;"><i class="fas fa-check-circle"></i> 一次核验成功</span>',
        4: '<span class="badge" style="background: #2ecc71;"><i class="fas fa-check-double"></i> 二次核验成功</span>'
    };
    return badges[status] || status;
}

/**
 * 获取邮件用途文本
 */
function getEmailPurposeText(purpose) {
    const purposeMap = {
        'register': '注册',
        'login': '登录',
        'reset_password': '重置密码',
        'bind_email': '绑定邮箱',
        'change_email': '更换邮箱',
        'verify': '验证'
    };
    return purposeMap[purpose] || purpose;
}

/**
 * 获取邮件渠道文本
 */
function getEmailChannelText(channel) {
    const channelMap = {
        'system': '系统',
        'smtp': 'SMTP'
    };
    return channelMap[channel] || channel;
}

/**
 * 渲染邮件日志分页
 */
function renderEmailLogPagination(pagination) {
    const container = document.getElementById('email-log-pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    let paginationHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
    `;
    
    // 上一页按钮
    if (pagination.page > 1) {
        paginationHTML += `
            <button class="btn-page" onclick="loadEmailLogs(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
        `;
    }
    
    // 页码按钮
    const maxPages = 5;
    let startPage = Math.max(1, pagination.page - Math.floor(maxPages / 2));
    let endPage = Math.min(pagination.total_pages, startPage + maxPages - 1);
    
    if (endPage - startPage < maxPages - 1) {
        startPage = Math.max(1, endPage - maxPages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        if (i === pagination.page) {
            paginationHTML += `<button class="btn-page active">${i}</button>`;
        } else {
            paginationHTML += `<button class="btn-page" onclick="loadEmailLogs(${i})">${i}</button>`;
        }
    }
    
    // 下一页按钮
    if (pagination.page < pagination.total_pages) {
        paginationHTML += `
            <button class="btn-page" onclick="loadEmailLogs(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        `;
    }
    
    paginationHTML += '</div>';
    container.innerHTML = paginationHTML;
}

/**
 * 更新邮件日志统计信息
 */
function updateEmailLogStats(stats) {
    document.getElementById('email-log-total').textContent = stats.total_count.toLocaleString();
    document.getElementById('email-log-used').textContent = stats.used_count.toLocaleString();
    document.getElementById('email-log-valid').textContent = stats.valid_count.toLocaleString();
    document.getElementById('email-log-expired').textContent = stats.expired_count.toLocaleString();
}

/**
 * 查看邮件日志详情
 */
async function viewEmailLogDetail(logId) {
    try {
        const response = await fetch(`api/GetEmailLogDetail.php?id=${logId}`);
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取日志详情失败', 'error');
            return;
        }
        
        const log = result.data;
        
        // 构建详情HTML
        let detailsHTML = `
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 20px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                    <i class="fas fa-envelope"></i> 邮件日志详情
                </h3>
                
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; margin-bottom: 20px;">
                    <div style="font-weight: bold; color: #666;">日志ID:</div>
                    <div>${escapeHtml(log.id)}</div>
                    
                    <div style="font-weight: bold; color: #666;">验证码ID:</div>
                    <div>${escapeHtml(log.code_id)}</div>
                    
                    <div style="font-weight: bold; color: #666;">邮箱:</div>
                    <div>${escapeHtml(log.email_masked || log.email)}</div>
                    
                    <div style="font-weight: bold; color: #666;">验证码:</div>
                    <div>${log.code ? '******（已隐藏）' : '-'}</div>
                    
                    <div style="font-weight: bold; color: #666;">状态:</div>
                    <div>${getEmailStatusBadge(log.status)}</div>
                    
                    <div style="font-weight: bold; color: #666;">用途:</div>
                    <div>${escapeHtml(getEmailPurposeText(log.purpose))}</div>
                    
                    <div style="font-weight: bold; color: #666;">渠道:</div>
                    <div>${escapeHtml(getEmailChannelText(log.channel))}</div>
                    
                    <div style="font-weight: bold; color: #666;">模板ID:</div>
                    <div>${escapeHtml(log.template_id || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">有效期:</div>
                    <div>${log.validity_period} 秒（${Math.floor(log.validity_period / 60)} 分钟）</div>
                    
                    <div style="font-weight: bold; color: #666;">核验次数:</div>
                    <div>${escapeHtml(log.verify_count)}</div>
                    
                    <div style="font-weight: bold; color: #666;">最后核验时间:</div>
                    <div>${escapeHtml(log.last_verify_at || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">客户端IP:</div>
                    <div>${escapeHtml(log.client_ip || '-')}</div>
                    
                    <div style="font-weight: bold; color: #666;">发送时间:</div>
                    <div>${escapeHtml(log.created_at)}</div>
                    
                    <div style="font-weight: bold; color: #666;">过期时间:</div>
                    <div>${escapeHtml(log.expires_at)}</div>
                    
                    <div style="font-weight: bold; color: #666;">更新时间:</div>
                    <div>${escapeHtml(log.updated_at)}</div>
                </div>
        `;
        
        // 发送结果
        if (log.send_result) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-paper-plane"></i> 发送结果
                </h4>
                <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; word-break: break-all;">
                    ${escapeHtml(log.send_result)}
                </div>
            `;
        }
        
        // 额外信息
        if (log.extra_info && Object.keys(log.extra_info).length > 0) {
            detailsHTML += `
                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #667eea;">
                    <i class="fas fa-info-circle"></i> 额外信息
                </h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px;">${escapeHtml(JSON.stringify(log.extra_info, null, 2))}</pre>
            `;
        }
        
        detailsHTML += '</div>';
        
        // 显示模态框
        showModal('邮件日志详情', detailsHTML);
        
    } catch (error) {
        console.error('获取邮件日志详情失败:', error);
        showToast('获取日志详情失败', 'error');
    }
}


// ============================================
// 短信限制配置
// ============================================

/**
 * 初始化短信限制配置标签页
 */
function initSmsLimitConfigTabs() {
    const tabBtns = document.querySelectorAll('#page-sms-limit-config .tab-btn');
    const tabContents = document.querySelectorAll('#page-sms-limit-config .tab-content');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            
            // 更新按钮状态
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // 更新内容显示
            tabContents.forEach(content => content.classList.remove('active'));
            document.getElementById(`tab-${tabName}`).classList.add('active');
            
            // 加载对应数据
            switch(tabName) {
                case 'send-limit':
                    loadSendLimitList();
                    break;
                case 'whitelist':
                    loadWhitelistList();
                    break;
                case 'blacklist':
                    loadBlacklistList();
                    break;
            }
        });
    });
    
    // 初始化筛选器
    initSendLimitFilters();
    initWhitelistFilters();
    initBlacklistFilters();
}

// ============================================
// 频率限制管理
// ============================================

let currentSendLimitPage = 1;
let currentSendLimitPageSize = 20;
let currentSendLimitSearch = '';
let currentSendLimitType = '';
let currentSendLimitStatus = '';

/**
 * 初始化频率限制筛选器
 */
function initSendLimitFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-send-limit-search');
    const searchInput = document.getElementById('send-limit-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentSendLimitSearch = searchInput.value.trim();
            loadSendLimitList(1);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentSendLimitSearch = searchInput.value.trim();
                loadSendLimitList(1);
            }
        });
    }
    
    // 类型筛选
    const filterType = document.getElementById('filter-send-limit-type');
    if (filterType) {
        filterType.addEventListener('change', function() {
            currentSendLimitType = this.value;
            loadSendLimitList(1);
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-send-limit-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentSendLimitStatus = this.value;
            loadSendLimitList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-send-limit-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentSendLimitSearch = '';
            currentSendLimitType = '';
            currentSendLimitStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterType) filterType.value = '';
            if (filterStatus) filterStatus.value = '';
            loadSendLimitList(1);
        });
    }
    
    // 新增按钮
    const btnAdd = document.getElementById('btn-add-send-limit');
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            openAddSendLimitModal();
        });
    }
}

/**
 * 加载频率限制列表
 */
async function loadSendLimitList(page = 1) {
    currentSendLimitPage = page;
    
    const loadingEl = document.getElementById('send-limit-list-loading');
    const emptyEl = document.getElementById('send-limit-list-empty');
    const tableEl = document.getElementById('send-limit-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentSendLimitPage,
            page_size: currentSendLimitPageSize
        });
        
        if (currentSendLimitSearch) params.append('search', currentSendLimitSearch);
        if (currentSendLimitType) params.append('limit_type', currentSendLimitType);
        if (currentSendLimitStatus !== '') params.append('is_enabled', currentSendLimitStatus);
        
        const response = await fetch(`/admin/api/GetSendLimitList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showToast(result.message || '加载频率限制列表失败', 'error');
            emptyEl.style.display = 'block';
            return;
        }
        
        const limits = result.data.limits || [];
        const pagination = result.data.pagination;
        
        if (limits.length === 0) {
            emptyEl.style.display = 'block';
        } else {
            tableEl.style.display = 'block';
            renderSendLimitList(limits);
            renderSendLimitPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载频率限制列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 渲染频率限制列表
 */
function renderSendLimitList(limits) {
    const tbody = document.getElementById('send-limit-list-tbody');
    tbody.innerHTML = '';
    
    limits.forEach(limit => {
        const tr = document.createElement('tr');
        
        // 限制类型文本
        const typeText = {
            'phone': '手机号',
            'ip': 'IP地址',
            'phone_template': '手机号+模板',
            'ip_template': 'IP+模板',
            'global': '全局'
        }[limit.limit_type] || limit.limit_type;
        
        // 时间窗口格式化
        const timeWindow = formatTimeWindow(limit.time_window);
        
        // 状态徽章
        const statusBadge = limit.is_enabled 
            ? '<span class="status-badge status-enabled">启用</span>'
            : '<span class="status-badge status-disabled">禁用</span>';
        
        tr.innerHTML = `
            <td>${limit.id}</td>
            <td>${escapeHtml(limit.limit_name)}</td>
            <td>${escapeHtml(limit.template_id)}</td>
            <td>${escapeHtml(limit.purpose)}</td>
            <td><span class="type-badge">${typeText}</span></td>
            <td>${timeWindow}</td>
            <td>${limit.max_count} 次</td>
            <td>${statusBadge}</td>
            <td>${limit.priority}</td>
            <td>
                <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                    <button class="btn-action" onclick="viewSendLimit(${limit.id})">
                        <i class="fas fa-eye"></i> 查看
                    </button>
                    <button class="btn-action" onclick="editSendLimit(${limit.id})">
                        <i class="fas fa-edit"></i> 编辑
                    </button>
                    <button class="btn-action danger" onclick="deleteSendLimit(${limit.id}, '${escapeHtml(limit.limit_name)}')">
                        <i class="fas fa-trash"></i> 删除
                    </button>
                </div>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染频率限制分页
 */
function renderSendLimitPagination(pagination) {
    const container = document.getElementById('send-limit-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadSendLimitList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadSendLimitList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 格式化时间窗口
 */
function formatTimeWindow(seconds) {
    if (seconds < 60) {
        return `${seconds} 秒`;
    } else if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} 分钟`;
    } else if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)} 小时`;
    } else {
        return `${Math.floor(seconds / 86400)} 天`;
    }
}

/**
 * 查看频率限制详情
 */
async function viewSendLimit(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        // 先加载模板列表
        await loadSmsTemplateListForLimit();
        
        const response = await fetch(`/admin/api/GetSendLimitDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const limit = result.data;
        
        // 填充表单（只读模式）
        document.getElementById('send-limit-id').value = limit.id;
        document.getElementById('send-limit-name').value = limit.limit_name;
        document.getElementById('send-limit-purpose').value = limit.purpose;
        document.getElementById('send-limit-type').value = limit.limit_type;
        document.getElementById('send-limit-time-window').value = limit.time_window;
        document.getElementById('send-limit-max-count').value = limit.max_count;
        document.getElementById('send-limit-is-enabled').value = limit.is_enabled ? '1' : '0';
        document.getElementById('send-limit-priority').value = limit.priority;
        document.getElementById('send-limit-description').value = limit.description || '';
        
        // 处理模板选择
        // 判断是模板还是服务商
        if (limit.template_id.includes(':*')) {
            // 服务商模式
            const channel = limit.template_id.replace(':*', '');
            document.getElementById('send-limit-template-type').value = 'channel';
            updateTemplateSelect(); // 更新选项
            document.getElementById('send-limit-template-id').value = `channel:${channel}`;
        } else {
            // 模板模式
            document.getElementById('send-limit-template-type').value = 'template';
            updateTemplateSelect(); // 更新选项
            document.getElementById('send-limit-template-id').value = limit.template_id;
        }
        
        // 设置为只读模式
        const form = document.getElementById('send-limit-form');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.disabled = true;
        });
        
        // 修改标题和按钮
        document.getElementById('send-limit-modal-title').textContent = '查看频率限制';
        document.getElementById('btn-save-send-limit').style.display = 'none';
        
        // 显示弹窗
        document.getElementById('send-limit-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取频率限制详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 编辑频率限制
 */
async function editSendLimit(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        // 先加载模板列表
        await loadSmsTemplateListForLimit();
        
        const response = await fetch(`/admin/api/GetSendLimitDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const limit = result.data;
        
        // 填充表单
        document.getElementById('send-limit-id').value = limit.id;
        document.getElementById('send-limit-name').value = limit.limit_name;
        document.getElementById('send-limit-purpose').value = limit.purpose;
        document.getElementById('send-limit-type').value = limit.limit_type;
        document.getElementById('send-limit-time-window').value = limit.time_window;
        document.getElementById('send-limit-max-count').value = limit.max_count;
        document.getElementById('send-limit-is-enabled').value = limit.is_enabled ? '1' : '0';
        document.getElementById('send-limit-priority').value = limit.priority;
        document.getElementById('send-limit-description').value = limit.description || '';
        
        // 处理模板选择
        // 判断是模板还是服务商
        if (limit.template_id.includes(':*')) {
            // 服务商模式
            const channel = limit.template_id.replace(':*', '');
            document.getElementById('send-limit-template-type').value = 'channel';
            updateTemplateSelect(); // 更新选项
            document.getElementById('send-limit-template-id').value = `channel:${channel}`;
        } else {
            // 模板模式
            document.getElementById('send-limit-template-type').value = 'template';
            updateTemplateSelect(); // 更新选项
            document.getElementById('send-limit-template-id').value = limit.template_id;
        }
        
        // 修改标题
        document.getElementById('send-limit-modal-title').textContent = '编辑频率限制';
        document.getElementById('btn-save-send-limit').style.display = 'inline-flex';
        
        // 显示弹窗
        document.getElementById('send-limit-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取频率限制详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 删除频率限制
 */
function deleteSendLimit(id, name) {
    showConfirmDialog(
        `确定要删除频率限制"${name}"吗？`,
        async function() {
            try {
                const response = await fetch('/admin/api/DeleteSendLimit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({ id: id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('删除成功', 'success');
                    loadSendLimitList(currentSendLimitPage);
                } else {
                    showToast(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('删除频率限制失败:', error);
                showToast('网络错误，请稍后重试', 'error');
            }
        }
    );
}

// ============================================
// 白名单管理
// ============================================

let currentWhitelistPage = 1;
let currentWhitelistPageSize = 20;
let currentWhitelistSearch = '';
let currentWhitelistStatus = '';

/**
 * 初始化白名单筛选器
 */
function initWhitelistFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-whitelist-search');
    const searchInput = document.getElementById('whitelist-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentWhitelistSearch = searchInput.value.trim();
            loadWhitelistList(1);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentWhitelistSearch = searchInput.value.trim();
                loadWhitelistList(1);
            }
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-whitelist-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentWhitelistStatus = this.value;
            loadWhitelistList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-whitelist-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentWhitelistSearch = '';
            currentWhitelistStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterStatus) filterStatus.value = '';
            loadWhitelistList(1);
        });
    }
    
    // 新增按钮
    const btnAdd = document.getElementById('btn-add-whitelist');
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            openAddWhitelistModal();
        });
    }
}

/**
 * 加载白名单列表
 */
async function loadWhitelistList(page = 1) {
    currentWhitelistPage = page;
    
    const loadingEl = document.getElementById('whitelist-list-loading');
    const emptyEl = document.getElementById('whitelist-list-empty');
    const tableEl = document.getElementById('whitelist-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentWhitelistPage,
            page_size: currentWhitelistPageSize
        });
        
        if (currentWhitelistSearch) params.append('search', currentWhitelistSearch);
        if (currentWhitelistStatus !== '') params.append('is_enabled', currentWhitelistStatus);
        
        const response = await fetch(`/admin/api/GetWhitelistList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showToast(result.message || '加载白名单列表失败', 'error');
            emptyEl.style.display = 'block';
            return;
        }
        
        const whitelist = result.data.whitelist || [];
        const pagination = result.data.pagination;
        
        if (whitelist.length === 0) {
            emptyEl.style.display = 'block';
        } else {
            tableEl.style.display = 'block';
            renderWhitelistList(whitelist);
            renderWhitelistPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载白名单列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 渲染白名单列表
 */
function renderWhitelistList(whitelist) {
    const tbody = document.getElementById('whitelist-list-tbody');
    tbody.innerHTML = '';
    
    whitelist.forEach(item => {
        const tr = document.createElement('tr');
        
        // 状态徽章
        const statusBadge = item.is_enabled 
            ? '<span class="status-badge status-enabled">启用</span>'
            : '<span class="status-badge status-disabled">禁用</span>';
        
        // 过期时间
        const expiresAt = item.expires_at ? formatDateTime(item.expires_at) : '永久';
        
        tr.innerHTML = `
            <td>${item.id}</td>
            <td>${escapeHtml(item.phone)}</td>
            <td>${escapeHtml(item.reason || '-')}</td>
            <td>${statusBadge}</td>
            <td>${expiresAt}</td>
            <td>${escapeHtml(item.created_by || '-')}</td>
            <td>${formatDateTime(item.created_at)}</td>
            <td>
                <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                    <button class="btn-action" onclick="viewWhitelist(${item.id})">
                        <i class="fas fa-eye"></i> 查看
                    </button>
                    <button class="btn-action" onclick="editWhitelist(${item.id})">
                        <i class="fas fa-edit"></i> 编辑
                    </button>
                    <button class="btn-action danger" onclick="deleteWhitelist(${item.id}, '${escapeHtml(item.phone)}')">
                        <i class="fas fa-trash"></i> 删除
                    </button>
                </div>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染白名单分页
 */
function renderWhitelistPagination(pagination) {
    const container = document.getElementById('whitelist-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadWhitelistList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadWhitelistList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 查看白名单详情
 */
async function viewWhitelist(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetWhitelistDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const whitelist = result.data;
        
        // 填充表单
        document.getElementById('whitelist-id').value = whitelist.id;
        document.getElementById('whitelist-phone').value = whitelist.phone;
        document.getElementById('whitelist-reason').value = whitelist.reason || '';
        document.getElementById('whitelist-is-enabled').value = whitelist.is_enabled ? '1' : '0';
        
        // 处理过期时间
        if (whitelist.expires_at) {
            const expiresDate = new Date(whitelist.expires_at);
            const year = expiresDate.getFullYear();
            const month = String(expiresDate.getMonth() + 1).padStart(2, '0');
            const day = String(expiresDate.getDate()).padStart(2, '0');
            const hours = String(expiresDate.getHours()).padStart(2, '0');
            const minutes = String(expiresDate.getMinutes()).padStart(2, '0');
            document.getElementById('whitelist-expires-at').value = `${year}-${month}-${day}T${hours}:${minutes}`;
        } else {
            document.getElementById('whitelist-expires-at').value = '';
        }
        
        // 设置为只读模式
        const form = document.getElementById('whitelist-form');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.disabled = true;
        });
        
        // 修改标题和按钮
        document.getElementById('whitelist-modal-title').textContent = '查看白名单';
        document.getElementById('btn-save-whitelist').style.display = 'none';
        
        // 显示弹窗
        document.getElementById('whitelist-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取白名单详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 编辑白名单
 */
async function editWhitelist(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetWhitelistDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const whitelist = result.data;
        
        // 填充表单
        document.getElementById('whitelist-id').value = whitelist.id;
        document.getElementById('whitelist-phone').value = whitelist.phone;
        document.getElementById('whitelist-reason').value = whitelist.reason || '';
        document.getElementById('whitelist-is-enabled').value = whitelist.is_enabled ? '1' : '0';
        
        // 处理过期时间
        if (whitelist.expires_at) {
            // 将数据库时间格式转换为 datetime-local 格式
            const expiresDate = new Date(whitelist.expires_at);
            const year = expiresDate.getFullYear();
            const month = String(expiresDate.getMonth() + 1).padStart(2, '0');
            const day = String(expiresDate.getDate()).padStart(2, '0');
            const hours = String(expiresDate.getHours()).padStart(2, '0');
            const minutes = String(expiresDate.getMinutes()).padStart(2, '0');
            document.getElementById('whitelist-expires-at').value = `${year}-${month}-${day}T${hours}:${minutes}`;
        } else {
            document.getElementById('whitelist-expires-at').value = '';
        }
        
        // 修改标题
        document.getElementById('whitelist-modal-title').textContent = '编辑白名单';
        
        // 显示弹窗
        document.getElementById('whitelist-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取白名单详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 删除白名单
 */
function deleteWhitelist(id, phone) {
    showConfirmDialog(
        `确定要删除白名单"${phone}"吗？`,
        async function() {
            try {
                const response = await fetch('/admin/api/DeleteWhitelist.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({ id: id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('删除成功', 'success');
                    loadWhitelistList(currentWhitelistPage);
                } else {
                    showToast(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('删除白名单失败:', error);
                showToast('网络错误，请稍后重试', 'error');
            }
        }
    );
}

// ============================================
// 黑名单管理
// ============================================

let currentBlacklistPage = 1;
let currentBlacklistPageSize = 20;
let currentBlacklistSearch = '';
let currentBlacklistStatus = '';

/**
 * 初始化黑名单筛选器
 */
function initBlacklistFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-blacklist-search');
    const searchInput = document.getElementById('blacklist-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentBlacklistSearch = searchInput.value.trim();
            loadBlacklistList(1);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentBlacklistSearch = searchInput.value.trim();
                loadBlacklistList(1);
            }
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-blacklist-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentBlacklistStatus = this.value;
            loadBlacklistList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-blacklist-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentBlacklistSearch = '';
            currentBlacklistStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterStatus) filterStatus.value = '';
            loadBlacklistList(1);
        });
    }
    
    // 新增按钮
    const btnAdd = document.getElementById('btn-add-blacklist');
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            openAddBlacklistModal();
        });
    }
}

/**
 * 加载黑名单列表
 */
async function loadBlacklistList(page = 1) {
    currentBlacklistPage = page;
    
    const loadingEl = document.getElementById('blacklist-list-loading');
    const emptyEl = document.getElementById('blacklist-list-empty');
    const tableEl = document.getElementById('blacklist-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentBlacklistPage,
            page_size: currentBlacklistPageSize
        });
        
        if (currentBlacklistSearch) params.append('search', currentBlacklistSearch);
        if (currentBlacklistStatus !== '') params.append('is_enabled', currentBlacklistStatus);
        
        const response = await fetch(`/admin/api/GetBlacklistList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showToast(result.message || '加载黑名单列表失败', 'error');
            emptyEl.style.display = 'block';
            return;
        }
        
        const blacklist = result.data.blacklist || [];
        const pagination = result.data.pagination;
        
        if (blacklist.length === 0) {
            emptyEl.style.display = 'block';
        } else {
            tableEl.style.display = 'block';
            renderBlacklistList(blacklist);
            renderBlacklistPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载黑名单列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 渲染黑名单列表
 */
function renderBlacklistList(blacklist) {
    const tbody = document.getElementById('blacklist-list-tbody');
    tbody.innerHTML = '';
    
    blacklist.forEach(item => {
        const tr = document.createElement('tr');
        
        // 状态徽章
        const statusBadge = item.is_enabled 
            ? '<span class="status-badge status-enabled">启用</span>'
            : '<span class="status-badge status-disabled">禁用</span>';
        
        // 过期时间
        const expiresAt = item.expires_at ? formatDateTime(item.expires_at) : '永久';
        
        tr.innerHTML = `
            <td>${item.id}</td>
            <td>${escapeHtml(item.phone)}</td>
            <td>${escapeHtml(item.reason)}</td>
            <td>${statusBadge}</td>
            <td>${expiresAt}</td>
            <td>${escapeHtml(item.created_by || '-')}</td>
            <td>${formatDateTime(item.created_at)}</td>
            <td>
                <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                    <button class="btn-action" onclick="viewBlacklist(${item.id})">
                        <i class="fas fa-eye"></i> 查看
                    </button>
                    <button class="btn-action" onclick="editBlacklist(${item.id})">
                        <i class="fas fa-edit"></i> 编辑
                    </button>
                    <button class="btn-action danger" onclick="deleteBlacklist(${item.id}, '${escapeHtml(item.phone)}')">
                        <i class="fas fa-trash"></i> 删除
                    </button>
                </div>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染黑名单分页
 */
function renderBlacklistPagination(pagination) {
    const container = document.getElementById('blacklist-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadBlacklistList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadBlacklistList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 查看黑名单详情
 */
async function viewBlacklist(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetBlacklistDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const blacklist = result.data;
        
        // 填充表单
        document.getElementById('blacklist-id').value = blacklist.id;
        document.getElementById('blacklist-phone').value = blacklist.phone;
        document.getElementById('blacklist-reason').value = blacklist.reason || '';
        document.getElementById('blacklist-is-enabled').value = blacklist.is_enabled ? '1' : '0';
        
        // 处理过期时间
        if (blacklist.expires_at) {
            const expiresDate = new Date(blacklist.expires_at);
            const year = expiresDate.getFullYear();
            const month = String(expiresDate.getMonth() + 1).padStart(2, '0');
            const day = String(expiresDate.getDate()).padStart(2, '0');
            const hours = String(expiresDate.getHours()).padStart(2, '0');
            const minutes = String(expiresDate.getMinutes()).padStart(2, '0');
            document.getElementById('blacklist-expires-at').value = `${year}-${month}-${day}T${hours}:${minutes}`;
        } else {
            document.getElementById('blacklist-expires-at').value = '';
        }
        
        // 设置为只读模式
        const form = document.getElementById('blacklist-form');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.disabled = true;
        });
        
        // 修改标题和按钮
        document.getElementById('blacklist-modal-title').textContent = '查看黑名单';
        document.getElementById('btn-save-blacklist').style.display = 'none';
        
        // 显示弹窗
        document.getElementById('blacklist-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取黑名单详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 编辑黑名单
 */
async function editBlacklist(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetBlacklistDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const blacklist = result.data;
        
        // 填充表单
        document.getElementById('blacklist-id').value = blacklist.id;
        document.getElementById('blacklist-phone').value = blacklist.phone;
        document.getElementById('blacklist-reason').value = blacklist.reason || '';
        document.getElementById('blacklist-is-enabled').value = blacklist.is_enabled ? '1' : '0';
        
        // 处理过期时间
        if (blacklist.expires_at) {
            // 将数据库时间格式转换为 datetime-local 格式
            const expiresDate = new Date(blacklist.expires_at);
            const year = expiresDate.getFullYear();
            const month = String(expiresDate.getMonth() + 1).padStart(2, '0');
            const day = String(expiresDate.getDate()).padStart(2, '0');
            const hours = String(expiresDate.getHours()).padStart(2, '0');
            const minutes = String(expiresDate.getMinutes()).padStart(2, '0');
            document.getElementById('blacklist-expires-at').value = `${year}-${month}-${day}T${hours}:${minutes}`;
        } else {
            document.getElementById('blacklist-expires-at').value = '';
        }
        
        // 修改标题
        document.getElementById('blacklist-modal-title').textContent = '编辑黑名单';
        
        // 显示弹窗
        document.getElementById('blacklist-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取黑名单详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 删除黑名单
 */
function deleteBlacklist(id, phone) {
    showConfirmDialog(
        `确定要删除黑名单"${phone}"吗？`,
        async function() {
            try {
                const response = await fetch('/admin/api/DeleteBlacklist.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({ id: id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('删除成功', 'success');
                    loadBlacklistList(currentBlacklistPage);
                } else {
                    showToast(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('删除黑名单失败:', error);
                showToast('网络错误，请稍后重试', 'error');
            }
        }
    );
}

// ============================================
// 频率限制弹窗管理
// ============================================

let smsTemplateList = []; // 存储短信模板列表

/**
 * 打开新增频率限制弹窗
 */
function openAddSendLimitModal() {
    // 重置表单
    document.getElementById('send-limit-form').reset();
    document.getElementById('send-limit-id').value = '';
    document.getElementById('send-limit-modal-title').textContent = '新增频率限制';
    document.getElementById('send-limit-priority').value = '100';
    document.getElementById('send-limit-is-enabled').value = '1';
    
    // 确保表单可编辑
    const form = document.getElementById('send-limit-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-send-limit').style.display = 'inline-flex';
    
    // 加载模板列表
    loadSmsTemplateListForLimit();
    
    // 显示弹窗
    document.getElementById('send-limit-modal').classList.add('show');
}

/**
 * 关闭频率限制弹窗
 */
function closeSendLimitModal() {
    // 恢复表单状态
    const form = document.getElementById('send-limit-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-send-limit').style.display = 'inline-flex';
    
    // 关闭弹窗
    document.getElementById('send-limit-modal').classList.remove('show');
}

/**
 * 加载短信模板列表（用于限制配置）
 */
async function loadSmsTemplateListForLimit() {
    try {
        const response = await fetch('/admin/api/GetSmsTemplateList.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            smsTemplateList = result.data.templates || [];
            updateTemplateSelect();
        } else {
            showToast('加载模板列表失败', 'error');
        }
    } catch (error) {
        console.error('加载模板列表失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 更新模板选择下拉框
 */
function updateTemplateSelect() {
    const templateType = document.getElementById('send-limit-template-type').value;
    const purpose = document.getElementById('send-limit-purpose').value;
    const templateSelect = document.getElementById('send-limit-template-id');
    const hint = document.getElementById('template-select-hint');
    
    templateSelect.innerHTML = '<option value="">请选择</option>';
    hint.textContent = '';
    
    if (!templateType) {
        templateSelect.innerHTML = '<option value="">请先选择限制对象</option>';
        return;
    }
    
    if (templateType === 'template') {
        // 按模板选择
        let filteredTemplates = smsTemplateList;
        if (purpose && purpose !== '*') {
            filteredTemplates = smsTemplateList.filter(t => t.purpose === purpose);
        }
        
        filteredTemplates.forEach(template => {
            const option = document.createElement('option');
            option.value = template.template_id;
            option.textContent = `${template.config_name} (${template.channel} - ${template.template_id})`;
            templateSelect.appendChild(option);
        });
        
        hint.textContent = '选择具体的短信模板';
        
    } else if (templateType === 'channel') {
        // 按服务商选择
        const channels = [...new Set(smsTemplateList.map(t => t.channel))];
        
        channels.forEach(channel => {
            const option = document.createElement('option');
            option.value = `channel:${channel}`;
            const channelName = {
                'aliyun': '阿里云',
                'tencent': '腾讯云',
                '321cn': '321.com.cn'
            }[channel] || channel;
            option.textContent = channelName;
            templateSelect.appendChild(option);
        });
        
        hint.textContent = '选择服务商后，限制将应用到该服务商的所有模板';
    }
}

/**
 * 保存频率限制
 */
async function saveSendLimit() {
    const form = document.getElementById('send-limit-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // 获取保存按钮
    const saveBtn = document.getElementById('btn-save-send-limit');
    const originalContent = saveBtn.innerHTML;
    
    // 禁用按钮并显示加载动画
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    const id = document.getElementById('send-limit-id').value;
    const limitName = document.getElementById('send-limit-name').value.trim();
    let templateId = document.getElementById('send-limit-template-id').value;
    const purpose = document.getElementById('send-limit-purpose').value;
    const limitType = document.getElementById('send-limit-type').value;
    const timeWindow = parseInt(document.getElementById('send-limit-time-window').value);
    const maxCount = parseInt(document.getElementById('send-limit-max-count').value);
    const isEnabled = document.getElementById('send-limit-is-enabled').value === '1';
    const priority = parseInt(document.getElementById('send-limit-priority').value);
    const description = document.getElementById('send-limit-description').value.trim();
    
    // 如果选择的是服务商，需要处理模板ID
    const templateType = document.getElementById('send-limit-template-type').value;
    if (templateType === 'channel' && templateId.startsWith('channel:')) {
        // 获取该服务商的所有模板ID
        const channel = templateId.replace('channel:', '');
        const channelTemplates = smsTemplateList.filter(t => t.channel === channel);
        
        if (channelTemplates.length === 0) {
            showToast('该服务商没有可用的模板', 'error');
            // 恢复按钮状态
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalContent;
            return;
        }
        
        // 使用通配符表示该服务商的所有模板
        templateId = `${channel}:*`;
    }
    
    const data = {
        limit_name: limitName,
        template_id: templateId,
        purpose: purpose,
        limit_type: limitType,
        time_window: timeWindow,
        max_count: maxCount,
        is_enabled: isEnabled,
        priority: priority,
        description: description
    };
    
    if (id) {
        data.id = parseInt(id);
    }
    
    try {
        const response = await fetch('/admin/api/SaveSendLimit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(id ? '编辑成功' : '新增成功', 'success');
            closeSendLimitModal();
            loadSendLimitList(currentSendLimitPage);
        } else {
            showToast(result.message || '保存失败', 'error');
        }
    } catch (error) {
        console.error('保存频率限制失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    } finally {
        // 恢复按钮状态
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalContent;
    }
}

// ============================================
// 白名单弹窗管理
// ============================================

/**
 * 打开新增白名单弹窗
 */
function openAddWhitelistModal() {
    // 重置表单
    document.getElementById('whitelist-form').reset();
    document.getElementById('whitelist-id').value = '';
    document.getElementById('whitelist-modal-title').textContent = '添加白名单';
    document.getElementById('whitelist-is-enabled').value = '1';
    
    // 显示弹窗
    document.getElementById('whitelist-modal').classList.add('show');
}

/**
 * 关闭白名单弹窗
 */
function closeWhitelistModal() {
    // 恢复表单状态
    const form = document.getElementById('whitelist-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-whitelist').style.display = 'inline-flex';
    
    // 关闭弹窗
    document.getElementById('whitelist-modal').classList.remove('show');
}

/**
 * 保存白名单
 */
async function saveWhitelist() {
    const form = document.getElementById('whitelist-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // 获取保存按钮
    const saveBtn = document.getElementById('btn-save-whitelist');
    const originalContent = saveBtn.innerHTML;
    
    // 禁用按钮并显示加载动画
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    const id = document.getElementById('whitelist-id').value;
    const phone = document.getElementById('whitelist-phone').value.trim();
    const reason = document.getElementById('whitelist-reason').value.trim();
    const isEnabled = document.getElementById('whitelist-is-enabled').value === '1';
    const expiresAt = document.getElementById('whitelist-expires-at').value;
    
    const data = {
        phone: phone,
        reason: reason,
        is_enabled: isEnabled,
        expires_at: expiresAt || null
    };
    
    if (id) {
        data.id = parseInt(id);
    }
    
    try {
        const response = await fetch('/admin/api/SaveWhitelist.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(id ? '编辑成功' : '添加成功', 'success');
            closeWhitelistModal();
            loadWhitelistList(currentWhitelistPage);
        } else {
            showToast(result.message || '保存失败', 'error');
        }
    } catch (error) {
        console.error('保存白名单失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    } finally {
        // 恢复按钮状态
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalContent;
    }
}

// ============================================
// 黑名单弹窗管理
// ============================================

/**
 * 打开新增黑名单弹窗
 */
function openAddBlacklistModal() {
    // 重置表单
    document.getElementById('blacklist-form').reset();
    document.getElementById('blacklist-id').value = '';
    document.getElementById('blacklist-modal-title').textContent = '添加黑名单';
    document.getElementById('blacklist-is-enabled').value = '1';
    
    // 显示弹窗
    document.getElementById('blacklist-modal').classList.add('show');
}

/**
 * 关闭黑名单弹窗
 */
function closeBlacklistModal() {
    // 恢复表单状态
    const form = document.getElementById('blacklist-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-blacklist').style.display = 'inline-flex';
    
    // 关闭弹窗
    document.getElementById('blacklist-modal').classList.remove('show');
}

/**
 * 保存黑名单
 */
async function saveBlacklist() {
    const form = document.getElementById('blacklist-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // 获取保存按钮
    const saveBtn = document.getElementById('btn-save-blacklist');
    const originalContent = saveBtn.innerHTML;
    
    // 禁用按钮并显示加载动画
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    const id = document.getElementById('blacklist-id').value;
    const phone = document.getElementById('blacklist-phone').value.trim();
    const reason = document.getElementById('blacklist-reason').value.trim();
    const isEnabled = document.getElementById('blacklist-is-enabled').value === '1';
    const expiresAt = document.getElementById('blacklist-expires-at').value;
    
    const data = {
        phone: phone,
        reason: reason,
        is_enabled: isEnabled,
        expires_at: expiresAt || null
    };
    
    if (id) {
        data.id = parseInt(id);
    }
    
    try {
        const response = await fetch('/admin/api/SaveBlacklist.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(id ? '编辑成功' : '添加成功', 'success');
            closeBlacklistModal();
            loadBlacklistList(currentBlacklistPage);
        } else {
            showToast(result.message || '保存失败', 'error');
        }
    } catch (error) {
        console.error('保存黑名单失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    } finally {
        // 恢复按钮状态
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalContent;
    }
}

// ============================================
// 事件监听器初始化
// ============================================

// 监听限制对象和用途变化，更新模板选择
document.addEventListener('DOMContentLoaded', function() {
    const templateTypeSelect = document.getElementById('send-limit-template-type');
    const purposeSelect = document.getElementById('send-limit-purpose');
    
    if (templateTypeSelect) {
        templateTypeSelect.addEventListener('change', updateTemplateSelect);
    }
    
    if (purposeSelect) {
        purposeSelect.addEventListener('change', updateTemplateSelect);
    }
    
    // 保存按钮事件
    const btnSaveSendLimit = document.getElementById('btn-save-send-limit');
    if (btnSaveSendLimit) {
        btnSaveSendLimit.addEventListener('click', saveSendLimit);
    }
    
    const btnSaveWhitelist = document.getElementById('btn-save-whitelist');
    if (btnSaveWhitelist) {
        btnSaveWhitelist.addEventListener('click', saveWhitelist);
    }
    
    const btnSaveBlacklist = document.getElementById('btn-save-blacklist');
    if (btnSaveBlacklist) {
        btnSaveBlacklist.addEventListener('click', saveBlacklist);
    }
});


// ============================================
// 第三方授权配置管理
// ============================================

let currentThirdPartyLoginConfigPage = 1;
let currentThirdPartyLoginConfigPageSize = 20;
let currentThirdPartyLoginConfigSearch = '';
let currentThirdPartyLoginConfigPlatform = '';
let currentThirdPartyLoginConfigStatus = '';

/**
 * 初始化第三方授权配置筛选器
 */
function initThirdPartyLoginConfigFilters() {
    // 搜索按钮
    const btnSearch = document.getElementById('btn-third-party-login-config-search');
    const searchInput = document.getElementById('third-party-login-config-search');
    
    if (btnSearch && searchInput) {
        btnSearch.addEventListener('click', function() {
            currentThirdPartyLoginConfigSearch = searchInput.value.trim();
            loadThirdPartyLoginConfigList(1);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentThirdPartyLoginConfigSearch = searchInput.value.trim();
                loadThirdPartyLoginConfigList(1);
            }
        });
    }
    
    // 平台筛选
    const filterPlatform = document.getElementById('filter-third-party-login-config-platform');
    if (filterPlatform) {
        filterPlatform.addEventListener('change', function() {
            currentThirdPartyLoginConfigPlatform = this.value;
            loadThirdPartyLoginConfigList(1);
        });
    }
    
    // 状态筛选
    const filterStatus = document.getElementById('filter-third-party-login-config-status');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            currentThirdPartyLoginConfigStatus = this.value;
            loadThirdPartyLoginConfigList(1);
        });
    }
    
    // 重置按钮
    const btnReset = document.getElementById('btn-reset-third-party-login-config-filter');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            currentThirdPartyLoginConfigSearch = '';
            currentThirdPartyLoginConfigPlatform = '';
            currentThirdPartyLoginConfigStatus = '';
            if (searchInput) searchInput.value = '';
            if (filterPlatform) filterPlatform.value = '';
            if (filterStatus) filterStatus.value = '';
            loadThirdPartyLoginConfigList(1);
        });
    }
    
    // 新增按钮
    const btnAdd = document.getElementById('btn-add-third-party-login-config');
    if (btnAdd) {
        btnAdd.addEventListener('click', function() {
            openAddThirdPartyLoginConfigModal();
        });
    }
}

/**
 * 加载第三方授权配置列表
 */
async function loadThirdPartyLoginConfigList(page = 1) {
    currentThirdPartyLoginConfigPage = page;
    
    const loadingEl = document.getElementById('third-party-login-config-list-loading');
    const emptyEl = document.getElementById('third-party-login-config-list-empty');
    const tableEl = document.getElementById('third-party-login-config-list-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentThirdPartyLoginConfigPage,
            page_size: currentThirdPartyLoginConfigPageSize
        });
        
        if (currentThirdPartyLoginConfigSearch) params.append('search', currentThirdPartyLoginConfigSearch);
        if (currentThirdPartyLoginConfigPlatform) params.append('platform', currentThirdPartyLoginConfigPlatform);
        if (currentThirdPartyLoginConfigStatus !== '') params.append('status', currentThirdPartyLoginConfigStatus);
        
        const response = await fetch(`/admin/api/GetThirdPartyLoginConfigList.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (!result.success) {
            showToast(result.message || '加载配置列表失败', 'error');
            emptyEl.style.display = 'block';
            return;
        }
        
        const configs = result.data.configs || [];
        const pagination = result.data.pagination;
        
        if (configs.length === 0) {
            emptyEl.style.display = 'block';
        } else {
            tableEl.style.display = 'block';
            renderThirdPartyLoginConfigList(configs);
            renderThirdPartyLoginConfigPagination(pagination);
        }
        
    } catch (error) {
        console.error('加载第三方授权配置列表失败:', error);
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 渲染第三方授权配置列表
 */
function renderThirdPartyLoginConfigList(configs) {
    const tbody = document.getElementById('third-party-login-config-list-tbody');
    tbody.innerHTML = '';
    
    // 平台名称映射
    const platformNames = {
        'wechat': '微信',
        'qq': 'QQ',
        'weibo': '微博',
        'alipay': '支付宝',
        'github': 'GitHub',
        'google': 'Google',
        'apple': 'Apple',
        'dingtalk': '钉钉',
        'feishu': '飞书'
    };
    
    configs.forEach(config => {
        const tr = document.createElement('tr');
        
        // 状态徽章
        const statusBadge = config.status === 1 
            ? '<span class="status-badge status-enabled">正常</span>'
            : config.status === 0
            ? '<span class="status-badge status-disabled">禁用</span>'
            : '<span class="status-badge status-warning">维护中</span>';
        
        // 启用状态
        const enabledBadge = config.is_enabled
            ? '<span class="status-badge status-enabled">已启用</span>'
            : '<span class="status-badge status-disabled">未启用</span>';
        
        const platformName = platformNames[config.platform] || config.platform;
        
        tr.innerHTML = `
            <td>${config.id}</td>
            <td>${escapeHtml(config.config_name)}</td>
            <td><span class="platform-badge">${platformName}</span></td>
            <td>${escapeHtml(config.app_id)}</td>
            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(config.callback_url)}</td>
            <td>${statusBadge}</td>
            <td>${enabledBadge}</td>
            <td>${config.priority}</td>
            <td>
                <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                    <button class="btn-action" onclick="viewThirdPartyLoginConfig(${config.id})">
                        <i class="fas fa-eye"></i> 查看
                    </button>
                    <button class="btn-action" onclick="editThirdPartyLoginConfig(${config.id})">
                        <i class="fas fa-edit"></i> 编辑
                    </button>
                    <button class="btn-action danger" onclick="deleteThirdPartyLoginConfig(${config.id}, '${escapeHtml(config.config_name)}')">
                        <i class="fas fa-trash"></i> 删除
                    </button>
                </div>
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * 渲染第三方授权配置分页
 */
function renderThirdPartyLoginConfigPagination(pagination) {
    const container = document.getElementById('third-party-login-config-pagination');
    
    const startItem = (pagination.page - 1) * pagination.page_size + 1;
    const endItem = Math.min(pagination.page * pagination.page_size, pagination.total);
    
    container.innerHTML = `
        <div class="pagination-info">
            显示 ${startItem} - ${endItem} 条，共 ${pagination.total} 条
        </div>
        <div class="pagination-buttons">
            <button class="btn-page" ${!pagination.has_prev ? 'disabled' : ''} 
                    onclick="loadThirdPartyLoginConfigList(${pagination.page - 1})">
                <i class="fas fa-chevron-left"></i> 上一页
            </button>
            <button class="btn-page active">${pagination.page}</button>
            <button class="btn-page" ${!pagination.has_next ? 'disabled' : ''} 
                    onclick="loadThirdPartyLoginConfigList(${pagination.page + 1})">
                下一页 <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
}

/**
 * 打开新增第三方授权配置弹窗
 */
function openAddThirdPartyLoginConfigModal() {
    // 重置表单
    document.getElementById('third-party-login-config-form').reset();
    document.getElementById('third-party-login-config-id').value = '';
    document.getElementById('third-party-login-config-modal-title').textContent = '新增第三方授权配置';
    document.getElementById('third-party-login-config-priority').value = '100';
    document.getElementById('third-party-login-config-status').value = '1';
    document.getElementById('third-party-login-config-is-enabled').checked = false;
    
    // 确保表单可编辑
    const form = document.getElementById('third-party-login-config-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-third-party-login-config').style.display = 'inline-flex';
    
    // 显示弹窗
    document.getElementById('third-party-login-config-modal').classList.add('show');
}

/**
 * 关闭第三方授权配置弹窗
 */
function closeThirdPartyLoginConfigModal() {
    // 恢复表单状态
    const form = document.getElementById('third-party-login-config-form');
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.disabled = false;
    });
    
    // 显示保存按钮
    document.getElementById('btn-save-third-party-login-config').style.display = 'inline-flex';
    
    // 关闭弹窗
    document.getElementById('third-party-login-config-modal').classList.remove('show');
}

/**
 * 查看第三方授权配置详情
 */
async function viewThirdPartyLoginConfig(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetThirdPartyLoginConfigDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const config = result.data;
        
        // 填充表单（只读模式）
        document.getElementById('third-party-login-config-id').value = config.id;
        document.getElementById('third-party-login-config-name').value = config.config_name;
        document.getElementById('third-party-login-config-platform').value = config.platform;
        document.getElementById('third-party-login-config-app-id').value = config.app_id;
        document.getElementById('third-party-login-config-app-secret').value = config.app_secret;
        document.getElementById('third-party-login-config-callback-url').value = config.callback_url;
        document.getElementById('third-party-login-config-scopes').value = config.scopes || '';
        document.getElementById('third-party-login-config-status').value = config.status;
        document.getElementById('third-party-login-config-is-enabled').checked = config.is_enabled;
        document.getElementById('third-party-login-config-priority').value = config.priority;
        document.getElementById('third-party-login-config-extra-config').value = config.extra_config || '';
        document.getElementById('third-party-login-config-description').value = config.description || '';
        
        // 设置为只读模式
        const form = document.getElementById('third-party-login-config-form');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.disabled = true;
        });
        
        // 修改标题和按钮
        document.getElementById('third-party-login-config-modal-title').textContent = '查看第三方授权配置';
        document.getElementById('btn-save-third-party-login-config').style.display = 'none';
        
        // 显示弹窗
        document.getElementById('third-party-login-config-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取第三方授权配置详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 编辑第三方授权配置
 */
async function editThirdPartyLoginConfig(id) {
    // 显示加载提示
    showToast('加载中...', 'info');
    
    try {
        const response = await fetch(`/admin/api/GetThirdPartyLoginConfigDetail.php?id=${id}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showToast(result.message || '获取详情失败', 'error');
            return;
        }
        
        const config = result.data;
        
        // 填充表单
        document.getElementById('third-party-login-config-id').value = config.id;
        document.getElementById('third-party-login-config-name').value = config.config_name;
        document.getElementById('third-party-login-config-platform').value = config.platform;
        document.getElementById('third-party-login-config-app-id').value = config.app_id;
        document.getElementById('third-party-login-config-app-secret').value = config.app_secret;
        document.getElementById('third-party-login-config-callback-url').value = config.callback_url;
        document.getElementById('third-party-login-config-scopes').value = config.scopes || '';
        document.getElementById('third-party-login-config-status').value = config.status;
        document.getElementById('third-party-login-config-is-enabled').checked = config.is_enabled;
        document.getElementById('third-party-login-config-priority').value = config.priority;
        document.getElementById('third-party-login-config-extra-config').value = config.extra_config || '';
        document.getElementById('third-party-login-config-description').value = config.description || '';
        
        // 修改标题
        document.getElementById('third-party-login-config-modal-title').textContent = '编辑第三方授权配置';
        document.getElementById('btn-save-third-party-login-config').style.display = 'inline-flex';
        
        // 显示弹窗
        document.getElementById('third-party-login-config-modal').classList.add('show');
        
    } catch (error) {
        console.error('获取第三方授权配置详情失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    }
}

/**
 * 保存第三方授权配置
 */
async function saveThirdPartyLoginConfig() {
    const form = document.getElementById('third-party-login-config-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // 获取保存按钮
    const saveBtn = document.getElementById('btn-save-third-party-login-config');
    const originalContent = saveBtn.innerHTML;
    
    // 禁用按钮并显示加载动画
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    const id = document.getElementById('third-party-login-config-id').value;
    const configName = document.getElementById('third-party-login-config-name').value.trim();
    const platform = document.getElementById('third-party-login-config-platform').value;
    const appId = document.getElementById('third-party-login-config-app-id').value.trim();
    const appSecret = document.getElementById('third-party-login-config-app-secret').value.trim();
    const callbackUrl = document.getElementById('third-party-login-config-callback-url').value.trim();
    const scopes = document.getElementById('third-party-login-config-scopes').value.trim();
    const status = parseInt(document.getElementById('third-party-login-config-status').value);
    const isEnabled = document.getElementById('third-party-login-config-is-enabled').checked;
    const priority = parseInt(document.getElementById('third-party-login-config-priority').value);
    const extraConfig = document.getElementById('third-party-login-config-extra-config').value.trim();
    const description = document.getElementById('third-party-login-config-description').value.trim();
    
    const data = {
        config_name: configName,
        platform: platform,
        app_id: appId,
        app_secret: appSecret,
        callback_url: callbackUrl,
        scopes: scopes,
        status: status,
        is_enabled: isEnabled,
        priority: priority,
        extra_config: extraConfig,
        description: description
    };
    
    if (id) {
        data.id = parseInt(id);
    }
    
    try {
        const response = await fetch('/admin/api/SaveThirdPartyLoginConfig.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        // 恢复按钮状态
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalContent;
        
        if (result.success) {
            showToast(id ? '更新成功' : '创建成功', 'success');
            closeThirdPartyLoginConfigModal();
            loadThirdPartyLoginConfigList(currentThirdPartyLoginConfigPage);
        } else {
            showToast(result.message || '保存失败', 'error');
        }
    } catch (error) {
        console.error('保存第三方授权配置失败:', error);
        showToast('网络错误，请稍后重试', 'error');
        
        // 恢复按钮状态
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalContent;
    }
}

/**
 * 删除第三方授权配置
 */
function deleteThirdPartyLoginConfig(id, name) {
    showConfirmDialog(
        `确定要删除配置"${name}"吗？`,
        async function() {
            try {
                const response = await fetch('/admin/api/DeleteThirdPartyLoginConfig.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({ id: id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('删除成功', 'success');
                    loadThirdPartyLoginConfigList(currentThirdPartyLoginConfigPage);
                } else {
                    showToast(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('删除第三方授权配置失败:', error);
                showToast('网络错误，请稍后重试', 'error');
            }
        }
    );
}

/**
 * 切换第三方授权配置密码显示/隐藏
 */
function toggleThirdPartyLoginConfigPassword() {
    const passwordInput = document.getElementById('third-party-login-config-app-secret');
    const passwordIcon = document.getElementById('third-party-login-config-password-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}


// ============================================
// 登录日志管理
// ============================================

let currentLoginLogsPage = 1;
let currentLoginLogsPageSize = 20;
let currentLoginLogsSearch = '';
let currentLoginMethod = '';
let currentTokenStatus = '';
let currentLoginDate = '';

/**
 * 初始化登录日志筛选器
 */
function initLoginLogFilters() {
    // 搜索按钮
    const searchBtn = document.getElementById('btn-login-logs-search');
    const searchInput = document.getElementById('login-logs-search');
    
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            currentLoginLogsSearch = searchInput.value.trim();
            loadLoginLogs(1);
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentLoginLogsSearch = this.value.trim();
                loadLoginLogs(1);
            }
        });
    }
    
    // 登录方式筛选
    const loginMethodFilter = document.getElementById('filter-login-method');
    if (loginMethodFilter) {
        loginMethodFilter.addEventListener('change', function() {
            currentLoginMethod = this.value;
            loadLoginLogs(1);
        });
    }
    
    // Token状态筛选
    const tokenStatusFilter = document.getElementById('filter-token-status');
    if (tokenStatusFilter) {
        tokenStatusFilter.addEventListener('change', function() {
            currentTokenStatus = this.value;
            loadLoginLogs(1);
        });
    }
    
    // 登录日期筛选
    const loginDateFilter = document.getElementById('filter-login-date');
    if (loginDateFilter) {
        loginDateFilter.addEventListener('change', function() {
            currentLoginDate = this.value;
            loadLoginLogs(1);
        });
    }
    
    // 重置按钮
    const resetBtn = document.getElementById('btn-reset-login-logs-filter');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            currentLoginLogsSearch = '';
            currentLoginMethod = '';
            currentTokenStatus = '';
            currentLoginDate = '';
            
            if (searchInput) searchInput.value = '';
            if (loginMethodFilter) loginMethodFilter.value = '';
            if (tokenStatusFilter) tokenStatusFilter.value = '';
            if (loginDateFilter) loginDateFilter.value = '';
            
            loadLoginLogs(1);
        });
    }
}

/**
 * 加载登录日志列表
 */
async function loadLoginLogs(page = 1) {
    currentLoginLogsPage = page;
    
    const loadingEl = document.getElementById('login-logs-loading');
    const emptyEl = document.getElementById('login-logs-empty');
    const tableEl = document.getElementById('login-logs-table');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    tableEl.style.display = 'none';
    
    try {
        // 构建查询参数
        const params = new URLSearchParams({
            page: currentLoginLogsPage,
            page_size: currentLoginLogsPageSize
        });
        
        if (currentLoginLogsSearch) {
            params.append('search', currentLoginLogsSearch);
        }
        if (currentLoginMethod) {
            params.append('login_method', currentLoginMethod);
        }
        if (currentTokenStatus !== '') {
            params.append('token_status', currentTokenStatus);
        }
        if (currentLoginDate) {
            params.append('login_date', currentLoginDate);
        }
        
        const response = await fetch(`/admin/api/GetLoginLogs.php?${params.toString()}`, {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载登录日志失败');
            loadingEl.style.display = 'none';
            emptyEl.style.display = 'block';
            return;
        }
        
        const logs = result.data.logs;
        const pagination = result.data.pagination;
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (logs.length === 0) {
            emptyEl.style.display = 'block';
            return;
        }
        
        // 显示表格
        tableEl.style.display = 'block';
        
        // 渲染登录日志列表
        renderLoginLogsList(logs);
        
        // 渲染分页
        renderLoginLogsPagination(pagination);
        
    } catch (error) {
        console.error('加载登录日志失败:', error);
        showError('加载登录日志失败');
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
    }
}

/**
 * 渲染登录日志列表
 */
function renderLoginLogsList(logs) {
    const tbody = document.getElementById('login-logs-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = logs.map(log => {
        // 状态样式
        const statusClass = {
            0: 'status-used',
            1: 'status-active',
            2: 'status-expired',
            3: 'status-closed'
        }[log.status] || 'status-unknown';
        
        // 登录方式图标
        const methodIcon = {
            'password': 'fa-key',
            'sms': 'fa-sms',
            'email': 'fa-envelope',
            'wechat': 'fa-weixin',
            'qq': 'fa-qq',
            'google': 'fa-google'
        }[log.login_method] || 'fa-sign-in-alt';
        
        return `
            <tr>
                <td>${log.id}</td>
                <td>
                    <div class="user-cell">
                        ${log.avatar ? `<img src="${log.avatar}" alt="头像" class="user-avatar-small">` : '<div class="user-avatar-small user-avatar-placeholder"><i class="fas fa-user"></i></div>'}
                        <div class="user-info-small">
                            <div class="user-nickname">${escapeHtml(log.nickname || log.username)}</div>
                            <div class="user-username">@${escapeHtml(log.username)}</div>
                        </div>
                    </div>
                </td>
                <td><code>${escapeHtml(log.app_id)}</code></td>
                <td>
                    <span class="login-method-badge">
                        <i class="fas ${methodIcon}"></i>
                        ${log.login_method_text}
                    </span>
                </td>
                <td><code>${escapeHtml(log.login_ip || '-')}</code></td>
                <td>${log.login_time_formatted}</td>
                <td><span class="status-badge ${statusClass}">${log.status_text}</span></td>
                <td>${log.expires_at_formatted}</td>
                <td>${log.used_at_formatted || '-'}</td>
                <td>
                    <button class="btn-icon" onclick="viewLoginLogDetail(${log.id})" title="查看详情">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * 渲染登录日志分页
 */
function renderLoginLogsPagination(pagination) {
    const container = document.getElementById('login-logs-pagination');
    if (!container) return;
    
    const { current_page, total_pages, total } = pagination;
    
    if (total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination">';
    html += `<div class="pagination-info">共 ${total} 条记录，第 ${current_page}/${total_pages} 页</div>`;
    html += '<div class="pagination-buttons">';
    
    // 上一页
    if (current_page > 1) {
        html += `<button class="pagination-btn" onclick="loadLoginLogs(${current_page - 1})">上一页</button>`;
    }
    
    // 页码
    const startPage = Math.max(1, current_page - 2);
    const endPage = Math.min(total_pages, current_page + 2);
    
    if (startPage > 1) {
        html += `<button class="pagination-btn" onclick="loadLoginLogs(1)">1</button>`;
        if (startPage > 2) {
            html += '<span class="pagination-ellipsis">...</span>';
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        if (i === current_page) {
            html += `<button class="pagination-btn active">${i}</button>`;
        } else {
            html += `<button class="pagination-btn" onclick="loadLoginLogs(${i})">${i}</button>`;
        }
    }
    
    if (endPage < total_pages) {
        if (endPage < total_pages - 1) {
            html += '<span class="pagination-ellipsis">...</span>';
        }
        html += `<button class="pagination-btn" onclick="loadLoginLogs(${total_pages})">${total_pages}</button>`;
    }
    
    // 下一页
    if (current_page < total_pages) {
        html += `<button class="pagination-btn" onclick="loadLoginLogs(${current_page + 1})">下一页</button>`;
    }
    
    html += '</div></div>';
    container.innerHTML = html;
}

/**
 * 查看登录日志详情
 */
function viewLoginLogDetail(logId) {
    // TODO: 实现登录日志详情弹窗
    showInfo(`查看登录日志详情功能开发中... (ID: ${logId})`);
}

/**
 * HTML 转义
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
