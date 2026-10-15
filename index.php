<?php
// Redirect logged-in users straight to dashboard
session_start();
if (!empty($_SESSION['userid'])) {
    header("Location: dashboard.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Flowork — Zimbabwe Tobacco Auction Management</title>
<meta name="description" content="Flowork is a modern tobacco floor auction management system by Core Africa Group. Real-time multi-floor operations, grower management, and settlement reporting.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink:    #1a1208;
    --gold:   #c8952a;
    --gold-l: #e8b84b;
    --gold-d: #92650e;
    --cream:  #f5f0e8;
    --cream-d:#ede6d6;
    --muted:  #7a6e5f;
    --leaf:   #3d6b35;
    --border: rgba(26,18,8,0.12);
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--ink);
    color: var(--cream);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── UTILITY ─────────────────────────────────────────────────────────────── */
.gold       { color: var(--gold-l); }
.muted      { color: rgba(245,240,232,0.45); }
.container  { max-width: 1140px; margin: 0 auto; padding: 0 2rem; }
.flex       { display: flex; }
.center     { text-align: center; }
.bebas      { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.08em; }

/* ── NAVBAR ──────────────────────────────────────────────────────────────── */
nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: rgba(26,18,8,0.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(200,149,42,0.18);
    padding: 0 2rem;
    height: 60px;
    display: flex; align-items: center; justify-content: space-between;
}
.nav-logo {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.5rem;
    color: var(--cream);
    letter-spacing: 0.12em;
    display: flex; align-items: center; gap: 10px;
    text-decoration: none;
}
.nav-logo-icon {
    width: 28px; height: 28px;
    background: var(--gold);
    border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.nav-links { display: flex; gap: 2rem; align-items: center; }
.nav-links a {
    color: rgba(245,240,232,0.6);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 400;
    transition: color 0.15s;
}
.nav-links a:hover { color: var(--cream); }
.nav-cta {
    background: var(--gold);
    color: var(--ink) !important;
    font-weight: 500 !important;
    padding: 0.45rem 1.1rem !important;
    border-radius: 2px;
    transition: background 0.15s !important;
}
.nav-cta:hover { background: var(--gold-l) !important; }

/* ── HERO ────────────────────────────────────────────────────────────────── */
.hero {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    overflow: hidden;
    padding: 100px 2rem 60px;
}

/* Background pattern: subtle tobacco leaf silhouettes */
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background-image:
        radial-gradient(ellipse 800px 600px at 80% 40%, rgba(200,149,42,0.06) 0%, transparent 70%),
        radial-gradient(ellipse 400px 600px at 10% 80%, rgba(61,107,53,0.07) 0%, transparent 60%);
    pointer-events: none;
}

/* Large watermark text */
.hero-watermark {
    position: absolute;
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(120px, 22vw, 340px);
    color: rgba(200,149,42,0.035);
    white-space: nowrap;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
    letter-spacing: 0.08em;
    user-select: none;
}

.hero-inner { max-width: 780px; text-align: center; position: relative; z-index: 1; }

.hero-eyebrow {
    display: inline-flex; align-items: center; gap: 0.5rem;
    background: rgba(200,149,42,0.12);
    border: 1px solid rgba(200,149,42,0.25);
    color: var(--gold-l);
    font-size: 0.72rem;
    font-weight: 500;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    padding: 0.35rem 0.85rem;
    border-radius: 999px;
    margin-bottom: 1.75rem;
}
.eyebrow-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--gold-l); }

.hero h1 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(3.5rem, 9vw, 7rem);
    line-height: 0.92;
    letter-spacing: 0.04em;
    color: var(--cream);
    margin-bottom: 0.5rem;
}
.hero h1 span { color: var(--gold); }

.hero-sub {
    font-size: clamp(0.95rem, 2vw, 1.15rem);
    color: rgba(245,240,232,0.55);
    font-weight: 300;
    line-height: 1.65;
    max-width: 560px;
    margin: 1.25rem auto 2.5rem;
}

.hero-actions { display: flex; gap: 0.85rem; justify-content: center; flex-wrap: wrap; }

.btn-primary-hero {
    background: var(--gold);
    color: var(--ink);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 500;
    padding: 0.75rem 2rem;
    border-radius: 2px;
    text-decoration: none;
    letter-spacing: 0.04em;
    transition: background 0.15s, transform 0.1s;
    display: inline-flex; align-items: center; gap: 0.45rem;
}
.btn-primary-hero:hover { background: var(--gold-l); transform: translateY(-1px); }

.btn-ghost-hero {
    background: transparent;
    color: rgba(245,240,232,0.7);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 400;
    padding: 0.75rem 1.75rem;
    border-radius: 2px;
    border: 1px solid rgba(245,240,232,0.18);
    text-decoration: none;
    transition: border-color 0.15s, color 0.15s;
    display: inline-flex; align-items: center; gap: 0.45rem;
}
.btn-ghost-hero:hover { border-color: rgba(245,240,232,0.45); color: var(--cream); }

/* ── STATS STRIP ─────────────────────────────────────────────────────────── */
.stats-strip {
    border-top: 1px solid rgba(200,149,42,0.15);
    border-bottom: 1px solid rgba(200,149,42,0.15);
    background: rgba(200,149,42,0.04);
    padding: 2rem 0;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
}
.stat-item {
    text-align: center;
    padding: 1rem 1.5rem;
    border-right: 1px solid rgba(200,149,42,0.12);
}
.stat-item:last-child { border-right: none; }
.stat-num {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 2.8rem;
    color: var(--gold);
    letter-spacing: 0.06em;
    line-height: 1;
    margin-bottom: 0.25rem;
}
.stat-label { font-size: 0.72rem; color: rgba(245,240,232,0.45); letter-spacing: 0.1em; text-transform: uppercase; }

/* ── FEATURES ────────────────────────────────────────────────────────────── */
.section { padding: 5rem 0; }
.section-tag {
    font-size: 0.65rem;
    font-weight: 500;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 0.75rem;
}
.section-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(2rem, 5vw, 3.5rem);
    letter-spacing: 0.05em;
    color: var(--cream);
    margin-bottom: 0.75rem;
    line-height: 1;
}
.section-body {
    font-size: 0.95rem;
    color: rgba(245,240,232,0.5);
    font-weight: 300;
    line-height: 1.7;
    max-width: 520px;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    background: rgba(200,149,42,0.1);
    border: 1px solid rgba(200,149,42,0.1);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 3.5rem;
}
.feature-card {
    background: rgba(26,18,8,0.6);
    padding: 1.75rem;
    transition: background 0.2s;
}
.feature-card:hover { background: rgba(200,149,42,0.06); }
.feature-icon {
    width: 40px; height: 40px;
    background: rgba(200,149,42,0.12);
    border: 1px solid rgba(200,149,42,0.2);
    border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 1rem;
}
.feature-icon svg { width: 18px; height: 18px; stroke: var(--gold); fill: none; stroke-width: 1.5; }
.feature-title {
    font-weight: 500;
    font-size: 0.92rem;
    color: var(--cream);
    margin-bottom: 0.45rem;
}
.feature-desc {
    font-size: 0.8rem;
    color: rgba(245,240,232,0.45);
    line-height: 1.6;
}

/* ── WORKFLOW ────────────────────────────────────────────────────────────── */
.workflow-section { padding: 5rem 0; background: rgba(200,149,42,0.03); }
.workflow-steps {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0;
    margin-top: 3rem;
    position: relative;
}
.workflow-step {
    text-align: center;
    padding: 1.25rem 0.5rem;
    position: relative;
}
.workflow-step::after {
    content: '→';
    position: absolute;
    right: -6px; top: 50%;
    transform: translateY(-50%);
    color: rgba(200,149,42,0.3);
    font-size: 1rem;
}
.workflow-step:last-child::after { display: none; }
.step-num {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 0.75rem;
    color: var(--gold);
    letter-spacing: 0.12em;
    margin-bottom: 0.35rem;
}
.step-name {
    font-size: 0.72rem;
    font-weight: 500;
    color: var(--cream);
    margin-bottom: 0.25rem;
}
.step-desc { font-size: 0.65rem; color: rgba(245,240,232,0.35); line-height: 1.4; }

/* ── SELLING POINTS VISUAL ───────────────────────────────────────────────── */
.floors-section { padding: 5rem 0; }
.floors-demo {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-top: 3rem;
}
.floor-card {
    background: rgba(200,149,42,0.05);
    border: 1px solid rgba(200,149,42,0.14);
    border-radius: 4px;
    overflow: hidden;
}
.floor-card-header {
    background: var(--ink);
    border-bottom: 2px solid var(--gold);
    padding: 0.85rem 1.15rem;
    display: flex; align-items: center; gap: 0.75rem;
}
.floor-code {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.4rem;
    color: var(--gold);
    letter-spacing: 0.1em;
    line-height: 1;
}
.floor-card-body { padding: 1rem 1.15rem; }
.floor-stat { display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid rgba(200,149,42,0.08); }
.floor-stat:last-child { border-bottom: none; }
.floor-stat-label { font-size: 0.72rem; color: rgba(245,240,232,0.45); }
.floor-stat-val { font-size: 0.72rem; font-weight: 500; color: var(--cream); font-family: monospace; }

/* ── LOCATION / SUBCONTRACTOR VISUAL ─────────────────────────────────────── */
.locs-grid {
    display: flex; gap: 0.75rem; margin-top: 1.25rem; flex-wrap: wrap;
}
.loc-pill {
    display: flex; align-items: center; gap: 0.5rem;
    background: rgba(200,149,42,0.08);
    border: 1px solid rgba(200,149,42,0.18);
    border-radius: 3px;
    padding: 0.4rem 0.85rem;
}
.loc-pill .code {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.1rem; color: var(--gold); line-height: 1;
}
.loc-pill .name { font-size: 0.72rem; color: rgba(245,240,232,0.5); }
.loc-pill.primary { border-color: var(--gold); background: rgba(200,149,42,0.15); }
.loc-pill.primary .name { color: rgba(245,240,232,0.7); }

/* ── CTA SECTION ─────────────────────────────────────────────────────────── */
.cta-section {
    padding: 6rem 0;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.cta-section::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 600px 400px at 50% 50%, rgba(200,149,42,0.08) 0%, transparent 70%);
    pointer-events: none;
}
.cta-inner { position: relative; z-index: 1; }
.cta-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(2.5rem, 6vw, 5rem);
    letter-spacing: 0.05em;
    color: var(--cream);
    margin-bottom: 1rem;
    line-height: 1;
}
.cta-title span { color: var(--gold); }
.cta-sub {
    font-size: 0.95rem;
    color: rgba(245,240,232,0.45);
    margin-bottom: 2.5rem;
    font-weight: 300;
}

/* ── FOOTER ──────────────────────────────────────────────────────────────── */
footer {
    border-top: 1px solid rgba(200,149,42,0.12);
    padding: 2.5rem 0;
}
.footer-inner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.footer-left { font-size: 0.78rem; color: rgba(245,240,232,0.35); }
.footer-right { font-size: 0.72rem; color: rgba(245,240,232,0.25); text-align: right; }
.footer-brand {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.2rem;
    color: rgba(200,149,42,0.5);
    letter-spacing: 0.1em;
    margin-bottom: 0.25rem;
}

/* ── GOLD DIVIDER ────────────────────────────────────────────────────────── */
.gold-rule {
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(200,149,42,0.4), transparent);
    border: none;
    margin: 0;
}

/* ── RESPONSIVE ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .features-grid  { grid-template-columns: repeat(2, 1fr); }
    .workflow-steps { grid-template-columns: repeat(4, 1fr); }
    .stats-grid     { grid-template-columns: repeat(2, 1fr); }
    .floors-demo    { grid-template-columns: 1fr; }
    .nav-links      { display: none; }
}
@media (max-width: 600px) {
    .features-grid  { grid-template-columns: 1fr; }
    .workflow-steps { grid-template-columns: repeat(2, 1fr); }
    .stats-grid     { grid-template-columns: repeat(2, 1fr); }
    .workflow-step::after { display: none; }
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════ NAVBAR -->
<nav>
    <a href="#" class="nav-logo">
        <div class="nav-logo-icon">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none">
                <path d="M10 2C6 2 3 5 3 9c0 2.5 1.5 4.5 3.5 5.5L10 18l3.5-3.5C15.5 13.5 17 11.5 17 9c0-4-3-7-7-7z" fill="#1a1208"/>
            </svg>
        </div>
        Flowork
    </a>
    <div class="nav-links">
        <a href="#features">Features</a>
        <a href="#workflow">Workflow</a>
        <a href="#floors">Multi-Floor</a>
        <a href="login.php" class="nav-cta">Sign In →</a>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════════ HERO -->
<section class="hero">
    <div class="hero-watermark">FLOWORK</div>
    <div class="hero-inner">
        <div class="hero-eyebrow">
            <span class="eyebrow-dot"></span>
            Zimbabwe Tobacco Auction System
            <span class="eyebrow-dot"></span>
        </div>

        <h1>
            FLOOR AUCTION<br>
            <span>MANAGED.</span>
        </h1>

        <p class="hero-sub">
            End-to-end tobacco auction management — from grower registration and
            bale weighing to floor selling, settlement and reporting.
            Multi-floor. Multi-contractor. Real-time.
        </p>

        <div class="hero-actions">
            <a href="login.php" class="btn-primary-hero">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2h4v12h-4M6 11l4-3-4-3"/></svg>
                Sign In to Flowork
            </a>
            <a href="#features" class="btn-ghost-hero">
                See Features
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3l5 5-5 5M3 8h10"/></svg>
            </a>
        </div>
    </div>
</section>

<hr class="gold-rule">

<!-- ═══════════════════════════════════════════════════════════════ STATS -->
<div class="stats-strip">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-num">∞</div>
                <div class="stat-label">Selling Points</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">A–D</div>
                <div class="stat-label">Bale Locations</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">PDF</div>
                <div class="stat-label">Instant Tickets</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">100%</div>
                <div class="stat-label">Offline-Ready</div>
            </div>
        </div>
    </div>
</div>

<hr class="gold-rule">

<!-- ═══════════════════════════════════════════════════════════════ FEATURES -->
<section class="section" id="features">
    <div class="container">
        <div class="center">
            <div class="section-tag">Platform Capabilities</div>
            <div class="section-title">Everything the<br><span class="gold">auction floor needs</span></div>
            <p class="section-body" style="margin:0 auto">
                Built specifically for Zimbabwe's tobacco auction environment —
                from TIMB grading and RBZ split calculations to subcontractor management.
            </p>
        </div>

        <div class="features-grid">

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
                </div>
                <div class="feature-title">Grower Management</div>
                <div class="feature-desc">Register growers per selling point. Bulk CSV upload with column mapping. Full farm and provincial data.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><rect x="1" y="5" width="10" height="8" rx="1"/><path d="M11 8l3 2v3h-3V8z"/><circle cx="4" cy="13" r="1.5"/><circle cx="12" cy="13" r="1.5"/></svg>
                </div>
                <div class="feature-title">Transporter & Location</div>
                <div class="feature-desc">Assign transporters to bale locations (A, B, C, D). Each location maps to a subcontractor with contact details.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><path d="M3 5h10M8 1v2"/><ellipse cx="8" cy="5" rx="5" ry="2"/><path d="M4 5l-1 8h10L12 5"/></svg>
                </div>
                <div class="feature-title">Scale & Weigh</div>
                <div class="feature-desc">Capture bale mass and temp barcodes. Keyboard-first flow for fast throughput at the weighbridge.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><rect x="2" y="1" width="12" height="14" rx="1"/><path d="M5 5h6M5 8h6M5 11h4"/></svg>
                </div>
                <div class="feature-title">Bale Tickets (PDF)</div>
                <div class="feature-desc">Generate 215×75mm barcode tickets matching TIMB GDN format. Resume interrupted print runs. Split bales across sales.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><path d="M8 2v12M2 8h12"/></svg>
                </div>
                <div class="feature-title">Junus & Split Management</div>
                <div class="feature-desc">Assign weighed bales to grower groups. Split bales across multiple sales IDs. Confirm splits to lock groups.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><path d="M3 12l4-4 3 3 5-6"/></svg>
                </div>
                <div class="feature-title">Auction Floor Capture</div>
                <div class="feature-desc">Capture buyer, TIMB grade, and price per barcode. Buyer–grade cascade dropdowns. Duplicate scan protection.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M5 8h6M8 5v6"/></svg>
                </div>
                <div class="feature-title">Balance & Settlement</div>
                <div class="feature-desc">Balance grower slots, run RBZ split and stop orders, compute net USD and ZiG payouts with deductions.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><path d="M4 13V6M8 13V3M12 13V8"/></svg>
                </div>
                <div class="feature-title">Reports by Floor & Location</div>
                <div class="feature-desc">Reports scoped to selling point or subcontractor location. Admin aggregate view across all floors. CSV export.</div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 16 16"><path d="M2 4h12v10H2zM2 4l6 5 6-5"/></svg>
                </div>
                <div class="feature-title">Printable Sales Sheets</div>
                <div class="feature-desc">Professional A4 settlement documents with company logo, grower details, bale table, deductions, and signature lines.</div>
            </div>

        </div>
    </div>
</section>

<hr class="gold-rule">

<!-- ═══════════════════════════════════════════════════════════════ WORKFLOW -->
<section class="workflow-section" id="workflow">
    <div class="container">
        <div class="center">
            <div class="section-tag">Auction Day Workflow</div>
            <div class="section-title">Seven steps.<br><span class="gold">One system.</span></div>
        </div>

        <div class="workflow-steps">
            <div class="workflow-step">
                <div class="step-num">01</div>
                <div class="step-name">Settings</div>
                <div class="step-desc">Set selling date & exchange rate</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">02</div>
                <div class="step-name">Scale</div>
                <div class="step-desc">Weigh bales, capture temp barcodes</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">03</div>
                <div class="step-name">Junus</div>
                <div class="step-desc">Assign bales to grower groups</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">04</div>
                <div class="step-name">Tickets</div>
                <div class="step-desc">Print GDN-format barcode tickets</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">05</div>
                <div class="step-name">Floor</div>
                <div class="step-desc">Capture buyer, grade & price</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">06</div>
                <div class="step-name">Balance</div>
                <div class="step-desc">Run stop orders & settlements</div>
            </div>
            <div class="workflow-step">
                <div class="step-num">07</div>
                <div class="step-name">Reports</div>
                <div class="step-desc">Print sheets, export by floor</div>
            </div>
        </div>
    </div>
</section>

<hr class="gold-rule">

<!-- ═══════════════════════════════════════════════════════════════ MULTI-FLOOR -->
<section class="floors-section" id="floors">
    <div class="container" style="display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center">

        <div>
            <div class="section-tag">Multi-Floor Architecture</div>
            <div class="section-title">Parallel floors.<br><span class="gold">One database.</span></div>
            <p class="section-body" style="margin-top:0.75rem">
                Multiple selling points run simultaneously — each floor sees only its own
                data. Floor managers switch between assigned floors via the topbar.
                Admins get an aggregate view across everything.
            </p>

            <div style="margin-top:1.75rem">
                <div style="font-size:0.65rem;font-weight:500;letter-spacing:0.14em;text-transform:uppercase;color:var(--gold);margin-bottom:0.85rem">Bale Locations per Floor</div>
                <div class="locs-grid">
                    <div class="loc-pill primary">
                        <span class="code">A</span>
                        <div>
                            <div class="name" style="color:rgba(245,240,232,0.8);font-weight:500">Main Bay</div>
                            <div class="name" style="font-size:0.62rem">Primary contractor</div>
                        </div>
                    </div>
                    <div class="loc-pill">
                        <span class="code">B</span>
                        <div><div class="name">East Wing</div><div class="name" style="font-size:0.62rem">Subcontractor</div></div>
                    </div>
                    <div class="loc-pill">
                        <span class="code">C</span>
                        <div><div class="name">West Bay</div><div class="name" style="font-size:0.62rem">Subcontractor</div></div>
                    </div>
                    <div class="loc-pill">
                        <span class="code">D</span>
                        <div><div class="name">Extension</div><div class="name" style="font-size:0.62rem">Subcontractor</div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="floors-demo">
            <div class="floor-card">
                <div class="floor-card-header">
                    <div>
                        <div class="floor-code">HRE-01</div>
                        <div style="font-size:0.65rem;color:rgba(245,240,232,0.4)">Harare Main Floor</div>
                    </div>
                    <div style="margin-left:auto;background:rgba(61,107,53,0.25);border:1px solid rgba(61,107,53,0.4);color:#6ee7b7;font-size:0.6rem;font-weight:500;padding:2px 8px;border-radius:999px;letter-spacing:0.08em">LIVE</div>
                </div>
                <div class="floor-card-body">
                    <div class="floor-stat"><span class="floor-stat-label">Growers</span><span class="floor-stat-val">142</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Bales weighed</span><span class="floor-stat-val">1,847</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Bales sold</span><span class="floor-stat-val">1,204</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Gross USD</span><span class="floor-stat-val">$48,320.00</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Locations</span><span class="floor-stat-val">A · B · C · D</span></div>
                </div>
            </div>

            <div class="floor-card">
                <div class="floor-card-header">
                    <div>
                        <div class="floor-code">MRD-01</div>
                        <div style="font-size:0.65rem;color:rgba(245,240,232,0.4)">Marondera Floor</div>
                    </div>
                    <div style="margin-left:auto;background:rgba(61,107,53,0.25);border:1px solid rgba(61,107,53,0.4);color:#6ee7b7;font-size:0.6rem;font-weight:500;padding:2px 8px;border-radius:999px;letter-spacing:0.08em">LIVE</div>
                </div>
                <div class="floor-card-body">
                    <div class="floor-stat"><span class="floor-stat-label">Growers</span><span class="floor-stat-val">89</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Bales weighed</span><span class="floor-stat-val">963</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Bales sold</span><span class="floor-stat-val">720</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Gross USD</span><span class="floor-stat-val">$29,150.00</span></div>
                    <div class="floor-stat"><span class="floor-stat-label">Locations</span><span class="floor-stat-val">A · B · C</span></div>
                </div>
            </div>
        </div>

    </div>
</section>

<hr class="gold-rule">

<!-- ═══════════════════════════════════════════════════════════════ CTA -->
<section class="cta-section">
    <div class="container">
        <div class="cta-inner">
            <div class="cta-title">READY TO<br><span>RUN THE FLOOR?</span></div>
            <p class="cta-sub">Sign in to your Flowork dashboard and start today's auction.</p>
            <a href="login.php" class="btn-primary-hero" style="font-size:1rem;padding:0.9rem 2.5rem">
                Sign In to Flowork →
            </a>
            <div style="margin-top:1.5rem;font-size:0.72rem;color:rgba(245,240,232,0.2)">
                Core Africa Group · Flowork Auction System · Zimbabwe
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════ FOOTER -->
<footer>
    <div class="container">
        <div class="footer-inner">
            <div>
                <div class="footer-brand">FLOWORK</div>
                <div class="footer-left">Tobacco Floor Auction Management System</div>
                <div class="footer-left" style="margin-top:3px">Core Africa Group · Zimbabwe</div>
            </div>
            <div class="footer-right">
                <div>Flowork &copy; <?= date('Y') ?></div>
                <div style="margin-top:3px">All rights reserved</div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>
