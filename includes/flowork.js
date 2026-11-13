/* flowork.js v4 — overlay spinner + rich toasts. No defer. */
/* Opening a page shows it settled: one-off entrance animations (fade-in /
   slide-up of cards and panels) are finished instantly when the page loads.
   Endless ones (spinners, pulses) keep running, and animations started later
   by the user (tab switches, pop-ups) play normally. */
(function(){
    function settle(){
        if(!document.getAnimations) return;
        document.getAnimations().forEach(function(a){
            try{
                if(typeof CSSAnimation==='undefined' || !(a instanceof CSSAnimation)) return;
                var t=a.effect && a.effect.getComputedTiming && a.effect.getComputedTiming();
                if(!t || t.iterations===Infinity) return;
                var el=a.effect.target;
                if(!el || !el.closest || !el.closest('.main')) return;
                a.finish();
            }catch(e){}
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        settle();
        requestAnimationFrame(function(){ settle(); requestAnimationFrame(settle); });
    });
})();
(function(){
    'use strict';

    /* ─── OVERLAY SPINNER ──────────────────────────────────────────── */
    var bar, overlay, label, barInt, showTimer, barPct = 0;

    function mkOverlay(){
        if(overlay) return;

        // Top bar
        bar = document.createElement('div');
        bar.id = 'fw-bar';
        bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0%;z-index:99999;' +
            'pointer-events:none;opacity:0;' +
            'background:linear-gradient(90deg,#c8952a,#e8b84b,#3d6b35);' +
            'border-radius:0 3px 3px 0;';

        // Overlay
        overlay = document.createElement('div');
        overlay.id = 'fw-overlay';
        overlay.innerHTML =
            '<div class="fw-card">' +
            '<svg class="fw-ring-svg" viewBox="0 0 58 58">' +
            '<circle class="fw-ring-bg"  cx="29" cy="29" r="22"/>' +
            '<circle class="fw-ring-arc" cx="29" cy="29" r="22" transform="rotate(-90 29 29)"/>' +
            '</svg>' +
            '<div class="fw-dots"><b></b><b></b><b></b></div>' +
            '<div class="fw-lbl" id="fw-lbl">Processing...</div>' +
            '</div>';

        document.body.appendChild(bar);
        document.body.appendChild(overlay);
        label = document.getElementById('fw-lbl');
    }

    // opts.barOnly: page navigation — keep the current page visible and show only
    // the thin top bar (after 150ms, so fast pages show nothing at all).
    // Otherwise (form submits, actions) the overlay appears only if the wait
    // passes 900ms, so quick saves never flash it.
    var barTimer;
    function pStart(lbl, opts){
        mkOverlay();
        clearInterval(barInt); clearTimeout(showTimer); clearTimeout(barTimer);
        barPct = 0;
        bar.style.transition = 'none';
        bar.style.width = '0%';
        barTimer = setTimeout(function(){
            bar.style.opacity = '1';
            // tick progress bar
            barInt = setInterval(function(){
                barPct += Math.random()*9 + 2;
                if(barPct > 82) barPct = 82;
                bar.style.transition = 'width .15s ease';
                bar.style.width = barPct + '%';
            }, 130);
        }, 150);
        if (opts && opts.barOnly) return;
        showTimer = setTimeout(function(){
            if(label) label.textContent = lbl || 'Processing...';
            overlay.classList.add('fw-on');
        }, 900);
    }

    function pDone(){
        clearInterval(barInt); clearTimeout(showTimer); clearTimeout(_maxTimer); clearTimeout(barTimer);
        if(overlay) overlay.classList.remove('fw-on');
        if(bar && bar.style.opacity === '1'){
            bar.style.transition = 'width .2s ease';
            bar.style.width = '100%';
            setTimeout(function(){
                bar.style.opacity = '0';
                setTimeout(function(){ bar.style.width='0%'; }, 400);
            }, 220);
        }
    }

    function pErr(){
        clearInterval(barInt); clearTimeout(showTimer); clearTimeout(_maxTimer);
        if(overlay) overlay.classList.remove('fw-on');
        if(bar){
            bar.style.background = '#e53935';
            bar.style.width = '100%';
            setTimeout(function(){
                bar.style.opacity = '0';
                setTimeout(function(){
                    bar.style.width = '0%';
                    bar.style.background = 'linear-gradient(90deg,#c8952a,#e8b84b,#3d6b35)';
                }, 400);
            }, 600);
        }
    }

// Attach immediately — before DOM ready
    document.addEventListener('submit', function(e){
        if(e.target && e.target.dataset && e.target.dataset.noProgress) return;
        pStart(e.target && e.target.dataset && e.target.dataset.progressLabel);
    }, true);

    document.addEventListener('click', function(e){
        var a = e.target && e.target.closest && e.target.closest('a[href]');
        if(!a) return;
        var h = a.getAttribute('href');
        if(!h || h[0]==='#' || h.indexOf('javascript:')=== 0 || h.indexOf('mailto:')===0) return;
        if(a.target==='_blank' || (a.dataset && a.dataset.actionUrl)) return;
        try{ var u=new URL(a.href,location.origin); if(u.origin===location.origin) pStart('Loading...', {barOnly:true}); }catch(x){}
    }, true);

// Stop overlay when page finishes loading — multiple triggers for reliability
    window.addEventListener('load', pDone);
    window.addEventListener('pageshow', pDone);
    document.addEventListener('DOMContentLoaded', pDone);

// Safety net — never run more than 8 seconds
    var _maxTimer;
    var _origPStart = pStart;
    pStart = function(lbl, opts){
        clearTimeout(_maxTimer);
        _origPStart(lbl, opts);
        _maxTimer = setTimeout(pDone, 8000);
    };

    /* ─── TOASTS ────────────────────────────────────────────────────── */
    var tWrap;

    function mkWrap(){
        if(tWrap) return;
        tWrap = document.createElement('div');
        tWrap.id = 'fw-toasts';
        document.body.appendChild(tWrap);
    }

    var ICO = {
        success: '<svg viewBox="0 0 16 16"><polyline points="2 8 6 12 14 4" class="fw-check-path"/></svg>',
        error:   '<svg viewBox="0 0 16 16"><line x1="3" y1="3" x2="13" y2="13"/><line x1="13" y1="3" x2="3" y2="13"/></svg>',
        warning: '<svg viewBox="0 0 16 16"><path d="M8 5v4"/><circle cx="8" cy="12" r=".8" fill="#fff" stroke="none"/></svg>',
        info:    '<svg viewBox="0 0 16 16"><path d="M8 7v4"/><circle cx="8" cy="4" r=".8" fill="#fff" stroke="none"/></svg>',
    };
    var LBL = { success:'Success', error:'Error', warning:'Warning', info:'Info' };

    function toast(msg, type, dur){
        type = type || 'success';
        mkWrap();

        var el = document.createElement('div');
        el.className = 'fw-toast fw-toast-' + type;

        el.innerHTML =
            '<div class="fw-ico">' + (ICO[type]||ICO.info) + '</div>' +
            '<div class="fw-t-body">' +
            '<div class="fw-t-type">' + (LBL[type]||type) + '</div>' +
            '<div class="fw-t-msg">'  + msg + '</div>' +
            '</div>' +
            '<button class="fw-t-x" title="Dismiss">✕</button>';

        function dismiss(){
            el.style.animation = 'none';
            el.style.transition = 'opacity .28s ease, transform .28s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateX(110%)';
            setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 300);
        }

        el.querySelector('.fw-t-x').addEventListener('click', dismiss);
        tWrap.appendChild(el);

        var d = dur !== undefined ? dur : (type==='error' ? 7000 : 4000);
        if(d > 0) setTimeout(dismiss, d);
        return el;
    }

    /* ─── SUCCESS ALERTS from server ───────────────────────────────── */
    function scanAlerts(){
        document.querySelectorAll('.alert-success,.alert-danger,.alert-warning').forEach(function(el){
            var msg = el.textContent.trim();
            if(!msg) return;
            var t = el.classList.contains('alert-success') ? 'success'
                : el.classList.contains('alert-danger')  ? 'error' : 'warning';
            setTimeout(function(){ toast(msg, t); }, 350);
        });
        // Pending toast from sessionStorage (set before redirect)
        try{
            var p = sessionStorage.getItem('fw_pt');
            if(p){ sessionStorage.removeItem('fw_pt'); var d=JSON.parse(p); setTimeout(function(){ toast(d.m,d.t); },400); }
        }catch(x){}
    }

    window.fw_queueToast = function(msg,type){
        try{ sessionStorage.setItem('fw_pt', JSON.stringify({m:msg,t:type||'success'})); }catch(x){}
    };

    /* ─── GLOBAL fw ─────────────────────────────────────────────────── */
    // Reload the page as a normal navigation: a same-URL replace keeps the smooth
    // page transition and the menu as it is, where location.reload() flashes the
    // whole screen. A URL with a #fragment would only scroll, so those reload.
    window.fwReload = function(){
        if (location.hash) { location.reload(); return; }
        location.replace(location.href);
    };
    window.fw = {
        base: function(){ return (window.APP_BASE||'').replace(/\/+$/,''); },
        toast: toast,
        barStart: pStart, barDone: pDone, barError: pErr,

        spin: function(btn, on){
            if(!btn) return;
            if(on){
                btn._orig=btn.innerHTML; btn.disabled=true;
                btn.innerHTML='<span class="fw-spinner"></span> '+(btn.dataset.loadingText||'Processing...');
            } else {
                btn.disabled=false; btn.innerHTML=btn._orig||btn.innerHTML;
            }
        },

        get: async function(url, params){
            params=params||{};
            var r=url.indexOf('http')===0?url:this.base()+'/'+url.replace(/^\//,'');
            var q=new URLSearchParams(params).toString();
            var res=await fetch(q?r+'?'+q:r,{headers:{'X-Requested-With':'XMLHttpRequest'}});
            pDone(); // stop overlay as soon as response arrives
            if(!res.ok) throw new Error('HTTP '+res.status);
            return (res.headers.get('content-type')||'').indexOf('json')>=0?res.json():res.text();
        },

        post: async function(url, data){
            data=data||{};
            var r=url.indexOf('http')===0?url:this.base()+'/'+url.replace(/^\//,'');
            var fd=new FormData();
            Object.entries(data).forEach(function(kv){
                Array.isArray(kv[1])?kv[1].forEach(function(v){fd.append(kv[0]+'[]',v);}):fd.append(kv[0],kv[1]);
            });
            var res=await fetch(r,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
            pDone(); // stop overlay as soon as response arrives
            if(!res.ok) throw new Error('HTTP '+res.status);
            return (res.headers.get('content-type')||'').indexOf('json')>=0?res.json():res.text();
        },

        ajax: async function(action,data,method){
            data=data||{}; method=method||'GET';
            var p=Object.assign({action:action},data);
            return method==='POST'?this.post('ajax.php',p):this.get('ajax.php',p);
        },

        debounce: function(fn,ms){
            ms=ms||300; var t;
            return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){fn.apply(null,a);},ms); };
        },

        escHtml: function(s){
            return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
    };

    /* ─── INLINE ACTIONS (data-action-url) ─────────────────────────── */
    document.addEventListener('click', function(e){
        var btn = e.target && e.target.closest && e.target.closest('[data-action-url]');
        if(!btn) return;
        e.preventDefault(); e.stopPropagation();
        var url=btn.dataset.actionUrl, cmsg=btn.dataset.actionConfirm;
        var body=btn.dataset.actionBody?JSON.parse(btn.dataset.actionBody):{};
        var onOk=btn.dataset.actionSuccess||'';
        if(cmsg && !confirm(cmsg)) return;
        fw.spin(btn,true); pStart(btn.dataset.progressLabel||'Processing...');
        fw.post(url,body).then(function(res){
            pDone(); fw.spin(btn,false);
            var ok=typeof res==='object'?res.success!==false:true;
            var msg=(typeof res==='object'&&res.msg)?res.msg:(ok?'Done.':'Failed.');
            toast(msg, ok?'success':'error');
            if(ok){
                if(onOk==='remove-row'){
                    var row=btn.closest('tr');
                    if(row){row.style.transition='opacity .3s,transform .3s';row.style.opacity='0';row.style.transform='translateX(16px)';setTimeout(function(){if(row.parentNode)row.parentNode.removeChild(row);},320);}
                } else if(onOk==='toggle-row-dim'){
                    var r2=btn.closest('tr'); if(r2) r2.style.opacity=r2.style.opacity==='0.4'?'1':'0.4';
                } else if(onOk==='reload'){
                    setTimeout(fwReload,800);
                }
            }
        }).catch(function(){ pErr(); fw.spin(btn,false); toast('Network error — try again.','error'); });
    });

    /* ─── LIVE SEARCH ───────────────────────────────────────────────── */
    document.addEventListener('input', fw.debounce(function(e){
        var inp=e.target; if(!inp||!inp.dataset||!inp.dataset.search) return;
        var tbl=document.querySelector(inp.dataset.search); if(!tbl) return;
        var q=inp.value.toLowerCase().trim(), n=0;
        tbl.querySelectorAll('tbody tr').forEach(function(row){
            var show=!q||row.textContent.toLowerCase().indexOf(q)>=0;
            row.style.display=show?'':'none'; if(show) n++;
        });
        var b=inp.dataset.countBadge&&document.querySelector(inp.dataset.countBadge); if(b) b.textContent=n;
    },180));

    /* ─── DATE PICKERS ──────────────────────────────────────────────── */
    function initDates(){
        document.querySelectorAll('input[type="date"]').forEach(function(inp){
            if(inp.hasAttribute('data-fw-d')) return;
            inp.setAttribute('data-fw-d','1');
            var form=inp.closest('form'); if(!form) return;
            inp.removeAttribute('onchange');
            inp.addEventListener('change', fw.debounce(function(){ pStart('Loading...'); form.submit(); },150));
        });
    }

    /* ─── KEYBOARD ──────────────────────────────────────────────────── */
    document.addEventListener('keydown',function(e){
        var tag=document.activeElement&&document.activeElement.tagName;
        if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT') return;
        if(e.key==='Escape') document.querySelectorAll('[id$="-modal"],[id$="_modal"]').forEach(function(m){if(m.style.display&&m.style.display!=='none')m.style.display='none';});
        if(e.key==='/'){e.preventDefault();var s=document.querySelector('input[data-search],input[type="search"]');if(s)s.focus();}
    });

    /* ─── ONLINE/OFFLINE ────────────────────────────────────────────── */
    window.addEventListener('offline',function(){toast('Connection lost','warning',0);});
    window.addEventListener('online', function(){toast('Connection restored','success');});

    /* ─── THEME ─────────────────────────────────────────────────────────
       The toggle button calls setTheme('toggle'), so resolve that here and
       never let a non-theme value ('toggle', anything unexpected) land on the
       body class or the cookie — otherwise no theme-* rule matches and the
       page renders unstyled while the server coerces the bad cookie to dark. */
    window.setTheme=function(t){
        if(t==='toggle') t = document.body.classList.contains('theme-dark') ? 'light' : 'dark';
        if(t!=='dark' && t!=='light') t='dark';
        document.body.classList.remove('theme-dark','theme-light');
        document.body.classList.add('theme-'+t);
        document.cookie='flowork_theme='+t+';path=/;max-age=31536000';
        var btn=document.getElementById('theme-toggle-btn');
        if(btn){ var lbl=(t==='dark')?'Switch to light mode':'Switch to dark mode'; btn.setAttribute('aria-label',lbl); btn.title=lbl; }
        toast('Switched to '+(t==='dark'?'dark':'light')+' mode','info',2000);
    };

    /* ─── KPI ANIMATION ─────────────────────────────────────────────── */
    // Counting KPI figures up from zero on every page load made numbers jump
    // around; figures now show their value straight away.
    function animKPIs(){ return; }
    function animKPIsOld(){
        document.querySelectorAll('.kpi-value:not([data-fwa])').forEach(function(el){
            el.setAttribute('data-fwa','1');
            var raw=el.textContent.replace(/[^0-9.]/g,''), num=parseFloat(raw);
            if(isNaN(num)||num<=0||num>99999999) return;
            var pre=(el.textContent.match(/^[^0-9]/)||[''])[0];
            var suf=el.textContent.slice((pre+raw).length);
            var isInt=Number.isInteger(num)&&el.textContent.indexOf('.')<0;
            var t0=Date.now(),dur=640;
            (function tick(){
                var p=Math.min(1,(Date.now()-t0)/dur),ease=1-Math.pow(1-p,3),cur=num*ease;
                el.textContent=pre+(isInt?Math.round(cur).toLocaleString('en'):cur.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}))+suf;
                if(p<1) requestAnimationFrame(tick);
            })();
        });
    }

    /* ─── TABS ──────────────────────────────────────────────────────────
       Shared segmented tab strip. Markup contract:
         <div class="premium-tabs-nav" data-tabs="GROUP" [data-force="ID"]>
           <button class="premium-tab-btn" data-tab="ID">…</button>
         <div class="tab-panel" data-tabs="GROUP" id="panel-ID">…</div>
       Legacy pages whose strip has no data-tabs keep their own inline JS. */
    function tabNavs(){ return document.querySelectorAll('.premium-tabs-nav[data-tabs]'); }

    window.switchTab=function(tabId,group){
        var nav=document.querySelector('.premium-tabs-nav[data-tabs="'+group+'"]');
        if(!nav) return;
        var btn=nav.querySelector('.premium-tab-btn[data-tab="'+tabId+'"]');
        var panel=document.querySelector('.tab-panel[data-tabs="'+group+'"]#panel-'+tabId);
        if(!btn||!panel) return;

        nav.querySelectorAll('.premium-tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel[data-tabs="'+group+'"]').forEach(function(p){ p.classList.remove('active'); });
        btn.classList.add('active');
        panel.classList.add('active');

        try{ sessionStorage.setItem('active_'+group+'_tab',tabId); }catch(e){}

        // Inactive panels keep a real width (see .tab-panel in layout.php), so
        // charts normally come up correctly sized. This is a safety net for the
        // case where a panel's visible width differs from its parked width.
        if(window.Chart && Chart.getChart){
            panel.querySelectorAll('canvas').forEach(function(cv){
                var ch=Chart.getChart(cv), box=cv.parentElement;
                if(!ch||!box) return;
                var avail=box.clientWidth;
                if(avail && Math.abs(cv.getBoundingClientRect().width-avail)>2){
                    cv.style.width='100%';
                    try{ ch.resize(); }catch(e){}
                }
            });
        }
        // Nudge anything else that measures on window resize.
        try{ window.dispatchEvent(new Event('resize')); }catch(e){}
        // Only the primary strip owns the ?tab= slot on multi-strip pages.
        if(nav===tabNavs()[0]){
            try{
                var u=new URL(window.location.href);
                u.searchParams.set('tab',tabId);
                history.replaceState(null,'',u);
            }catch(e){}
        }
    };

    document.addEventListener('click',function(e){
        var btn=e.target.closest?e.target.closest('.premium-tab-btn[data-tab]'):null;
        if(!btn) return;
        var nav=btn.closest('.premium-tabs-nav[data-tabs]');
        if(!nav) return;               // legacy strip — leave its inline onclick alone
        window.switchTab(btn.getAttribute('data-tab'),nav.getAttribute('data-tabs'));
    });

    function initTabs(){
        var urlTab=null;
        try{ urlTab=new URL(window.location.href).searchParams.get('tab'); }catch(e){}
        tabNavs().forEach(function(nav,i){
            var group=nav.getAttribute('data-tabs');
            var first=nav.querySelector('.premium-tab-btn[data-tab]');
            var stored=null;
            try{ stored=sessionStorage.getItem('active_'+group+'_tab'); }catch(e){}
            var want=nav.getAttribute('data-force')
                  || (i===0?urlTab:null)
                  || stored
                  || (first&&first.getAttribute('data-tab'));
            if(!want) return;
            // Fall back to the first tab if the remembered one no longer exists.
            if(!nav.querySelector('.premium-tab-btn[data-tab="'+want+'"]') && first)
                want=first.getAttribute('data-tab');
            window.switchTab(want,group);
        });
    }

    /* ─── DOM READY ─────────────────────────────────────────────────── */
    function ready(fn){ document.readyState!=='loading'?fn():document.addEventListener('DOMContentLoaded',fn); }

    ready(function(){
        mkOverlay();
        mkWrap();
        initDates();
        animKPIs();
        scanAlerts();
        initTabs();
    });

})();