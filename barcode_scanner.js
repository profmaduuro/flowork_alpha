/**
 * FloWork Barcode Scanner Module
 * ──────────────────────────────
 * Uses html5-qrcode library (must be loaded before this script).
 * Provides: window.FloworkScanner.scan(callback)
 *
 * Supports: CODE_128, CODE_39, EAN_13, EAN_8, UPC_A, UPC_E,
 *           QR_CODE, DATA_MATRIX, and more via html5-qrcode.
 */
(function () {
    'use strict';

    /* ── FloWork CSS variable fallbacks ──────────────────────────────────── */
    var GOLD   = '#c8952a';
    var INK    = '#1a1208';
    var CREAM  = '#f5f0e8';
    var LEAF   = '#3d6b35';
    var RADIUS = '8px';

    /* ── State ───────────────────────────────────────────────────────────── */
    var overlay      = null;
    var scanner      = null;
    var scanCallback = null;
    var isScanning   = false;

    /* ── Inject stylesheet (once) ────────────────────────────────────────── */
    function injectStyles() {
        if (document.getElementById('fw-scanner-styles')) return;
        var style = document.createElement('style');
        style.id = 'fw-scanner-styles';
        style.textContent = [
            /* Overlay backdrop */
            '.fw-scan-overlay {',
            '  position:fixed; inset:0; z-index:99999;',
            '  background:rgba(0,0,0,.88);',
            '  display:flex; flex-direction:column; align-items:center; justify-content:center;',
            '  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;',
            '  opacity:0; transition:opacity .25s ease;',
            '}',
            '.fw-scan-overlay.fw-visible { opacity:1; }',

            /* Close button */
            '.fw-scan-close {',
            '  position:absolute; top:env(safe-area-inset-top,12px); right:12px;',
            '  width:44px; height:44px; border:none; border-radius:50%;',
            '  background:rgba(255,255,255,.15); color:#fff; font-size:22px;',
            '  display:flex; align-items:center; justify-content:center;',
            '  cursor:pointer; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);',
            '  transition:background .2s; z-index:2; margin-top:12px;',
            '}',
            '.fw-scan-close:hover,.fw-scan-close:active { background:rgba(255,255,255,.3); }',

            /* Viewfinder container */
            '.fw-scan-viewfinder {',
            '  position:relative; width:min(85vw,360px); height:min(85vw,360px);',
            '  border-radius:' + RADIUS + '; overflow:hidden;',
            '  box-shadow:0 0 0 3px ' + GOLD + ', 0 8px 40px rgba(0,0,0,.5);',
            '  transition:box-shadow .3s ease;',
            '}',
            '.fw-scan-viewfinder.fw-scan-success {',
            '  box-shadow:0 0 0 4px ' + LEAF + ', 0 0 30px rgba(61,107,53,.5);',
            '}',

            /* Camera preview element */
            '.fw-scan-viewfinder video { width:100%!important; height:100%!important; object-fit:cover; }',
            '#fw-scanner-region { width:100%; height:100%; }',
            '#fw-scanner-region video { width:100%!important; height:100%!important; object-fit:cover!important; }',
            '#fw-scanner-region img[alt="Info icon"] { display:none!important; }',

            /* Corner brackets */
            '.fw-scan-corners {',
            '  position:absolute; inset:0; pointer-events:none; z-index:1;',
            '}',
            '.fw-scan-corner {',
            '  position:absolute; width:28px; height:28px;',
            '  border-color:' + GOLD + '; border-style:solid; border-width:0;',
            '}',
            '.fw-scan-corner.tl { top:0;left:0; border-top-width:3px; border-left-width:3px; border-top-left-radius:' + RADIUS + '; }',
            '.fw-scan-corner.tr { top:0;right:0; border-top-width:3px; border-right-width:3px; border-top-right-radius:' + RADIUS + '; }',
            '.fw-scan-corner.bl { bottom:0;left:0; border-bottom-width:3px; border-left-width:3px; border-bottom-left-radius:' + RADIUS + '; }',
            '.fw-scan-corner.br { bottom:0;right:0; border-bottom-width:3px; border-right-width:3px; border-bottom-right-radius:' + RADIUS + '; }',

            /* Scanning line */
            '.fw-scan-line {',
            '  position:absolute; left:8%; right:8%; height:2px; z-index:1;',
            '  background:linear-gradient(90deg, transparent, ' + GOLD + ', transparent);',
            '  box-shadow:0 0 8px ' + GOLD + ', 0 0 20px rgba(200,149,42,.3);',
            '  animation:fw-scanline 2.2s ease-in-out infinite;',
            '}',
            '@keyframes fw-scanline {',
            '  0%   { top:10%; opacity:.4; }',
            '  50%  { top:85%; opacity:1; }',
            '  100% { top:10%; opacity:.4; }',
            '}',

            /* Instruction text */
            '.fw-scan-hint {',
            '  margin-top:1.5rem; color:rgba(255,255,255,.7); font-size:.85rem;',
            '  letter-spacing:.04em; text-align:center;',
            '}',

            /* Result banner */
            '.fw-scan-result {',
            '  position:absolute; bottom:0; left:0; right:0;',
            '  background:' + LEAF + '; color:#fff; text-align:center;',
            '  padding:.6rem; font-family:monospace; font-size:1rem;',
            '  font-weight:700; letter-spacing:.08em;',
            '  transform:translateY(100%); transition:transform .25s ease;',
            '  z-index:2;',
            '}',
            '.fw-scan-result.fw-show { transform:translateY(0); }',

            /* Error message */
            '.fw-scan-error {',
            '  color:#fff; text-align:center; padding:2rem;',
            '  max-width:340px;',
            '}',
            '.fw-scan-error h3 { color:' + GOLD + '; margin-bottom:.75rem; font-size:1.1rem; }',
            '.fw-scan-error p { color:rgba(255,255,255,.7); font-size:.85rem; line-height:1.5; margin:0; }',
            '.fw-scan-error button {',
            '  margin-top:1.25rem; padding:.6rem 1.5rem; border:1px solid ' + GOLD + ';',
            '  background:transparent; color:' + GOLD + '; border-radius:6px;',
            '  font-size:.85rem; cursor:pointer; transition:background .2s;',
            '}',
            '.fw-scan-error button:hover { background:rgba(200,149,42,.15); }',
        ].join('\n');
        document.head.appendChild(style);
    }

    /* ── Build overlay DOM ───────────────────────────────────────────────── */
    function createOverlay() {
        var el = document.createElement('div');
        el.className = 'fw-scan-overlay';
        el.innerHTML = [
            '<button class="fw-scan-close" title="Close scanner">&times;</button>',
            '<div class="fw-scan-viewfinder" id="fw-scan-vf">',
            '  <div id="fw-scanner-region"></div>',
            '  <div class="fw-scan-corners">',
            '    <div class="fw-scan-corner tl"></div>',
            '    <div class="fw-scan-corner tr"></div>',
            '    <div class="fw-scan-corner bl"></div>',
            '    <div class="fw-scan-corner br"></div>',
            '  </div>',
            '  <div class="fw-scan-line"></div>',
            '  <div class="fw-scan-result" id="fw-scan-result"></div>',
            '</div>',
            '<div class="fw-scan-hint">Point camera at barcode</div>',
        ].join('\n');

        /* Close button handler */
        el.querySelector('.fw-scan-close').addEventListener('click', function (e) {
            e.stopPropagation();
            closeScanner();
        });

        return el;
    }

    /* ── Audio feedback (short beep via AudioContext) ─────────────────── */
    function playBeep() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 1200;
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.15);
        } catch (_) { /* AudioContext not supported — silent fallback */ }
    }

    /* ── Success handler ─────────────────────────────────────────────── */
    function onScanSuccess(decodedText) {
        if (!isScanning) return;          // avoid duplicate fires
        isScanning = false;

        /* Visual feedback */
        var vf = document.getElementById('fw-scan-vf');
        if (vf) vf.classList.add('fw-scan-success');

        var resultEl = document.getElementById('fw-scan-result');
        if (resultEl) {
            resultEl.textContent = decodedText;
            resultEl.classList.add('fw-show');
        }

        /* Haptic */
        if (navigator.vibrate) navigator.vibrate(200);

        /* Audio */
        playBeep();

        /* Stop scanning & close after delay */
        var cb = scanCallback;
        setTimeout(function () {
            closeScanner();
            if (typeof cb === 'function') cb(decodedText);
        }, 500);
    }

    /* ── Start camera scanner ────────────────────────────────────────── */
    function startScanner() {
        /* Check library loaded */
        if (typeof Html5Qrcode === 'undefined') {
            showError('Scanner library not loaded',
                'The html5-qrcode library could not be found. ' +
                'Please check your internet connection and reload the page.');
            return;
        }

        isScanning = true;

        scanner = new Html5Qrcode('fw-scanner-region', /* verbose= */ false);

        var config = {
            fps: 12,
            qrbox: function (vw, vh) {
                var s = Math.min(vw, vh);
                return { width: Math.floor(s * 0.85), height: Math.floor(s * 0.55) };
            },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.DATA_MATRIX
            ],
            aspectRatio: 1.0,
            disableFlip: false
        };

        scanner.start(
            { facingMode: 'environment' },   // rear camera
            config,
            onScanSuccess,
            function () { /* ignore scan failures (no barcode in frame) */ }
        ).catch(function (err) {
            isScanning = false;
            var msg = String(err);
            if (msg.indexOf('NotAllowedError') !== -1 || msg.indexOf('Permission') !== -1) {
                showError('Camera permission denied',
                    'FloWork needs camera access to scan barcodes. ' +
                    'Please allow camera access in your browser settings and try again.');
            } else if (msg.indexOf('NotFoundError') !== -1 || msg.indexOf('Requested device not found') !== -1) {
                showError('No camera found',
                    'No rear camera was detected on this device. ' +
                    'You can still type the barcode manually.');
            } else if (msg.indexOf('NotReadableError') !== -1) {
                showError('Camera in use',
                    'The camera is being used by another application. ' +
                    'Please close other apps using the camera and try again.');
            } else if (msg.indexOf('insecure') !== -1 || msg.indexOf('https') !== -1) {
                showError('HTTPS required',
                    'Camera access requires a secure connection (HTTPS) or localhost. ' +
                    'Please access FloWork via HTTPS.');
            } else {
                showError('Camera error',
                    'Could not start the camera: ' + msg);
            }
        });
    }

    /* ── Show error inside the overlay ───────────────────────────────── */
    function showError(title, message) {
        if (!overlay) return;
        var vf = document.getElementById('fw-scan-vf');
        var hint = overlay.querySelector('.fw-scan-hint');
        if (vf) vf.style.display = 'none';
        if (hint) hint.style.display = 'none';

        var errDiv = document.createElement('div');
        errDiv.className = 'fw-scan-error';
        errDiv.innerHTML = '<h3>📷 ' + title + '</h3>' +
            '<p>' + message + '</p>' +
            '<button type="button">Close</button>';
        errDiv.querySelector('button').addEventListener('click', function () {
            closeScanner();
        });
        overlay.appendChild(errDiv);
    }

    /* ── Close & cleanup ─────────────────────────────────────────────── */
    function closeScanner() {
        isScanning = false;

        if (scanner) {
            try {
                var state = scanner.getState();
                // Html5QrcodeScannerState: NOT_STARTED=1, SCANNING=2, PAUSED=3
                if (state === 2 || state === 3) {
                    scanner.stop().then(function () {
                        scanner.clear();
                    }).catch(function () {
                        try { scanner.clear(); } catch (_) {}
                    });
                } else {
                    try { scanner.clear(); } catch (_) {}
                }
            } catch (_) {
                try { scanner.clear(); } catch (_e) {}
            }
            scanner = null;
        }

        if (overlay) {
            overlay.classList.remove('fw-visible');
            var ov = overlay;
            setTimeout(function () {
                if (ov.parentNode) ov.parentNode.removeChild(ov);
            }, 280);
            overlay = null;
        }

        scanCallback = null;
    }

    /* ── Public API ──────────────────────────────────────────────────── */
    window.FloworkScanner = {
        /**
         * Open the camera scanner overlay.
         * @param {function} callback - called with the decoded barcode string on success
         */
        scan: function (callback) {
            if (isScanning) return;       // prevent double-open

            injectStyles();
            scanCallback = callback || null;

            overlay = createOverlay();
            document.body.appendChild(overlay);

            /* Trigger CSS fade-in on next frame */
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    overlay.classList.add('fw-visible');
                });
            });

            startScanner();
        }
    };
})();
