// 用户中心脚本

// 全局变量
let userInfo = null;

document.addEventListener('DOMContentLoaded', function() {
    init();
});

async function init() {
    // 检查登录状态
    await checkLogin();
    
    // 加载用户信息
    await loadUserInfo();
    
    // 初始化导航
    initNavigation();
    
    // 初始化退出登录
    initLogout();
    
    // 初始化弹出框
    initModal();
    
    // 初始化输入弹出框
    initInputModal();
    
    // 初始化编辑功能
    initEditFunctions();
}

/**
 * 检查登录状态
 */
async function checkLogin() {
    try {
        // 调用 API 检查登录状态
        const response = await fetch('api/CheckSession.php', {
            method: 'GET',
            credentials: 'include' // 包含 cookie
        });
        
        const result = await response.json();
        
        if (!result.success) {
            // 未登录，跳转到登录页
            showError('请先登录', '未登录');
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
            return;
        }
        
    } catch (error) {
        console.error('检查登录状态失败:', error);
        showError('网络错误，请稍后重试');
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 2000);
    }
}

/**
 * 加载用户信息
 */
async function loadUserInfo() {
    try {
        const response = await fetch('api/GetUserInfo.php', {
            method: 'GET',
            credentials: 'include' // 包含 cookie
        });
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载用户信息失败');
            // 如果是未登录错误，跳转到登录页
            if (result.message && result.message.includes('未登录')) {
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
            }
            return;
        }
        
        userInfo = result.data;
        
        // 更新页面显示
        updateUserDisplay();
        
    } catch (error) {
        console.error('加载用户信息失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 更新用户显示
 */
function updateUserDisplay() {
    if (!userInfo) return;
    
    // 更新导航栏
    const navAvatar = document.getElementById('nav-avatar');
    const navUsername = document.getElementById('nav-username');
    
    if (navAvatar) {
        navAvatar.src = userInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
        navAvatar.onerror = function() {
            this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
            this.onerror = null; // 防止无限循环
        };
    }
    
    if (navUsername) {
        navUsername.textContent = userInfo.nickname || userInfo.username;
    }
    
    // 如果是管理员，显示管理后台按钮
    const btnAdmin = document.getElementById('btn-admin');
    if (btnAdmin && userInfo.user_type === 'admin') {
        btnAdmin.style.display = 'flex';
    }
    
    // 更新账户概览
    const profileAvatar = document.getElementById('profile-avatar');
    const profileName = document.getElementById('profile-name');
    const profileUsername = document.getElementById('profile-username');
    
    if (profileAvatar) {
        profileAvatar.src = userInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
        profileAvatar.onerror = function() {
            this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
            this.onerror = null; // 防止无限循环
        };
    }
    
    if (profileName) {
        profileName.textContent = userInfo.nickname || userInfo.username;
    }
    
    if (profileUsername) {
        profileUsername.textContent = '@' + userInfo.username;
    }
    
    // 更新统计数据
    document.getElementById('stat-authorized').textContent = userInfo.authorized_count || '0';
    document.getElementById('stat-register-time').textContent = formatDate(userInfo.created_at);
    document.getElementById('stat-register-ip').textContent = userInfo.register_ip || '-';
    document.getElementById('stat-last-login').textContent = formatDateTime(userInfo.last_login_at);
    document.getElementById('stat-last-ip').textContent = userInfo.last_login_ip || '-';
    
    // 更新账户状态卡片
    updateStatusCard(userInfo.status);
    
    // 更新账户信息页面
    updateProfilePage();
}

/**
 * 更新账户状态卡片
 */
function updateStatusCard(status) {
    const statusCard = document.getElementById('status-card');
    const statusText = document.getElementById('stat-status-text');
    
    if (!statusCard || !statusText) return;
    
    // 移除所有状态类
    statusCard.classList.remove('status-normal', 'status-banned', 'status-warning');
    
    // 根据状态设置文本和样式
    switch (parseInt(status)) {
        case 0:
            statusText.textContent = '已封禁';
            statusCard.classList.add('status-banned');
            break;
        case 1:
            statusText.textContent = '正常';
            statusCard.classList.add('status-normal');
            break;
        case 2:
            statusText.textContent = '手机号待核验';
            statusCard.classList.add('status-warning');
            break;
        case 3:
            statusText.textContent = '邮箱待核验';
            statusCard.classList.add('status-warning');
            break;
        default:
            statusText.textContent = '未知';
            statusCard.classList.add('status-warning');
            break;
    }
}

/**
 * 更新账户信息页面
 */
function updateProfilePage() {
    if (!userInfo) return;
    
    // 头像和基本信息
    const infoAvatar = document.getElementById('info-avatar');
    if (infoAvatar) {
        infoAvatar.src = userInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
        infoAvatar.onerror = function() {
            this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
            this.onerror = null; // 防止无限循环
        };
    }
    
    document.getElementById('info-display-name').textContent = userInfo.nickname || userInfo.username;
    document.getElementById('info-username').textContent = userInfo.username || '-';
    updateStatusBadge('info-status', userInfo.status);
    document.getElementById('info-user-type').textContent = getUserTypeText(userInfo.user_type);
    
    // 显示审核状态
    updatePendingReviewNotices();
    
    // 联系方式
    document.getElementById('info-phone').textContent = userInfo.phone || '未绑定';
    document.getElementById('info-email').textContent = userInfo.email || '未绑定';
    
    // 个人资料
    document.getElementById('info-gender').textContent = getGenderText(userInfo.gender);
    document.getElementById('info-birth-date').textContent = userInfo.birth_date ? formatDate(userInfo.birth_date) : '未设置';
    
    // 账户记录
    document.getElementById('info-created-at').textContent = formatDateTime(userInfo.created_at);
    document.getElementById('info-register-ip').textContent = userInfo.register_ip || '-';
    document.getElementById('info-last-login').textContent = formatDateTime(userInfo.last_login_at);
    document.getElementById('info-last-ip').textContent = userInfo.last_login_ip || '-';
}

/**
 * 更新审核状态提示
 */
function updatePendingReviewNotices() {
    // 昵称审核状态小标签
    const nicknameBadge = document.getElementById('nickname-pending-badge');
    
    if (userInfo.pending_nickname) {
        if (nicknameBadge) {
            nicknameBadge.style.display = 'inline-flex';
            nicknameBadge.title = `昵称"${userInfo.pending_nickname.nickname}"正在审核中`;
        }
    } else {
        if (nicknameBadge) {
            nicknameBadge.style.display = 'none';
        }
    }
    
    // 头像审核状态小标签
    const avatarBadge = document.getElementById('avatar-pending-badge');
    
    if (userInfo.pending_avatar) {
        if (avatarBadge) {
            avatarBadge.style.display = 'inline-flex';
        }
    } else {
        if (avatarBadge) {
            avatarBadge.style.display = 'none';
        }
    }
}

/**
 * 更新状态徽章
 */
function updateStatusBadge(elementId, status) {
    const badge = document.getElementById(elementId);
    if (!badge) return;
    
    // 移除所有状态类
    badge.className = 'status-badge';
    
    // 根据状态设置文本和样式
    switch (parseInt(status)) {
        case 0:
            badge.textContent = '已封禁';
            badge.classList.add('status-banned');
            break;
        case 1:
            badge.textContent = '正常';
            badge.classList.add('status-normal');
            break;
        case 2:
            badge.textContent = '手机号待核验';
            badge.classList.add('status-phone-pending');
            break;
        case 3:
            badge.textContent = '邮箱待核验';
            badge.classList.add('status-email-pending');
            break;
        default:
            badge.textContent = '未知';
            break;
    }
}

/**
 * 获取性别文本
 */
function getGenderText(gender) {
    switch (parseInt(gender)) {
        case 0:
            return '未知';
        case 1:
            return '男';
        case 2:
            return '女';
        default:
            return '未设置';
    }
}

/**
 * 获取用户类型文本
 */
function getUserTypeText(userType) {
    switch (userType) {
        case 'user':
            return '普通用户';
        case 'admin':
            return '全局管理员';
        case 'siteadmin':
            return '站点管理员';
        default:
            return '未知';
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
            
            // 切换页面
            pages.forEach(page => page.classList.remove('active'));
            const targetPage = document.getElementById(`page-${pageName}`);
            if (targetPage) {
                targetPage.classList.add('active');
                
                // 如果切换到已授权网站页面，加载数据
                if (pageName === 'authorized') {
                    loadAuthorizedApps();
                }
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
 * 初始化退出登录
 */
function initLogout() {
    const logoutBtn = document.getElementById('btn-logout');
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            showConfirm('确定要退出登录吗？', '退出登录', async function() {
                try {
                    // 调用退出登录 API
                    const response = await fetch('api/Logout.php', {
                        method: 'POST',
                        credentials: 'include' // 包含 cookie
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // 显示提示
                        showSuccessToast('已退出登录', '退出成功');
                        
                        // 跳转到登录页
                        setTimeout(() => {
                            window.location.href = 'login.php';
                        }, 1500);
                    } else {
                        showError(result.message || '退出登录失败');
                    }
                } catch (error) {
                    console.error('退出登录失败:', error);
                    showError('网络错误，请稍后重试');
                }
            });
        });
    }
}

/**
 * 初始化弹出框
 */
function initModal() {
    const overlay = document.getElementById('modal-overlay');
    const confirmBtn = document.getElementById('modal-confirm');
    const cancelBtn = document.getElementById('modal-cancel');
    
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (overlay) {
                overlay.classList.remove('show');
            }
            
            // 执行回调
            if (overlay.confirmCallback) {
                overlay.confirmCallback();
                overlay.confirmCallback = null;
            }
        });
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            if (overlay) {
                overlay.classList.remove('show');
            }
            
            // 执行取消回调
            if (overlay.cancelCallback) {
                overlay.cancelCallback();
                overlay.cancelCallback = null;
            }
        });
    }
    
    // 点击遮罩层关闭
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('show');
            }
        });
    }
}

/**
 * 显示错误信息
 */
function showError(message, title = '错误') {
    const overlay = document.getElementById('modal-overlay');
    const icon = document.getElementById('modal-icon');
    const titleEl = document.getElementById('modal-title');
    const messageEl = document.getElementById('modal-message');
    const cancelBtn = document.getElementById('modal-cancel');
    
    if (overlay && icon && titleEl && messageEl) {
        icon.className = 'modal-icon error';
        icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
        titleEl.textContent = title;
        messageEl.textContent = message;
        cancelBtn.style.display = 'none';
        overlay.classList.add('show');
    }
}

/**
 * 显示确认对话框
 */
function showConfirm(message, title = '确认', onConfirm, onCancel) {
    const overlay = document.getElementById('modal-overlay');
    const icon = document.getElementById('modal-icon');
    const titleEl = document.getElementById('modal-title');
    const messageEl = document.getElementById('modal-message');
    const cancelBtn = document.getElementById('modal-cancel');
    
    if (overlay && icon && titleEl && messageEl) {
        icon.className = 'modal-icon warning';
        icon.innerHTML = '<i class="fas fa-question-circle"></i>';
        titleEl.textContent = title;
        messageEl.textContent = message;
        cancelBtn.style.display = 'inline-block';
        overlay.classList.add('show');
        
        // 设置回调
        overlay.confirmCallback = onConfirm;
        overlay.cancelCallback = onCancel;
    }
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
 * 初始化输入弹出框
 */
function initInputModal() {
    const overlay = document.getElementById('input-modal-overlay');
    const confirmBtn = document.getElementById('input-modal-confirm');
    const cancelBtn = document.getElementById('input-modal-cancel');
    const inputField = document.getElementById('input-modal-field');
    
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (overlay) {
                const value = inputField.value.trim();
                overlay.classList.remove('show');
                
                // 执行回调
                if (overlay.confirmCallback) {
                    overlay.confirmCallback(value);
                    overlay.confirmCallback = null;
                }
            }
        });
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            if (overlay) {
                overlay.classList.remove('show');
                
                // 执行取消回调
                if (overlay.cancelCallback) {
                    overlay.cancelCallback();
                    overlay.cancelCallback = null;
                }
            }
        });
    }
    
    // 点击遮罩层关闭
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('show');
                if (overlay.cancelCallback) {
                    overlay.cancelCallback();
                    overlay.cancelCallback = null;
                }
            }
        });
    }
    
    // 回车键确认
    if (inputField) {
        inputField.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmBtn.click();
            }
        });
    }
}

/**
 * 显示输入对话框
 */
function showInputDialog(title, message, defaultValue, onConfirm, onCancel) {
    const overlay = document.getElementById('input-modal-overlay');
    const titleEl = document.getElementById('input-modal-title');
    const messageEl = document.getElementById('input-modal-message');
    const inputField = document.getElementById('input-modal-field');
    
    if (overlay && titleEl && messageEl && inputField) {
        titleEl.textContent = title;
        messageEl.textContent = message;
        inputField.value = defaultValue || '';
        overlay.classList.add('show');
        
        // 聚焦输入框
        setTimeout(() => {
            inputField.focus();
            inputField.select();
        }, 100);
        
        // 设置回调
        overlay.confirmCallback = onConfirm;
        overlay.cancelCallback = onCancel;
    }
}

/**
 * 初始化编辑功能
 */
function initEditFunctions() {
    // 编辑昵称
    const btnEditNickname = document.getElementById('btn-edit-nickname');
    if (btnEditNickname) {
        btnEditNickname.addEventListener('click', function() {
            showEditNicknameDialog();
        });
    }
    
    // 编辑头像 - 点击头像区域
    const avatarWrapper = document.querySelector('.profile-avatar-wrapper');
    const avatarOverlay = document.getElementById('avatar-overlay');
    
    if (avatarWrapper && avatarOverlay) {
        avatarWrapper.addEventListener('click', function() {
            showUploadAvatarDialog();
        });
    }
}

/**
 * 显示编辑昵称对话框
 */
function showEditNicknameDialog() {
    if (!userInfo) return;
    
    const currentNickname = userInfo.nickname || userInfo.username;
    
    showInputDialog(
        '修改昵称',
        '请输入新昵称（2-20个字符）',
        currentNickname,
        function(newNickname) {
            // 确认回调
            if (newNickname === '') {
                showError('昵称不能为空');
                return;
            }
            
            if (newNickname === currentNickname) {
                showError('新昵称与当前昵称相同');
                return;
            }
            
            // 调用API修改昵称
            updateNickname(newNickname);
        },
        function() {
            // 取消回调（可选）
            console.log('取消修改昵称');
        }
    );
}

/**
 * 修改昵称
 */
async function updateNickname(nickname) {
    try {
        const response = await fetch('api/UpdateNickname.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ nickname: nickname })
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.data.need_review) {
                showSuccessToast(result.message, '提交成功');
            } else {
                showSuccessToast('昵称修改成功', '修改成功');
                // 更新用户信息
                userInfo.nickname = result.data.nickname;
                updateUserDisplay();
            }
        } else {
            showError(result.message || '修改昵称失败');
        }
    } catch (error) {
        console.error('修改昵称失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 显示头像上传对话框
 */
function showUploadAvatarDialog() {
    const overlay = document.getElementById('upload-modal-overlay');
    const uploadArea = document.getElementById('upload-area');
    const fileInput = document.getElementById('avatar-file-input');
    const btnSelectFile = document.getElementById('btn-select-file');
    const btnRemovePreview = document.getElementById('btn-remove-preview');
    const uploadPreview = document.getElementById('upload-preview');
    const previewImage = document.getElementById('preview-image');
    const uploadProgress = document.getElementById('upload-progress');
    const confirmBtn = document.getElementById('upload-modal-confirm');
    const cancelBtn = document.getElementById('upload-modal-cancel');
    
    let selectedFile = null;
    
    // 显示弹出框
    overlay.classList.add('show');
    
    // 重置状态
    uploadArea.style.display = 'block';
    uploadPreview.style.display = 'none';
    uploadProgress.style.display = 'none';
    selectedFile = null;
    
    // 点击选择文件
    btnSelectFile.onclick = function() {
        fileInput.click();
    };
    
    // 文件选择
    fileInput.onchange = function(e) {
        const file = e.target.files[0];
        if (file) {
            handleFileSelect(file);
        }
    };
    
    // 拖拽上传
    uploadArea.ondragover = function(e) {
        e.preventDefault();
        uploadArea.classList.add('drag-over');
    };
    
    uploadArea.ondragleave = function(e) {
        e.preventDefault();
        uploadArea.classList.remove('drag-over');
    };
    
    uploadArea.ondrop = function(e) {
        e.preventDefault();
        uploadArea.classList.remove('drag-over');
        
        const file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            handleFileSelect(file);
        } else {
            showError('请拖拽图片文件');
        }
    };
    
    // 处理文件选择
    function handleFileSelect(file) {
        // 验证文件类型
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showError('不支持的文件格式，请选择 JPG、PNG、GIF 或 WEBP 格式的图片');
            return;
        }
        
        // 验证文件大小 (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showError('文件大小超过限制，最大支持 5MB');
            return;
        }
        
        selectedFile = file;
        
        // 显示预览
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            uploadArea.style.display = 'none';
            uploadPreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
    
    // 移除预览
    btnRemovePreview.onclick = function() {
        selectedFile = null;
        fileInput.value = '';
        uploadArea.style.display = 'block';
        uploadPreview.style.display = 'none';
    };
    
    // 确认上传
    confirmBtn.onclick = async function() {
        if (!selectedFile) {
            showError('请先选择要上传的图片');
            return;
        }
        
        // 显示进度条
        uploadPreview.style.display = 'none';
        uploadProgress.style.display = 'block';
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        
        // 上传文件
        await uploadAvatarFile(selectedFile);
    };
    
    // 取消
    cancelBtn.onclick = function() {
        overlay.classList.remove('show');
        selectedFile = null;
        fileInput.value = '';
        confirmBtn.disabled = false;
        cancelBtn.disabled = false;
    };
    
    // 点击遮罩层关闭
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            cancelBtn.click();
        }
    };
}

/**
 * 上传头像文件
 */
async function uploadAvatarFile(file) {
    try {
        const formData = new FormData();
        formData.append('avatar', file);
        
        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');
        
        // 模拟进度（实际应该使用 XMLHttpRequest 来获取真实进度）
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += 10;
            if (progress <= 90) {
                progressFill.style.width = progress + '%';
                progressText.textContent = `上传中... ${progress}%`;
            }
        }, 200);
        
        const response = await fetch('api/UploadAvatar.php', {
            method: 'POST',
            credentials: 'include',
            body: formData
        });
        
        clearInterval(progressInterval);
        progressFill.style.width = '100%';
        progressText.textContent = '上传中... 100%';
        
        const result = await response.json();
        
        // 关闭弹出框
        document.getElementById('upload-modal-overlay').classList.remove('show');
        document.getElementById('upload-modal-confirm').disabled = false;
        document.getElementById('upload-modal-cancel').disabled = false;
        
        if (result.success) {
            if (result.data.need_review) {
                showSuccessToast(result.message, '提交成功');
            } else {
                showSuccessToast('头像上传成功', '上传成功');
                // 更新用户信息
                userInfo.avatar = result.data.avatar;
                updateUserDisplay();
            }
        } else {
            showError(result.message || '头像上传失败');
        }
        
    } catch (error) {
        console.error('头像上传失败:', error);
        showError('网络错误，请稍后重试');
        
        // 关闭弹出框
        document.getElementById('upload-modal-overlay').classList.remove('show');
        document.getElementById('upload-modal-confirm').disabled = false;
        document.getElementById('upload-modal-cancel').disabled = false;
    }
}

/**
 * 显示编辑头像对话框（URL方式 - 保留作为备用）
 */
function showEditAvatarDialog() {
    if (!userInfo) return;
    
    const currentAvatar = userInfo.avatar || '';
    
    showInputDialog(
        '修改头像',
        '请输入新头像URL（必须是HTTPS链接）',
        currentAvatar,
        function(newAvatar) {
            // 确认回调
            if (newAvatar === '') {
                showError('头像URL不能为空');
                return;
            }
            
            if (newAvatar === currentAvatar) {
                showError('新头像与当前头像相同');
                return;
            }
            
            if (!newAvatar.startsWith('https://')) {
                showError('头像URL必须是HTTPS链接');
                return;
            }
            
            // 调用API修改头像
            updateAvatar(newAvatar);
        },
        function() {
            // 取消回调（可选）
            console.log('取消修改头像');
        }
    );
}

/**
 * 修改头像
 */
async function updateAvatar(avatar) {
    try {
        const response = await fetch('api/UpdateAvatar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ avatar: avatar })
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.data.need_review) {
                showSuccessToast(result.message, '提交成功');
            } else {
                showSuccessToast('头像修改成功', '修改成功');
                // 更新用户信息
                userInfo.avatar = result.data.avatar;
                updateUserDisplay();
            }
        } else {
            showError(result.message || '修改头像失败');
        }
    } catch (error) {
        console.error('修改头像失败:', error);
        showError('网络错误，请稍后重试');
    }
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
function formatDateTime(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    
    return `${year}-${month}-${day} ${hours}:${minutes}`;
}


/**
 * 加载已授权应用列表
 */
async function loadAuthorizedApps() {
    const loadingEl = document.getElementById('authorized-loading');
    const emptyEl = document.getElementById('authorized-empty');
    const listEl = document.getElementById('authorized-list');
    
    // 显示加载中
    loadingEl.style.display = 'block';
    emptyEl.style.display = 'none';
    listEl.style.display = 'none';
    
    try {
        const response = await fetch('api/GetAuthorizedApps.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载已授权应用失败');
            loadingEl.style.display = 'none';
            
            // 如果是系统未初始化的提示，显示特殊的空状态
            if (result.message && result.message.includes('尚未初始化')) {
                emptyEl.querySelector('h3').textContent = '授权系统尚未初始化';
                emptyEl.querySelector('p').textContent = result.message;
            }
            emptyEl.style.display = 'block';
            return;
        }
        
        const apps = result.data.apps || [];
        
        // 隐藏加载中
        loadingEl.style.display = 'none';
        
        if (apps.length === 0) {
            // 显示空状态
            emptyEl.querySelector('h3').textContent = '暂无授权应用';
            emptyEl.querySelector('p').textContent = '您还没有授权任何第三方应用访问您的账户';
            emptyEl.style.display = 'block';
        } else {
            // 显示应用列表
            listEl.style.display = 'grid';
            renderAuthorizedApps(apps);
        }
        
    } catch (error) {
        console.error('加载已授权应用失败:', error);
        showError('网络错误，请稍后重试');
        loadingEl.style.display = 'none';
        emptyEl.style.display = 'block';
    }
}

/**
 * 渲染已授权应用列表
 */
function renderAuthorizedApps(apps) {
    const listEl = document.getElementById('authorized-list');
    listEl.innerHTML = '';
    
    apps.forEach(app => {
        const appCard = createAppCard(app);
        listEl.appendChild(appCard);
    });
}

/**
 * 创建应用卡片
 */
function createAppCard(app) {
    const card = document.createElement('div');
    card.className = 'app-card';
    
    // 解析权限
    const scopes = app.scope ? app.scope.split(',') : [];
    const scopeNames = {
        'user.basic': '基本信息',
        'user.email': '邮箱地址',
        'user.phone': '手机号',
        'user.profile': '个人资料',
        'basic': '基本信息',
        'email': '邮箱地址',
        'phone': '手机号',
        'profile': '个人资料'
    };
    
    card.innerHTML = `
        <div class="app-header">
            <img src="${app.app_icon}" alt="${app.app_name}" class="app-icon" onerror="this.src='https://via.placeholder.com/64'">
            <div class="app-info">
                <div class="app-name">${app.app_name}</div>
                <div class="app-url">
                    <a href="${app.app_url}" target="_blank" rel="noopener noreferrer">
                        ${app.app_url}
                    </a>
                </div>
            </div>
        </div>
        
        ${app.app_description ? `<div class="app-description">${app.app_description}</div>` : ''}
        
        <div class="app-details">
            <div class="app-detail-item">
                <i class="fas fa-calendar-alt"></i>
                <span class="app-detail-label">授权时间：</span>
                <span class="app-detail-value">${formatDateTime(app.authorized_at)}</span>
            </div>
        </div>
        
        ${scopes.length > 0 ? `
        <div class="app-scopes">
            ${scopes.map(scope => `<span class="scope-badge">${scopeNames[scope.trim()] || scope.trim()}</span>`).join('')}
        </div>
        ` : ''}
        
        <div class="app-actions">
            <button class="btn-revoke" onclick="revokeAuthorization('${app.app_id}', '${app.app_name}')">
                <i class="fas fa-times-circle"></i>
                取消授权
            </button>
            <a href="${app.app_url}" target="_blank" rel="noopener noreferrer" class="btn-visit">
                <i class="fas fa-external-link-alt"></i>
                访问网站
            </a>
        </div>
    `;
    
    return card;
}

/**
 * 取消授权
 */
async function revokeAuthorization(appId, appName) {
    showConfirm(
        `确定要取消对"${appName}"的授权吗？取消后该应用将无法访问您的账户信息。`,
        '取消授权',
        async function() {
            try {
                const response = await fetch('api/RevokeAuthorization.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({ app_id: appId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessToast('已取消授权', '操作成功');
                    // 重新加载应用列表
                    setTimeout(() => {
                        loadAuthorizedApps();
                    }, 1000);
                } else {
                    showError(result.message || '取消授权失败');
                }
            } catch (error) {
                console.error('取消授权失败:', error);
                showError('网络错误，请稍后重试');
            }
        }
    );
}


/**
 * 加载第三方绑定信息
 */
async function loadThirdPartyBindings() {
    const loadingEl = document.getElementById('third-party-loading');
    const listEl = document.getElementById('third-party-list');
    
    try {
        const response = await fetch('api/GetThirdPartyBindings.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '加载第三方绑定信息失败');
            return;
        }
        
        // 隐藏加载中
        if (loadingEl) loadingEl.style.display = 'none';
        
        // 显示列表
        if (listEl) listEl.style.display = 'block';
        
        // 更新QQ绑定状态
        updateQQBindingStatus(result.data.qq);
        
        // 更新微信绑定状态
        updateWechatBindingStatus(result.data.wechat);
        
        // 更新微博绑定状态
        updateWeiboBindingStatus(result.data.weibo);
        
        // 更新 GitHub 绑定状态
        updateGithubBindingStatus(result.data.github);
        
    } catch (error) {
        console.error('加载第三方绑定信息失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 更新QQ绑定状态
 */
function updateQQBindingStatus(qqInfo) {
    const statusText = document.getElementById('qq-status-text');
    const detailEl = document.getElementById('qq-detail');
    const bindBtn = document.getElementById('btn-bind-qq');
    const unbindBtn = document.getElementById('btn-unbind-qq');
    
    if (qqInfo && qqInfo.bound) {
        // 已绑定
        if (statusText) {
            statusText.textContent = '已绑定';
            statusText.classList.add('status-bound');
        }
        
        // 显示详细信息
        if (detailEl) {
            detailEl.style.display = 'block';
            
            const avatar = document.getElementById('qq-avatar');
            const nickname = document.getElementById('qq-nickname');
            const bindTime = document.getElementById('qq-bind-time');
            
            if (avatar) {
                // 设置头像，如果没有则使用默认头像
                avatar.src = qqInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
                // 添加错误处理，加载失败时使用默认头像
                avatar.onerror = function() {
                    this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
                    this.onerror = null; // 防止无限循环
                };
            }
            if (nickname) nickname.textContent = qqInfo.nickname || '-';
            if (bindTime) bindTime.textContent = formatDateTime(qqInfo.bind_time);
        }
        
        // 显示解绑按钮，隐藏绑定按钮
        if (bindBtn) bindBtn.style.display = 'none';
        if (unbindBtn) unbindBtn.style.display = 'inline-flex';
        
    } else {
        // 未绑定
        if (statusText) {
            statusText.textContent = '未绑定';
            statusText.classList.remove('status-bound');
        }
        
        // 隐藏详细信息
        if (detailEl) detailEl.style.display = 'none';
        
        // 显示绑定按钮，隐藏解绑按钮
        if (bindBtn) bindBtn.style.display = 'inline-flex';
        if (unbindBtn) unbindBtn.style.display = 'none';
    }
}

/**
 * 更新微信绑定状态
 */
function updateWechatBindingStatus(wechatInfo) {
    const statusText = document.getElementById('wechat-status-text');
    const detailEl = document.getElementById('wechat-detail');
    const bindBtn = document.getElementById('btn-bind-wechat');
    const unbindBtn = document.getElementById('btn-unbind-wechat');
    
    if (wechatInfo && wechatInfo.bound) {
        // 已绑定
        if (statusText) {
            statusText.textContent = '已绑定';
            statusText.classList.add('status-bound');
        }
        
        // 显示详细信息
        if (detailEl) {
            detailEl.style.display = 'block';
            
            const avatar = document.getElementById('wechat-avatar');
            const nickname = document.getElementById('wechat-nickname');
            const bindTime = document.getElementById('wechat-bind-time');
            
            if (avatar) {
                // 设置头像，如果没有则使用默认头像
                avatar.src = wechatInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
                // 添加错误处理，加载失败时使用默认头像
                avatar.onerror = function() {
                    this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
                    this.onerror = null; // 防止无限循环
                };
            }
            if (nickname) nickname.textContent = wechatInfo.nickname || '-';
            if (bindTime) bindTime.textContent = formatDateTime(wechatInfo.bind_time);
        }
        
        // 显示解绑按钮，隐藏绑定按钮
        if (bindBtn) bindBtn.style.display = 'none';
        if (unbindBtn) unbindBtn.style.display = 'inline-flex';
        
    } else {
        // 未绑定
        if (statusText) {
            statusText.textContent = '未绑定';
            statusText.classList.remove('status-bound');
        }
        
        // 隐藏详细信息
        if (detailEl) detailEl.style.display = 'none';
        
        // 显示绑定按钮，隐藏解绑按钮
        if (bindBtn) bindBtn.style.display = 'inline-flex';
        if (unbindBtn) unbindBtn.style.display = 'none';
    }
}

/**
 * 更新微博绑定状态
 */
function updateWeiboBindingStatus(weiboInfo) {
    const statusText = document.getElementById('weibo-status-text');
    const detailEl = document.getElementById('weibo-detail');
    const bindBtn = document.getElementById('btn-bind-weibo');
    const unbindBtn = document.getElementById('btn-unbind-weibo');
    
    if (weiboInfo && weiboInfo.bound) {
        // 已绑定
        if (statusText) {
            statusText.textContent = '已绑定';
            statusText.classList.add('status-bound');
        }
        
        // 显示详细信息
        if (detailEl) {
            detailEl.style.display = 'block';
            
            const avatar = document.getElementById('weibo-avatar');
            const nickname = document.getElementById('weibo-nickname');
            const bindTime = document.getElementById('weibo-bind-time');
            
            if (avatar) {
                // 设置头像，如果没有则使用默认头像
                avatar.src = weiboInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
                // 添加错误处理，加载失败时使用默认头像
                avatar.onerror = function() {
                    this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
                    this.onerror = null; // 防止无限循环
                };
            }
            if (nickname) nickname.textContent = weiboInfo.nickname || '-';
            if (bindTime) bindTime.textContent = formatDateTime(weiboInfo.bind_time);
        }
        
        // 显示解绑按钮，隐藏绑定按钮
        if (bindBtn) bindBtn.style.display = 'none';
        if (unbindBtn) unbindBtn.style.display = 'inline-flex';
        
    } else {
        // 未绑定
        if (statusText) {
            statusText.textContent = '未绑定';
            statusText.classList.remove('status-bound');
        }
        
        // 隐藏详细信息
        if (detailEl) detailEl.style.display = 'none';
        
        // 显示绑定按钮，隐藏解绑按钮
        if (bindBtn) bindBtn.style.display = 'inline-flex';
        if (unbindBtn) unbindBtn.style.display = 'none';
    }
}

/**
 * 更新 GitHub 绑定状态
 */
function updateGithubBindingStatus(githubInfo) {
    const statusText = document.getElementById('github-status-text');
    const detailEl = document.getElementById('github-detail');
    const bindBtn = document.getElementById('btn-bind-github');
    const unbindBtn = document.getElementById('btn-unbind-github');
    
    if (githubInfo && githubInfo.bound) {
        // 已绑定
        if (statusText) {
            statusText.textContent = '已绑定';
            statusText.classList.add('status-bound');
        }
        
        // 显示详细信息
        if (detailEl) {
            detailEl.style.display = 'block';
            
            const avatar = document.getElementById('github-avatar');
            const nickname = document.getElementById('github-nickname');
            const bindTime = document.getElementById('github-bind-time');
            
            if (avatar) {
                // 设置头像，如果没有则使用默认头像
                avatar.src = githubInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
                // 添加错误处理，加载失败时使用默认头像
                avatar.onerror = function() {
                    this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
                    this.onerror = null; // 防止无限循环
                };
            }
            if (nickname) nickname.textContent = githubInfo.nickname || githubInfo.login || '-';
            if (bindTime) bindTime.textContent = formatDateTime(githubInfo.bind_time);
        }
        
        // 显示解绑按钮，隐藏绑定按钮
        if (bindBtn) bindBtn.style.display = 'none';
        if (unbindBtn) unbindBtn.style.display = 'inline-flex';
        
    } else {
        // 未绑定
        if (statusText) {
            statusText.textContent = '未绑定';
            statusText.classList.remove('status-bound');
        }
        
        // 隐藏详细信息
        if (detailEl) detailEl.style.display = 'none';
        
        // 显示绑定按钮，隐藏解绑按钮
        if (bindBtn) bindBtn.style.display = 'inline-flex';
        if (unbindBtn) unbindBtn.style.display = 'none';
    }
}

/**
 * 绑定QQ
 */
async function bindQQ() {
    try {
        const response = await fetch('api/BindQQ.php', {
            method: 'POST',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '绑定失败');
            return;
        }
        
        // 如果需要跳转到QQ授权页面
        if (result.data && result.data.need_auth && result.data.auth_url) {
            window.location.href = result.data.auth_url;
            return;
        }
        
        // 绑定成功
        showSuccessToast('QQ账号绑定成功', '绑定成功');
        
        // 重新加载绑定信息
        setTimeout(() => {
            loadThirdPartyBindings();
        }, 1000);
        
    } catch (error) {
        console.error('绑定QQ失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解绑QQ
 */
function unbindQQ() {
    // 确认对话框
    showConfirm(
        '解绑后将无法使用QQ账号快捷登录，确定要解绑吗？',
        '确认解绑',
        async function() {
            try {
                const response = await fetch('api/UnbindQQ.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    showError(result.message || '解绑失败');
                    return;
                }
                
                showSuccessToast('QQ账号解绑成功', '解绑成功');
                
                // 重新加载绑定信息
                setTimeout(() => {
                    loadThirdPartyBindings();
                }, 1000);
                
            } catch (error) {
                console.error('解绑QQ失败:', error);
                showError('网络错误，请稍后重试');
            }
        }
    );
}

/**
 * 绑定微信
 */
async function bindWechat() {
    try {
        const response = await fetch('api/BindWechat.php', {
            method: 'POST',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '绑定失败');
            return;
        }
        
        // 跳转到微信授权页面
        if (result.data && result.data.authorize_url) {
            window.location.href = result.data.authorize_url;
            return;
        }
        
        // 绑定成功
        showSuccessToast('微信账号绑定成功', '绑定成功');
        
        // 重新加载绑定信息
        setTimeout(() => {
            loadThirdPartyBindings();
        }, 1000);
        
    } catch (error) {
        console.error('绑定微信失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解绑微信
 */
function unbindWechat() {
    // 确认对话框
    showConfirm(
        '解绑后将无法使用微信账号快捷登录，确定要解绑吗？',
        '确认解绑',
        async function() {
            try {
                const response = await fetch('api/UnbindWechat.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    showError(result.message || '解绑失败');
                    return;
                }
                
                showSuccessToast('微信账号解绑成功', '解绑成功');
                
                // 重新加载绑定信息
                setTimeout(() => {
                    loadThirdPartyBindings();
                }, 1000);
                
            } catch (error) {
                console.error('解绑微信失败:', error);
                showError('网络错误，请稍后重试');
            }
        }
    );
}

/**
 * 绑定微博
 */
async function bindWeibo() {
    try {
        const response = await fetch('api/BindWeibo.php', {
            method: 'POST',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '绑定失败');
            return;
        }
        
        // 跳转到微博授权页面
        if (result.data && result.data.authorize_url) {
            window.location.href = result.data.authorize_url;
            return;
        }
        
        // 绑定成功
        showSuccessToast('微博账号绑定成功', '绑定成功');
        
        // 重新加载绑定信息
        setTimeout(() => {
            loadThirdPartyBindings();
        }, 1000);
        
    } catch (error) {
        console.error('绑定微博失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解绑微博
 */
function unbindWeibo() {
    // 确认对话框
    showConfirm(
        '解绑后将无法使用微博账号快捷登录，确定要解绑吗？',
        '确认解绑',
        async function() {
            try {
                const response = await fetch('api/UnbindWeibo.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    showError(result.message || '解绑失败');
                    return;
                }
                
                showSuccessToast('微博账号解绑成功', '解绑成功');
                
                // 重新加载绑定信息
                setTimeout(() => {
                    loadThirdPartyBindings();
                }, 1000);
                
            } catch (error) {
                console.error('解绑微博失败:', error);
                showError('网络错误，请稍后重试');
            }
        }
    );
}

/**
 * 绑定 GitHub
 */
async function bindGithub() {
    try {
        const response = await fetch('api/BindGithub.php', {
            method: 'POST',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '绑定失败');
            return;
        }
        
        // 跳转到 GitHub 授权页面
        if (result.data && result.data.auth_url) {
            window.location.href = result.data.auth_url;
            return;
        }
        
        // 绑定成功
        showSuccessToast('GitHub 账号绑定成功', '绑定成功');
        
        // 重新加载绑定信息
        setTimeout(() => {
            loadThirdPartyBindings();
        }, 1000);
        
    } catch (error) {
        console.error('绑定 GitHub 失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解绑 GitHub
 */
function unbindGithub() {
    // 确认对话框
    showConfirm(
        '解绑后将无法使用 GitHub 账号快捷登录，确定要解绑吗？',
        '确认解绑',
        async function() {
            try {
                const response = await fetch('api/UnbindGithub.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    showError(result.message || '解绑失败');
                    return;
                }
                
                showSuccessToast('GitHub 账号解绑成功', '解绑成功');
                
                // 重新加载绑定信息
                setTimeout(() => {
                    loadThirdPartyBindings();
                }, 1000);
                
            } catch (error) {
                console.error('解绑 GitHub 失败:', error);
                showError('网络错误，请稍后重试');
            }
        }
    );
}

/**
 * 初始化账户安全页面
 */
function initSecurityPage() {
    // 绑定QQ按钮
    const bindQQBtn = document.getElementById('btn-bind-qq');
    if (bindQQBtn) {
        bindQQBtn.addEventListener('click', bindQQ);
    }
    
    // 解绑QQ按钮
    const unbindQQBtn = document.getElementById('btn-unbind-qq');
    if (unbindQQBtn) {
        unbindQQBtn.addEventListener('click', unbindQQ);
    }
    
    // 绑定微信按钮
    const bindWechatBtn = document.getElementById('btn-bind-wechat');
    if (bindWechatBtn) {
        bindWechatBtn.addEventListener('click', bindWechat);
    }
    
    // 解绑微信按钮
    const unbindWechatBtn = document.getElementById('btn-unbind-wechat');
    if (unbindWechatBtn) {
        unbindWechatBtn.addEventListener('click', unbindWechat);
    }
    
    // 绑定微博按钮
    const bindWeiboBtn = document.getElementById('btn-bind-weibo');
    if (bindWeiboBtn) {
        bindWeiboBtn.addEventListener('click', bindWeibo);
    }
    
    // 解绑微博按钮
    const unbindWeiboBtn = document.getElementById('btn-unbind-weibo');
    if (unbindWeiboBtn) {
        unbindWeiboBtn.addEventListener('click', unbindWeibo);
    }
    
    // 绑定 GitHub 按钮
    const bindGithubBtn = document.getElementById('btn-bind-github');
    if (bindGithubBtn) {
        bindGithubBtn.addEventListener('click', bindGithub);
    }
    
    // 解绑 GitHub 按钮
    const unbindGithubBtn = document.getElementById('btn-unbind-github');
    if (unbindGithubBtn) {
        unbindGithubBtn.addEventListener('click', unbindGithub);
    }
    
    // 加载第三方绑定信息
    loadThirdPartyBindings();
    
    // 更新联系方式信息
    if (userInfo) {
        const securityPhone = document.getElementById('security-phone');
        const securityEmail = document.getElementById('security-email');
        
        if (securityPhone) {
            securityPhone.textContent = userInfo.phone || '未绑定';
        }
        
        if (securityEmail) {
            securityEmail.textContent = userInfo.email || '未绑定';
        }
    }
}

// 在init函数中添加账户安全页面初始化
// 修改原有的init函数，在导航切换时加载账户安全页面
const originalInitNavigation = initNavigation;
initNavigation = function() {
    if (originalInitNavigation) {
        originalInitNavigation();
    }
    
    // 监听导航切换
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const page = this.getAttribute('data-page');
            
            // 如果切换到账户安全页面，初始化该页面
            if (page === 'security') {
                setTimeout(() => {
                    initSecurityPage();
                }, 100);
            }
        });
    });
    
    // 如果当前URL包含#security，立即初始化账户安全页面
    if (window.location.hash === '#security') {
        setTimeout(() => {
            initSecurityPage();
        }, 500);
    }
};
