<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
startAdminSession();
$database = muskanDatabase();
$error = '';
$setupComplete = (int) $database->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');
    if ($action === 'logout' && adminIsAuthenticated()) {
        if (hash_equals(adminCsrfToken(), (string) ($_POST['csrf'] ?? ''))) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], '', true, true);
            }
            session_destroy();
        }
        header('Location: ./');
        exit;
    }
    if ($action === 'login' && $setupComplete) {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rateFile = sys_get_temp_dir() . '/muskan-admin-' . hash('sha256', $ip);
        $now = time();
        $attempts = [];
        if (is_file($rateFile)) {
            $attempts = array_values(array_filter(array_map('intval', explode(',', (string) file_get_contents($rateFile))), static fn(int $time): bool => $time > $now - 900));
        }
        if (count($attempts) >= 8) {
            $error = 'Too many sign-in attempts. Wait 15 minutes and try again.';
        } else {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $statement = $database->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
            $statement->execute([$email]);
            $admin = $statement->fetch();
            if ($admin && password_verify($password, (string) $admin['password_hash'])) {
                @unlink($rateFile);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_email'] = (string) $admin['email'];
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                $database->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([muskanNow(), $admin['id']]);
                header('Location: ./');
                exit;
            }
            $attempts[] = $now;
            file_put_contents($rateFile, implode(',', $attempts), LOCK_EX);
            $error = 'Email or password is incorrect.';
        }
    }
}

$authenticated = adminIsAuthenticated();
$csrf = $authenticated ? adminCsrfToken() : '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Muskan Admin<?= $authenticated ? ' — Booking requests' : ' — Sign in' ?></title>
  <style>
    :root{--ink:#0d2232;--navy:#092a3d;--navy2:#0d3850;--teal:#0a847b;--teal-dark:#07675f;--saffron:#f3a712;--paper:#f6f7f5;--white:#fff;--muted:#637481;--line:#dfe5e6;--danger:#b53c30;--shadow:0 18px 50px rgba(5,34,48,.11)}
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Manrope,Inter,system-ui,sans-serif;color:var(--ink);background:var(--paper)}button,input,select,textarea{font:inherit}button,a{touch-action:manipulation}button{cursor:pointer}a{color:inherit;text-decoration:none}
    .login-page{min-height:100vh;display:grid;grid-template-columns:1fr minmax(420px,560px)}.login-scene{position:relative;display:flex;align-items:flex-end;padding:64px;color:#fff;background:linear-gradient(120deg,rgba(3,25,37,.92),rgba(4,34,48,.45)),url('https://images.unsplash.com/photo-1544735716-392fe2489ffa?auto=format&fit=crop&w=1400&q=82') center/cover}.login-scene h1{max-width:650px;margin:0;font-family:Georgia,serif;font-size:clamp(44px,6vw,78px);font-weight:400;line-height:1.02}.login-panel{display:grid;place-items:center;padding:42px;background:#fff}.login-card{width:min(390px,100%)}.brand{display:flex;align-items:center;gap:12px;font-weight:850}.brand-mark{width:44px;height:44px;display:grid;place-items:center;border-radius:13px;background:var(--saffron);color:var(--navy)}.login-card h2{margin:46px 0 8px;font-size:30px}.subtle{color:var(--muted);line-height:1.55}.form-field{margin-top:18px}.form-field label{display:block;margin-bottom:7px;font-size:13px;font-weight:750}.form-field input,.form-field textarea{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:11px;color:var(--ink);background:#fff;outline:0}.form-field input:focus,.form-field textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(10,132,123,.12)}.primary{width:100%;margin-top:22px;padding:14px;border:0;border-radius:11px;color:#fff;background:var(--teal);font-weight:800}.primary:hover{background:var(--teal-dark)}.alert{margin-top:18px;padding:12px 14px;border-radius:10px;color:#9e3127;background:#fff0ee;font-size:13px}
    .app{min-height:100vh;display:grid;grid-template-columns:250px minmax(0,1fr)}.sidebar{position:sticky;top:0;height:100vh;padding:26px 20px;color:#fff;background:var(--navy)}.sidebar .brand{padding:0 8px}.sidebar small{display:block;margin-top:3px;color:rgba(255,255,255,.58);font-size:10px;letter-spacing:.1em}.side-nav{display:grid;gap:7px;margin-top:46px}.side-link{display:flex;align-items:center;gap:11px;padding:12px 13px;border-radius:11px;color:rgba(255,255,255,.7);font-size:13px;font-weight:700}.side-link.active,.side-link:hover{color:#fff;background:rgba(255,255,255,.09)}.side-bottom{position:absolute;left:20px;right:20px;bottom:24px}.signout{width:100%;padding:11px;border:1px solid rgba(255,255,255,.17);border-radius:10px;color:rgba(255,255,255,.75);background:transparent}.main{min-width:0;padding:34px}.top{display:flex;align-items:center;justify-content:space-between;gap:25px}.top h1{margin:0;font-family:Georgia,serif;font-size:38px;font-weight:400}.top p{margin:5px 0 0;color:var(--muted);font-size:13px}.open-site{padding:11px 16px;border:1px solid var(--line);border-radius:11px;background:#fff;font-size:13px;font-weight:750}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:28px 0}.stat{padding:19px;border:1px solid var(--line);border-radius:16px;background:#fff}.stat span{color:var(--muted);font-size:12px;font-weight:700}.stat strong{display:block;margin-top:7px;font-family:Georgia,serif;font-size:32px;font-weight:400}.stat.new strong{color:var(--teal)}
    .workspace{border:1px solid var(--line);border-radius:18px;background:#fff;overflow:hidden}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:17px;border-bottom:1px solid var(--line)}.filters{display:flex;gap:7px;overflow:auto}.filter{padding:8px 12px;border:1px solid var(--line);border-radius:999px;color:var(--muted);background:#fff;font-size:12px;font-weight:750;white-space:nowrap}.filter.active{color:#fff;border-color:var(--navy);background:var(--navy)}.search{width:min(300px,100%);padding:10px 12px;border:1px solid var(--line);border-radius:10px;outline:0}.request-table{width:100%;border-collapse:collapse}.request-table th{padding:12px 17px;color:var(--muted);background:#fafbfa;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.06em}.request-table td{padding:15px 17px;border-top:1px solid var(--line);font-size:13px}.request-row{cursor:pointer}.request-row:hover{background:#f7faf9}.request-row strong{display:block}.request-row small{color:var(--muted)}.route{font-weight:800}.fare-cell{text-align:right}.status{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800;text-transform:capitalize}.status-new{color:#086653;background:#daf3ea}.status-contacted{color:#735400;background:#fff0c9}.status-quoted{color:#315f98;background:#e5eefb}.status-confirmed{color:#426f2c;background:#e5f2dd}.status-closed{color:#69757b;background:#edf0f1}.empty{padding:60px 25px;text-align:center}.empty h3{margin:0 0 7px}.empty p{margin:0;color:var(--muted)}
    .drawer-backdrop{position:fixed;inset:0;z-index:40;background:rgba(3,25,37,.35);opacity:0;visibility:hidden;transition:.2s}.drawer-backdrop.open{opacity:1;visibility:visible}.drawer{position:absolute;top:0;right:0;width:min(560px,100%);height:100%;overflow:auto;padding:28px;background:#fff;transform:translateX(100%);transition:.25s}.drawer-backdrop.open .drawer{transform:none}.drawer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.close{width:38px;height:38px;border:1px solid var(--line);border-radius:50%;background:#fff;font-size:20px}.reference{color:var(--teal);font-size:12px;font-weight:850;letter-spacing:.06em}.drawer h2{margin:6px 0 4px;font-family:Georgia,serif;font-size:32px;font-weight:400}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin:23px 0}.detail{padding:13px;border-radius:12px;background:#f5f7f6}.detail small{display:block;color:var(--muted);font-size:11px}.detail strong{display:block;margin-top:4px;font-size:13px}.section-title{margin:25px 0 10px;font-size:13px}.contact-actions{display:flex;gap:8px}.contact-actions a{flex:1;padding:11px;border-radius:10px;text-align:center;font-size:12px;font-weight:800}.whatsapp{color:#fff;background:#1fae64}.call{color:var(--navy);background:var(--saffron)}.status-select{width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;background:#fff}.notes{min-height:120px;resize:vertical}.save-notes{padding:11px 16px;border:0;border-radius:10px;color:#fff;background:var(--teal);font-size:12px;font-weight:800}.save-state{margin-left:8px;color:var(--muted);font-size:12px}.mobile-menu{display:none;width:42px;height:42px;border:1px solid var(--line);border-radius:10px;background:#fff}
    @media(max-width:980px){.app{grid-template-columns:1fr}.sidebar{position:fixed;z-index:50;left:0;transform:translateX(-100%);transition:.22s;width:250px}.sidebar.open{transform:none}.main{padding:24px}.mobile-menu{display:block}.top>div:first-child{display:flex;align-items:center;gap:12px}.stats{grid-template-columns:1fr 1fr}.request-table th:nth-child(3),.request-table td:nth-child(3){display:none}}
    @media(max-width:640px){.login-page{grid-template-columns:1fr}.login-scene{display:none}.login-panel{padding:28px 20px}.main{padding:18px 13px}.top{align-items:flex-start}.top h1{font-size:30px}.open-site{display:none}.stats{gap:9px}.stat{padding:14px}.stat strong{font-size:27px}.toolbar{align-items:stretch;flex-direction:column}.search{width:100%}.request-table th:nth-child(4),.request-table td:nth-child(4){display:none}.request-table td,.request-table th{padding:13px 11px}.detail-grid{grid-template-columns:1fr}.drawer{padding:22px}.contact-actions{flex-direction:column}}
  </style>
</head>
<body>
<?php if (!$authenticated): ?>
  <main class="login-page"><section class="login-scene"><h1>Turn every travel enquiry into a clear next step.</h1></section><section class="login-panel"><div class="login-card"><a class="brand" href="../"><span class="brand-mark">⌁</span><span>Muskan Travels</span></a><h2>Admin sign in</h2><p class="subtle">Manage customer flight requests, follow-ups and confirmations.</p>
  <?php if (!$setupComplete): ?><div class="alert">The first admin account has not been created yet. Use the private setup link.</div><?php elseif ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($setupComplete): ?><form method="post"><input type="hidden" name="action" value="login"><div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required></div><div class="form-field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="primary" type="submit">Sign in</button></form><?php endif; ?>
  </div></section></main>
<?php else: ?>
  <div class="app"><aside class="sidebar" id="sidebar"><a class="brand" href="./"><span class="brand-mark">⌁</span><span>Muskan Travels<small>ADMIN WORKSPACE</small></span></a><nav class="side-nav"><a class="side-link active" href="./">▦ Booking requests</a><a class="side-link" href="../" target="_blank" rel="noopener">↗ Open website</a></nav><div class="side-bottom"><small><?= htmlspecialchars((string) ($_SESSION['admin_email'] ?? '')) ?></small><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="signout" type="submit">Sign out</button></form></div></aside>
  <main class="main"><header class="top"><div><button class="mobile-menu" id="menu" type="button" aria-label="Open navigation">☰</button><div><h1>Booking requests</h1><p>Customer enquiries from the Muskan website</p></div></div><a class="open-site" href="../" target="_blank" rel="noopener">Open customer website ↗</a></header>
  <section class="stats"><article class="stat new"><span>New requests</span><strong id="statNew">0</strong></article><article class="stat"><span>Contacted</span><strong id="statContacted">0</strong></article><article class="stat"><span>Quotes sent</span><strong id="statQuoted">0</strong></article><article class="stat"><span>Confirmed</span><strong id="statConfirmed">0</strong></article></section>
  <section class="workspace"><div class="toolbar"><div class="filters" id="filters"><button class="filter active" data-status="all">All</button><button class="filter" data-status="new">New</button><button class="filter" data-status="contacted">Contacted</button><button class="filter" data-status="quoted">Quoted</button><button class="filter" data-status="confirmed">Confirmed</button><button class="filter" data-status="closed">Closed</button></div><input class="search" id="search" type="search" placeholder="Search name, phone, route or reference"></div><div id="requestList"><div class="empty"><p>Loading requests…</p></div></div></section></main></div>
  <div class="drawer-backdrop" id="drawer"><aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawerTitle"><div class="drawer-head"><div><span class="reference" id="detailReference"></span><h2 id="drawerTitle"></h2><span class="status" id="detailStatus"></span></div><button class="close" id="closeDrawer" type="button" aria-label="Close details">×</button></div><div class="detail-grid" id="detailGrid"></div><h3 class="section-title">Contact customer</h3><div class="contact-actions" id="contactActions"></div><h3 class="section-title">Progress</h3><select class="status-select" id="statusSelect"><option value="new">New request</option><option value="contacted">Customer contacted</option><option value="quoted">Quote sent</option><option value="confirmed">Booking confirmed</option><option value="closed">Closed</option></select><h3 class="section-title">Internal notes</h3><textarea class="form-field notes" id="adminNotes" placeholder="Add fare details, follow-up time, payment status or other private notes"></textarea><div><button class="save-notes" id="saveNotes" type="button">Save notes</button><span class="save-state" id="saveState"></span></div></aside></div>
  <script>
    const csrf = <?= json_encode($csrf) ?>;
    const api = './api.php';
    let requests = [], selected = null, activeStatus = 'all', searchTimer;
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const money = (amount,currency) => amount ? new Intl.NumberFormat('en',{style:'currency',currency:currency||'USD'}).format(Number(amount)) : 'Not recorded';
    const date = value => value ? new Intl.DateTimeFormat('en',{dateStyle:'medium'}).format(new Date(value.slice(0,10)+'T12:00:00')) : '—';
    const statusLabel = value => ({new:'New',contacted:'Contacted',quoted:'Quoted',confirmed:'Confirmed',closed:'Closed'})[value] || value;
    async function load(){const q=document.getElementById('search').value.trim();const response=await fetch(`${api}?status=${encodeURIComponent(activeStatus)}&q=${encodeURIComponent(q)}`,{headers:{Accept:'application/json'}});if(response.status===401)return location.reload();const data=await response.json();requests=data.requests||[];document.getElementById('statNew').textContent=data.stats.new||0;document.getElementById('statContacted').textContent=data.stats.contacted||0;document.getElementById('statQuoted').textContent=data.stats.quoted||0;document.getElementById('statConfirmed').textContent=data.stats.confirmed||0;render()}
    function render(){const host=document.getElementById('requestList');if(!requests.length){host.innerHTML='<div class="empty"><h3>No requests here</h3><p>New website booking enquiries will appear automatically.</p></div>';return}host.innerHTML=`<table class="request-table"><thead><tr><th>Customer</th><th>Journey</th><th>Travel date</th><th>Fare</th><th>Status</th></tr></thead><tbody>${requests.map(r=>`<tr class="request-row" data-id="${esc(r.public_id)}"><td><strong>${esc(r.customer_name)}</strong><small>${esc(r.public_id)} · ${esc(r.phone)}</small></td><td><span class="route">${esc(r.origin)} → ${esc(r.destination)}</span><small>${esc(r.airline||'Airline pending')}</small></td><td><strong>${date(r.departure_date)}</strong><small>${r.return_date?'Return '+date(r.return_date):'One way'}</small></td><td class="fare-cell"><strong>${money(r.fare_amount,r.fare_currency)}</strong><small>${r.provider_live==1?'Live offer':'Needs verification'}</small></td><td><span class="status status-${esc(r.status)}">${statusLabel(r.status)}</span></td></tr>`).join('')}</tbody></table>`;host.querySelectorAll('.request-row').forEach(row=>row.addEventListener('click',()=>openDetail(row.dataset.id)))}
    function openDetail(id){selected=requests.find(r=>r.public_id===id);if(!selected)return;document.getElementById('detailReference').textContent=selected.public_id;document.getElementById('drawerTitle').textContent=selected.customer_name;const badge=document.getElementById('detailStatus');badge.className=`status status-${selected.status}`;badge.textContent=statusLabel(selected.status);document.getElementById('detailGrid').innerHTML=`<div class="detail"><small>Route</small><strong>${esc(selected.origin)} → ${esc(selected.destination)}</strong></div><div class="detail"><small>Travel dates</small><strong>${date(selected.departure_date)}${selected.return_date?' – '+date(selected.return_date):''}</strong></div><div class="detail"><small>Airline / flight</small><strong>${esc(selected.airline||'—')} ${esc(selected.flight_numbers||'')}</strong></div><div class="detail"><small>Fare</small><strong>${money(selected.fare_amount,selected.fare_currency)}</strong></div><div class="detail"><small>Travellers / cabin</small><strong>${selected.adults} adult${selected.adults==1?'':'s'} · ${esc(selected.cabin_class.replace('_',' '))}</strong></div><div class="detail"><small>Submitted</small><strong>${date(selected.created_at)}</strong></div>${selected.customer_message?`<div class="detail" style="grid-column:1/-1"><small>Customer message</small><strong>${esc(selected.customer_message)}</strong></div>`:''}`;const phone=String(selected.phone).replace(/[^0-9+]/g,'');document.getElementById('contactActions').innerHTML=`<a class="whatsapp" target="_blank" rel="noopener" href="https://wa.me/${encodeURIComponent(phone.replace(/^\+/,''))}?text=${encodeURIComponent('Hello '+selected.customer_name+', this is Muskan Travel & Tours regarding your flight request '+selected.public_id+'.')}">WhatsApp</a><a class="call" href="tel:${encodeURIComponent(phone)}">Call ${esc(selected.phone)}</a>`;document.getElementById('statusSelect').value=selected.status;document.getElementById('adminNotes').value=selected.admin_notes||'';document.getElementById('saveState').textContent='';document.getElementById('drawer').classList.add('open')}
    async function update(payload){const response=await fetch(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({reference:selected.public_id,...payload})});const data=await response.json();if(!response.ok)throw new Error(data.message||'Could not save changes.');await load();selected=requests.find(r=>r.public_id===selected.public_id);return data}
    document.getElementById('statusSelect').addEventListener('change',async e=>{try{await update({action:'status',status:e.target.value});openDetail(selected.public_id)}catch(error){alert(error.message)}});
    document.getElementById('saveNotes').addEventListener('click',async()=>{const state=document.getElementById('saveState');state.textContent='Saving…';try{await update({action:'notes',notes:document.getElementById('adminNotes').value});state.textContent='Saved'}catch(error){state.textContent=error.message}});
    document.getElementById('closeDrawer').addEventListener('click',()=>document.getElementById('drawer').classList.remove('open'));document.getElementById('drawer').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open')});document.addEventListener('keydown',e=>{if(e.key==='Escape')document.getElementById('drawer').classList.remove('open')});
    document.getElementById('filters').addEventListener('click',e=>{const button=e.target.closest('.filter');if(!button)return;document.querySelectorAll('.filter').forEach(x=>x.classList.remove('active'));button.classList.add('active');activeStatus=button.dataset.status;load()});document.getElementById('search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(load,250)});document.getElementById('menu').addEventListener('click',()=>document.getElementById('sidebar').classList.toggle('open'));load().catch(()=>{document.getElementById('requestList').innerHTML='<div class="empty"><h3>Could not load requests</h3><p>Refresh the page to try again.</p></div>'});
  </script>
<?php endif; ?>
</body></html>

