</div><!-- /.main -->
<script>
// 1. Service Worker Registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').then(function(registration) {
            console.log('ServiceWorker registration successful with scope: ', registration.scope);
            
            // Auto reload when new service worker is found and installed
            registration.addEventListener('updatefound', function() {
                var newWorker = registration.installing;
                if (newWorker) {
                    newWorker.addEventListener('statechange', function() {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('New Service Worker detected, forcing reload...');
                            window.location.reload();
                        }
                    });
                }
            });
        }, function(err) {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
}

// 2. PWA Connection Status Indicator
function updatePwaOnlineStatus() {
    var pill = document.getElementById('pwa-status-pill');
    var txt = document.getElementById('pwa-status-text');
    if (!pill || !txt) return;

    if (navigator.onLine) {
        pill.style.background = 'rgba(61,107,53,0.12)';
        pill.style.borderColor = 'rgba(61,107,53,0.25)';
        pill.style.color = 'var(--leaf)';
        txt.textContent = 'Online';
        
        // Hide offline warnings if they exist on the page
        var warn = document.getElementById('pwa-offline-warning');
        if (warn) warn.style.display = 'none';
    } else {
        pill.style.background = 'rgba(192,57,43,0.12)';
        pill.style.borderColor = 'rgba(192,57,43,0.25)';
        pill.style.color = 'var(--danger)';
        txt.textContent = 'Offline';
        
        // Show offline warnings if they exist on the page
        var warn = document.getElementById('pwa-offline-warning');
        if (warn) warn.style.display = 'flex';
    }
}
window.addEventListener('online', updatePwaOnlineStatus);
window.addEventListener('offline', updatePwaOnlineStatus);
updatePwaOnlineStatus(); // run initial check

// 3. Custom PWA Install Prompt
var deferredPrompt;
var installBtn = document.getElementById('pwa-install-btn');
window.addEventListener('beforeinstallprompt', function(e) {
    // Prevent Chrome 67 and earlier from automatically showing the prompt
    e.preventDefault();
    // Stash the event so it can be triggered later.
    deferredPrompt = e;
    // Update UI to notify the user they can install the PWA
    if (installBtn) installBtn.style.display = 'block';
});

if (installBtn) {
    installBtn.addEventListener('click', function() {
        if (!deferredPrompt) return;
        // Show the prompt
        deferredPrompt.prompt();
        // Wait for the user to respond to the prompt
        deferredPrompt.userChoice.then(function(choiceResult) {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
            } else {
                console.log('User dismissed the install prompt');
            }
            deferredPrompt = null;
            installBtn.style.display = 'none';
        });
    });
}
window.addEventListener('appinstalled', function() {
    console.log('Flowork was successfully installed!');
    if (installBtn) installBtn.style.display = 'none';
});

// 4. Web Push Notification Permissions
if ('Notification' in window) {
    window.addEventListener('DOMContentLoaded', function() {
        if (Notification.permission === 'default') {
            // Suggest enabling notifications after some interaction
            setTimeout(function() {
                Notification.requestPermission().then(function(permission) {
                    if (permission === 'granted') {
                        console.log('Notification permission granted.');
                    }
                });
            }, 5000);
        }
    });
}

// 5. Ambient Light & Sunset Dark Theme Scheduler
(function() {
    if (document.cookie.indexOf('flowork_theme=') === -1) {
        var hour = new Date().getHours();
        var isNight = (hour >= 18 || hour < 6);
        var targetTheme = isNight ? 'dark' : 'light';
        document.cookie = "flowork_theme=" + targetTheme + "; max-age=" + (60*60*24*365) + "; path=/";
        document.body.className = document.body.className.replace(/theme-(dark|light)/g, '') + ' theme-' + targetTheme;
    }
})();

// 6. Offline Form submission capture & Queue Sync
(function() {
    var queueKey = 'fw_offline_sync_queue';
    
    function updateSyncQueueBadge() {
        var queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
        var badge = document.getElementById('pwa-sync-queue-badge');
        var count = document.getElementById('pwa-sync-queue-count');
        if (badge && count) {
            if (queue.length > 0) {
                badge.style.display = 'inline-flex';
                count.textContent = queue.length;
            } else {
                badge.style.display = 'none';
            }
        }
    }
    
    // Auto-sync when returning online
    window.addEventListener('online', function() {
        showToastNotification('🗲 Connected', 'Connection restored! Auto-synchronizing offline actions...');
        triggerManualSync();
    });

    window.triggerManualSync = function() {
        var queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
        if (queue.length === 0) return;
        
        var badge = document.getElementById('pwa-sync-queue-badge');
        if (badge) badge.style.opacity = '0.6';
        
        var promises = queue.map(function(item) {
            var searchParams = new URLSearchParams();
            for (var key in item.data) {
                searchParams.append(key, item.data[key]);
            }
            return fetch(item.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: searchParams.toString()
            }).then(function(res) {
                if (res.ok) return item.id;
                throw new Error('Sync failed');
            });
        });
        
        Promise.allSettled(promises).then(function(results) {
            var succeededIds = results.filter(function(r) { return r.status === 'fulfilled'; }).map(function(r) { return r.value; });
            var remainingQueue = queue.filter(function(item) { return !succeededIds.includes(item.id); });
            localStorage.setItem(queueKey, JSON.stringify(remainingQueue));
            updateSyncQueueBadge();
            if (badge) badge.style.opacity = '1';
            
            if (succeededIds.length > 0) {
                showToastNotification('🗲 Sync Complete', 'Successfully uploaded ' + succeededIds.length + ' offline submissions!');
            }
        });
    };

    // Intercept form submissions
    document.addEventListener('submit', function(e) {
        if (!navigator.onLine) {
            var form = e.target;
            var action = form.getAttribute('action') || window.location.href;
            
            // Limit intercepting to scale and booking pages to protect state integrity
            var isScale = window.location.pathname.includes('scale.php') || action.includes('scale.php');
            var isBooking = window.location.pathname.includes('grower_booking.php') || action.includes('grower_booking.php');
            
            if (isScale || isBooking) {
                e.preventDefault();
                var formData = {};
                var fd = new FormData(form);
                fd.forEach(function(val, key) {
                    formData[key] = val;
                });
                
                var queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
                queue.push({
                    id: Date.now(),
                    url: action,
                    data: formData,
                    page: window.location.pathname
                });
                localStorage.setItem(queueKey, JSON.stringify(queue));
                updateSyncQueueBadge();
                
                showToastNotification('🗲 Queued Offline', 'Saved to local queue. Will auto-sync when online.');
                
                // If it is booking page, clear the form to let user make next booking
                if (isBooking) {
                    form.reset();
                }
            }
        }
    });

    updateSyncQueueBadge();
})();

// 7. Toast Notifications System
window.showToastNotification = function(title, text) {
    var toast = document.createElement('div');
    toast.className = 'pwa-toast';
    toast.innerHTML = '<strong>' + title + '</strong><span style="font-size:0.75rem;opacity:0.8">' + text + '</span>';
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.transform = 'translateY(20px)';
        toast.style.opacity = '0';
        setTimeout(function() { document.body.removeChild(toast); }, 300);
    }, 4000);
};

// 7.5 printCard layout iframe helper
window.printCard = function(cardId, title) {
    var card = document.getElementById(cardId);
    if (!card) return;
    
    var iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);
    
    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<html><head><title>' + title + '</title>');
    
    // Copy stylesheets
    document.querySelectorAll('link[rel="stylesheet"]').forEach(function(link) {
        doc.write('<link rel="stylesheet" href="' + link.href + '">');
    });
    
    doc.write('<style>');
    doc.write('body { font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif; background: #fff; color: #000; padding: 20px; }');
    doc.write('.dash-card-head button, .btn { display: none !important; }');
    doc.write('table { width: 100%; border-collapse: collapse; margin-top: 15px; }');
    doc.write('th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }');
    doc.write('th { background: #f5f5f5; font-weight: bold; }');
    doc.write('h3 { font-family: "Bebas Neue", sans-serif; font-size: 1.5rem; border-bottom: 2px solid #1a1208; padding-bottom: 8px; margin: 0; }');
    doc.write('</style></head><body>');
    doc.write('<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #c8952a; padding-bottom: 10px;">');
    doc.write('  <div><h2 style="margin:0;color:#c8952a;font-family:\'Bebas Neue\',sans-serif;font-size:1.6rem;letter-spacing:0.06em">FLOWORK TOBACCO</h2><p style="font-size:0.75rem;color:#666;margin:2px 0 0 0;">Report Export · ' + new Date().toLocaleDateString() + '</p></div>');
    doc.write('</div>');
    
    var clone = card.cloneNode(true);
    var canvases = card.querySelectorAll('canvas');
    var clonedCanvases = clone.querySelectorAll('canvas');
    canvases.forEach(function(canvas, index) {
        var img = doc.createElement('img');
        try {
            img.src = canvas.toDataURL('image/png');
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            var clonedCanvas = clonedCanvases[index];
            clonedCanvas.parentNode.replaceChild(img, clonedCanvas);
        } catch (e) {
            console.error('Failed to convert canvas to image', e);
        }
    });
    
    doc.write(clone.innerHTML);
    doc.write('</body></html>');
    doc.close();
    
    iframe.contentWindow.focus();
    setTimeout(function() {
        iframe.contentWindow.print();
        document.body.removeChild(iframe);
    }, 500);
};

// 8. Command Palette Logic
(function() {
    var activeIndex = -1;
    var staticPages = [
        { title: 'Dashboard - Overview', desc: 'Main dashboard overview statistics', url: 'dashboard.php?tab=overview', icon: '⚡', keys: 'G D' },
        { title: 'Dashboard - Operations', desc: 'Floor bale weighing and queue metrics', url: 'dashboard.php?tab=operations', icon: '⚙️' },
        { title: 'Dashboard - Analytics Suite', desc: 'Visual charts, buyer share & heatmap', url: 'dashboard.php?tab=analytics', icon: '📊', keys: 'G A' },
        { title: 'Notification Tester', desc: 'Trigger test SSE real-time alerts', url: 'trigger_notification.php', icon: '🔔' },
        { title: 'Selling Floor', desc: 'Live bidding and sales transactions', url: 'selling.php', icon: '🔨', keys: 'G S' },
        { title: 'Balance & Payout', desc: 'Process grower credit balances', url: 'balance.php', icon: '💰', keys: 'G B' },
        { title: 'Reports Suite', desc: 'Export and print floor operations', url: 'reports.php', icon: '📋', keys: 'G R' },
        { title: 'Price Forecast', desc: 'Tobacco price trend analytics', url: 'analytics.php', icon: '📈', keys: 'G P' },
        { title: 'Scale Weighing', desc: 'Weigh incoming grower bales', url: 'scale.php', icon: '⚖️', keys: 'G W' },
        { title: 'Bale Junus', desc: 'Ticket processing and catalog classification', url: 'junus.php', icon: '🏷️', keys: 'G J' },
        { title: 'Print Tickets', desc: 'Reprint floor ticket labels', url: 'tickets.php', icon: '🎫', keys: 'G T' },
        { title: 'Sync Monitor', desc: 'Queue logs and db backups', url: 'sync_monitor.php', icon: '🖥️', keys: 'G M' },
        { title: 'System Settings', desc: 'Configure system defaults', url: 'settings.php', icon: '⚙️', keys: 'G O' }
    ];

    window.toggleCommandPalette = function() {
        var palette = document.getElementById('command-palette');
        var input = document.getElementById('palette-input');
        var results = document.getElementById('palette-results');
        if (!palette) return;
        
        var isHidden = palette.style.display === 'none' || palette.style.display === '';
        palette.style.display = isHidden ? 'flex' : 'none';
        if (isHidden) {
            if (input && results) {
                input.value = '';
                renderResults(results, staticPages);
                input.focus();
            }
        }
    };

    window.addEventListener('keydown', function(e) {
        var palette = document.getElementById('command-palette');
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            toggleCommandPalette();
        }
        if (e.key === 'Escape' && palette && palette.style.display === 'flex') {
            toggleCommandPalette();
        }
    });

    window.addEventListener('DOMContentLoaded', function() {
        var palette = document.getElementById('command-palette');
        var input = document.getElementById('palette-input');
        var results = document.getElementById('palette-results');
        
        if (palette) {
            palette.addEventListener('click', function(e) {
                if (e.target === palette) toggleCommandPalette();
            });
        }

        if (input && results) {
            input.addEventListener('input', function() {
                var val = input.value.trim().toLowerCase();
                if (val === '') {
                    renderResults(results, staticPages);
                    return;
                }

                var filtered = staticPages.filter(function(p) {
                    return p.title.toLowerCase().includes(val) || p.desc.toLowerCase().includes(val);
                });

                if (val.length >= 2) {
                    fetch('grower_search_api.php?q=' + encodeURIComponent(val))
                        .then(function(res) { return res.json(); })
                        .then(function(growers) {
                            var growerItems = growers.map(function(g) {
                                return {
                                    title: g.name,
                                    desc: 'Grower No: ' + g.grower_num + ' · View Portal',
                                    url: 'grower_portal.php?gnum=' + g.grower_num,
                                    icon: '👤'
                                };
                            });
                            renderResults(results, filtered.concat(growerItems));
                        }).catch(function(err) {
                            renderResults(results, filtered);
                        });
                } else {
                    renderResults(results, filtered);
                }
            });

            input.addEventListener('keydown', function(e) {
                var items = results.querySelectorAll('.palette-item');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = (activeIndex + 1) % items.length;
                    updateSelection(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    updateSelection(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIndex >= 0 && activeIndex < items.length) {
                        items[activeIndex].click();
                    }
                }
            });
        }
    });

    function updateSelection(items) {
        items.forEach(function(el, i) {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function renderResults(resultsEl, list) {
        if (!resultsEl) return;
        resultsEl.innerHTML = '';
        activeIndex = -1;

        if (list.length === 0) {
            resultsEl.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:0.8rem">No commands or growers found.</div>';
            return;
        }

        var hasGrowers = list.some(function(item) { return item.desc.includes('Grower No'); });
        var hasPages = list.some(function(item) { return !item.desc.includes('Grower No'); });

        if (hasPages) {
            var ph = document.createElement('div');
            ph.className = 'palette-header';
            ph.textContent = 'Navigation & Shortcuts';
            resultsEl.appendChild(ph);

            list.forEach(function(item) {
                if (item.desc.includes('Grower No')) return;
                resultsEl.appendChild(createItemEl(item));
            });
        }

        if (hasGrowers) {
            var gh = document.createElement('div');
            gh.className = 'palette-header';
            gh.textContent = 'Matching Growers';
            resultsEl.appendChild(gh);

            list.forEach(function(item) {
                if (!item.desc.includes('Grower No')) return;
                resultsEl.appendChild(createItemEl(item));
            });
        }
    }

    function createItemEl(item) {
        var a = document.createElement('a');
        a.className = 'palette-item';
        a.href = item.url;
        
        var iconHtml = '<div class="palette-item-icon">' + item.icon + '</div>';
        var textHtml = '<div class="palette-item-info"><div class="palette-item-title">' + item.title + '</div><div class="palette-item-desc">' + item.desc + '</div></div>';
        var kbdHtml = item.keys ? '<kbd class="palette-item-kbd">' + item.keys + '</kbd>' : '';
        
        a.innerHTML = iconHtml + textHtml + kbdHtml;

        // Prevent page reload if we are already on dashboard and navigating to a dashboard tab
        a.addEventListener('click', function(e) {
            var url = item.url;
            var isDashboard = window.location.pathname.indexOf('dashboard.php') !== -1 || window.location.pathname.slice(-1) === '/';
            if (isDashboard && url.indexOf('dashboard.php?tab=') !== -1) {
                var tabName = url.split('tab=')[1];
                var tabBtn = document.querySelector('.tab-btn[data-tab="' + tabName + '"]');
                if (tabBtn) {
                    e.preventDefault();
                    tabBtn.click();
                    toggleCommandPalette();
                }
            }
        });

        return a;
    }
})();

// 9. Links load pages normally. A "seamless" loader used to fetch the next page and
// swap only #main-content, but page scripts that set up on DOMContentLoaded never
// ran again and const/let declarations clashed on re-run, so many pages stayed
// unresponsive until reloaded.
</script>

<!-- Command Palette Modal overlay -->
<div id="command-palette" class="palette-overlay" style="display:none">
  <div class="palette-container">
    <div class="palette-search-wrap">
      <span style="font-size:1.1rem;opacity:0.7">🔍</span>
      <input type="text" id="palette-input" class="palette-search-input" placeholder="Search pages, commands, or grower numbers..." autocomplete="off">
    </div>
    <div id="palette-results" class="palette-results"></div>
  </div>
</div>

<style>
/* ── Command Palette styling ── */
.palette-overlay {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(26, 18, 8, 0.4);
  backdrop-filter: blur(8px);
  z-index: 10000;
  align-items: flex-start;
  justify-content: center;
  padding-top: 15vh;
}
.palette-container {
  width: 90%;
  max-width: 580px;
  background: var(--cream);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.15), 0 0 0 1px rgba(200,149,42,0.05);
  overflow: hidden;
  animation: palette-slideDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
body.theme-dark .palette-container {
  background: #1e1b15;
  border-color: rgba(255,255,255,0.08);
}
@keyframes palette-slideDown {
  from { transform: translateY(-20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
.palette-search-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
}
.palette-search-input {
  flex: 1;
  background: transparent;
  border: 0;
  outline: none;
  font-size: 1.05rem;
  color: var(--ink);
  font-family: inherit;
}
.palette-results {
  max-height: 320px;
  overflow-y: auto;
  padding: 0.5rem;
}
.palette-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  cursor: pointer;
  color: var(--ink);
  text-decoration: none;
  transition: all 0.15s;
}
.palette-item:hover, .palette-item.active {
  background: rgba(200, 149, 42, 0.08);
}
.palette-item-icon {
  font-size: 1.15rem;
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--gold-bg);
}
.palette-item-info {
  flex: 1;
  min-width: 0;
}
.palette-item-title {
  font-size: 0.85rem;
  font-weight: 600;
}
.palette-item-desc {
  font-size: 0.7rem;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.palette-item-kbd {
  font-size: 0.65rem;
  background: var(--border);
  color: var(--muted);
  padding: 2px 6px;
  border-radius: 4px;
  font-family: monospace;
}
.palette-header {
  padding: 0.5rem 0.85rem 0.25rem;
  font-size: 0.62rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
  border-bottom: 1px solid rgba(0,0,0,0.03);
}

/* ── PWA toast notification ── */
.pwa-toast {
  position: fixed;
  bottom: 20px; right: 20px;
  background: var(--gold-bg);
  border: 1px solid var(--gold);
  color: var(--ink);
  padding: 12px 18px;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  font-size: 0.82rem;
  z-index: 10001;
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition: all 0.3s ease;
  animation: toast-slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes toast-slideIn {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
</style>
<?php if (!empty($_SESSION['flash_err'])): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    if (typeof fw !== 'undefined' && typeof fw.toast === 'function') {
        fw.toast(<?= json_encode($_SESSION['flash_err']) ?>, 'error');
    } else {
        alert(<?= json_encode($_SESSION['flash_err']) ?>);
    }
});
</script>
<?php unset($_SESSION['flash_err']); endif; ?>
</body>
</html>
