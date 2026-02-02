// 一碗小米周授权登录平台 - 登录页面脚本

// 全局配置
let appConfig = null;
let urlParams = null;
let geetestInstance = null;
let geetestResult = null;
let turnstileWidgetId = null; // Cloudflare Turnstile Widget ID
let turnstileToken = null; // Cloudflare Turnstile Token
let captchaEnabled = false; // 是否启用人机验证
let captchaConfig = null; // 人机验证配置
let captchaProvider = null; // 人机验证服务商：geetest, turnstile 等

document.addEventListener('DOMContentLoaded', function() {
    init();
});

async function init() {
    // 获取 URL 参数
    urlParams = new URLSearchParams(window.location.search);
    
    // 更新注册链接，保留参数
    updateRegisterLink();
    
    // 更新忘记密码链接，保留参数
    updateForgotPasswordLink();
    
    // 验证应用配置
    await verifyAppConfig();
    
    // 初始化人机验证
    await initCaptcha();
    
    // 初始化登录方式切换
    initTabSwitch();
    
    // 初始化密码显示/隐藏
    initPasswordToggle();
    
    // 初始化验证码按钮
    initCodeButton();
    
    // 初始化表单提交
    initFormSubmit();
    
    // 初始化第三方登录
    initSocialLogin();
    
    // 初始化弹出框
    initModal();
    
    // console.log('登录页面已加载');
}

/**
 * 更新注册链接，保留URL参数
 */
function updateRegisterLink() {
    const registerLink = document.getElementById('register-link');
    if (registerLink && urlParams.toString()) {
        registerLink.href = `../register?${urlParams.toString()}`;
        //console.log('注册链接已更新:', registerLink.href);
    }
}

/**
 * 更新忘记密码链接，保留URL参数
 */
function updateForgotPasswordLink() {
    const forgotPasswordLink = document.querySelector('.forgot-password');
    if (forgotPasswordLink && urlParams.toString()) {
        forgotPasswordLink.href = `../user/forgot-password.html?${urlParams.toString()}`;
        //console.log('忘记密码链接已更新:', forgotPasswordLink.href);
    }
}

/**
 * 验证应用配置
 */
async function verifyAppConfig() {
    let appId = urlParams.get('app_id');
    let callbackUrl = urlParams.get('callback_url');
    const code = urlParams.get('code');
    let permissions = urlParams.get('permissions');
    
    // 如果没有传入 app_id，则加载用户中心配置
    if (!appId) {
        try {
            const response = await fetch('/api/GetUserCenterConfig.php');
            const result = await response.json();
            
            if (!result.success) {
                showError('加载用户中心配置失败：' + result.message);
                return;
            }
            
            // 使用用户中心配置
            appId = result.data.app_id;
            callbackUrl = result.data.callback_url;
            permissions = result.data.permissions;
            
            // 更新 URL 参数
            urlParams.set('app_id', appId);
            urlParams.set('callback_url', callbackUrl);
            urlParams.set('permissions', permissions);
            
            //console.log('已加载用户中心配置:', result.data);
        } catch (error) {
            console.error('加载用户中心配置失败:', error);
            showError('加载用户中心配置失败，请稍后重试');
            return;
        }
    }
    
    // 验证必填参数
    if (!appId) {
        showError('缺少 app_id 参数');
        return;
    }
    
    if (!callbackUrl) {
        showError('缺少 callback_url 参数');
        return;
    }
    
    if (!permissions) {
        showError('缺少 permissions 参数');
        return;
    }
    
    try {
        // 调用验证 API
        const params = new URLSearchParams({
            app_id: appId,
            callback_url: callbackUrl,
            permissions: permissions
        });
        
        if (code) params.append('code', code);
        
        const response = await fetch(`/api/verify_app.php?${params.toString()}`);
        const result = await response.json();
        
        if (!result.success) {
            showError(result.message);
            return;
        }
        
        // 保存配置
        appConfig = result.data;
        
        // 调试：打印完整的API响应
        //console.log('API响应数据:', result);
        //console.log('权限信息:', result.data.permissions);
        
        // 更新页面显示
        updatePageDisplay();
        
    } catch (error) {
        console.error('验证失败:', error);
        showError('验证应用配置失败，请稍后重试');
    }
}

/**
 * 更新页面显示
 */
function updatePageDisplay() {
    if (!appConfig) return;
    
    // 更新网站信息
    updateSiteInfo(appConfig.app_info);
    
    // 更新权限列表
    updatePermissions(appConfig.permissions);
    
    // 更新登录方式
    updateLoginMethods(appConfig.login_config);
    
    // 更新第三方登录
    updateSocialLogins(appConfig.login_config);
}

/**
 * 更新网站信息
 */
function updateSiteInfo(appInfo) {
    // 更新站点名称
    const siteName = document.querySelector('.site-name');
    if (siteName) {
        siteName.textContent = appInfo.site_name;
    }
    
    // 更新站点图标
    const siteLogo = document.querySelector('.site-logo');
    if (siteLogo) {
        if (appInfo.app_icon_url) {
            // 如果有图标URL，显示图片
            const img = document.createElement('img');
            img.src = appInfo.app_icon_url;
            img.alt = appInfo.site_name;
            
            // 图片加载失败时回退到默认图标
            img.onerror = function() {
                siteLogo.innerHTML = '<i class="fa-solid fa-image"></i>';
            };
            
            // 清空并添加图片
            siteLogo.innerHTML = '';
            siteLogo.appendChild(img);
        }
        // 如果没有图标URL，保持默认图标
    }
}

/**
 * 更新权限列表
 */
function updatePermissions(permissions) {
    const permissionList = document.querySelector('.permission-list');
    if (!permissionList || !permissions || permissions.length === 0) {
        return;
    }
    
    // 调试：打印权限数据
    //console.log('权限数据:', permissions);
    
    permissionList.innerHTML = permissions.map(permission => {
        // 调试：打印每个权限的描述
        //console.log('权限:', permission.permission_name, '描述:', permission.permission_description);
        
        const description = permission.permission_description ? `（${permission.permission_description}）` : '';
        return `
            <li class="permission-item">
                <div class="permission-content">
                    <strong>${permission.permission_name}</strong>${description}
                </div>
            </li>
        `;
    }).join('');
}

/**
 * 更新登录方式
 */
function updateLoginMethods(loginConfig) {
    //console.log('更新登录方式，配置:', loginConfig);
    
    // 检查是否启用登录
    if (!loginConfig.enable_login) {
        showError('该应用未启用登录功能');
        return;
    }
    
    // 检查是否有可用的登录方式
    const hasPasswordLogin = loginConfig.enable_password_login;
    const hasEmailCodeLogin = loginConfig.enable_email_code_login;
    const hasPhoneCodeLogin = loginConfig.enable_phone_code_login;
    const hasCodeLogin = hasEmailCodeLogin || hasPhoneCodeLogin;
    
    /**console.log('登录方式状态:', {
        hasPasswordLogin,
        hasEmailCodeLogin,
        hasPhoneCodeLogin,
        hasCodeLogin
    });**/
    
    if (!hasPasswordLogin && !hasCodeLogin) {
        showError('该应用未配置任何登录方式');
        return;
    }
    
    // 获取元素
    const tabs = document.querySelectorAll('.tab-btn');
    const forms = document.querySelectorAll('.login-form');
    
    // 密码登录
    if (!hasPasswordLogin) {
        tabs[0]?.style.setProperty('display', 'none');
        forms[0]?.style.setProperty('display', 'none');
    }
    
    // 验证码登录
    if (!hasCodeLogin) {
        tabs[1]?.style.setProperty('display', 'none');
        forms[1]?.style.setProperty('display', 'none');
    } else {
        // 更新验证码登录的显示文本和输入框
        updateCodeLoginDisplay(hasEmailCodeLogin, hasPhoneCodeLogin);
    }
    
    // 如果只有一种登录方式，自动选中
    if (hasPasswordLogin && !hasCodeLogin) {
        tabs[0]?.classList.add('active');
        forms[0]?.classList.add('active');
    } else if (!hasPasswordLogin && hasCodeLogin) {
        tabs[1]?.classList.add('active');
        forms[1]?.classList.add('active');
    }
}

/**
 * 更新验证码登录显示
 */
function updateCodeLoginDisplay(hasEmailCodeLogin, hasPhoneCodeLogin) {
    //console.log('更新验证码登录显示:', { hasEmailCodeLogin, hasPhoneCodeLogin });
    
    const tabBtn = document.querySelector('.tab-btn[data-tab="code"]');
    const contactLabel = document.getElementById('contact-label-text');
    const contactInput = document.getElementById('contact');
    const contactIcon = document.getElementById('contact-icon');
    
    /**console.log('找到的元素:', { 
        tabBtn: !!tabBtn, 
        contactLabel: !!contactLabel, 
        contactInput: !!contactInput, 
        contactIcon: !!contactIcon 
    });**/
    
    if (!tabBtn || !contactLabel || !contactInput || !contactIcon) {
        console.error('未找到必要的元素');
        return;
    }
    
    if (hasEmailCodeLogin && hasPhoneCodeLogin) {
        // 两者都开启：显示"验证码登录"
        //console.log('设置：两者都开启');
        tabBtn.innerHTML = '<i class="fas fa-envelope"></i> 验证码登录';
        contactLabel.textContent = '手机号/邮箱';
        contactInput.placeholder = '请输入手机号或邮箱';
        contactInput.type = 'text';
        contactIcon.className = 'fas fa-envelope';
    } else if (hasEmailCodeLogin) {
        // 仅邮箱：显示"邮箱验证码登录"
        //console.log('设置：仅邮箱');
        tabBtn.innerHTML = '<i class="fas fa-envelope"></i> 邮箱验证码登录';
        contactLabel.textContent = '邮箱';
        contactInput.placeholder = '请输入邮箱地址';
        contactInput.type = 'email';
        contactIcon.className = 'fas fa-envelope';
    } else if (hasPhoneCodeLogin) {
        // 仅手机：显示"手机验证码登录"
        //console.log('设置：仅手机');
        tabBtn.innerHTML = '<i class="fas fa-mobile-alt"></i> 手机验证码登录';
        contactLabel.textContent = '手机号';
        contactInput.placeholder = '请输入手机号';
        contactInput.type = 'tel';
        contactIcon.className = 'fas fa-mobile-alt';
    } else {
        console.warn('没有启用任何验证码登录方式');
    }
    
    /**console.log('更新后的显示:', {
        tabText: tabBtn.textContent,
        labelText: contactLabel.textContent,
        placeholder: contactInput.placeholder,
        inputType: contactInput.type
    });**/
}

/**
 * 更新第三方登录
 */
function updateSocialLogins(loginConfig) {
    if (!loginConfig.enable_third_party_login) {
        const socialLogin = document.querySelector('.social-login');
        const divider = document.querySelector('.divider');
        if (socialLogin) socialLogin.style.display = 'none';
        if (divider) divider.style.display = 'none';
        return;
    }
    
    // 隐藏未启用的第三方登录
    const socialBtns = {
        'qq': loginConfig.enable_qq_login,
        'wechat': loginConfig.enable_wechat_login,
        'github': loginConfig.enable_github_login,
        'google': loginConfig.enable_google_login,
        'weibo': loginConfig.enable_weibo_login
    };
    
    Object.keys(socialBtns).forEach(platform => {
        const btn = document.querySelector(`.social-btn.${platform}`);
        if (btn && !socialBtns[platform]) {
            btn.style.display = 'none';
        }
    });
}

/**
 * 显示错误信息
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
// 人机验证
// ============================================

/**
 * 动态加载验证 SDK
 */
function loadCaptchaSDK(provider) {
    return new Promise((resolve, reject) => {
        let scriptUrl = '';
        let scriptId = '';
        
        switch (provider) {
            case 'geetest':
                scriptUrl = 'https://static.geetest.com/v4/gt4.js';
                scriptId = 'geetest-sdk';
                break;
            case 'turnstile':
                scriptUrl = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
                scriptId = 'turnstile-sdk';
                break;
            case 'recaptcha':
                scriptUrl = 'https://www.recaptcha.net/recaptcha/api.js';
                scriptId = 'recaptcha-sdk';
                break;
            case 'hcaptcha':
                scriptUrl = 'https://js.hcaptcha.com/1/api.js';
                scriptId = 'hcaptcha-sdk';
                break;
            default:
                reject(new Error('未知的验证服务商: ' + provider));
                return;
        }
        
        // 检查是否已经加载
        if (document.getElementById(scriptId)) {
            console.log('SDK 已加载:', provider);
            resolve();
            return;
        }
        
        console.log('开始加载 SDK:', provider, scriptUrl);
        
        const script = document.createElement('script');
        script.id = scriptId;
        script.src = scriptUrl;
        script.async = true;
        script.defer = true;
        
        script.onload = () => {
            console.log('SDK 加载成功:', provider);
            resolve();
        };
        
        script.onerror = () => {
            console.error('SDK 加载失败:', provider);
            reject(new Error('SDK 加载失败: ' + provider));
        };
        
        document.head.appendChild(script);
    });
}

/**
 * 初始化人机验证
 */
async function initCaptcha() {
    try {
        console.log('开始初始化人机验证...');
        
        // 获取人机验证配置
        const response = await fetch('/captcha/GetCaptchaConfig.php?scene=login');
        const result = await response.json();
        
        //console.log('人机验证配置响应:', result);
        
        if (!result.success) {
            console.log('获取人机验证配置失败:', result.message);
            captchaEnabled = false;
            return;
        }
        
        // 检查是否启用人机验证
        if (!result.data.enabled) {
            console.log('人机验证未启用');
            captchaEnabled = false;
            return;
        }
        
        captchaConfig = result.data;
        captchaProvider = result.data.provider;
        captchaEnabled = true;
        
        console.log('人机验证已启用，服务商:', captchaProvider);
        //console.log('验证配置:', captchaConfig);
        
        // 动态加载对应的 SDK
        try {
            await loadCaptchaSDK(captchaProvider);
        } catch (error) {
            console.error('加载验证 SDK 失败:', error);
            captchaEnabled = false;
            return;
        }
        
        // 根据服务商初始化对应的验证
        if (captchaProvider === 'geetest') {
            console.log('初始化极验验证...');
            await initGeetest();
        } else if (captchaProvider === 'turnstile') {
            console.log('初始化 Cloudflare Turnstile...');
            await initTurnstile();
        } else if (captchaProvider === 'recaptcha') {
            console.log('初始化 Google reCAPTCHA...');
            await initRecaptcha();
        } else if (captchaProvider === 'hcaptcha') {
            console.log('初始化 hCaptcha...');
            await initHcaptcha();
        } else {
            console.warn('未知的验证服务商:', captchaProvider);
        }
        
    } catch (error) {
        console.error('初始化人机验证失败:', error);
        captchaEnabled = false;
    }
}

/**
 * 初始化 Cloudflare Turnstile
 */
async function initTurnstile() {
    try {
        console.log('开始初始化 Cloudflare Turnstile...');
        
        // 等待 Turnstile SDK 加载（最多等待 5 秒）
        let attempts = 0;
        const maxAttempts = 50; // 5秒 / 100ms = 50次
        
        while (typeof turnstile === 'undefined' && attempts < maxAttempts) {
            await new Promise(resolve => setTimeout(resolve, 100));
            attempts++;
        }
        
        // 检查 Turnstile SDK 是否加载
        if (typeof turnstile === 'undefined') {
            console.error('Cloudflare Turnstile SDK 未加载（超时）');
            captchaEnabled = false;
            return;
        }
        
        console.log('Turnstile SDK 已加载');
        
        const siteKey = captchaConfig.site_key;
        console.log('Site Key:', siteKey);
        
        // 为两个表单都渲染 Turnstile
        const containers = ['captcha-container-password', 'captcha-container-code'];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                console.log('渲染 Turnstile 到容器:', containerId);
                
                // 清空容器
                container.innerHTML = '';
                
                // 渲染 Turnstile
                try {
                    turnstile.render(`#${containerId}`, {
                        sitekey: siteKey,
                        theme: 'light',
                        callback: function(token) {
                            turnstileToken = token;
                            console.log('Turnstile 验证成功，token:', token.substring(0, 20) + '...');
                        },
                        'error-callback': function(error) {
                            turnstileToken = null;
                            console.error('Turnstile 验证失败:', error);
                        },
                        'expired-callback': function() {
                            turnstileToken = null;
                            console.log('Turnstile 验证已过期');
                        }
                    });
                    console.log('Turnstile 渲染成功:', containerId);
                } catch (renderError) {
                    console.error('Turnstile 渲染失败:', containerId, renderError);
                }
            } else {
                console.warn('容器不存在:', containerId);
            }
        });
        
        console.log('Turnstile 初始化完成');
        
    } catch (error) {
        console.error('初始化 Turnstile 失败:', error);
        captchaEnabled = false;
    }
}

/**
 * 初始化 Google reCAPTCHA
 */
async function initRecaptcha() {
    try {
        console.log('开始初始化 Google reCAPTCHA...');
        
        // 等待 reCAPTCHA SDK 加载（最多等待 10 秒）
        let attempts = 0;
        const maxAttempts = 100; // 10秒 / 100ms = 100次
        
        // 等待 grecaptcha 对象和 ready 方法都可用
        while (attempts < maxAttempts) {
            if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.ready === 'function') {
                break;
            }
            await new Promise(resolve => setTimeout(resolve, 100));
            attempts++;
        }
        
        // 检查 grecaptcha 对象是否存在
        if (typeof grecaptcha === 'undefined') {
            console.error('Google reCAPTCHA SDK 未加载（超时）');
            captchaEnabled = false;
            return;
        }
        
        // 检查 grecaptcha.ready 方法是否可用
        if (typeof grecaptcha.ready !== 'function') {
            console.error('Google reCAPTCHA SDK 加载不完整（ready 方法不可用）');
            captchaEnabled = false;
            return;
        }
        
        console.log('reCAPTCHA SDK 已完全加载');
        
        const siteKey = captchaConfig.site_key;
        //console.log('Site Key:', siteKey);
        
        // 等待 grecaptcha.render 方法可用
        await grecaptcha.ready(async function() {
            // 为两个表单都渲染 reCAPTCHA
            const containers = ['captcha-container-password', 'captcha-container-code'];
            
            containers.forEach(containerId => {
                const container = document.getElementById(containerId);
                if (container) {
                    console.log('渲染 reCAPTCHA 到容器:', containerId);
                    
                    // 清空容器
                    container.innerHTML = '';
                    
                    // 渲染 reCAPTCHA
                    try {
                        grecaptcha.render(containerId, {
                            'sitekey': siteKey,
                            'theme': 'light',
                            'callback': function(token) {
                                turnstileToken = token; // 复用 turnstileToken 变量
                                console.log('reCAPTCHA 验证成功，token:', token.substring(0, 20) + '...');
                            },
                            'expired-callback': function() {
                                turnstileToken = null;
                                console.log('reCAPTCHA 验证已过期');
                            },
                            'error-callback': function() {
                                turnstileToken = null;
                                console.error('reCAPTCHA 验证失败');
                            }
                        });
                        console.log('reCAPTCHA 渲染成功:', containerId);
                    } catch (renderError) {
                        console.error('reCAPTCHA 渲染失败:', containerId, renderError);
                    }
                } else {
                    console.warn('容器不存在:', containerId);
                }
            });
        });
        
        console.log('reCAPTCHA 初始化完成');
        
    } catch (error) {
        console.error('初始化 reCAPTCHA 失败:', error);
        captchaEnabled = false;
    }
}

/**
 * 初始化 hCaptcha
 */
async function initHcaptcha() {
    try {
        console.log('开始初始化 hCaptcha...');
        
        // 等待 hCaptcha SDK 加载（最多等待 5 秒）
        let attempts = 0;
        const maxAttempts = 50;
        
        while (typeof hcaptcha === 'undefined' && attempts < maxAttempts) {
            await new Promise(resolve => setTimeout(resolve, 100));
            attempts++;
        }
        
        if (typeof hcaptcha === 'undefined') {
            console.error('hCaptcha SDK 未加载（超时）');
            captchaEnabled = false;
            return;
        }
        
        console.log('hCaptcha SDK 已加载');
        
        const siteKey = captchaConfig.site_key;
        console.log('Site Key:', siteKey);
        
        // 为两个表单都渲染 hCaptcha
        const containers = ['captcha-container-password', 'captcha-container-code'];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                console.log('渲染 hCaptcha 到容器:', containerId);
                
                // 清空容器
                container.innerHTML = '';
                
                // 渲染 hCaptcha
                try {
                    hcaptcha.render(containerId, {
                        'sitekey': siteKey,
                        'theme': 'light',
                        'callback': function(token) {
                            turnstileToken = token; // 复用 turnstileToken 变量
                            console.log('hCaptcha 验证成功，token:', token.substring(0, 20) + '...');
                        },
                        'expired-callback': function() {
                            turnstileToken = null;
                            console.log('hCaptcha 验证已过期');
                        },
                        'error-callback': function() {
                            turnstileToken = null;
                            console.error('hCaptcha 验证失败');
                        }
                    });
                    console.log('hCaptcha 渲染成功:', containerId);
                } catch (renderError) {
                    console.error('hCaptcha 渲染失败:', containerId, renderError);
                }
            } else {
                console.warn('容器不存在:', containerId);
            }
        });
        
        console.log('hCaptcha 初始化完成');
        
    } catch (error) {
        console.error('初始化 hCaptcha 失败:', error);
        captchaEnabled = false;
    }
}

// ============================================
// 极验验证（保留兼容）
// ============================================

/**
 * 初始化极验
 */
async function initGeetest() {
    try {
        // 检查极验 SDK 是否加载
        if (typeof initGeetest4 === 'undefined') {
            console.error('极验 SDK 未加载');
            captchaEnabled = false;
            return;
        }
        
        const config = captchaConfig;
        
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
                // console.log('极验验证成功');
            });
            
            // 监听验证关闭事件
            captcha.onClose(function() {
                geetestResult = null;
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
        if (!geetestInstance) {
            reject(new Error('极验未初始化'));
            return;
        }
        
        // 重置验证结果
        geetestResult = null;
        
        // 显示极验验证
        try {
            geetestInstance.showCaptcha();
        } catch (error) {
            reject(error);
            return;
        }
        
        // 监听验证成功
        const checkInterval = setInterval(() => {
            if (geetestResult) {
                clearInterval(checkInterval);
                resolve(geetestResult);
            }
        }, 100);
        
        // 超时处理（30秒）
        setTimeout(() => {
            clearInterval(checkInterval);
            if (!geetestResult) {
                reject(new Error('验证超时'));
            }
        }, 30000);
    });
}


// 登录方式切换
function initTabSwitch() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const forms = document.querySelectorAll('.login-form');
    
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
    const toggleBtn = document.querySelector('.toggle-password');
    const passwordInput = document.getElementById('password');
    
    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function() {
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
    }
}

// 验证码按钮
function initCodeButton() {
    const codeBtn = document.querySelector('.btn-code');
    const contactInput = document.getElementById('contact');
    
    if (codeBtn && contactInput) {
        codeBtn.addEventListener('click', async function() {
            const contact = contactInput.value.trim();
            
            // 验证输入
            if (!contact) {
                showError('请输入手机号或邮箱');
                return;
            }
            
            // 判断是手机号还是邮箱
            const isPhone = validatePhone(contact);
            const isEmail = validateEmail(contact);
            
            if (!isPhone && !isEmail) {
                showError('请输入正确的手机号或邮箱地址');
                return;
            }
            
            // 检查是否允许该类型的验证码登录
            if (appConfig && appConfig.login_config) {
                if (isPhone && !appConfig.login_config.enable_phone_code_login) {
                    showError('该应用未开启手机验证码登录');
                    return;
                }
                if (isEmail && !appConfig.login_config.enable_email_code_login) {
                    showError('该应用未开启邮箱验证码登录');
                    return;
                }
            }
            
            // 发送验证码
            await sendVerificationCode(contact, isPhone ? 'phone' : 'email', this);
        });
    }
}

// 发送验证码
async function sendVerificationCode(contact, type, btn) {
    // 保存按钮原始文本
    const originalText = btn.textContent;
    
    // 如果启用了人机验证，则需要先完成验证
    let captchaData = null;
    if (captchaEnabled) {
        if (captchaProvider === 'geetest') {
            // 极验验证
            if (!geetestInstance) {
                showError('人机验证服务正在加载中，请稍后再试', '提示');
                await initGeetest();
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                if (!geetestInstance) {
                    showError('人机验证服务未就绪，请刷新页面重试', '验证失败');
                    return;
                }
            }
            
            try {
                captchaData = await showGeetest();
                if (!captchaData) {
                    showError('请完成人机验证');
                    return;
                }
            } catch (error) {
                if (error.message === '极验未初始化') {
                    showError('人机验证服务未就绪，请刷新页面重试', '验证失败');
                } else if (error.message === '验证超时') {
                    showError('验证超时，请重试', '验证超时');
                } else {
                    showError('人机验证失败，请重试', '验证失败');
                }
                return;
            }
        } else if (captchaProvider === 'turnstile' || captchaProvider === 'recaptcha' || captchaProvider === 'hcaptcha') {
            // Cloudflare Turnstile / Google reCAPTCHA / hCaptcha 验证
            if (!turnstileToken) {
                showError('请完成人机验证');
                return;
            }
            captchaData = {
                captcha_token: turnstileToken
            };
        }
    }
    
    try {
        // 显示按钮加载状态
        btn.classList.add('loading');
        btn.disabled = true;
        btn.textContent = '正在发送...';
        
        // 构建请求数据
        const requestData = {
            [type]: contact
        };
        
        // 如果有人机验证数据，则添加到请求中
        if (captchaData) {
            if (captchaProvider === 'geetest') {
                requestData.lot_number = captchaData.lot_number;
                requestData.captcha_output = captchaData.captcha_output;
                requestData.pass_token = captchaData.pass_token;
                requestData.gen_time = captchaData.gen_time;
            } else if (captchaProvider === 'turnstile') {
                requestData.turnstile_token = captchaData.captcha_token;
            } else if (captchaProvider === 'recaptcha') {
                requestData.recaptcha_token = captchaData.captcha_token;
            } else if (captchaProvider === 'hcaptcha') {
                requestData.hcaptcha_token = captchaData.captcha_token;
            }
            requestData.captcha_provider = captchaProvider;
        }
        
        // 根据类型选择不同的 API 端点
        const apiUrl = type === 'phone' ? '/sms/SendLoginCode.php' : '/mail/SendLoginCode.php';
        
        // 调用后端 API 发送验证码（登录场景）
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
            // 发送成功
            const successMessage = type === 'phone' ? '验证码已发送，请注意查收短信' : '验证码已发送，请查收邮件';
            showSuccessToast(successMessage, '发送成功');
            
            // 保存 lot_number 用于登录时二次验证
            if (result.data.lot_number) {
                btn.setAttribute('data-lot-number', result.data.lot_number);
            }
            
            // 保存 code_id 用于验证码验证
            if (result.data.code_id || result.data.sms_id) {
                const codeId = result.data.code_id || result.data.sms_id;
                btn.setAttribute('data-code-id', codeId);
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
        } else {
            // 发送失败
            btn.disabled = false;
            btn.textContent = originalText;
            showError(result.message || '验证码发送失败，请稍后重试', '发送失败');
        }
        
    } catch (error) {
        // 移除按钮加载状态
        btn.classList.remove('loading');
        btn.disabled = false;
        btn.textContent = originalText;
        showError('网络错误，请稍后重试', '网络错误');
    }
}

// 表单提交
function initFormSubmit() {
    const passwordForm = document.getElementById('password-form');
    const codeForm = document.getElementById('code-form');
    
    // 账号密码登录
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const agree = document.getElementById('agree-password').checked;
            
            if (!agree) {
                showError('请阅读并同意隐私协议和服务协议');
                return;
            }
            
            if (!username || !password) {
                showError('请填写完整信息');
                return;
            }
            
            // 如果启用了人机验证，需要先完成验证
            let captchaData = null;
            if (captchaEnabled) {
                if (captchaProvider === 'geetest') {
                    // 极验验证
                    if (!geetestInstance) {
                        showError('人机验证服务正在加载中，请稍后再试', '提示');
                        return;
                    }
                    
                    try {
                        captchaData = await showGeetest();
                        if (!captchaData) {
                            showError('请完成人机验证');
                            return;
                        }
                    } catch (error) {
                        showError('人机验证失败，请重试', '验证失败');
                        return;
                    }
                } else if (captchaProvider === 'turnstile' || captchaProvider === 'recaptcha' || captchaProvider === 'hcaptcha') {
                    // Cloudflare Turnstile / Google reCAPTCHA / hCaptcha 验证
                    if (!turnstileToken) {
                        showError('请完成人机验证');
                        return;
                    }
                    captchaData = {
                        captcha_token: turnstileToken
                    };
                }
            }
            
            // 获取登录按钮
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div><span>登录中...</span>';
            
            try {
                // 构建请求数据
                const requestData = {
                    app_id: urlParams.get('app_id') || '',
                    callback_url: urlParams.get('callback_url') || '',
                    permissions: urlParams.get('permissions') || '',
                    account: username,
                    password: password,
                    login_method: 'password'
                };
                
                // 如果 URL 中有 state code 参数，也传递给后端
                const stateCode = urlParams.get('code');
                if (stateCode) {
                    requestData.state_code = stateCode;
                }
                
                // 如果有人机验证数据，则添加到请求中
                if (captchaData) {
                    if (captchaProvider === 'geetest') {
                        requestData.lot_number = captchaData.lot_number;
                        requestData.captcha_output = captchaData.captcha_output;
                        requestData.pass_token = captchaData.pass_token;
                        requestData.gen_time = captchaData.gen_time;
                    } else if (captchaProvider === 'turnstile' || captchaProvider === 'recaptcha' || captchaProvider === 'hcaptcha') {
                        requestData.captcha_token = captchaData.captcha_token;
                    }
                    requestData.captcha_provider = captchaProvider;
                }
                
                // 调用登录 API
                const response = await fetch('/api/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessToast('登录成功，即将跳转...', '登录成功');
                    // 处理登录成功后的跳转
                    setTimeout(() => {
                        window.location.href = result.data.redirect_url;
                    }, 1500);
                } else {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    showError(result.message || '登录失败，请稍后重试', '登录失败');
                }
            } catch (error) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                showError('网络错误，请稍后重试', '网络错误');
            }
        });
    }
    
    // 验证码登录
    if (codeForm) {
        codeForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const contact = document.getElementById('contact').value.trim();
            const code = document.getElementById('code').value.trim();
            const agree = document.getElementById('agree-code').checked;
            
            if (!agree) {
                showError('请阅读并同意隐私协议和服务协议');
                return;
            }
            
            if (!contact || !code) {
                showError('请填写完整信息');
                return;
            }
            
            // 验证联系方式格式
            const isPhone = validatePhone(contact);
            const isEmail = validateEmail(contact);
            
            if (!isPhone && !isEmail) {
                showError('请输入正确的手机号或邮箱地址');
                return;
            }
            
            // 检查是否允许该类型的验证码登录
            if (appConfig && appConfig.login_config) {
                if (isPhone && !appConfig.login_config.enable_phone_code_login) {
                    showError('该应用未开启手机验证码登录');
                    return;
                }
                if (isEmail && !appConfig.login_config.enable_email_code_login) {
                    showError('该应用未开启邮箱验证码登录');
                    return;
                }
            }
            
            // 获取 lot_number 和 code_id（从发送验证码按钮）
            const sendCodeBtn = document.querySelector('.btn-code');
            const lotNumber = sendCodeBtn ? sendCodeBtn.getAttribute('data-lot-number') : '';
            const codeId = sendCodeBtn ? sendCodeBtn.getAttribute('data-code-id') : '';
            
            // 获取登录按钮
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div><span>登录中...</span>';
            
            try {
                // 构建请求数据
                const requestData = {
                    app_id: urlParams.get('app_id') || '',
                    callback_url: urlParams.get('callback_url') || '',
                    permissions: urlParams.get('permissions') || '',
                    phone: isPhone ? contact : '',
                    email: isEmail ? contact : '',
                    code: code,  // 验证码
                    code_id: codeId,
                    login_type: 'code'
                };
                
                // 如果 URL 中有 state code 参数，使用不同的字段名传递给后端
                const stateCode = urlParams.get('code');
                if (stateCode) {
                    requestData.state_code = stateCode;
                }
                
                // 如果启用了人机验证，添加验证参数
                if (captchaEnabled) {
                    if (captchaProvider === 'geetest') {
                        // 极验验证：使用从发送验证码按钮保存的 lot_number
                        if (lotNumber) {
                            requestData.lot_number = lotNumber;
                            requestData.captcha_provider = 'geetest';
                        } else {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            showError('缺少人机验证参数，请重新获取验证码');
                            return;
                        }
                    } else if (captchaProvider === 'turnstile' || captchaProvider === 'recaptcha' || captchaProvider === 'hcaptcha') {
                        // Cloudflare Turnstile / Google reCAPTCHA / hCaptcha：使用从发送验证码按钮保存的 lot_number
                        // lot_number 是后端在发送验证码时保存的验证标识，用于二次验证
                        if (lotNumber) {
                            requestData.captcha_token = lotNumber;
                            requestData.captcha_provider = captchaProvider;
                        } else {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            showError('缺少人机验证参数，请重新获取验证码');
                            return;
                        }
                    }
                }
                
                // 调用登录 API
                const response = await fetch('/api/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessToast('登录成功，即将跳转...', '登录成功');
                    // 处理登录成功后的跳转
                    setTimeout(() => {
                        window.location.href = result.data.redirect_url;
                    }, 1500);
                } else {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    showError(result.message || '登录失败，请稍后重试', '登录失败');
                }
            } catch (error) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                showError('网络错误，请稍后重试', '网络错误');
            }
        });
    }
}

// 第三方登录
function initSocialLogin() {
    const socialBtns = document.querySelectorAll('.social-btn');
    
    socialBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const platform = this.classList[1]; // qq, wechat, github, google, weibo
            console.log('第三方登录:', platform);
            
            // 获取应用ID
            const appId = urlParams.get('app_id');
            const callbackUrl = urlParams.get('callback_url');
            const permissions = urlParams.get('permissions') || '';
            const stateCode = urlParams.get('code') || '';
            
            if (!appId) {
                showError('缺少应用ID参数');
                return;
            }
            
            if (!callbackUrl) {
                showError('缺少回调地址参数');
                return;
            }
            
            // 根据平台跳转到对应的第三方登录页面
            if (platform === 'qq') {
                // QQ登录 - 传递完整参数
                const qqLoginUrl = `thirdparty/qq/index.php?app_id=${encodeURIComponent(appId)}&callback_url=${encodeURIComponent(callbackUrl)}&permissions=${encodeURIComponent(permissions)}&state_code=${encodeURIComponent(stateCode)}`;
                window.location.href = qqLoginUrl;
            } else if (platform === 'wechat') {
                // 微信登录 - 传递完整参数
                const wechatLoginUrl = `thirdparty/wechat/index.php?app_id=${encodeURIComponent(appId)}&callback_url=${encodeURIComponent(callbackUrl)}&permissions=${encodeURIComponent(permissions)}&state_code=${encodeURIComponent(stateCode)}`;
                window.location.href = wechatLoginUrl;
            } else if (platform === 'github') {
                // GitHub登录 - 传递完整参数
                const githubLoginUrl = `thirdparty/github/index.php?app_id=${encodeURIComponent(appId)}&callback_url=${encodeURIComponent(callbackUrl)}&permissions=${encodeURIComponent(permissions)}&state_code=${encodeURIComponent(stateCode)}`;
                window.location.href = githubLoginUrl;
            } else if (platform === 'weibo') {
                // 微博登录 - 传递完整参数
                const weiboLoginUrl = `thirdparty/weibo/index.php?app_id=${encodeURIComponent(appId)}&callback_url=${encodeURIComponent(callbackUrl)}&permissions=${encodeURIComponent(permissions)}&state_code=${encodeURIComponent(stateCode)}`;
                window.location.href = weiboLoginUrl;
            } else if (platform === 'google') {
                // Google登录 - 传递完整参数
                const googleLoginUrl = `thirdparty/google/index.php?app_id=${encodeURIComponent(appId)}&callback_url=${encodeURIComponent(callbackUrl)}&permissions=${encodeURIComponent(permissions)}&state_code=${encodeURIComponent(stateCode)}`;
                window.location.href = googleLoginUrl;
            } else {
                showToast(`${platform.toUpperCase()} 登录功能开发中...`, 'info');
            }
        });
    });
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
