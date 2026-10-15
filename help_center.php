<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Help Center";
$active_page = "help_center";
include "includes/layout.php";

$modules = [
    ['icon'=>'📋','title'=>'Grower Booking','desc'=>'How to book growers for auction days','steps'=>['Go to Grower Booking from the sidebar','Click "Book Grower" or use the booking form','Select the grower, transporter, and sell date','Set the number of bales expected','Click Save — the grower will appear in the booked list']],
    ['icon'=>'⚖️','title'=>'Scale & Weighing','desc'=>'Capturing bale weights at the weighbridge','steps'=>['Open Scale from the Receiving section','Scan or type the temp barcode','Enter the bale mass in kg','The bale is saved to the queue','Continue scanning until all bales are weighed']],
    ['icon'=>'🎫','title'=>'Ticket Printing','desc'=>'Generating GDN barcode tickets','steps'=>['Go to Ticket Printing','Select the grower group (junus)','Click Generate Tickets','Print on 215×75mm ticket paper','Tickets contain barcodes matching TIMB GDN format']],
    ['icon'=>'🔨','title'=>'Selling Floor','desc'=>'Capturing buyer, grade and price per bale','steps'=>['Open Selling from Sale Processing','Scan the bale barcode','Select the buyer from the dropdown','Select the TIMB grade','Enter the price per kg','The sale is recorded automatically']],
    ['icon'=>'⚡','title'=>'Balancing','desc'=>'Running settlements and stop orders','steps'=>['Go to Sale Balancing','Select a booked grower with sold bales','Click Balance — the system calculates gross value','Stop orders are automatically deducted','Net USD and ZiG amounts are computed','Print the settlement sheet']],
    ['icon'=>'💰','title'=>'Payments','desc'=>'Processing grower payments','steps'=>['Go to Pay Dashboard','Review balanced batches ready for payment','Create a payment batch','Verify amounts and bank details','Generate the bank file for upload','Mark batch as paid']],
    ['icon'=>'📊','title'=>'Reports & Analytics','desc'=>'End-of-day reports and analytics','steps'=>['Go to EOD Reports for daily summaries','Use Analytics for trend analysis','Season Compare shows year-over-year data','All reports can be exported to CSV','Reports are filtered by your current floor']],
    ['icon'=>'⚙️','title'=>'Settings & Admin','desc'=>'System configuration','steps'=>['Settings: Exchange rate, selling date, floor config','Users: Add/remove system users','Audit Log: Track all system changes','Backup: Manual and auto-backup management','System Health: Monitor database and server status']],
];

$faqs = [
    ['q'=>'How do I switch between floors?','a'=>'Use the floor dropdown in the top bar. You can only see floors assigned to your account.'],
    ['q'=>'What happens if I scan the same barcode twice?','a'=>'The system has duplicate protection. It will warn you and prevent double-scanning.'],
    ['q'=>'Can I void a sold bale?','a'=>'Yes, go to Void Controls. You need admin authorization to void sales.'],
    ['q'=>'How is the ZiG amount calculated?','a'=>'ZiG = USD value × exchange rate. The rate is set in Settings or auto-fetched from RBZ.'],
    ['q'=>'What is a junus?','a'=>'A junus groups weighed bales to a specific grower for ticketing and selling.'],
    ['q'=>'How do stop orders work?','a'=>'Stop orders are deductions uploaded from creditors. They are automatically applied during balancing based on priority.'],
    ['q'=>'Can I re-offer a bale?','a'=>'Yes, use Re-Offer in Sale Processing. The bale goes back to the floor at a new price.'],
    ['q'=>'How do I export data?','a'=>'Most tables have a CSV export button. Use Reports for formatted exports.'],
    ['q'=>'What is the difference between Selling 1 and Selling 2?','a'=>'Selling 1 is the primary capture interface. Selling 2 is an alternative layout optimized for different workflows.'],
    ['q'=>'How do I add a new buyer?','a'=>'Go to Buyer Management and click Add Buyer. Set their code, grades, and contact details.'],
];
?>

<style>
.hc-module-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.85rem;margin-bottom:1.5rem; }
.hc-module { background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:all .2s;cursor:pointer; }
body.theme-dark .hc-module { background:var(--ink-soft); }
.hc-module:hover { border-color:var(--gold);box-shadow:0 4px 16px rgba(0,0,0,.06); }
.hc-module-head { display:flex;align-items:center;gap:.65rem;padding:.85rem 1rem;border-bottom:1px solid var(--border); }
.hc-module-icon { font-size:1.3rem; }
.hc-module-title { font-weight:500;font-size:.85rem; }
.hc-module-desc { font-size:.7rem;color:var(--muted); }
.hc-module-steps { display:none;padding:.75rem 1rem; }
.hc-module.open .hc-module-steps { display:block; }
.hc-module-steps ol { padding-left:1.2rem;font-size:.75rem;line-height:1.8;color:var(--ink); }
.hc-module-steps ol li { margin-bottom:.15rem; }
.hc-module-arrow { margin-left:auto;font-size:.7rem;color:var(--muted);transition:transform .2s; }
.hc-module.open .hc-module-arrow { transform:rotate(90deg); }
.hc-faq { border-bottom:1px solid var(--border);padding:.75rem 0;cursor:pointer; }
.hc-faq:last-child { border-bottom:none; }
.hc-faq-q { display:flex;align-items:center;justify-content:space-between;font-weight:500;font-size:.82rem; }
.hc-faq-a { display:none;font-size:.78rem;color:var(--muted);margin-top:.4rem;line-height:1.6;padding-left:.5rem;border-left:2px solid var(--gold); }
.hc-faq.open .hc-faq-a { display:block; }
.hc-faq-arrow { font-size:.7rem;color:var(--muted);transition:transform .2s; }
.hc-faq.open .hc-faq-arrow { transform:rotate(90deg); }
.hc-search { max-width:500px;margin:0 auto 1.5rem;position:relative; }
.hc-search input { width:100%;padding:.75rem 1rem .75rem 2.5rem;border:2px solid var(--border);border-radius:8px;font-size:.88rem;background:#fff;color:var(--ink);font-family:inherit; }
body.theme-dark .hc-search input { background:var(--ink-soft);color:var(--cream); }
.hc-search input:focus { border-color:var(--gold);outline:none; }
.hc-search-icon { position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:1rem;opacity:.4; }
</style>

<div class="page-header" style="text-align:center;display:block">
  <h2 style="font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:.08em">Help Center</h2>
  <p style="color:var(--muted);font-size:.85rem">Guides, FAQs, and documentation for Flowork</p>
</div>

<!-- Search -->
<div class="hc-search">
  <span class="hc-search-icon">🔍</span>
  <input type="text" id="hc-search" placeholder="Search help topics..." oninput="filterHelp(this.value)">
</div>

<!-- Module Guides -->
<div class="card mb-3">
  <div class="card-head"><h3>📖 Module Guides</h3><span class="badge badge-gold"><?=count($modules)?> modules</span></div>
</div>
<div class="hc-module-grid" id="hc-modules">
  <?php foreach($modules as $m): ?>
  <div class="hc-module" data-search="<?=strtolower($m['title'].' '.$m['desc'])?>" onclick="this.classList.toggle('open')">
    <div class="hc-module-head">
      <span class="hc-module-icon"><?=$m['icon']?></span>
      <div>
        <div class="hc-module-title"><?=$m['title']?></div>
        <div class="hc-module-desc"><?=$m['desc']?></div>
      </div>
      <span class="hc-module-arrow">▶</span>
    </div>
    <div class="hc-module-steps">
      <ol>
        <?php foreach($m['steps'] as $s): ?><li><?=htmlspecialchars($s)?></li><?php endforeach; ?>
      </ol>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- FAQs -->
<div class="card">
  <div class="card-head"><h3>❓ Frequently Asked Questions</h3><span class="badge badge-gold"><?=count($faqs)?></span></div>
  <div class="card-body" id="hc-faqs">
    <?php foreach($faqs as $f): ?>
    <div class="hc-faq" data-search="<?=strtolower($f['q'].' '.$f['a'])?>" onclick="this.classList.toggle('open')">
      <div class="hc-faq-q"><?=htmlspecialchars($f['q'])?> <span class="hc-faq-arrow">▶</span></div>
      <div class="hc-faq-a"><?=htmlspecialchars($f['a'])?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function filterHelp(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.hc-module, .hc-faq').forEach(el => {
        const match = !q || el.dataset.search.includes(q);
        el.style.display = match ? '' : 'none';
        if (q && match) el.classList.add('open');
        else if (!q) el.classList.remove('open');
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
