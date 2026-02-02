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
                // 刷新页面以显示最新信息
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
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
                // 刷新页面以显示最新信息
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
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
        
        // 更新 Google 绑定状态
        updateGoogleBindingStatus(result.data.google);
        
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
 * 更新 Google 绑定状态
 */
function updateGoogleBindingStatus(googleInfo) {
    const statusText = document.getElementById('google-status-text');
    const detailEl = document.getElementById('google-detail');
    const bindBtn = document.getElementById('btn-bind-google');
    const unbindBtn = document.getElementById('btn-unbind-google');
    
    if (googleInfo && googleInfo.bound) {
        // 已绑定
        if (statusText) {
            statusText.textContent = '已绑定';
            statusText.style.color = '#4caf50';
        }
        
        // 显示详细信息
        if (detailEl) {
            detailEl.style.display = 'block';
            
            const avatar = document.getElementById('google-avatar');
            const nickname = document.getElementById('google-nickname');
            const bindTime = document.getElementById('google-bind-time');
            
            if (avatar) {
                // 设置头像，如果没有则使用默认头像
                avatar.src = googleInfo.avatar || 'https://avatar.ywxmz.com/user-6380868_1920.png';
                // 添加错误处理，加载失败时使用默认头像
                avatar.onerror = function() {
                    this.src = 'https://avatar.ywxmz.com/user-6380868_1920.png';
                };
            }
            if (nickname) nickname.textContent = googleInfo.nickname || googleInfo.name || '-';
            if (bindTime) bindTime.textContent = formatDateTime(googleInfo.bind_time);
        }
        
        // 显示解绑按钮，隐藏绑定按钮
        if (bindBtn) bindBtn.style.display = 'none';
        if (unbindBtn) unbindBtn.style.display = 'inline-flex';
    } else {
        // 未绑定
        if (statusText) {
            statusText.textContent = '未绑定';
            statusText.style.color = '#999';
        }
        
        // 隐藏详细信息
        if (detailEl) {
            detailEl.style.display = 'none';
        }
        
        // 显示绑定按钮，隐藏解绑按钮
        if (bindBtn) bindBtn.style.display = 'inline-flex';
        if (unbindBtn) unbindBtn.style.display = 'none';
    }
}

/**
 * 绑定 Google
 */
async function bindGoogle() {
    try {
        const response = await fetch('api/BindGoogle.php', {
            method: 'POST',
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message || '绑定失败');
            return;
        }
        
        // 跳转到 Google 授权页面
        if (result.data && result.data.auth_url) {
            window.location.href = result.data.auth_url;
            return;
        }
        
        // 绑定成功
        showSuccessToast('Google 账号绑定成功', '绑定成功');
        
        // 重新加载绑定信息
        setTimeout(() => {
            loadThirdPartyBindings();
        }, 1000);
        
    } catch (error) {
        console.error('绑定 Google 失败:', error);
        showError('网络错误，请稍后重试');
    }
}

/**
 * 解绑 Google
 */
function unbindGoogle() {
    // 确认对话框
    showConfirm(
        '解绑后将无法使用 Google 账号快捷登录，确定要解绑吗？',
        '确认解绑',
        async function() {
            try {
                const response = await fetch('api/UnbindGoogle.php', {
                    method: 'POST',
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    showError(result.message || '解绑失败');
                    return;
                }
                
                showSuccessToast('Google 账号解绑成功', '解绑成功');
                
                // 重新加载绑定信息
                setTimeout(() => {
                    loadThirdPartyBindings();
                }, 1000);
                
            } catch (error) {
                console.error('解绑 Google 失败:', error);
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
    
    // 绑定 Google 按钮
    const bindGoogleBtn = document.getElementById('btn-bind-google');
    if (bindGoogleBtn) {
        bindGoogleBtn.addEventListener('click', bindGoogle);
    }
    
    // 解绑 Google 按钮
    const unbindGoogleBtn = document.getElementById('btn-unbind-google');
    if (unbindGoogleBtn) {
        unbindGoogleBtn.addEventListener('click', unbindGoogle);
    }
    
    // 加载第三方绑定信息
    loadThirdPartyBindings();
    
    // 更新联系方式信息
    if (userInfo) {
        const securityPhone = document.getElementById('security-phone');
        const securityEmail = document.getElementById('security-email');
        const btnBindPhone = document.getElementById('btn-bind-phone');
        const btnChangePhone = document.getElementById('btn-change-phone');
        const btnBindEmail = document.getElementById('btn-bind-email');
        const btnChangeEmail = document.getElementById('btn-change-email');
        
        if (securityPhone) {
            securityPhone.textContent = userInfo.phone || '未绑定';
        }
        
        if (securityEmail) {
            securityEmail.textContent = userInfo.email || '未绑定';
        }
        
        // 根据是否绑定手机号显示对应的按钮
        if (userInfo.phone && userInfo.phone.trim() !== '') {
            if (btnChangePhone) btnChangePhone.style.display = 'inline-flex';
            if (btnBindPhone) btnBindPhone.style.display = 'none';
        } else {
            if (btnChangePhone) btnChangePhone.style.display = 'none';
            if (btnBindPhone) btnBindPhone.style.display = 'inline-flex';
        }
        
        // 根据是否绑定邮箱显示对应的按钮
        if (userInfo.email && userInfo.email.trim() !== '') {
            if (btnChangeEmail) btnChangeEmail.style.display = 'inline-flex';
            if (btnBindEmail) btnBindEmail.style.display = 'none';
        } else {
            if (btnChangeEmail) btnChangeEmail.style.display = 'none';
            if (btnBindEmail) btnBindEmail.style.display = 'inline-flex';
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


/**
 * ========================================
 * 修改密码功能
 * ========================================
 */

// 修改密码相关变量
let changePasswordModal = null;
let currentPasswordStep = 1;
let selectedVerifyMethod = '';
let verifyTarget = '';
let passwordResetToken = '';
let sendCodeCountdown = 0;
let sendCodeTimer = null;

/**
 * 初始化修改密码功能
 */
function initChangePassword() {
    changePasswordModal = document.getElementById('change-password-modal');
    const btnChangePassword = document.getElementById('btn-change-password');
    const btnCloseModal = document.getElementById('close-change-password-modal');

    // 打开修改密码弹窗
    if (btnChangePassword) {
        btnChangePassword.addEventListener('click', openChangePasswordModal);
    }

    // 关闭弹窗
    if (btnCloseModal) {
        btnCloseModal.addEventListener('click', closeChangePasswordModal);
    }

    // 点击遮罩层关闭
    if (changePasswordModal) {
        changePasswordModal.addEventListener('click', function(e) {
            if (e.target === changePasswordModal) {
                closeChangePasswordModal();
            }
        });
    }

    // 第一步：选择验证方式
    const btnSelectMethods = document.querySelectorAll('.btn-select-method');
    btnSelectMethods.forEach(btn => {
        btn.addEventListener('click', function() {
            const method = this.getAttribute('data-method');
            selectVerifyMethod(method);
        });
    });

    // 第二步：发送验证码
    const btnSendCode = document.getElementById('btn-send-verify-code');
    if (btnSendCode) {
        btnSendCode.addEventListener('click', sendVerifyCode);
    }

    // 第二步：验证验证码
    const btnVerifyCode = document.getElementById('btn-verify-code');
    if (btnVerifyCode) {
        btnVerifyCode.addEventListener('click', verifyCode);
    }

    // 第二步：返回上一步
    const btnBackStep1 = document.getElementById('btn-back-step-1');
    if (btnBackStep1) {
        btnBackStep1.addEventListener('click', () => goToStep(1));
    }

    // 第三步：返回上一步
    const btnBackStep2 = document.getElementById('btn-back-step-2');
    if (btnBackStep2) {
        btnBackStep2.addEventListener('click', () => goToStep(2));
    }

    // 第三步：提交修改
    const btnSubmitPassword = document.getElementById('btn-submit-password');
    if (btnSubmitPassword) {
        btnSubmitPassword.addEventListener('click', submitPasswordChange);
    }

    // 密码强度检测
    const newPasswordInput = document.getElementById('new-password');
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', checkPasswordStrength);
    }

    // 密码显示/隐藏切换
    const btnTogglePasswords = document.querySelectorAll('.btn-toggle-password');
    btnTogglePasswords.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
}

/**
 * 打开修改密码弹窗
 */
async function openChangePasswordModal() {
    if (!userInfo) {
        showError('请先登录');
        return;
    }

    // 重置状态
    currentPasswordStep = 1;
    selectedVerifyMethod = '';
    verifyTarget = '';
    passwordResetToken = '';

    // 检查用户绑定的联系方式
    const hasPhone = userInfo.phone && userInfo.phone.trim() !== '';
    const hasEmail = userInfo.email && userInfo.email.trim() !== '';

    if (!hasPhone && !hasEmail) {
        showError('您还未绑定手机号或邮箱，无法修改密码');
        return;
    }

    // 显示可用的验证方式
    const phoneMethod = document.getElementById('verify-method-phone');
    const emailMethod = document.getElementById('verify-method-email');
    const phoneDisplay = document.getElementById('verify-phone-display');
    const emailDisplay = document.getElementById('verify-email-display');

    if (hasPhone) {
        phoneMethod.style.display = 'flex';
        phoneDisplay.textContent = maskPhone(userInfo.phone);
    } else {
        phoneMethod.style.display = 'none';
    }

    if (hasEmail) {
        emailMethod.style.display = 'flex';
        emailDisplay.textContent = maskEmail(userInfo.email);
    } else {
        emailMethod.style.display = 'none';
    }

    // 显示弹窗
    changePasswordModal.classList.add('show');
    goToStep(1);
}

/**
 * 关闭修改密码弹窗
 */
function closeChangePasswordModal() {
    changePasswordModal.classList.remove('show');
    
    // 清理倒计时
    if (sendCodeTimer) {
        clearInterval(sendCodeTimer);
        sendCodeTimer = null;
    }
    
    // 重置表单
    document.getElementById('verify-code').value = '';
    document.getElementById('new-password').value = '';
    document.getElementById('confirm-password').value = '';
    
    // 重置密码强度指示器
    const strengthFill = document.getElementById('strength-fill');
    const strengthText = document.getElementById('strength-text');
    strengthFill.className = 'strength-fill';
    strengthText.className = 'strength-text';
    strengthText.textContent = '请输入密码';
}

/**
 * 切换到指定步骤
 */
function goToStep(step) {
    currentPasswordStep = step;

    // 更新步骤指示器
    const steps = document.querySelectorAll('.step');
    steps.forEach((s, index) => {
        const stepNum = index + 1;
        if (stepNum < step) {
            s.classList.add('completed');
            s.classList.remove('active');
        } else if (stepNum === step) {
            s.classList.add('active');
            s.classList.remove('completed');
        } else {
            s.classList.remove('active', 'completed');
        }
    });

    // 显示对应的步骤内容
    document.getElementById('password-step-1').style.display = step === 1 ? 'block' : 'none';
    document.getElementById('password-step-2').style.display = step === 2 ? 'block' : 'none';
    document.getElementById('password-step-3').style.display = step === 3 ? 'block' : 'none';
}

/**
 * 选择验证方式
 */
function selectVerifyMethod(method) {
    selectedVerifyMethod = method;

    if (method === 'phone') {
        verifyTarget = userInfo.phone;
        document.getElementById('verify-icon-phone').style.display = 'inline';
        document.getElementById('verify-icon-email').style.display = 'none';
        document.getElementById('verify-target-label').textContent = '手机号';
        document.getElementById('verify-target').value = maskPhone(userInfo.phone);
    } else {
        verifyTarget = userInfo.email;
        document.getElementById('verify-icon-phone').style.display = 'none';
        document.getElementById('verify-icon-email').style.display = 'inline';
        document.getElementById('verify-target-label').textContent = '邮箱';
        document.getElementById('verify-target').value = maskEmail(userInfo.email);
    }

    // 进入第二步
    goToStep(2);
}

/**
 * 发送验证码
 */
async function sendVerifyCode() {
    const btnSendCode = document.getElementById('btn-send-verify-code');
    
    if (sendCodeCountdown > 0) {
        return;
    }

    try {
        btnSendCode.disabled = true;
        btnSendCode.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 发送中...';

        const response = await fetch('/user/api/SendPasswordResetCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                method: selectedVerifyMethod
            })
        });

        const result = await response.json();

        if (!result.success) {
            showError(result.message || '发送验证码失败');
            btnSendCode.disabled = false;
            btnSendCode.innerHTML = '<i class="fas fa-paper-plane"></i> <span>发送验证码</span>';
            return;
        }

        showSuccessToast(result.message || '验证码已发送');

        // 开始倒计时
        sendCodeCountdown = 60;
        btnSendCode.classList.add('counting');
        updateSendCodeButton();

        sendCodeTimer = setInterval(() => {
            sendCodeCountdown--;
            if (sendCodeCountdown <= 0) {
                clearInterval(sendCodeTimer);
                sendCodeTimer = null;
                btnSendCode.disabled = false;
                btnSendCode.classList.remove('counting');
                btnSendCode.innerHTML = '<i class="fas fa-paper-plane"></i> <span>重新发送</span>';
            } else {
                updateSendCodeButton();
            }
        }, 1000);

    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('网络错误，请稍后重试');
        btnSendCode.disabled = false;
        btnSendCode.innerHTML = '<i class="fas fa-paper-plane"></i> <span>发送验证码</span>';
    }
}

/**
 * 更新发送验证码按钮文本
 */
function updateSendCodeButton() {
    const btnSendCode = document.getElementById('btn-send-verify-code');
    btnSendCode.innerHTML = `<i class="fas fa-clock"></i> <span>${sendCodeCountdown}秒后重试</span>`;
}

/**
 * 验证验证码
 */
async function verifyCode() {
    const code = document.getElementById('verify-code').value.trim();

    if (!code) {
        showError('请输入验证码');
        return;
    }

    if (code.length !== 6) {
        showError('验证码格式错误');
        return;
    }

    const btnVerify = document.getElementById('btn-verify-code');

    try {
        btnVerify.disabled = true;
        btnVerify.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';

        const response = await fetch('/user/api/VerifyPasswordResetCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                method: selectedVerifyMethod,
                code: code
            })
        });

        const result = await response.json();

        if (!result.success) {
            showError(result.message || '验证失败');
            btnVerify.disabled = false;
            btnVerify.innerHTML = '下一步 <i class="fas fa-arrow-right"></i>';
            return;
        }

        // 保存令牌
        passwordResetToken = result.data.token;

        showSuccessToast('验证成功');

        // 进入第三步
        goToStep(3);

        btnVerify.disabled = false;
        btnVerify.innerHTML = '下一步 <i class="fas fa-arrow-right"></i>';

    } catch (error) {
        console.error('验证验证码失败:', error);
        showError('网络错误，请稍后重试');
        btnVerify.disabled = false;
        btnVerify.innerHTML = '下一步 <i class="fas fa-arrow-right"></i>';
    }
}

/**
 * 检查密码强度
 */
function checkPasswordStrength() {
    const password = document.getElementById('new-password').value;
    const strengthFill = document.getElementById('strength-fill');
    const strengthText = document.getElementById('strength-text');

    if (!password) {
        strengthFill.className = 'strength-fill';
        strengthText.className = 'strength-text';
        strengthText.textContent = '请输入密码';
        return;
    }

    let strength = 0;
    
    // 长度检查
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    
    // 包含数字
    if (/\d/.test(password)) strength++;
    
    // 包含小写字母
    if (/[a-z]/.test(password)) strength++;
    
    // 包含大写字母
    if (/[A-Z]/.test(password)) strength++;
    
    // 包含特殊字符
    if (/[^a-zA-Z0-9]/.test(password)) strength++;

    if (strength <= 2) {
        strengthFill.className = 'strength-fill weak';
        strengthText.className = 'strength-text weak';
        strengthText.textContent = '弱';
    } else if (strength <= 4) {
        strengthFill.className = 'strength-fill medium';
        strengthText.className = 'strength-text medium';
        strengthText.textContent = '中';
    } else {
        strengthFill.className = 'strength-fill strong';
        strengthText.className = 'strength-text strong';
        strengthText.textContent = '强';
    }
}

/**
 * 提交密码修改
 */
async function submitPasswordChange() {
    const newPassword = document.getElementById('new-password').value.trim();
    const confirmPassword = document.getElementById('confirm-password').value.trim();

    // 验证输入
    if (!newPassword) {
        showError('请输入新密码');
        return;
    }

    if (newPassword.length < 8 || newPassword.length > 20) {
        showError('密码长度必须在8-20位之间');
        return;
    }

    if (!confirmPassword) {
        showError('请确认新密码');
        return;
    }

    if (newPassword !== confirmPassword) {
        showError('两次输入的密码不一致');
        return;
    }

    if (!passwordResetToken) {
        showError('令牌无效，请重新验证');
        return;
    }

    const btnSubmit = document.getElementById('btn-submit-password');

    try {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';

        const response = await fetch('/user/api/ResetPassword.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                token: passwordResetToken,
                new_password: newPassword
            })
        });

        const result = await response.json();

        if (!result.success) {
            showError(result.message || '修改密码失败');
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="fas fa-check"></i> 提交修改';
            return;
        }

        showSuccessToast('密码修改成功，请重新登录');

        // 关闭弹窗
        closeChangePasswordModal();

        // 延迟跳转到登录页
        setTimeout(() => {
            window.location.href = '/user/login.php';
        }, 2000);

    } catch (error) {
        console.error('修改密码失败:', error);
        showError('网络错误，请稍后重试');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="fas fa-check"></i> 提交修改';
    }
}

/**
 * 掩码手机号
 */
function maskPhone(phone) {
    if (!phone || phone.length < 11) return phone;
    return phone.substring(0, 3) + '****' + phone.substring(7);
}

/**
 * 掩码邮箱
 */
function maskEmail(email) {
    if (!email) return email;
    const atIndex = email.indexOf('@');
    if (atIndex <= 0) return email;
    const username = email.substring(0, atIndex);
    const domain = email.substring(atIndex);
    if (username.length <= 3) {
        return username.substring(0, 1) + '***' + domain;
    }
    return username.substring(0, 3) + '***' + domain;
}

// 在页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    initChangePassword();
    initChangePhone();
    initChangeEmailModal();
});


// ==================== 修改手机号功能 ====================

// 修改手机号相关变量
let changePhoneModal = null;
let currentPhoneStep = 1;
let selectedPhoneVerifyMethod = '';
let phoneVerifyTarget = '';
let sendPhoneCodeTimer = null;
let sendNewPhoneCodeTimer = null;

/**
 * 初始化修改手机号功能
 */
function initChangePhone() {
    changePhoneModal = document.getElementById('change-phone-modal');
    const btnChangePhone = document.getElementById('btn-change-phone');
    const btnCloseModal = document.getElementById('close-change-phone-modal');

    // 打开修改手机号弹窗
    if (btnChangePhone) {
        btnChangePhone.addEventListener('click', openChangePhoneModal);
    }

    // 关闭弹窗
    if (btnCloseModal) {
        btnCloseModal.addEventListener('click', closeChangePhoneModal);
    }

    // 点击遮罩层关闭
    if (changePhoneModal) {
        changePhoneModal.addEventListener('click', function(e) {
            if (e.target === changePhoneModal) {
                closeChangePhoneModal();
            }
        });
    }

    // 第一步：选择验证方式
    const btnSelectMethods = document.querySelectorAll('#change-phone-modal .btn-select-method');
    btnSelectMethods.forEach(btn => {
        btn.addEventListener('click', function() {
            const method = this.getAttribute('data-method');
            selectPhoneVerifyMethod(method);
        });
    });

    // 第二步：发送旧手机号/邮箱验证码
    const btnSendPhoneVerifyCode = document.getElementById('btn-send-phone-verify-code');
    if (btnSendPhoneVerifyCode) {
        btnSendPhoneVerifyCode.addEventListener('click', sendPhoneVerifyCode);
    }

    // 第二步：验证旧手机号/邮箱验证码
    const btnPhoneVerifyCode = document.getElementById('btn-phone-verify-code');
    if (btnPhoneVerifyCode) {
        btnPhoneVerifyCode.addEventListener('click', verifyPhoneCode);
    }

    // 第二步：返回上一步
    const btnPhoneBackStep1 = document.getElementById('btn-phone-back-step-1');
    if (btnPhoneBackStep1) {
        btnPhoneBackStep1.addEventListener('click', () => goToPhoneStep(1));
    }

    // 第三步：发送新手机号验证码
    const btnSendNewPhoneCode = document.getElementById('btn-send-new-phone-code');
    if (btnSendNewPhoneCode) {
        btnSendNewPhoneCode.addEventListener('click', sendNewPhoneCode);
    }

    // 第三步：返回上一步
    const btnPhoneBackStep2 = document.getElementById('btn-phone-back-step-2');
    if (btnPhoneBackStep2) {
        btnPhoneBackStep2.addEventListener('click', () => goToPhoneStep(2));
    }

    // 第三步：提交修改
    const btnPhoneSubmit = document.getElementById('btn-phone-submit');
    if (btnPhoneSubmit) {
        btnPhoneSubmit.addEventListener('click', submitPhoneChange);
    }

    // 新手机号输入验证
    const newPhoneInput = document.getElementById('new-phone');
    if (newPhoneInput) {
        newPhoneInput.addEventListener('input', function() {
            // 只允许输入数字
            this.value = this.value.replace(/\D/g, '');
        });
    }
}

/**
 * 打开修改手机号弹窗
 */
async function openChangePhoneModal() {
    if (!userInfo) {
        showError('请先登录');
        return;
    }

    // 检查是否已绑定手机号
    if (!userInfo.phone || userInfo.phone.trim() === '') {
        showError('您还未绑定手机号');
        return;
    }

    // 重置状态
    currentPhoneStep = 1;
    selectedPhoneVerifyMethod = '';
    phoneVerifyTarget = '';

    // 检查用户绑定的联系方式
    const hasPhone = userInfo.phone && userInfo.phone.trim() !== '';
    const hasEmail = userInfo.email && userInfo.email.trim() !== '';

    if (!hasPhone && !hasEmail) {
        showError('您还未绑定手机号或邮箱，无法修改手机号');
        return;
    }

    // 显示可用的验证方式
    const phoneMethod = document.getElementById('phone-verify-method-phone');
    const emailMethod = document.getElementById('phone-verify-method-email');
    const phoneDisplay = document.getElementById('phone-verify-phone-display');
    const emailDisplay = document.getElementById('phone-verify-email-display');

    if (hasPhone) {
        phoneMethod.style.display = 'flex';
        phoneDisplay.textContent = maskPhone(userInfo.phone);
    } else {
        phoneMethod.style.display = 'none';
    }

    if (hasEmail) {
        emailMethod.style.display = 'flex';
        emailDisplay.textContent = maskEmail(userInfo.email);
    } else {
        emailMethod.style.display = 'none';
    }

    // 显示弹窗
    changePhoneModal.classList.add('show');
    goToPhoneStep(1);
}

/**
 * 关闭修改手机号弹窗
 */
function closeChangePhoneModal() {
    changePhoneModal.classList.remove('show');
    
    // 清理倒计时
    if (sendPhoneCodeTimer) {
        clearInterval(sendPhoneCodeTimer);
        sendPhoneCodeTimer = null;
    }
    if (sendNewPhoneCodeTimer) {
        clearInterval(sendNewPhoneCodeTimer);
        sendNewPhoneCodeTimer = null;
    }
    
    // 重置表单
    document.getElementById('phone-verify-code').value = '';
    document.getElementById('new-phone').value = '';
    document.getElementById('new-phone-code').value = '';
}

/**
 * 切换到指定步骤
 */
function goToPhoneStep(step) {
    currentPhoneStep = step;

    // 更新步骤指示器
    const steps = document.querySelectorAll('#change-phone-modal .step');
    steps.forEach((s, index) => {
        const stepNum = index + 1;
        if (stepNum < step) {
            s.classList.add('completed');
            s.classList.remove('active');
        } else if (stepNum === step) {
            s.classList.add('active');
            s.classList.remove('completed');
        } else {
            s.classList.remove('active', 'completed');
        }
    });

    // 显示/隐藏对应的步骤内容
    document.querySelectorAll('.phone-step').forEach((stepDiv, index) => {
        if (index + 1 === step) {
            stepDiv.style.display = 'block';
        } else {
            stepDiv.style.display = 'none';
        }
    });
}

/**
 * 选择验证方式
 */
function selectPhoneVerifyMethod(method) {
    selectedPhoneVerifyMethod = method;
    
    if (method === 'phone') {
        phoneVerifyTarget = userInfo.phone;
    } else {
        phoneVerifyTarget = userInfo.email;
    }
    
    // 更新第二步的显示
    const targetInput = document.getElementById('phone-verify-target');
    const targetLabel = document.getElementById('phone-verify-target-label');
    const iconPhone = document.getElementById('phone-verify-icon-phone');
    const iconEmail = document.getElementById('phone-verify-icon-email');
    
    if (method === 'phone') {
        targetInput.value = maskPhone(phoneVerifyTarget);
        targetLabel.textContent = '手机号';
        iconPhone.style.display = 'inline-block';
        iconEmail.style.display = 'none';
    } else {
        targetInput.value = maskEmail(phoneVerifyTarget);
        targetLabel.textContent = '邮箱地址';
        iconPhone.style.display = 'none';
        iconEmail.style.display = 'inline-block';
    }
    
    // 切换到第二步
    goToPhoneStep(2);
}

/**
 * 发送旧手机号/邮箱验证码
 */
async function sendPhoneVerifyCode() {
    const btn = document.getElementById('btn-send-phone-verify-code');
    
    if (btn.disabled) {
        return;
    }
    
    if (!selectedPhoneVerifyMethod) {
        showError('请先选择验证方式');
        return;
    }
    
    // 禁用按钮
    btn.disabled = true;
    const originalText = btn.querySelector('span').textContent;
    btn.querySelector('span').textContent = '发送中...';
    
    try {
        const response = await fetch('/user/api/SendChangePhoneVerifyCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                method: selectedPhoneVerifyMethod
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast(result.message);
            
            // 开始倒计时
            let countdown = 60;
            btn.querySelector('span').textContent = `${countdown}秒后重试`;
            
            sendPhoneCodeTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    btn.querySelector('span').textContent = `${countdown}秒后重试`;
                } else {
                    clearInterval(sendPhoneCodeTimer);
                    sendPhoneCodeTimer = null;
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            }, 1000);
        } else {
            showError(result.message || '发送失败');
            btn.disabled = false;
            btn.querySelector('span').textContent = originalText;
        }
    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('发送失败，请稍后重试');
        btn.disabled = false;
        btn.querySelector('span').textContent = originalText;
    }
}

/**
 * 验证旧手机号/邮箱验证码
 */
async function verifyPhoneCode() {
    const code = document.getElementById('phone-verify-code').value.trim();
    
    if (!code) {
        showError('请输入验证码');
        return;
    }
    
    if (code.length !== 6) {
        showError('验证码格式不正确');
        return;
    }
    
    const btn = document.getElementById('btn-phone-verify-code');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';
    
    try {
        const response = await fetch('/user/api/VerifyChangePhoneCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                method: selectedPhoneVerifyMethod,
                code: code
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast('验证成功');
            // 切换到第三步
            goToPhoneStep(3);
        } else {
            showError(result.message || '验证失败');
        }
    } catch (error) {
        console.error('验证失败:', error);
        showError('验证失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

/**
 * 发送新手机号验证码
 */
async function sendNewPhoneCode() {
    const newPhone = document.getElementById('new-phone').value.trim();
    const btn = document.getElementById('btn-send-new-phone-code');
    
    if (btn.disabled) {
        return;
    }
    
    if (!newPhone) {
        showError('请输入新手机号');
        return;
    }
    
    if (!/^1[3-9]\d{9}$/.test(newPhone)) {
        showError('手机号格式不正确');
        return;
    }
    
    // 检查是否与当前手机号相同
    if (newPhone === userInfo.phone) {
        showError('新手机号不能与当前手机号相同');
        return;
    }
    
    // 禁用按钮
    btn.disabled = true;
    const originalText = btn.querySelector('span').textContent;
    btn.querySelector('span').textContent = '发送中...';
    
    try {
        const response = await fetch('/user/api/SendNewPhoneCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                new_phone: newPhone
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast(result.message);
            
            // 开始倒计时
            let countdown = 60;
            btn.querySelector('span').textContent = `${countdown}秒后重试`;
            
            sendNewPhoneCodeTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    btn.querySelector('span').textContent = `${countdown}秒后重试`;
                } else {
                    clearInterval(sendNewPhoneCodeTimer);
                    sendNewPhoneCodeTimer = null;
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            }, 1000);
        } else {
            showError(result.message || '发送失败');
            btn.disabled = false;
            btn.querySelector('span').textContent = originalText;
        }
    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('发送失败，请稍后重试');
        btn.disabled = false;
        btn.querySelector('span').textContent = originalText;
    }
}

/**
 * 提交修改手机号
 */
async function submitPhoneChange() {
    const newPhone = document.getElementById('new-phone').value.trim();
    const oldVerifyCode = document.getElementById('phone-verify-code').value.trim();
    const newPhoneCode = document.getElementById('new-phone-code').value.trim();
    
    // 验证输入
    if (!newPhone) {
        showError('请输入新手机号');
        return;
    }
    
    if (!/^1[3-9]\d{9}$/.test(newPhone)) {
        showError('手机号格式不正确');
        return;
    }
    
    if (!oldVerifyCode) {
        showError('请输入旧手机号/邮箱验证码');
        return;
    }
    
    if (!newPhoneCode) {
        showError('请输入新手机号验证码');
        return;
    }
    
    if (newPhoneCode.length !== 6) {
        showError('验证码格式不正确');
        return;
    }
    
    const btn = document.getElementById('btn-phone-submit');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';
    
    try {
        const response = await fetch('/user/api/ChangePhone.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                new_phone: newPhone,
                old_verify_code: oldVerifyCode,
                new_phone_code: newPhoneCode
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast('手机号修改成功');
            
            // 关闭弹窗
            closeChangePhoneModal();
            
            // 刷新页面以显示最新信息
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showError(result.message || '修改失败');
        }
    } catch (error) {
        console.error('修改失败:', error);
        showError('修改失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}


// ==================== 修改邮箱功能 ====================

// 修改邮箱相关变量
let changeEmailModal = null;
let currentEmailStep = 1;
let selectedEmailVerifyMethod = null;
let emailVerifyTarget = null;
let sendEmailCodeTimer = null;
let sendNewEmailCodeTimer = null;

/**
 * 初始化修改邮箱弹窗
 */
function initChangeEmailModal() {
    changeEmailModal = document.getElementById('change-email-modal');
    
    if (!changeEmailModal) {
        console.error('修改邮箱弹窗元素不存在');
        return;
    }
    
    // 修改邮箱按钮
    const btnChangeEmail = document.getElementById('btn-change-email');
    if (btnChangeEmail) {
        btnChangeEmail.addEventListener('click', openChangeEmailModal);
    }
    
    // 关闭按钮
    document.getElementById('close-change-email-modal').addEventListener('click', closeChangeEmailModal);
    
    // 选择验证方式按钮
    document.querySelectorAll('#change-email-modal .btn-select-method').forEach(btn => {
        btn.addEventListener('click', function() {
            const method = this.getAttribute('data-method');
            selectEmailVerifyMethod(method);
        });
    });
    
    // 发送旧手机号/邮箱验证码
    document.getElementById('btn-send-email-verify-code').addEventListener('click', sendEmailVerifyCode);
    
    // 验证旧手机号/邮箱验证码
    document.getElementById('btn-email-verify-code').addEventListener('click', verifyEmailCode);
    
    // 发送新邮箱验证码
    document.getElementById('btn-send-new-email-code').addEventListener('click', sendNewEmailCode);
    
    // 提交修改
    document.getElementById('btn-email-submit').addEventListener('click', submitEmailChange);
    
    // 返回按钮
    document.getElementById('btn-email-back-step-1').addEventListener('click', () => goToEmailStep(1));
    document.getElementById('btn-email-back-step-2').addEventListener('click', () => goToEmailStep(2));
    
    // 点击遮罩层关闭
    changeEmailModal.addEventListener('click', function(e) {
        if (e.target === changeEmailModal) {
            closeChangeEmailModal();
        }
    });
}

/**
 * 打开修改邮箱弹窗
 */
function openChangeEmailModal() {
    if (!userInfo) {
        showError('请先登录');
        return;
    }
    
    // 重置状态
    currentEmailStep = 1;
    selectedEmailVerifyMethod = null;
    emailVerifyTarget = null;
    
    // 清空输入框
    document.getElementById('email-verify-code').value = '';
    document.getElementById('new-email').value = '';
    document.getElementById('new-email-code').value = '';
    
    // 显示可用的验证方式
    const phoneMethod = document.getElementById('email-verify-method-phone');
    const emailMethod = document.getElementById('email-verify-method-email');
    
    if (userInfo.phone) {
        phoneMethod.style.display = 'flex';
        document.getElementById('email-verify-phone-display').textContent = maskPhone(userInfo.phone);
    } else {
        phoneMethod.style.display = 'none';
    }
    
    if (userInfo.email) {
        emailMethod.style.display = 'flex';
        document.getElementById('email-verify-email-display').textContent = maskEmail(userInfo.email);
    } else {
        emailMethod.style.display = 'none';
    }
    
    // 检查是否有可用的验证方式
    if (!userInfo.phone && !userInfo.email) {
        showError('您还没有绑定手机号或邮箱，无法修改邮箱');
        return;
    }
    
    // 切换到第一步
    goToEmailStep(1);
    
    // 显示弹窗
    changeEmailModal.classList.add('show');
}

/**
 * 关闭修改邮箱弹窗
 */
function closeChangeEmailModal() {
    changeEmailModal.classList.remove('show');
    
    // 清理倒计时
    if (sendEmailCodeTimer) {
        clearInterval(sendEmailCodeTimer);
        sendEmailCodeTimer = null;
    }
    if (sendNewEmailCodeTimer) {
        clearInterval(sendNewEmailCodeTimer);
        sendNewEmailCodeTimer = null;
    }
    
    // 重置表单
    document.getElementById('email-verify-code').value = '';
    document.getElementById('new-email').value = '';
    document.getElementById('new-email-code').value = '';
}

/**
 * 切换到指定步骤
 */
function goToEmailStep(step) {
    currentEmailStep = step;

    // 更新步骤指示器
    const steps = document.querySelectorAll('#change-email-modal .step');
    steps.forEach((s, index) => {
        const stepNum = index + 1;
        if (stepNum < step) {
            s.classList.add('completed');
            s.classList.remove('active');
        } else if (stepNum === step) {
            s.classList.add('active');
            s.classList.remove('completed');
        } else {
            s.classList.remove('active', 'completed');
        }
    });

    // 显示/隐藏对应的步骤内容
    document.querySelectorAll('.email-step').forEach((stepDiv, index) => {
        if (index + 1 === step) {
            stepDiv.style.display = 'block';
        } else {
            stepDiv.style.display = 'none';
        }
    });
}

/**
 * 选择验证方式
 */
function selectEmailVerifyMethod(method) {
    selectedEmailVerifyMethod = method;
    
    if (method === 'phone') {
        emailVerifyTarget = userInfo.phone;
    } else {
        emailVerifyTarget = userInfo.email;
    }
    
    // 更新第二步的显示
    const targetInput = document.getElementById('email-verify-target');
    const targetLabel = document.getElementById('email-verify-target-label');
    const iconPhone = document.getElementById('email-verify-icon-phone');
    const iconEmail = document.getElementById('email-verify-icon-email');
    
    if (method === 'phone') {
        targetInput.value = maskPhone(emailVerifyTarget);
        targetLabel.textContent = '手机号';
        iconPhone.style.display = 'inline-block';
        iconEmail.style.display = 'none';
    } else {
        targetInput.value = maskEmail(emailVerifyTarget);
        targetLabel.textContent = '邮箱地址';
        iconPhone.style.display = 'none';
        iconEmail.style.display = 'inline-block';
    }
    
    // 切换到第二步
    goToEmailStep(2);
}

/**
 * 发送旧手机号/邮箱验证码
 */
async function sendEmailVerifyCode() {
    const btn = document.getElementById('btn-send-email-verify-code');
    
    if (btn.disabled) {
        return;
    }
    
    if (!selectedEmailVerifyMethod) {
        showError('请先选择验证方式');
        return;
    }
    
    // 禁用按钮
    btn.disabled = true;
    const originalText = btn.querySelector('span').textContent;
    btn.querySelector('span').textContent = '发送中...';
    
    try {
        const response = await fetch('/user/api/SendChangeEmailVerifyCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                method: selectedEmailVerifyMethod
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast(result.message);
            
            // 开始倒计时
            let countdown = 60;
            btn.querySelector('span').textContent = `${countdown}秒后重试`;
            
            sendEmailCodeTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    btn.querySelector('span').textContent = `${countdown}秒后重试`;
                } else {
                    clearInterval(sendEmailCodeTimer);
                    sendEmailCodeTimer = null;
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            }, 1000);
        } else {
            showError(result.message || '发送失败');
            btn.disabled = false;
            btn.querySelector('span').textContent = originalText;
        }
    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('发送失败，请稍后重试');
        btn.disabled = false;
        btn.querySelector('span').textContent = originalText;
    }
}

/**
 * 验证旧手机号/邮箱验证码
 */
async function verifyEmailCode() {
    const code = document.getElementById('email-verify-code').value.trim();
    
    if (!code) {
        showError('请输入验证码');
        return;
    }
    
    if (code.length !== 6) {
        showError('验证码格式不正确');
        return;
    }
    
    const btn = document.getElementById('btn-email-verify-code');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';
    
    try {
        const response = await fetch('/user/api/VerifyChangeEmailCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                method: selectedEmailVerifyMethod,
                code: code
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast('验证成功');
            // 切换到第三步
            goToEmailStep(3);
        } else {
            showError(result.message || '验证失败');
        }
    } catch (error) {
        console.error('验证失败:', error);
        showError('验证失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

/**
 * 发送新邮箱验证码
 */
async function sendNewEmailCode() {
    const newEmail = document.getElementById('new-email').value.trim();
    const btn = document.getElementById('btn-send-new-email-code');
    
    if (btn.disabled) {
        return;
    }
    
    if (!newEmail) {
        showError('请输入新邮箱地址');
        return;
    }
    
    // 验证邮箱格式
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(newEmail)) {
        showError('邮箱格式不正确');
        return;
    }
    
    // 检查是否与当前邮箱相同
    if (newEmail === userInfo.email) {
        showError('新邮箱不能与当前邮箱相同');
        return;
    }
    
    // 禁用按钮
    btn.disabled = true;
    const originalText = btn.querySelector('span').textContent;
    btn.querySelector('span').textContent = '发送中...';
    
    try {
        const response = await fetch('/user/api/SendNewEmailCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                new_email: newEmail
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast(result.message);
            
            // 开始倒计时
            let countdown = 60;
            btn.querySelector('span').textContent = `${countdown}秒后重试`;
            
            sendNewEmailCodeTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    btn.querySelector('span').textContent = `${countdown}秒后重试`;
                } else {
                    clearInterval(sendNewEmailCodeTimer);
                    sendNewEmailCodeTimer = null;
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            }, 1000);
        } else {
            showError(result.message || '发送失败');
            btn.disabled = false;
            btn.querySelector('span').textContent = originalText;
        }
    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('发送失败，请稍后重试');
        btn.disabled = false;
        btn.querySelector('span').textContent = originalText;
    }
}

/**
 * 提交修改邮箱
 */
async function submitEmailChange() {
    const newEmail = document.getElementById('new-email').value.trim();
    const oldVerifyCode = document.getElementById('email-verify-code').value.trim();
    const newEmailCode = document.getElementById('new-email-code').value.trim();
    
    // 验证输入
    if (!newEmail) {
        showError('请输入新邮箱地址');
        return;
    }
    
    // 验证邮箱格式
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(newEmail)) {
        showError('邮箱格式不正确');
        return;
    }
    
    if (!oldVerifyCode) {
        showError('请输入旧手机号/邮箱验证码');
        return;
    }
    
    if (!newEmailCode) {
        showError('请输入新邮箱验证码');
        return;
    }
    
    if (newEmailCode.length !== 6) {
        showError('验证码格式不正确');
        return;
    }
    
    const btn = document.getElementById('btn-email-submit');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';
    
    try {
        const response = await fetch('/user/api/ChangeEmail.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                new_email: newEmail,
                old_verify_code: oldVerifyCode,
                new_email_code: newEmailCode
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccessToast('邮箱修改成功');
            
            // 关闭弹窗
            closeChangeEmailModal();
            
            // 刷新页面以显示最新信息
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showError(result.message || '修改失败');
        }
    } catch (error) {
        console.error('修改失败:', error);
        showError('修改失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
