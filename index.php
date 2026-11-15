<?php
// Redirect logged-in users straight to dashboard
session_start();
if (!empty($_SESSION['userid'])) {
    header("Location: dashboard.php"); exit;
}
$lp_theme = in_array($_COOKIE['flowork_theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['flowork_theme'] : '';
$lp_ic = function (string $n): string {
    $p = [
        'book'    => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M8 14h3M8 17h6"/>',
        'truck'   => '<path d="M2 6h11v10H2zM13 10h4l4 4v2h-8"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'file'    => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'scale'   => '<path d="M12 3v18M6 21h12M5 7h14"/><path d="m5 7-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
        'ticket'  => '<path d="M3 7a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4z"/><path d="M8 9v6M11 9v6M14 9v6"/>',
        'gavel'   => '<path d="m14 13-7.5 7.5a2.1 2.1 0 0 1-3-3L11 10"/><path d="m16 16 6-6M8 8l6-6M9 7l8 8M21 11l-8-8"/>',
        'balance' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'pay'     => '<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'report'  => '<path d="M4 4h16v16H4z"/><path d="M8 15v-3M12 15V9M16 15v-5"/>',
        'shield'  => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'box'     => '<path d="m21 8-9-5-9 5v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'live'    => '<circle cx="12" cy="12" r="2"/><path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 16.2a6 6 0 0 0 0-8.4M4.9 4.9a10 10 0 0 0 0 14.2M19.1 19.1a10 10 0 0 0 0-14.2"/>',
        'floor'   => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>',
        'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'login'   => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
        'check'   => '<path d="M20 6 9 17l-5-5"/>',
    ][$n] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$lp_leaf = '<svg viewBox="0 0 24 24" fill="none" stroke="#E2B04A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-9 0 12-4 16-9 16z"/><path d="M4 20c4-4 7-6 11-8"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flowork — Tobacco floor management</title>
<meta name="description" content="Flowork runs Zimbabwe tobacco auction floors from grower booking and TIMB bale files to weighing, selling, balancing with RBZ retention, and grower payments.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="includes/layout.css?v=9">
<link rel="preload" as="image" href="images/login-tobacco-bales.jpg">
<style>
/* Landing page. Colours come from the shared tokens in includes/layout.css
   (light and dark), like the sign-in page; the photo panels stay dark. */
html{scroll-behavior:smooth}
html,body{height:auto}
body.lp{font-size:16px;line-height:1.6;background:var(--page);color:var(--text)}
.lp a{color:inherit}
.lp-wrap{width:100%;max-width:1180px;margin:0 auto;padding:0 1.5rem}

/* Logo */
.lp-mark{display:flex;align-items:center;gap:11px;text-decoration:none}
.lp-mark .dot{width:38px;height:38px;border-radius:10px;background:#2A231A;display:flex;align-items:center;justify-content:center;border:1px solid rgba(226,176,74,.25)}
.lp-mark .dot svg{width:20px;height:20px}
.lp-mark span{font-family:'Bebas Neue',sans-serif;font-size:1.75rem;letter-spacing:.12em;line-height:1}

/* Buttons */
.lp-btn{display:inline-flex;align-items:center;gap:.5rem;min-height:46px;padding:0 1.35rem;border-radius:10px;font-weight:600;font-size:.95rem;text-decoration:none;border:1px solid transparent;transition:background .15s,border-color .15s,transform .15s}
.lp-btn svg{width:18px;height:18px}
.lp-btn-gold{background:#E2B04A;color:#1F1A14}
.lp-btn-gold:hover{background:#ECC066}
.lp-btn-ghost{border-color:rgba(244,239,230,.35);color:#F4EFE6}
.lp-btn-ghost:hover{background:rgba(244,239,230,.1);border-color:rgba(244,239,230,.6)}

/* Navigation over the photo */
.lp-nav{position:absolute;top:0;left:0;right:0;z-index:5}
.lp-nav .lp-wrap{display:flex;align-items:center;gap:1.5rem;height:76px}
.lp-nav .lp-mark{color:#F4EFE6}
.lp-nav-links{margin-left:auto;display:flex;align-items:center;gap:1.6rem;font-size:.92rem}
.lp-nav-links a:not(.lp-btn){color:#D8CFC0;text-decoration:none}
.lp-nav-links a:not(.lp-btn):hover{color:#fff}
.lp-nav .lp-btn{min-height:40px;padding:0 1rem;font-size:.88rem}

/* Hero */
.lp-hero{position:relative;min-height:min(92vh,820px);display:flex;align-items:flex-end;color:#F4EFE6;
  background:#14110D url('images/login-tobacco-bales.jpg') center 55%/cover no-repeat}
.lp-hero::before{content:'';position:absolute;inset:0;
  background:linear-gradient(90deg,rgba(20,17,13,.82) 0%,rgba(20,17,13,.6) 45%,rgba(20,17,13,.1) 85%),
             linear-gradient(180deg,rgba(20,17,13,.7) 0%,rgba(20,17,13,.15) 30%,rgba(20,17,13,.3) 60%,rgba(20,17,13,.95) 100%)}
.lp-hero .lp-wrap{position:relative;padding-top:8rem;padding-bottom:4.5rem}
.lp-eyebrow{display:inline-flex;align-items:center;gap:.5rem;font-size:.8rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#E2B04A;background:rgba(20,17,13,.55);border:1px solid rgba(226,176,74,.3);padding:.35rem .8rem;border-radius:999px}
.lp-hero h1{margin-top:1.1rem;font-size:clamp(2.2rem,5vw,3.6rem);font-weight:600;line-height:1.08;letter-spacing:-.02em;max-width:760px;text-shadow:0 2px 18px rgba(0,0,0,.35)}
.lp-hero p.lead{margin-top:1.1rem;font-size:clamp(1rem,1.6vw,1.15rem);color:#D8CFC0;max-width:620px}
.lp-hero-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.9rem}
.lp-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;margin-top:3.25rem;background:rgba(244,239,230,.14);border:1px solid rgba(244,239,230,.14);border-radius:14px;overflow:hidden}
.lp-fact{background:rgba(20,17,13,.62);padding:1rem 1.15rem;backdrop-filter:blur(4px)}
.lp-fact strong{display:block;font-size:1.02rem;font-weight:600;color:#F4EFE6}
.lp-fact span{font-size:.84rem;color:#B5AA98}

/* Sections */
.lp-section{padding:5.5rem 0}
.lp-section.alt{background:var(--surface);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.lp-tag{font-size:.78rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-text)}
.lp-h2{margin-top:.5rem;font-size:clamp(1.7rem,3vw,2.35rem);font-weight:600;line-height:1.15;letter-spacing:-.015em;max-width:720px}
.lp-intro{margin-top:.8rem;color:var(--text-muted);max-width:640px;font-size:1.02rem}

/* Features */
.lp-features{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-top:2.75rem}
.lp-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:1.4rem;box-shadow:var(--shadow-sm);transition:box-shadow .15s,border-color .15s}
.lp-section.alt .lp-card{background:var(--page)}
.lp-card:hover{box-shadow:var(--shadow-md);border-color:var(--accent)}
.lp-icon{width:42px;height:42px;border-radius:11px;background:var(--accent-tint);color:var(--accent-text);display:flex;align-items:center;justify-content:center}
.lp-icon svg{width:21px;height:21px}
.lp-card h3{margin-top:1rem;font-size:1.05rem;font-weight:600;line-height:1.3}
.lp-card p{margin-top:.4rem;color:var(--text-muted);font-size:.93rem}
.lp-card ul{list-style:none;margin-top:.75rem;display:flex;flex-direction:column;gap:.35rem}
.lp-card li{display:flex;gap:.45rem;font-size:.88rem;color:var(--text-soft)}
.lp-card li svg{width:16px;height:16px;color:var(--ok-text);flex-shrink:0;margin-top:3px}

/* Workflow */
.lp-steps{list-style:none;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-top:2.75rem;counter-reset:step}
.lp-step{position:relative;padding:1.25rem 1.2rem 1.3rem;border-radius:14px;border:1px solid var(--line);background:var(--page)}
.lp-step::before{counter-increment:step;content:counter(step,decimal-leading-zero);font-family:'Bebas Neue',sans-serif;font-size:1.6rem;letter-spacing:.06em;color:var(--accent);line-height:1}
.lp-step h3{margin-top:.55rem;font-size:1rem;font-weight:600}
.lp-step p{margin-top:.25rem;font-size:.88rem;color:var(--text-muted)}
.lp-step small{display:inline-block;margin-top:.6rem;font-size:.74rem;font-weight:600;color:var(--accent-text);background:var(--accent-tint);padding:1px 8px;border-radius:999px}

/* Floors */
.lp-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:4rem;align-items:center}
.lp-list{list-style:none;margin-top:1.5rem;display:flex;flex-direction:column;gap:.85rem}
.lp-list li{display:flex;gap:.75rem;align-items:flex-start}
.lp-list .lp-icon{width:36px;height:36px;flex-shrink:0}
.lp-list .lp-icon svg{width:18px;height:18px}
.lp-list strong{display:block;font-weight:600;font-size:.98rem}
.lp-list span{color:var(--text-muted);font-size:.9rem}
.lp-mock{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:1.5rem;box-shadow:var(--shadow-md)}
.lp-mock-head{display:flex;align-items:center;gap:.6rem;font-size:.88rem;color:var(--text-muted);padding-bottom:1rem;border-bottom:1px solid var(--line);margin-bottom:1rem}
.lp-mock-head b{color:var(--text);font-weight:600}
.lp-avatar{width:32px;height:32px;border-radius:50%;background:var(--accent-tint);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:600}
.lp-pill{margin-left:auto;font-size:.74rem;font-weight:600;color:var(--accent-text);background:var(--accent-tint);padding:2px 9px;border-radius:999px}
.lp-floors{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}
.lp-floor{border:1px solid var(--line);border-radius:12px;padding:.9rem;background:var(--page)}
.lp-floor.on{border-color:var(--ok-text);background:var(--ok-tint)}
.lp-floor .lp-icon{width:32px;height:32px;border-radius:9px}
.lp-floor .lp-icon svg{width:17px;height:17px}
.lp-floor b{display:block;margin-top:.55rem;font-size:.9rem;font-weight:600}
.lp-floor i{font-style:normal;font-size:.72rem;font-weight:600;color:var(--accent-text);background:var(--accent-tint);padding:1px 7px;border-radius:999px}
.lp-floor em{display:block;margin-top:.45rem;font-style:normal;font-size:.76rem;color:var(--text-muted)}

/* Closing band */
.lp-cta{position:relative;color:#F4EFE6;text-align:center;padding:6rem 0;
  background:#14110D url('images/login-tobacco-field.jpg') center/cover no-repeat}
.lp-cta::before{content:'';position:absolute;inset:0;background:rgba(20,17,13,.78)}
.lp-cta .lp-wrap{position:relative}
.lp-cta h2{font-size:clamp(1.8rem,3.4vw,2.6rem);font-weight:600;letter-spacing:-.015em;line-height:1.15}
.lp-cta p{margin:.8rem auto 0;color:#D8CFC0;max-width:520px}
.lp-cta .lp-hero-actions{justify-content:center}

/* Footer */
.lp-foot{background:#14110D;color:#B5AA98;font-size:.86rem;padding:2rem 0}
.lp-foot .lp-wrap{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between}
.lp-foot .lp-mark{color:#F4EFE6}
.lp-foot .lp-mark span{font-size:1.4rem}
.lp-foot .lp-mark .dot{width:32px;height:32px}
.lp-foot-links{display:flex;gap:1.25rem}
.lp-foot-links a{text-decoration:none}
.lp-foot-links a:hover{color:#F4EFE6}

@media (max-width:1000px){
  .lp-features{grid-template-columns:repeat(2,minmax(0,1fr))}
  .lp-steps{grid-template-columns:repeat(2,minmax(0,1fr))}
  .lp-split{grid-template-columns:1fr;gap:2.5rem}
}
@media (max-width:720px){
  .lp-nav-links a:not(.lp-btn){display:none}
  .lp-wrap{padding:0 1rem}
  .lp-hero .lp-wrap{padding-top:6.5rem;padding-bottom:2.5rem}
  .lp-facts{grid-template-columns:repeat(2,minmax(0,1fr));margin-top:2.25rem}
  .lp-section{padding:3.75rem 0}
  .lp-features{grid-template-columns:1fr}
  .lp-hero-actions .lp-btn{flex:1;justify-content:center}
}
@media (max-width:420px){
  .lp-steps,.lp-floors{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
</style>
</head>
<body class="lp <?= $lp_theme ? 'theme-' . $lp_theme : '' ?>">
<?php if (!$lp_theme): ?>
<script>
// No saved choice: follow the device's light/dark setting
if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) document.body.classList.add('theme-dark');
</script>
<?php endif; ?>

<header class="lp-nav">
  <div class="lp-wrap">
    <a href="index.php" class="lp-mark" aria-label="Flowork home"><span class="dot"><?= $lp_leaf ?></span><span>Flowork</span></a>
    <nav class="lp-nav-links" aria-label="Page sections">
      <a href="#features">Features</a>
      <a href="#workflow">How it works</a>
      <a href="#floors">Floors &amp; security</a>
      <a href="login.php" class="lp-btn lp-btn-gold"><?= $lp_ic('login') ?>Sign in</a>
    </nav>
  </div>
</header>

<main>
<section class="lp-hero">
  <div class="lp-wrap">
    <span class="lp-eyebrow">Zimbabwe tobacco auction floors</span>
    <h1>Tobacco floor management, from booking to payment.</h1>
    <p class="lead">Book growers, import TIMB bale files, weigh and sell bales, balance deliveries with RBZ retention and stop orders, and pay growers, all on one floor system.</p>
    <div class="lp-hero-actions">
      <a href="login.php" class="lp-btn lp-btn-gold"><?= $lp_ic('login') ?>Sign in to Flowork</a>
      <a href="#workflow" class="lp-btn lp-btn-ghost">See how it works<?= $lp_ic('arrow') ?></a>
    </div>
    <div class="lp-facts">
      <div class="lp-fact"><strong>Every floor</strong><span>Each floor sees its own data</span></div>
      <div class="lp-fact"><strong>USD &amp; ZiG</strong><span>RBZ retention per delivery</span></div>
      <div class="lp-fact"><strong>TIMB ready</strong><span>Bale files, grades, reports</span></div>
      <div class="lp-fact"><strong>Two-factor</strong><span>Sign-in for administrators</span></div>
    </div>
  </div>
</section>

<section class="lp-section" id="features">
  <div class="lp-wrap">
    <div class="lp-tag">Features</div>
    <h2 class="lp-h2">Everything the auction floor needs, in one place.</h2>
    <p class="lp-intro">Built for the way Zimbabwe's floors work: TIMB grades and bale files, buyer marks, RBZ retention, creditors' stop orders and grower payments in USD and ZiG.</p>

    <div class="lp-features">
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('book') ?></div>
        <h3>Booking &amp; receiving</h3>
        <p>Book growers for a sale day and receive them on transporter delivery notes.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Import TIMB bale files (.v); growers and bale counts go straight to booking and receiving</li>
          <li><?= $lp_ic('check') ?>Transporters, trucks and bale locations per floor</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('scale') ?></div>
        <h3>Scale, junus &amp; tickets</h3>
        <p>Weigh bales at the scale, group them per grower and print barcode tickets.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Keyboard-first weighing and scale sync</li>
          <li><?= $lp_ic('check') ?>Split bales across sales and resume print runs</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('gavel') ?></div>
        <h3>Floor selling</h3>
        <p>Capture buyer, buyer mark, TIMB grade and price for every bale.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Grade lists per buyer and duplicate-scan protection</li>
          <li><?= $lp_ic('check') ?>Live floor view while the sale runs</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('balance') ?></div>
        <h3>Balance &amp; settlement</h3>
        <p>Balance each delivery and work out what every grower is owed.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Stop orders and company deductions, applied once</li>
          <li><?= $lp_ic('check') ?>RBZ retention per delivery; a rate change recalculates unpaid sales</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('pay') ?></div>
        <h3>Grower payments</h3>
        <p>Pay growers in batches, with checks before money moves.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Draft, review, approve and release payment batches</li>
          <li><?= $lp_ic('check') ?>Bank files, receipts and reconciliation</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('report') ?></div>
        <h3>Reports &amp; dashboards</h3>
        <p>End-of-day reports and the figures management asks for.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Sales sheets, buyer invoices and TIMB reports, exported as one ZIP</li>
          <li><?= $lp_ic('check') ?>Floor and management dashboards, prices by grade and buyer</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('file') ?></div>
        <h3>Creditors &amp; stop orders</h3>
        <p>Contractors and creditors load stop orders, and the floor deducts them at balancing.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Creditor portal and loan repayments</li>
          <li><?= $lp_ic('check') ?>Stop orders that arrive after balancing are applied too</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('box') ?></div>
        <h3>After the sale</h3>
        <p>Follow the tobacco once it leaves the floor.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Packhouse, processing and dispatch</li>
          <li><?= $lp_ic('check') ?>Logistics, vehicles and fuel requests</li>
        </ul>
      </article>
      <article class="lp-card">
        <div class="lp-icon"><?= $lp_ic('shield') ?></div>
        <h3>Control &amp; audit</h3>
        <p>Who did what, and a way back if something goes wrong.</p>
        <ul>
          <li><?= $lp_ic('check') ?>Audit log of changes and notifications for rate changes</li>
          <li><?= $lp_ic('check') ?>Encrypted nightly backups</li>
        </ul>
      </article>
    </div>
  </div>
</section>

<section class="lp-section alt" id="workflow">
  <div class="lp-wrap">
    <div class="lp-tag">How it works</div>
    <h2 class="lp-h2">One sale day, eight steps, one system.</h2>
    <p class="lp-intro">Each step picks up where the last one left off, so nothing is typed twice.</p>
    <ol class="lp-steps">
      <li class="lp-step"><h3>Open the day</h3><p>Set the sale date and the exchange rate.</p><small>Day control</small></li>
      <li class="lp-step"><h3>Book</h3><p>Book growers or import the TIMB bale file.</p><small>Booking</small></li>
      <li class="lp-step"><h3>Receive</h3><p>Growers arrive on transporter delivery notes.</p><small>Receiving</small></li>
      <li class="lp-step"><h3>Weigh &amp; ticket</h3><p>Weigh bales, group them, print tickets.</p><small>Scale</small></li>
      <li class="lp-step"><h3>Sell</h3><p>Buyer, grade and price for every bale.</p><small>Floor</small></li>
      <li class="lp-step"><h3>Balance</h3><p>Stop orders, deductions and RBZ retention.</p><small>Settlement</small></li>
      <li class="lp-step"><h3>Pay</h3><p>Approve and release grower payments.</p><small>Payments</small></li>
      <li class="lp-step"><h3>Report</h3><p>Sales sheets, invoices and EOD exports.</p><small>Reports</small></li>
    </ol>
  </div>
</section>

<section class="lp-section" id="floors">
  <div class="lp-wrap lp-split">
    <div>
      <div class="lp-tag">Floors &amp; security</div>
      <h2 class="lp-h2">Several floors, kept apart.</h2>
      <p class="lp-intro">Each floor works on its own data. Floor staff see only their floor; a master user can move between floors and manage users for all of them.</p>
      <ul class="lp-list">
        <li><span class="lp-icon"><?= $lp_ic('floor') ?></span><div><strong>Per-floor access</strong><span>Users belong to a floor; their screens and reports show only that floor.</span></div></li>
        <li><span class="lp-icon"><?= $lp_ic('shield') ?></span><div><strong>Two-factor sign-in</strong><span>Administrators confirm their sign-in with an authenticator app.</span></div></li>
        <li><span class="lp-icon"><?= $lp_ic('live') ?></span><div><strong>Live notifications</strong><span>Rate changes and approvals show up on every open screen.</span></div></li>
      </ul>
    </div>
    <div class="lp-mock" aria-hidden="true">
      <div class="lp-mock-head"><span class="lp-avatar">M</span><span>Signed in as <b>manager</b></span><span class="lp-pill">Master user</span></div>
      <div class="lp-floors">
        <div class="lp-floor on"><span class="lp-icon"><?= $lp_ic('floor') ?></span><b>Harare Floor</b><i>HRE</i><em>Day open · selling</em></div>
        <div class="lp-floor"><span class="lp-icon"><?= $lp_ic('floor') ?></span><b>Marondera Floor</b><i>MRD</i><em>Balancing</em></div>
        <div class="lp-floor"><span class="lp-icon"><?= $lp_ic('floor') ?></span><b>Karoi Floor</b><i>KRI</i><em>Booking</em></div>
        <div class="lp-floor"><span class="lp-icon"><?= $lp_ic('floor') ?></span><b>Rusape Floor</b><i>RSP</i><em>Day closed</em></div>
      </div>
    </div>
  </div>
</section>

<section class="lp-cta">
  <div class="lp-wrap">
    <h2>Ready to run the floor?</h2>
    <p>Sign in to open today's sale, or ask your administrator for an account.</p>
    <div class="lp-hero-actions">
      <a href="login.php" class="lp-btn lp-btn-gold"><?= $lp_ic('login') ?>Sign in to Flowork</a>
    </div>
  </div>
</section>
</main>

<footer class="lp-foot">
  <div class="lp-wrap">
    <a href="index.php" class="lp-mark"><span class="dot"><?= $lp_leaf ?></span><span>Flowork</span></a>
    <nav class="lp-foot-links" aria-label="Footer">
      <a href="#features">Features</a>
      <a href="#workflow">How it works</a>
      <a href="login.php">Sign in</a>
    </nav>
    <div>&copy; <?= date('Y') ?> Core Africa Group · Zimbabwe</div>
  </div>
</footer>
</body>
</html>
