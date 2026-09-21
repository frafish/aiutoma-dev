document.addEventListener('DOMContentLoaded', function () {
    const toggleSafeModeBtn = document.getElementById('aiutoma-toggle-safe-mode');
    const getNonce = () => (window.aiutomaSettings && window.aiutomaSettings.nonceRest) ? window.aiutomaSettings.nonceRest : '';
    const getRestBase = () => {
        if (window.aiutomaSettings && window.aiutomaSettings.restUrl) {
            return window.aiutomaSettings.restUrl.replace(/ai-chat.*$/, '');
        }
        return '/wp-json/aiutoma/v1/';
    };

    if (toggleSafeModeBtn) {
        toggleSafeModeBtn.addEventListener('click', async function () {
            const originalTitle = toggleSafeModeBtn.title;
            toggleSafeModeBtn.title = 'Toggling...';
            toggleSafeModeBtn.style.opacity = '0.7';

            try {
                const isCurrentlyActive = toggleSafeModeBtn.dataset.active === '1';
                const actionForce = isCurrentlyActive ? 'disable' : 'enable';
                const toggleUrl = getRestBase() + 'toggle-safe-mode';

                const response = await fetch(toggleUrl, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': getNonce(),
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ force: actionForce })
                });

                const data = await response.json();
                if (data && data.success) {
                    if (data.safe_mode) {
                        toggleSafeModeBtn.classList.add('aiutoma-safe-mode-active');
                        toggleSafeModeBtn.dataset.active = '1';
                        const statusEl = document.getElementById('aiutoma-safemode-status');
                        if (statusEl) statusEl.innerText = 'Strict Safe Mode Enforced (.aiutoma_safe)';
                    } else {
                        toggleSafeModeBtn.classList.remove('aiutoma-safe-mode-active');
                        toggleSafeModeBtn.dataset.active = '0';
                        const statusEl = document.getElementById('aiutoma-safemode-status');
                        if (statusEl) statusEl.innerText = 'Native (All Plugins Active)';
                    }
                }
            } catch (err) {
                console.error('[Aiutoma Dev] Error toggling safe mode:', err);
            } finally {
                toggleSafeModeBtn.title = originalTitle;
                toggleSafeModeBtn.style.opacity = '1';
            }
        });
    }

    // Auto-recovery on site error
    document.addEventListener('aiutoma:site_error', async function (e) {
        if (!toggleSafeModeBtn || toggleSafeModeBtn.dataset.active === '1') {
            return;
        }

        try {
            const toggleUrl = getRestBase() + 'toggle-safe-mode';
            const response = await fetch(toggleUrl, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': getNonce(),
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ force: 'enable' })
            });

            const data = await response.json();
            if (data && data.success && data.safe_mode) {
                toggleSafeModeBtn.classList.add('aiutoma-safe-mode-active');
                toggleSafeModeBtn.dataset.active = '1';
                const statusEl = document.getElementById('aiutoma-safemode-status');
                if (statusEl) statusEl.innerText = 'Strict Safe Mode Enforced (Auto-Recovered)';

                const chatEl = document.getElementById('aiutoma-playground-chat');
                if (chatEl) {
                    chatEl.insertAdjacentHTML('beforeend', '<div class="aiutoma-msg-tool-result aiutoma-safe-mode-notice"><strong>Aiutoma Dev:</strong> Safe Mode has been activated automatically to isolate site errors.</div>');
                    chatEl.scrollTop = chatEl.scrollHeight;
                }
            }
        } catch (err) {
            console.error('[Aiutoma Dev] Error during auto-recovery safe mode activation:', err);
        }
    });
});

