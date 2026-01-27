// 一碗小米周授权登录平台 - 主脚本文件

document.addEventListener('DOMContentLoaded', function() {
    // 初始化
    init();
});

function init() {
    // 添加卡片点击事件
    initCardClickEvents();
    
    // 添加导航链接平滑滚动
    initSmoothScroll();
    
    console.log('一碗小米周授权登录平台已加载');
}

// 初始化卡片点击事件
function initCardClickEvents() {
    const methodCards = document.querySelectorAll('.method-card');
    
    methodCards.forEach(card => {
        card.addEventListener('click', function() {
            const methodName = this.querySelector('h3').textContent;
            console.log(`点击了登录方式: ${methodName}`);
            // 这里可以添加跳转到具体登录页面的逻辑
            // window.location.href = `/login/${methodName}`;
        });
    });
}

// 初始化平滑滚动
function initSmoothScroll() {
    const navLinks = document.querySelectorAll('.nav-links a, .footer-links a');
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // 如果是锚点链接
            if (href.startsWith('#')) {
                e.preventDefault();
                const targetId = href.substring(1);
                const targetElement = document.getElementById(targetId);
                
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
}
