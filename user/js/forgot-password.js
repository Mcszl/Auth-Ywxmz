// 忘记密码页面脚本

// 全局变量
let currentStep = 1;
let captchaToken = null;
let captchaInstance = null;
let captchaConfig = null; // 人机验证配置
let accountType = null; // 'phone' 或 'email'
let accountValue = null;
let sendCodeTimer = null;
let urlParams = new URLSearchParams(window.location.search); // 保存URL参数
let currentCaptchaAction = null; // 当前极验操作类型：'send_code' 或 'verify_identity'
let lotNumber = null; // 保存第一步的 lot_number，用于二次验证
let verifyCode = null; // 保存用户输入的验证码

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    init();
});

/**
 * 初始化
 */
async function init() {
    // 更新返回登录链接，携带URL参数
    updateBackToLoginLink();
    
    // 初始化人机验证
    await initCaptcha();
    
    // 绑定事件
    bindEvents();
}

/**
 * 更新返回登录链接
 */
function updateBackToLoginLink() {
    const backLink = document.getElementById('back-to-login');
    if (backLink && urlParams.toString()) {
        backLink.href = `../login?${urlParams.toString()}`;
    }
}

/**
 * 初始化人机验证
 */
async function initCaptcha() {
    try {
        console.log('开始初始化人机验证...');
        
        // 获取人机验证配置（使用绝对路径，场景名称改为 reset_password）
        const response = await fetch('/captcha/GetCaptchaConfig.php?scene=reset_password');
        const result = await response.json();
        
        console.log('人机验证配置响应:', result);
        
        if (!result.success || !result.data) {
            console.log('获取人机验证配置失败');
            // 配置获取失败，启用发送按钮（允许无验证发送）
            document.getElementById('btn-send-code').disabled = false;
            return;
        }
        
        // 检查是否启用人机验证
        if (!result.data.enabled) {
            console.log('人机验证未启用');
            // 人机验证未启用时，隐藏人机验证容器
            const captchaContainer = document.getElementById('captcha-container');
            if (captchaContainer) {
                captchaContainer.style.display = 'none';
            }
            // 隐藏人机验证表单组
            const captchaGroup = document.getElementById('captcha-container').closest('.form-group');
            if (captchaGroup) {
                captchaGroup.style.display = 'none';
            }
            // 不需要人机验证，启用发送按钮
            document.getElementById('btn-send-code').disabled = false;
            return;
        }
        
        const config = result.data;
        captchaConfig = config;
        
        console.log('人机验证已启用，服务商:', config.provider);
        
        // 极验绑定在按钮上，隐藏整个人机验证表单组
        if (config.provider === 'geetest') {
            const captchaGroup = document.getElementById('captcha-container').closest('.form-group');
            if (captchaGroup) {
                captchaGroup.style.display = 'none';
            }
        }
        
        // 根据配置加载对应的人机验证 SDK
        if (config.provider === 'geetest') {
            console.log('准备加载极验...');
            await loadGeetestCaptcha(config);
            console.log('极验加载完成');
        } else if (config.provider === 'recaptcha') {
            await loadRecaptchaCaptcha(config);
        } else if (config.provider === 'hcaptcha') {
            await loadHcaptchaCaptcha(config);
        } else if (config.provider === 'turnstile') {
            await loadTurnstileCaptcha(config);
        } else {
            console.error('不支持的人机验证类型:', config.provider);
            // 不支持的类型，启用发送按钮
            document.getElementById('btn-send-code').disabled = false;
        }
    } catch (error) {
        console.error('初始化人机验证失败:', error);
        showError('人机验证加载失败');
        // 加载失败，启用发送按钮（允许无验证发送）
        document.getElementById('btn-send-code').disabled = false;
    }
}

/**
 * 加载 Cloudflare Turnstile
 */
async function loadTurnstileCaptcha(config) {
    await loadScript('https://challenges.cloudflare.com/turnstile/v0/api.js');
    
    // 渲染 Turnstile
    const container = document.getElementById('captcha-container');
    container.innerHTML = '<div id="turnstile-widget"></div>';
    
    // 等待 turnstile 对象加载
    let attempts = 0;
    while (typeof turnstile === 'undefined' && attempts < 50) {
        await new Promise(resolve => setTimeout(resolve, 100));
        attempts++;
    }
    
    if (typeof turnstile === 'undefined') {
        showError('Turnstile 加载失败');
        return;
    }
    
    turnstile.render('#turnstile-widget', {
        sitekey: config.site_key,
        callback: function(token) {
            captchaToken = token;
            document.getElementById('btn-send-code').disabled = false;
        },
        'expired-callback': function() {
            captchaToken = null;
            document.getElementById('btn-send-code').disabled = true;
            showError('人机验证已过期，请重新验证');
        }
    });
}

/**
 * 加载极验人机验证（使用bind模式，手动触发）
 */
async function loadGeetestCaptcha(config) {
    console.log('开始加载极验人机验证...');
    console.log('极验配置:', config);
    
    // 先加载极验 SDK
    await loadScript('https://static.geetest.com/v4/gt4.js');
    console.log('极验 SDK 脚本已添加');
    
    // 等待 initGeetest4 函数可用
    let attempts = 0;
    while (typeof initGeetest4 === 'undefined' && attempts < 50) {
        await new Promise(resolve => setTimeout(resolve, 100));
        attempts++;
    }
    
    if (typeof initGeetest4 === 'undefined') {
        console.error('极验 SDK 加载失败（超时）');
        showError('极验 SDK 加载失败');
        // 加载失败，启用发送按钮
        document.getElementById('btn-send-code').disabled = false;
        return;
    }
    
    console.log('initGeetest4 函数已可用');
    
    // 初始化极验 V4（使用bind模式）
    initGeetest4({
        captchaId: config.captcha_id,
        product: 'bind',  // 使用bind模式，而不是popup
        language: 'zho'
    }, function(captcha) {
        console.log('极验初始化回调被调用');
        captchaInstance = captcha;
        console.log('captchaInstance 已设置');
        
        // 监听验证成功事件
        captcha.onSuccess(function() {
            console.log('极验验证成功回调，当前操作:', currentCaptchaAction);
            const result = captcha.getValidate();
            // 保存完整的验证结果
            captchaToken = {
                lot_number: result.lot_number,
                captcha_output: result.captcha_output,
                pass_token: result.pass_token,
                gen_time: result.gen_time
            };
            console.log('极验验证成功，结果:', captchaToken);
            
            // 根据当前操作类型调用对应的函数
            if (currentCaptchaAction === 'send_code') {
                sendVerificationCodeAfterCaptcha();
            } else if (currentCaptchaAction === 'verify_identity') {
                verifyIdentityAfterCaptcha();
            }
        });
        
        // 监听验证失败事件
        captcha.onError(function(error) {
            captchaToken = null;
            console.error('极验验证失败:', error);
        });
        
        // 监听验证关闭事件
        captcha.onClose(function() {
            captchaToken = null;
            console.log('极验验证已关闭');
        });
        
        // 极验加载完成，启用发送按钮
        const sendBtn = document.getElementById('btn-send-code');
        if (sendBtn) {
            sendBtn.disabled = false;
            console.log('极验初始化完成，按钮已启用');
        }
    });
    
    console.log('initGeetest4 已调用，等待回调');
}

/**
 * 加载 reCAPTCHA（v2版本，显示验证框）
 */
async function loadRecaptchaCaptcha(config) {
    try {
        // 确保 site_key 存在
        if (!config.site_key) {
            console.error('reCAPTCHA site_key 未配置');
            showError('reCAPTCHA 配置错误');
            document.getElementById('btn-send-code').disabled = false;
            return;
        }
        
        console.log('加载 reCAPTCHA v2，site_key:', config.site_key);
        
        // 使用 recaptcha.net 域名（适应中国大陆用户）
        // reCAPTCHA v2 不需要 render 参数
        await loadScript('https://www.recaptcha.net/recaptcha/api.js');
        
        // 等待 grecaptcha 对象加载（最多等待 10 秒）
        let attempts = 0;
        const maxAttempts = 100;
        
        while (attempts < maxAttempts) {
            if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.ready === 'function') {
                break;
            }
            await new Promise(resolve => setTimeout(resolve, 100));
            attempts++;
        }
        
        // 检查 grecaptcha 对象是否存在
        if (typeof grecaptcha === 'undefined') {
            console.error('reCAPTCHA SDK 未加载（超时）');
            showError('reCAPTCHA 加载失败');
            document.getElementById('btn-send-code').disabled = false;
            return;
        }
        
        // 检查 grecaptcha.ready 方法是否可用
        if (typeof grecaptcha.ready !== 'function') {
            console.error('reCAPTCHA SDK 加载不完整');
            showError('reCAPTCHA 加载失败');
            document.getElementById('btn-send-code').disabled = false;
            return;
        }
        
        console.log('reCAPTCHA SDK 已完全加载');
        
        // 等待 grecaptcha.render 方法可用
        await grecaptcha.ready(async function() {
            const container = document.getElementById('captcha-container');
            if (container) {
                console.log('渲染 reCAPTCHA 到容器');
                
                // 清空容器
                container.innerHTML = '';
                
                // 渲染 reCAPTCHA v2
                try {
                    grecaptcha.render('captcha-container', {
                        'sitekey': config.site_key,
                        'theme': 'light',
                        'callback': function(token) {
                            captchaToken = token;
                            console.log('reCAPTCHA 验证成功');
                            document.getElementById('btn-send-code').disabled = false;
                        },
                        'expired-callback': function() {
                            captchaToken = null;
                            console.log('reCAPTCHA 验证已过期');
                            document.getElementById('btn-send-code').disabled = true;
                            showError('人机验证已过期，请重新验证');
                        },
                        'error-callback': function() {
                            captchaToken = null;
                            console.error('reCAPTCHA 验证失败');
                            document.getElementById('btn-send-code').disabled = true;
                        }
                    });
                    console.log('reCAPTCHA 渲染成功');
                } catch (renderError) {
                    console.error('reCAPTCHA 渲染失败:', renderError);
                    showError('reCAPTCHA 渲染失败');
                    document.getElementById('btn-send-code').disabled = false;
                }
            } else {
                console.warn('容器不存在: captcha-container');
            }
        });
        
        console.log('reCAPTCHA 初始化完成');
        
    } catch (error) {
        console.error('加载 reCAPTCHA 失败:', error);
        showError('reCAPTCHA 加载失败：' + error.message);
        document.getElementById('btn-send-code').disabled = false;
    }
}

/**
 * 加载 hCaptcha
 */
async function loadHcaptchaCaptcha(config) {
    await loadScript('https://js.hcaptcha.com/1/api.js');
    
    // 渲染 hCaptcha
    const container = document.getElementById('captcha-container');
    container.innerHTML = '<div id="hcaptcha-widget"></div>';
    
    // 等待 hcaptcha 对象加载
    let attempts = 0;
    while (typeof hcaptcha === 'undefined' && attempts < 50) {
        await new Promise(resolve => setTimeout(resolve, 100));
        attempts++;
    }
    
    if (typeof hcaptcha === 'undefined') {
        showError('hCaptcha 加载失败');
        return;
    }
    
    hcaptcha.render('hcaptcha-widget', {
        sitekey: config.site_key,
        callback: function(token) {
            captchaToken = token;
            document.getElementById('btn-send-code').disabled = false;
        },
        'expired-callback': function() {
            captchaToken = null;
            document.getElementById('btn-send-code').disabled = true;
            showError('人机验证已过期，请重新验证');
        }
    });
}

/**
 * 加载外部脚本
 */
function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

/**
 * 绑定事件
 */
function bindEvents() {
    // 发送验证码
    document.getElementById('btn-send-code').addEventListener('click', sendVerificationCode);
    
    // 验证身份
    document.getElementById('btn-verify').addEventListener('click', verifyIdentity);
    
    // 重置密码
    document.getElementById('btn-reset').addEventListener('click', resetPassword);
    
    // 返回上一步
    document.getElementById('btn-back-step-1').addEventListener('click', () => goToStep(1));
    
    // 前往登录
    document.getElementById('btn-go-login').addEventListener('click', () => {
        // 携带URL参数跳转到登录页
        if (urlParams.toString()) {
            window.location.href = `../login?${urlParams.toString()}`;
        } else {
            window.location.href = '../login';
        }
    });
    
    // 密码显示/隐藏切换
    document.querySelectorAll('.btn-toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = this.previousElementSibling;
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
    
    // 密码强度检测
    document.getElementById('new-password').addEventListener('input', function() {
        checkPasswordStrength(this.value);
    });
}

/**
 * 发送验证码
 */
async function sendVerificationCode() {
    const account = document.getElementById('account').value.trim();
    
    if (!account) {
        showError('请输入手机号或邮箱');
        return;
    }
    
    // 判断账号类型
    if (/^1[3-9]\d{9}$/.test(account)) {
        accountType = 'phone';
    } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(account)) {
        accountType = 'email';
    } else {
        showError('请输入正确的手机号或邮箱');
        return;
    }
    
    accountValue = account;
    
    console.log('发送验证码 - captchaConfig:', captchaConfig);
    console.log('发送验证码 - captchaInstance:', captchaInstance);
    
    // 如果启用了极验，触发极验验证
    if (captchaConfig && captchaConfig.provider === 'geetest') {
        console.log('检测到极验模式');
        if (!captchaInstance) {
            console.error('极验实例不存在');
            showError('人机验证服务正在加载中，请稍后再试', '提示');
            
            // 尝试重新初始化极验
            await loadGeetestCaptcha(captchaConfig);
            
            // 等待1秒后再次检查
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            if (!captchaInstance) {
                showError('人机验证服务未就绪，请刷新页面重试', '验证失败');
                return;
            }
        }
        
        console.log('准备显示极验验证窗口');
        try {
            // 重置验证结果
            captchaToken = null;
            
            // 设置当前操作为发送验证码
            currentCaptchaAction = 'send_code';
            
            // 显示极验验证窗口
            captchaInstance.showCaptcha();
            console.log('已调用 showCaptcha()');
            
            // 等待验证完成（通过 onSuccess 回调自动调用 sendVerificationCodeAfterCaptcha）
            return;
        } catch (error) {
            console.error('调用 showCaptcha() 失败:', error);
            showError('人机验证显示失败：' + error.message);
            return;
        }
    }
    
    console.log('非极验模式，直接发送');
    // 非极验模式，直接发送
    await sendVerificationCodeAfterCaptcha();
}

/**
 * 验证通过后发送验证码
 */
async function sendVerificationCodeAfterCaptcha() {
    const btn = document.getElementById('btn-send-code');
    
    // 禁用按钮
    btn.disabled = true;
    const originalText = btn.querySelector('span').textContent;
    btn.querySelector('span').textContent = '发送中...';
    
    try {
        // 构建请求数据
        const requestData = {
            account: accountValue,
            account_type: accountType
        };
        
        // 根据验证类型传递不同的字段
        if (captchaToken) {
            if (captchaConfig && captchaConfig.provider === 'geetest') {
                // 极验：传递完整的验证对象
                requestData.captcha_token = captchaToken;
                console.log('发送极验数据:', captchaToken);
            } else if (captchaConfig && captchaConfig.provider === 'turnstile') {
                // Turnstile：传递 turnstile_token 字段
                requestData.captcha_token = {
                    turnstile_token: captchaToken
                };
                console.log('发送Turnstile数据:', captchaToken);
            } else if (captchaConfig && captchaConfig.provider === 'recaptcha') {
                // reCAPTCHA：传递 recaptcha_token 字段
                requestData.captcha_token = {
                    recaptcha_token: captchaToken
                };
                console.log('发送reCAPTCHA数据:', captchaToken);
            } else if (captchaConfig && captchaConfig.provider === 'hcaptcha') {
                // hCaptcha：传递 hcaptcha_token 字段
                requestData.captcha_token = {
                    hcaptcha_token: captchaToken
                };
                console.log('发送hCaptcha数据:', captchaToken);
            } else {
                // 其他情况，直接传递
                requestData.captcha_token = captchaToken;
            }
        }
        
        console.log('发送请求数据:', requestData);
        
        const response = await fetch('api/ForgotPasswordSendCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestData)
        });
        
        const result = await response.json();
        console.log('API响应:', result);
        
        if (result.success) {
            showSuccess(result.message || '验证码已发送');
            
            // 保存 code_id 用于验证
            if (result.data && result.data.code_id) {
                btn.setAttribute('data-code-id', result.data.code_id);
            }
            
            // 保存 lot_number 用于二次验证（如果有）
            if (result.data && result.data.lot_number) {
                lotNumber = result.data.lot_number;
                console.log('保存 lot_number 用于二次验证:', lotNumber);
            }
            
            // 开始倒计时
            let countdown = 60;
            btn.querySelector('span').textContent = `${countdown}秒后重试`;
            
            sendCodeTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    btn.querySelector('span').textContent = `${countdown}秒后重试`;
                } else {
                    clearInterval(sendCodeTimer);
                    sendCodeTimer = null;
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            }, 1000);
        } else {
            showError(result.message || '发送失败');
            btn.disabled = false;
            btn.querySelector('span').textContent = originalText;
            
            // 重置人机验证
            resetCaptcha();
        }
    } catch (error) {
        console.error('发送验证码失败:', error);
        showError('发送失败，请稍后重试');
        btn.disabled = false;
        btn.querySelector('span').textContent = originalText;
    }
}

/**
 * 验证身份
 */
async function verifyIdentity() {
    const account = document.getElementById('account').value.trim();
    const code = document.getElementById('verify-code').value.trim();
    const btn = document.getElementById('btn-verify');
    
    if (!account) {
        showError('请输入手机号或邮箱');
        return;
    }
    
    if (!code) {
        showError('请输入验证码');
        return;
    }
    
    if (code.length !== 6) {
        showError('验证码格式不正确');
        return;
    }
    
    // 保存验证码
    verifyCode = code;
    
    // 如果有 lot_number，使用二次验证（无需弹窗）
    if (lotNumber) {
        console.log('使用二次验证，lot_number:', lotNumber);
        await verifyIdentityWithLotNumber();
        return;
    }
    
    // 如果启用了极验但没有 lot_number，需要重新触发验证
    if (captchaConfig && captchaConfig.provider === 'geetest') {
        console.log('检测到极验模式，需要重新验证');
        if (!captchaInstance) {
            showError('人机验证服务未就绪，请刷新页面重试');
            return;
        }
        
        // 重置验证结果
        captchaToken = null;
        
        // 设置当前操作为验证身份
        currentCaptchaAction = 'verify_identity';
        
        // 显示极验验证窗口
        try {
            captchaInstance.showCaptcha();
            console.log('已调用 showCaptcha()，等待用户完成验证');
            
            // 等待验证完成后自动调用 verifyIdentityAfterCaptcha
            return;
        } catch (error) {
            console.error('调用 showCaptcha() 失败:', error);
            showError('人机验证显示失败：' + error.message);
            return;
        }
    }
    
    // 非极验模式或已有token，直接验证
    await verifyIdentityAfterCaptcha();
}

/**
 * 使用 lot_number 进行二次验证
 */
async function verifyIdentityWithLotNumber() {
    const account = document.getElementById('account').value.trim();
    const btn = document.getElementById('btn-verify');
    
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';
    
    try {
        // 构建请求数据，使用 lot_number 进行二次验证
        const requestData = {
            account: account,
            account_type: accountType,
            code: verifyCode,
            lot_number: lotNumber  // 传递 lot_number 进行二次验证
        };
        
        console.log('验证身份（二次验证） - 请求数据:', requestData);
        
        const response = await fetch('api/ForgotPasswordVerifyCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestData)
        });
        
        const result = await response.json();
        console.log('验证身份（二次验证） - API响应:', result);
        
        if (result.success) {
            showSuccess('验证成功');
            // 进入下一步
            setTimeout(() => {
                goToStep(2);
            }, 500);
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
 * 人机验证通过后执行验证身份
 */
async function verifyIdentityAfterCaptcha() {
    const account = document.getElementById('account').value.trim();
    const btn = document.getElementById('btn-verify');
    
    // 验证身份时需要人机验证token
    if (!captchaToken) {
        showError('请先完成人机验证');
        return;
    }
    
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';
    
    try {
        // 构建请求数据
        const requestData = {
            account: account,
            account_type: accountType,
            code: verifyCode
        };
        
        // 根据验证类型传递不同的字段
        if (captchaConfig && captchaConfig.provider === 'geetest') {
            // 极验：传递完整的验证对象
            requestData.captcha_token = captchaToken;
        } else if (captchaConfig && captchaConfig.provider === 'turnstile') {
            // Turnstile：传递 turnstile_token 字段
            requestData.captcha_token = {
                turnstile_token: captchaToken
            };
        } else if (captchaConfig && captchaConfig.provider === 'recaptcha') {
            // reCAPTCHA：传递 recaptcha_token 字段
            requestData.captcha_token = {
                recaptcha_token: captchaToken
            };
        } else if (captchaConfig && captchaConfig.provider === 'hcaptcha') {
            // hCaptcha：传递 hcaptcha_token 字段
            requestData.captcha_token = {
                hcaptcha_token: captchaToken
            };
        } else {
            // 其他情况，直接传递
            requestData.captcha_token = captchaToken;
        }
        
        console.log('验证身份 - 请求数据:', requestData);
        
        const response = await fetch('api/ForgotPasswordVerifyCode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestData)
        });
        
        const result = await response.json();
        console.log('验证身份 - API响应:', result);
        
        if (result.success) {
            showSuccess('验证成功');
            // 进入下一步
            setTimeout(() => {
                goToStep(2);
            }, 500);
        } else {
            showError(result.message || '验证失败');
            // 重置人机验证
            resetCaptcha();
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
 * 重置密码
 */
async function resetPassword() {
    const newPassword = document.getElementById('new-password').value.trim();
    const confirmPassword = document.getElementById('confirm-password').value.trim();
    const btn = document.getElementById('btn-reset');
    
    if (!newPassword) {
        showError('请输入新密码');
        return;
    }
    
    if (newPassword.length < 8 || newPassword.length > 20) {
        showError('密码长度为8-20位');
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
    
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 重置中...';
    
    try {
        const response = await fetch('api/ForgotPasswordReset.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                account: accountValue,
                account_type: accountType,
                code: verifyCode,  // 传递验证码用于再次验证
                new_password: newPassword
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('密码重置成功');
            // 进入完成页面
            setTimeout(() => {
                goToStep(3);
            }, 500);
        } else {
            showError(result.message || '重置失败');
        }
    } catch (error) {
        console.error('重置密码失败:', error);
        showError('重置失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

/**
 * 切换步骤
 */
function goToStep(step) {
    currentStep = step;
    
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
    
    // 显示/隐藏对应的步骤内容
    document.querySelectorAll('.step-content').forEach((content, index) => {
        if (index + 1 === step) {
            content.classList.add('active');
        } else {
            content.classList.remove('active');
        }
    });
}

/**
 * 检查密码强度
 */
function checkPasswordStrength(password) {
    const strengthFill = document.querySelector('.strength-fill');
    const strengthText = document.querySelector('.strength-text');
    
    if (!password) {
        strengthFill.className = 'strength-fill';
        strengthText.textContent = '密码强度：弱';
        return;
    }
    
    let strength = 0;
    
    // 长度
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    
    // 包含小写字母
    if (/[a-z]/.test(password)) strength++;
    
    // 包含大写字母
    if (/[A-Z]/.test(password)) strength++;
    
    // 包含数字
    if (/\d/.test(password)) strength++;
    
    // 包含特殊字符
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    // 根据强度设置样式
    if (strength <= 2) {
        strengthFill.className = 'strength-fill weak';
        strengthText.textContent = '密码强度：弱';
    } else if (strength <= 4) {
        strengthFill.className = 'strength-fill medium';
        strengthText.textContent = '密码强度：中';
    } else {
        strengthFill.className = 'strength-fill strong';
        strengthText.textContent = '密码强度：强';
    }
}

/**
 * 重置人机验证
 */
function resetCaptcha() {
    captchaToken = null;
    
    if (captchaInstance && captchaInstance.reset) {
        captchaInstance.reset();
    }
    
    // 如果是 hCaptcha，重置
    if (typeof hcaptcha !== 'undefined') {
        hcaptcha.reset();
    }
}

/**
 * 显示成功提示
 */
function showSuccess(message, title = '成功') {
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
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}

/**
 * 显示错误提示
 */
function showError(message, title = '错误') {
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
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
}
