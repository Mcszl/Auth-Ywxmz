// 一碗小米周授权登录平台 - 注册页面脚本

// 全局变量
let urlParams = null;
let geetestInstance = null;
let geetestResult = null;
let captchaEnabled = false; // 是否启用人机验证

document.addEventListener('DOMContentLoaded', function() {
    init();
});

function init() {
    // 获取 URL 参数
    urlParams = new URLSearchParams(window.location.search);
    
    // 更新登录链接，保留参数
    updateLoginLink();
    
    // 初始化注册方式切换
    initTabSwitch();
    
    // 初始化密码显示/隐藏
    initPasswordToggle();
    
    // 初始化验证码按钮
    initCodeButton();
    
    // 初始化表单提交
    initFormSubmit();
    
    // 初始化实时验证
    initValidation();
    
    // 初始化弹出框
    initModal();
    
    // 初始化极验
    initGeetest();
    
    // console.log('注册页面已加载');
}

/**
 * 更新登录链接，保留URL参数
 */
function updateLoginLink() {
    const loginLink = document.getElementById('login-link');
    if (loginLink && urlParams.toString()) {
        loginLink.href = `../login?${urlParams.toString()}`;
        // console.log('登录链接已更新:', loginLink.href);
    }
}

// ============================================
// 极验验证
// ============================================

/**
 * 初始化极验
 */
async function initGeetest() {
    try {
        // 获取极验配置
        const response = await fetch('/captcha/GetGeetestConfig.php?scene=send_sms');
        const result = await response.json();
        
        if (!result.success) {
            // console.error('获取极验配置失败:', result.message);
            captchaEnabled = false;
            return;
        }
        
        // 检查是否启用人机验证
        if (!result.data.enabled) {
            // console.log('人机验证未启用');
            captchaEnabled = false;
            return;
        }
        
        // 检查极验 SDK 是否加载
        if (typeof initGeetest4 === 'undefined') {
            // console.error('极验 SDK 未加载，initGeetest4 函数不存在');
            captchaEnabled = false;
            return;
        }
        
        const config = result.data;
        // console.log('极验配置:', config);
        
        // 标记人机验证已启用
        captchaEnabled = true;
        
        // 初始化极验 V4
        initGeetest4({
            captchaId: config.captcha_id,
            product: 'bind',
            language: 'zho'
        }, function(captcha) {
            geetestInstance = captcha;
            
            // 监听验证成功事件
            captcha.onSuccess(function() {
                const result = captcha.getValidate();
                geetestResult = {
                    lot_number: result.lot_number,
                    captcha_output: result.captcha_output,
                    pass_token: result.pass_token,
                    gen_time: result.gen_time
                };
                console.log('极验验证成功，gen_time:', result.gen_time, 'type:', typeof result.gen_time);
                console.log('完整验证结果:', geetestResult);
            });
            
            // 监听验证关闭事件
            captcha.onClose(function() {
                geetestResult = null;
                // console.log('极验验证已关闭');
            });
            
            // 监听验证错误事件
            captcha.onError(function(error) {
                // console.error('极验验证错误:', error);
                geetestResult = null;
            });
            
            // console.log('极验初始化成功');
        });
        
    } catch (error) {
        // console.error('初始化极验失败:', error);
        captchaEnabled = false;
    }
}

/**
 * 显示极验验证
 */
function showGeetest() {
    return new Promise((resolve, reject) => {
        // console.log('准备显示极验验证, geetestInstance:', geetestInstance);
        
        if (!geetestInstance) {
            // console.error('极验实例不存在');
            reject(new Error('极验未初始化'));
            return;
        }
        
        // 重置验证结果
        geetestResult = null;
        
        // 显示极验验证
        try {
            geetestInstance.showCaptcha();
            // console.log('极验验证窗口已显示');
        } catch (error) {
            // console.error('显示极验验证失败:', error);
            reject(error);
            return;
        }
        
        // 监听验证成功
        const checkInterval = setInterval(() => {
            if (geetestResult) {
                clearInterval(checkInterval);
                // console.log('获取到验证结果:', geetestResult);
                resolve(geetestResult);
            }
        }, 100);
        
        // 超时处理（30秒）
        setTimeout(() => {
            clearInterval(checkInterval);
            if (!geetestResult) {
                // console.error('验证超时');
                reject(new Error('验证超时'));
            }
        }, 30000);
    });
}

// ============================================
// 弹出框和提示功能
// ============================================

/**
 * 显示错误弹出框
 */
function showError(message, title = '错误') {
    const overlay = document.getElementById('modal-overlay');
    const icon = document.getElementById('modal-icon');
    const titleEl = document.getElementById('modal-title');
    const messageEl = document.getElementById('modal-message');
    
    if (overlay && icon && titleEl && messageEl) {
        icon.className = 'modal-icon error';
        icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
        titleEl.textContent = title;
        messageEl.textContent = message;
        overlay.classList.add('show');
    }
}

/**
 * 显示成功 Toast（右上角，5秒后自动消失）
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
 * 初始化弹出框
 */
function initModal() {
    const overlay = document.getElementById('modal-overlay');
    const confirmBtn = document.getElementById('modal-confirm');
    
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (overlay) {
                overlay.classList.remove('show');
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

// ============================================
// 原有功能
// ============================================

// 注册方式切换
function initTabSwitch() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const forms = document.querySelectorAll('.register-form');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabType = this.getAttribute('data-tab');
            
            // 切换按钮状态
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // 切换表单显示
            forms.forEach(form => {
                if (form.id === `${tabType}-form`) {
                    form.classList.add('active');
                } else {
                    form.classList.remove('active');
                }
            });
        });
    });
}

// 密码显示/隐藏
function initPasswordToggle() {
    const toggleBtns = document.querySelectorAll('.toggle-password');
    
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const passwordInput = this.parentElement.querySelector('input');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
}

// 验证码按钮
function initCodeButton() {
    const codeBtns = document.querySelectorAll('.btn-code');
    
    codeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            const value = targetInput.value.trim();
            
            // 验证输入
            if (!value) {
                showError(targetId.includes('phone') ? '请输入手机号' : '请输入邮箱地址');
                return;
            }
            
            if (targetId.includes('phone') && !validatePhone(value)) {
                showError('请输入正确的手机号');
                return;
            }
            
            if (targetId.includes('email') && !validateEmail(value)) {
                showError('请输入正确的邮箱地址');
                return;
            }
            
            // 发送验证码
            sendVerificationCode(value, this);
        });
    });
}

// 发送验证码
async function sendVerificationCode(target, btn) {
    // 判断是手机号还是邮箱
    const isPhone = validatePhone(target);
    const isEmail = validateEmail(target);
    
    if (!isPhone && !isEmail) {
        showError('请输入正确的手机号或邮箱地址');
        return;
    }
    
    // 保存按钮原始文本
    const originalText = btn.textContent;
    
    // 如果启用了人机验证，则需要先完成验证
    let geetestData = null;
    if (captchaEnabled) {
        // 检查极验是否已初始化
        // console.log('检查极验状态, geetestInstance:', geetestInstance);
        
        if (!geetestInstance) {
            showError('人机验证服务正在加载中，请稍后再试', '提示');
            
            // 尝试重新初始化极验
            // console.log('尝试重新初始化极验...');
            await initGeetest();
            
            // 等待1秒后再次检查
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            if (!geetestInstance) {
                showError('人机验证服务未就绪，请刷新页面重试', '验证失败');
                return;
            }
        }
        
        try {
            // 显示极验验证
            // console.log('准备显示极验验证...');
            geetestData = await showGeetest();
            
            if (!geetestData) {
                showError('请完成人机验证');
                return;
            }
            
            // console.log('极验验证完成，准备发送验证码...');
        } catch (error) {
            // console.error('极验验证错误:', error);
            if (error.message === '极验未初始化') {
                showError('人机验证服务未就绪，请刷新页面重试', '验证失败');
            } else if (error.message === '验证超时') {
                showError('验证超时，请重试', '验证超时');
            } else {
                showError('人机验证失败，请重试', '验证失败');
            }
            return;
        }
    }
    
    try {
        // 显示按钮加载状态
        btn.classList.add('loading');
        btn.disabled = true;
        btn.textContent = '正在发送...';
        
        // 构建请求数据
        const requestData = isPhone ? { phone: target } : { email: target };
        
        // 如果有极验数据，则添加到请求中
        if (geetestData) {
            requestData.lot_number = geetestData.lot_number;
            requestData.captcha_output = geetestData.captcha_output;
            requestData.pass_token = geetestData.pass_token;
            requestData.gen_time = geetestData.gen_time;
            
            console.log('发送极验数据:', {
                lot_number: geetestData.lot_number,
                gen_time: geetestData.gen_time,
                gen_time_type: typeof geetestData.gen_time
            });
        }
        
        // 根据类型选择 API 端点
        const apiUrl = isPhone ? '/sms/SendRegisterCode.php' : '/mail/SendRegisterCode.php';
        
        // 调用后端 API 发送验证码
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestData)
        });
        
        const result = await response.json();
        
        // 移除按钮加载状态
        btn.classList.remove('loading');
        
        if (result.success) {
            // 发送成功，显示成功提示
            showSuccessToast('验证码已发送，请注意查收', '发送成功');
            
            // 保存 code_id 用于注册时验证
            if (result.data.code_id) {
                btn.setAttribute('data-code-id', result.data.code_id);
            }
            
            // 保存 lot_number 用于注册时二次验证（如果有）
            if (result.data.lot_number) {
                btn.setAttribute('data-lot-number', result.data.lot_number);
            }
            
            // 开始倒计时
            let countdown = 60;
            btn.textContent = `${countdown}秒后重试`;
            
            const timer = setInterval(() => {
                countdown--;
                btn.textContent = `${countdown}秒后重试`;
                
                if (countdown <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }, 1000);
            
            // console.log('验证码发送成功:', result);
        } else {
            // 发送失败，恢复按钮状态
            btn.disabled = false;
            btn.textContent = originalText;
            
            // 显示错误弹出框
            showError(result.message || '验证码发送失败，请稍后重试', '发送失败');
            // console.error('验证码发送失败:', result);
        }
        
    } catch (error) {
        // 移除按钮加载状态
        btn.classList.remove('loading');
        btn.disabled = false;
        btn.textContent = originalText;
        
        // console.error('发送验证码错误:', error);
        showError('网络错误，请稍后重试', '网络错误');
    }
}

// 表单提交
function initFormSubmit() {
    const phoneForm = document.getElementById('phone-form');
    const emailForm = document.getElementById('email-form');
    
    // 手机号注册
    if (phoneForm) {
        phoneForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleRegister('phone');
        });
    }
    
    // 邮箱注册
    if (emailForm) {
        emailForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleRegister('email');
        });
    }
}

// 处理注册
async function handleRegister(type) {
    const prefix = type === 'phone' ? 'phone' : 'email';
    
    const username = document.getElementById(`${prefix}-username`).value.trim();
    const nickname = document.getElementById(`${prefix}-nickname`).value.trim();
    const contact = document.getElementById(type === 'phone' ? 'phone-number' : 'email-address').value.trim();
    const code = document.getElementById(`${prefix}-code`).value.trim();
    const password = document.getElementById(`${prefix}-password`).value;
    const confirmPassword = document.getElementById(`${prefix}-confirm-password`).value;
    const agree = document.getElementById(`agree-${prefix}`).checked;
    
    // 验证协议
    if (!agree) {
        showError('请阅读并同意隐私协议和服务协议');
        return;
    }
    
    // 验证必填项
    if (!username || !nickname || !contact || !code || !password || !confirmPassword) {
        showError('请填写完整信息');
        return;
    }
    
    // 验证账号格式
    if (!validateUsername(username)) {
        showError('账号必须以字母开头，4-12位英文或数字');
        return;
    }
    
    // 验证联系方式
    if (type === 'phone' && !validatePhone(contact)) {
        showError('请输入正确的手机号');
        return;
    }
    
    if (type === 'email' && !validateEmail(contact)) {
        showError('请输入正确的邮箱地址');
        return;
    }
    
    // 验证密码
    if (password.length < 6) {
        showError('密码长度不能少于6位');
        return;
    }
    
    if (password !== confirmPassword) {
        showError('两次输入的密码不一致');
        return;
    }
    
    // 获取注册按钮
    const submitBtn = document.querySelector(`#${prefix}-form button[type="submit"]`);
    if (!submitBtn) {
        showError('页面错误，请刷新重试');
        return;
    }
    
    // 显示加载动画
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<div class="spinner"></div><span>注册中...</span>';
    
    // 获取 code_id 和 lot_number（从发送验证码按钮）
    const sendCodeBtn = type === 'phone' 
        ? document.getElementById('phone-send-code')
        : document.getElementById('email-send-code');
    const codeId = sendCodeBtn ? sendCodeBtn.getAttribute('data-code-id') : '';
    const lotNumber = sendCodeBtn ? sendCodeBtn.getAttribute('data-lot-number') : '';
    
    // 验证是否有 code_id
    if (!codeId) {
        showError('缺少验证码标识，请重新获取验证码');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
        return;
    }
    
    try {
        // 调用注册 API
        const response = await fetch('/api/register.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                app_id: urlParams.get('app_id') || '',
                callback_url: urlParams.get('callback_url') || '',
                permissions: urlParams.get('permissions') || '',
                username: username,
                nickname: nickname,
                phone: type === 'phone' ? contact : '',
                email: type === 'email' ? contact : '',
                code: code,
                code_id: codeId,  // 添加 code_id
                password: password,
                lot_number: lotNumber
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // 注册成功
            showSuccessToast('注册成功，即将跳转到登录页面...', '注册成功');
            
            // 2秒后跳转到登录页面，携带URL参数
            setTimeout(() => {
                // 构建登录页面URL，保留所有参数
                const loginUrl = '../login/?' + urlParams.toString();
                window.location.href = loginUrl;
            }, 2000);
        } else {
            // 注册失败，恢复按钮状态
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            showError(result.message || '注册失败，请稍后重试', '注册失败');
        }
        
    } catch (error) {
        // 网络错误，恢复按钮状态
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
        console.error('注册错误:', error);
        showError('网络错误，请稍后重试', '网络错误');
    }
}

// 实时验证
function initValidation() {
    // 账号验证
    const usernameInputs = document.querySelectorAll('input[name="username"]');
    usernameInputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.value && !validateUsername(this.value)) {
                this.setCustomValidity('账号必须以字母开头，4-12位英文或数字');
            } else {
                this.setCustomValidity('');
            }
        });
    });
    
    // 确认密码验证
    const confirmPasswordInputs = document.querySelectorAll('input[name="confirm_password"]');
    confirmPasswordInputs.forEach(input => {
        input.addEventListener('input', function() {
            const form = this.closest('form');
            const passwordInput = form.querySelector('input[name="password"]');
            
            if (this.value && this.value !== passwordInput.value) {
                this.setCustomValidity('两次输入的密码不一致');
            } else {
                this.setCustomValidity('');
            }
        });
    });
}

// 验证账号格式
function validateUsername(username) {
    const usernameRegex = /^[a-zA-Z][a-zA-Z0-9]{3,11}$/;
    return usernameRegex.test(username);
}

// 验证手机号
function validatePhone(phone) {
    const phoneRegex = /^1[3-9]\d{9}$/;
    return phoneRegex.test(phone);
}

// 验证邮箱
function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}
