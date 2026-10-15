<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Language Settings";
$active_page = "language";

// Handle language change
if (!empty($_POST['set_lang'])) {
    $_SESSION['lang'] = $_POST['set_lang'];
    setcookie('flowork_lang', $_POST['set_lang'], time()+60*60*24*365, '/');
}
$current_lang = $_SESSION['lang'] ?? $_COOKIE['flowork_lang'] ?? 'en';

// Translation dictionary
$translations = [
    'en' => [
        'lang_name' => 'English',
        'flag' => '🇬🇧',
        'dashboard' => 'Dashboard',
        'grower_num' => 'Grower Number',
        'bales' => 'Bales',
        'mass' => 'Mass',
        'price' => 'Price',
        'buyer' => 'Buyer',
        'grade' => 'Grade',
        'balance' => 'Balance',
        'payment' => 'Payment',
        'total' => 'Total',
        'today' => 'Today',
        'season' => 'Season',
        'sold' => 'Sold',
        'pending' => 'Pending',
        'booked' => 'Booked',
        'transporter' => 'Transporter',
        'farm' => 'Farm',
        'province' => 'Province',
        'settlement' => 'Settlement',
        'deductions' => 'Deductions',
        'net_amount' => 'Net Amount',
        'gross_amount' => 'Gross Amount',
        'exchange_rate' => 'Exchange Rate',
        'sign_here' => 'Sign Here',
        'your_bales_sold' => 'Your bales have been sold',
        'payment_ready' => 'Your payment is ready for collection',
        'booking_confirmed' => 'Your booking has been confirmed',
    ],
    'sn' => [
        'lang_name' => 'Shona',
        'flag' => '🇿🇼',
        'dashboard' => 'Peji Rekutanga',
        'grower_num' => 'Nhamba Yemurimi',
        'bales' => 'Mabhero',
        'mass' => 'Uremu',
        'price' => 'Mutengo',
        'buyer' => 'Mutengi',
        'grade' => 'Mhando',
        'balance' => 'Bharansi',
        'payment' => 'Muripo',
        'total' => 'Zvese',
        'today' => 'Nhasi',
        'season' => 'Mwaka',
        'sold' => 'Zvakatengwa',
        'pending' => 'Zvichiri Kumirira',
        'booked' => 'Zvakabhukirwa',
        'transporter' => 'Mutakuri',
        'farm' => 'Purazi',
        'province' => 'Dunhu',
        'settlement' => 'Kuripwa',
        'deductions' => 'Zvakabviswa',
        'net_amount' => 'Mari Yakasara',
        'gross_amount' => 'Mari Yese',
        'exchange_rate' => 'Kurinjana Kwemari',
        'sign_here' => 'Saina Pano',
        'your_bales_sold' => 'Mabhero enyu akatengwa',
        'payment_ready' => 'Muripo wenyu wagadzirwa',
        'booking_confirmed' => 'Kubhukirwa kwenyu kwasimbiswa',
    ],
    'nd' => [
        'lang_name' => 'Ndebele',
        'flag' => '🇿🇼',
        'dashboard' => 'Ikhasi Lokuqala',
        'grower_num' => 'Inombolo Yomlimi',
        'bales' => 'Amabheyili',
        'mass' => 'Isisindo',
        'price' => 'Intengo',
        'buyer' => 'Umthengi',
        'grade' => 'Isigaba',
        'balance' => 'Ibhalansi',
        'payment' => 'Inhlawulo',
        'total' => 'Ingqikithi',
        'today' => 'Lamuhla',
        'season' => 'Isikhathi',
        'sold' => 'Okuthengisiweyo',
        'pending' => 'Okulindileyo',
        'booked' => 'Okubhukiweyo',
        'transporter' => 'Umthwali',
        'farm' => 'Ipulazi',
        'province' => 'Isifunda',
        'settlement' => 'Ukubhadalwa',
        'deductions' => 'Okususwayo',
        'net_amount' => 'Imali Eseleyo',
        'gross_amount' => 'Imali Yonke',
        'exchange_rate' => 'Isimo Semali',
        'sign_here' => 'Sayina Lapha',
        'your_bales_sold' => 'Amabheyili akho athengisiwe',
        'payment_ready' => 'Inhlawulo yakho ilungile',
        'booking_confirmed' => 'Ukubhuka kwakho kuqinisekisiwe',
    ],
];

include "includes/layout.php";
?>

<style>
.lang-card-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem; }
.lang-card { background:#fff;border:2px solid var(--border);border-radius:var(--radius);padding:1.5rem;text-align:center;cursor:pointer;transition:all .2s; }
body.theme-dark .lang-card { background:var(--ink-soft); }
.lang-card:hover { border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.06); }
.lang-card.active { border-color:var(--gold);background:var(--gold-bg); }
body.theme-dark .lang-card.active { background:rgba(200,149,42,.1); }
.lang-flag { font-size:2.5rem;margin-bottom:.5rem; }
.lang-name { font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:.06em; }
.lang-sample { font-size:.72rem;color:var(--muted);margin-top:.35rem; }
.lang-dict { display:grid;grid-template-columns:1fr 1fr 1fr;gap:0; }
.lang-dict-head { font-size:.6rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);padding:.6rem .85rem;background:var(--cream);border-bottom:1px solid var(--border); }
.lang-dict-cell { font-size:.75rem;padding:.5rem .85rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border); }
.lang-dict-cell:nth-child(3n) { border-right:none; }
.lang-dict-key { font-weight:500;color:var(--ink); }
</style>

<div class="page-header" style="text-align:center;display:block">
  <h2 style="font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:.08em">Language / Mutauro / Ulimi</h2>
  <p style="color:var(--muted)">Multi-language support for grower-facing communications</p>
</div>

<!-- Language Selection Cards -->
<div class="lang-card-grid">
  <?php foreach($translations as $code => $t): ?>
  <form method="POST" style="display:contents">
    <input type="hidden" name="set_lang" value="<?=$code?>">
    <div class="lang-card <?=$current_lang===$code?'active':''?>" onclick="this.closest('form').submit()">
      <div class="lang-flag"><?=$t['flag']?></div>
      <div class="lang-name"><?=$t['lang_name']?></div>
      <div class="lang-sample">"<?=$t['your_bales_sold']?>"</div>
      <?php if($current_lang===$code): ?><div style="margin-top:.5rem"><span class="badge badge-gold">Active</span></div><?php endif; ?>
    </div>
  </form>
  <?php endforeach; ?>
</div>

<!-- Translation Dictionary -->
<div class="card mb-3">
  <div class="card-head"><h3>📖 Translation Dictionary</h3><span class="badge badge-gold"><?=count($translations['en'])?> terms</span></div>
  <div class="table-wrap" style="max-height:500px;overflow-y:auto">
    <div class="lang-dict">
      <div class="lang-dict-head">English 🇬🇧</div>
      <div class="lang-dict-head">Shona 🇿🇼</div>
      <div class="lang-dict-head">Ndebele 🇿🇼</div>
      <?php foreach($translations['en'] as $key => $val):
        if($key === 'lang_name' || $key === 'flag') continue;
      ?>
      <div class="lang-dict-cell lang-dict-key"><?=htmlspecialchars($val)?></div>
      <div class="lang-dict-cell"><?=htmlspecialchars($translations['sn'][$key]??'')?></div>
      <div class="lang-dict-cell"><?=htmlspecialchars($translations['nd'][$key]??'')?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- SMS Template Preview -->
<div class="card">
  <div class="card-head"><h3>📱 Message Templates (<?=$translations[$current_lang]['lang_name']?>)</h3></div>
  <div class="card-body">
    <div class="grid-3" style="gap:.75rem">
      <div style="padding:.75rem;background:rgba(37,211,102,.05);border:1px solid rgba(37,211,102,.12);border-radius:8px">
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.3rem">Sale Notification</div>
        <div style="font-size:.78rem;line-height:1.5"><?=htmlspecialchars($translations[$current_lang]['your_bales_sold'])?></div>
      </div>
      <div style="padding:.75rem;background:rgba(37,211,102,.05);border:1px solid rgba(37,211,102,.12);border-radius:8px">
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.3rem">Payment Ready</div>
        <div style="font-size:.78rem;line-height:1.5"><?=htmlspecialchars($translations[$current_lang]['payment_ready'])?></div>
      </div>
      <div style="padding:.75rem;background:rgba(37,211,102,.05);border:1px solid rgba(37,211,102,.12);border-radius:8px">
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.3rem">Booking Confirmed</div>
        <div style="font-size:.78rem;line-height:1.5"><?=htmlspecialchars($translations[$current_lang]['booking_confirmed'])?></div>
      </div>
    </div>
  </div>
</div>

<?php include "includes/layout_end.php"; ?>
