<?php
/**
 * admin.php — JGM Group content editor
 * --------------------------------------------------------------------------
 * A friendly form for editing config.json. No raw JSON required.
 *
 * SECURITY: This file performs NO authentication of its own. It is designed to
 * live in a folder protected by cPanel > Directory Privacy (Apache Basic Auth),
 * so the login is enforced BEFORE this script ever runs. Do not place it in an
 * unprotected folder.
 *
 * SETUP
 *   1. Create a folder, e.g.  public_html/admin/  and put this file in it.
 *   2. cPanel > Directory Privacy > select that folder > "Password protect this
 *      directory" > create a user + password.
 *   3. Make sure $CONFIG_PATH below points at your live config.json.
 *   4. Visit https://yourdomain/admin/admin.php and log in.
 *
 * On save it backs up the current config.json (config.backup-<timestamp>.json)
 * and writes the new one atomically.
 * --------------------------------------------------------------------------
 */

// ============================ SETTINGS ============================
$CONFIG_PATH = __DIR__ . '/../config.json';   // config.json lives one level up (in the site root)
// ==================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Same-origin guard (defence-in-depth on top of Basic Auth)
    $host    = $_SERVER['HTTP_HOST'] ?? '';
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $sameOrigin = true;
    if ($origin !== '')       $sameOrigin = (parse_url($origin, PHP_URL_HOST) === $host);
    elseif ($referer !== '')  $sameOrigin = (parse_url($referer, PHP_URL_HOST) === $host);
    if (!$sameOrigin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cross-origin request blocked.']);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid data received.']);
        exit;
    }

    $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($pretty === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not encode configuration.']);
        exit;
    }

    $dir = dirname($CONFIG_PATH);

    // Back up the current file before overwriting
    if (is_file($CONFIG_PATH)) {
        @copy($CONFIG_PATH, $dir . '/config.backup-' . date('Ymd-His') . '.json');
    }

    // Atomic write: temp file, then rename
    $tmp = $CONFIG_PATH . '.tmp';
    if (file_put_contents($tmp, $pretty, LOCK_EX) === false || !@rename($tmp, $CONFIG_PATH)) {
        @unlink($tmp);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not write config.json — check the file/folder permissions in cPanel.']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// GET: load the current config to pre-fill the editor
$current = is_file($CONFIG_PATH) ? json_decode(file_get_contents($CONFIG_PATH), true) : null;
if (!is_array($current)) $current = [];
$currentJson = json_encode($current, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>JGM Group — Content Editor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Oswald:wght@500;600&family=Sora:wght@600;700;800&display=swap" rel="stylesheet" />
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; }
    .font-display { font-family: 'Sora', sans-serif; }
    .font-label { font-family: 'Oswald', sans-serif; }
    input, textarea, select { color-scheme: dark; }
    .tab.active { background:#f59e0b; color:#000; }
    .field { display:block; }
    .field > label { display:block; font-family:'Oswald',sans-serif; text-transform:uppercase; letter-spacing:.1em; font-size:.72rem; color:#9ca3af; margin-bottom:.35rem; }
    .inp { width:100%; border:1px solid #1f2937; background:#030712; color:#fff; border-radius:.25rem; padding:.6rem .75rem; outline:none; }
    .inp:focus { border-color:#f59e0b; }
    .row { border:1px solid #1f2937; background:#0b0f19; border-radius:.5rem; padding:1rem; }
  </style>
</head>
<body class="bg-gray-950 text-gray-200 min-h-screen">

  <!-- Top bar -->
  <header class="sticky top-0 z-20 border-b border-gray-800 bg-gray-950/90 backdrop-blur">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-4">
      <div class="flex items-center gap-2.5">
        <span class="flex h-8 w-8 items-center justify-center rounded-sm bg-amber-500 text-black font-display font-extrabold">J</span>
        <div>
          <p class="font-display font-bold leading-none text-white">Content Editor</p>
          <p class="font-label text-[11px] uppercase tracking-widest text-gray-500">config.json</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span id="status" class="hidden rounded-sm px-3 py-1.5 text-sm"></span>
        <a href="../index.html" target="_blank" class="rounded-sm border border-gray-700 px-3 py-2 font-label text-xs uppercase tracking-widest text-gray-300 hover:border-amber-500 hover:text-amber-500">View site</a>
        <button id="downloadBtn" class="rounded-sm border border-gray-700 px-3 py-2 font-label text-xs uppercase tracking-widest text-gray-300 hover:border-amber-500 hover:text-amber-500">Download</button>
        <button id="saveBtn" class="rounded-sm bg-amber-500 px-4 py-2 font-label text-xs font-semibold uppercase tracking-widest text-black hover:bg-amber-400">Save changes</button>
      </div>
    </div>
    <!-- Tabs -->
    <nav class="mx-auto flex max-w-5xl flex-wrap gap-1.5 px-5 pb-3">
      <?php foreach (['brand'=>'Brand','contact'=>'Contact','credentials'=>'Credentials','social'=>'Social','about'=>'About','services'=>'Services','estimator'=>'Estimator','reels'=>'Reels','forms'=>'Form'] as $k=>$label): ?>
        <button class="tab rounded-sm border border-gray-800 px-3 py-1.5 font-label text-xs uppercase tracking-widest text-gray-400 hover:text-white" data-tab="<?= $k ?>"><?= $label ?></button>
      <?php endforeach; ?>
    </nav>
  </header>

  <main class="mx-auto max-w-5xl px-5 py-8">

    <!-- BRAND -->
    <section data-panel="brand" class="space-y-5">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="field"><label>Logo — primary text</label><input id="b_markPrimary" class="inp" /></div>
        <div class="field"><label>Logo — accent text</label><input id="b_markAccent" class="inp" /></div>
        <div class="field"><label>Company name</label><input id="b_name" class="inp" /></div>
        <div class="field"><label>Tagline</label><input id="b_tagline" class="inp" /></div>
      </div>
    </section>

    <!-- CONTACT -->
    <section data-panel="contact" class="hidden space-y-5">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="field"><label>Phone (display)</label><input id="c_phone" class="inp" /></div>
        <div class="field"><label>Phone link (tel:)</label><input id="c_phoneHref" class="inp" placeholder="tel:+15550123456" /></div>
        <div class="field sm:col-span-2"><label>Email</label><input id="c_email" class="inp" /></div>
        <div class="field"><label>Address line 1</label><input id="c_line1" class="inp" /></div>
        <div class="field"><label>Address line 2</label><input id="c_line2" class="inp" /></div>
        <div class="field"><label>City</label><input id="c_city" class="inp" /></div>
        <div class="field"><label>Province</label><input id="c_province" class="inp" /></div>
        <div class="field"><label>Postal code</label><input id="c_postal" class="inp" /></div>
        <div class="field"><label>Hours</label><input id="c_hours" class="inp" /></div>
        <div class="field sm:col-span-2"><label>Service area</label><input id="c_serviceArea" class="inp" /></div>
      </div>
      <p class="text-xs text-gray-600">The map on the site is generated automatically from this address.</p>
    </section>

    <!-- CREDENTIALS -->
    <section data-panel="credentials" class="hidden space-y-5">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="field"><label>Licence number</label><input id="cr_license" class="inp" /></div>
        <div class="field"><label>Established (year)</label><input id="cr_established" type="number" class="inp" /></div>
      </div>
      <div class="flex gap-6">
        <label class="flex items-center gap-2 text-sm"><input id="cr_bonded" type="checkbox" class="h-4 w-4 accent-amber-500" /> Bonded</label>
        <label class="flex items-center gap-2 text-sm"><input id="cr_insured" type="checkbox" class="h-4 w-4 accent-amber-500" /> Insured</label>
      </div>
    </section>

    <!-- SOCIAL -->
    <section data-panel="social" class="hidden space-y-5">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="field"><label>Instagram URL</label><input id="s_instagram" class="inp" /></div>
        <div class="field"><label>LinkedIn URL</label><input id="s_linkedin" class="inp" /></div>
        <div class="field"><label>Facebook URL</label><input id="s_facebook" class="inp" /></div>
      </div>
    </section>

    <!-- ABOUT -->
    <section data-panel="about" class="hidden space-y-8">
      <div>
        <div class="mb-3 flex items-center justify-between"><h2 class="font-display font-bold text-white">Story paragraphs</h2><button class="add-btn" data-add="about_body">+ Add paragraph</button></div>
        <div id="about_body" class="space-y-3"></div>
      </div>
      <div>
        <div class="mb-3 flex items-center justify-between"><h2 class="font-display font-bold text-white">Stats</h2><button class="add-btn" data-add="about_stats">+ Add stat</button></div>
        <div id="about_stats" class="space-y-3"></div>
      </div>
      <div>
        <div class="mb-3 flex items-center justify-between"><h2 class="font-display font-bold text-white">Process steps</h2><button class="add-btn" data-add="about_process">+ Add step</button></div>
        <div id="about_process" class="space-y-3"></div>
      </div>
    </section>

    <!-- SERVICES -->
    <section data-panel="services" class="hidden space-y-4">
      <div class="flex items-center justify-between"><h2 class="font-display font-bold text-white">Services</h2><button class="add-btn" data-add="services_list">+ Add service</button></div>
      <p class="text-xs text-gray-600">Icon uses a Lucide icon name (e.g. home, building-2, hard-hat, wrench, ruler).</p>
      <div id="services_list" class="space-y-3"></div>
    </section>

    <!-- ESTIMATOR -->
    <section data-panel="estimator" class="hidden space-y-8">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="field"><label>Default footprint (sq ft)</label><input id="e_defaultSqft" type="number" class="inp" /></div>
        <div class="field"><label>Range band (e.g. 0.12 = ±12%)</label><input id="e_rangeBand" type="number" step="0.01" class="inp" /></div>
      </div>
      <div>
        <div class="mb-3 flex items-center justify-between"><h2 class="font-display font-bold text-white">Project types &amp; base rate ($/sq ft)</h2><button class="add-btn" data-add="estimator_types">+ Add type</button></div>
        <div id="estimator_types" class="space-y-3"></div>
      </div>
      <div>
        <div class="mb-3 flex items-center justify-between"><h2 class="font-display font-bold text-white">Finish tiers &amp; multiplier</h2><button class="add-btn" data-add="estimator_tiers">+ Add tier</button></div>
        <div id="estimator_tiers" class="space-y-3"></div>
      </div>
    </section>

    <!-- REELS -->
    <section data-panel="reels" class="hidden space-y-4">
      <div class="flex items-center justify-between"><h2 class="font-display font-bold text-white">On-site reels</h2><button class="add-btn" data-add="reels_list">+ Add reel</button></div>
      <p class="text-xs text-gray-600">Type "video" plays a hosted MP4 (fill Video URL). Type "link" shows a thumbnail that opens Instagram (fill Permalink + Poster image URL).</p>
      <div id="reels_list" class="space-y-3"></div>
    </section>

    <!-- FORM -->
    <section data-panel="forms" class="hidden space-y-5">
      <div class="grid gap-5">
        <div class="field"><label>Web3Forms access key (leave blank if using send.php)</label><input id="f_web3formsKey" class="inp" /></div>
        <div class="field"><label>Endpoint (e.g. /send.php)</label><input id="f_endpoint" class="inp" /></div>
        <div class="field"><label>Email subject line</label><input id="f_subject" class="inp" /></div>
      </div>
    </section>

  </main>

  <script>
    // ----- Load current config from PHP -----
    const LOADED = <?= $currentJson ?: '{}' ?>;
    const SKELETON = {
      brand: {}, contact: { address: {} }, credentials: {}, social: {},
      about: { body: [], stats: [], process: [] }, services: [],
      estimator: { types: [], tiers: [] }, reels: [], forms: {}
    };
    const deepMerge = (base, over) => {
      const out = Array.isArray(base) ? base.slice() : Object.assign({}, base);
      for (const k in over) {
        if (over[k] && typeof over[k] === 'object' && !Array.isArray(over[k])) out[k] = deepMerge(base[k] || {}, over[k]);
        else out[k] = over[k];
      }
      return out;
    };
    const CONFIG = deepMerge(SKELETON, LOADED);

    const $ = (id) => document.getElementById(id);
    const escAttr = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const escText = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // ----- Tabs -----
    const tabs = document.querySelectorAll('.tab');
    const panels = document.querySelectorAll('[data-panel]');
    function showTab(name) {
      tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
      panels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));
    }
    tabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));
    showTab('brand');

    // ----- Style the add buttons -----
    document.querySelectorAll('.add-btn').forEach(b =>
      b.className = 'add-btn rounded-sm border border-gray-700 px-3 py-1.5 font-label text-xs uppercase tracking-widest text-amber-500 hover:bg-amber-500 hover:text-black');

    // ----- Row templates for array sections -----
    const removeBtn = '<button class="remove-row shrink-0 rounded-sm border border-gray-700 px-2 py-1 text-xs text-gray-400 hover:border-red-500 hover:text-red-400">Remove</button>';

    const templates = {
      about_body: (v = '') => `<div class="row flex gap-3">
        <textarea data-f="text" rows="2" class="inp">${escText(v)}</textarea>${removeBtn}</div>`,

      about_stats: (v = {}) => `<div class="row flex flex-wrap items-end gap-3">
        <div class="field flex-1 min-w-[120px]"><label>Value</label><input data-f="value" class="inp" value="${escAttr(v.value)}"></div>
        <div class="field flex-[2] min-w-[160px]"><label>Label</label><input data-f="label" class="inp" value="${escAttr(v.label)}"></div>${removeBtn}</div>`,

      about_process: (v = {}) => `<div class="row space-y-3">
        <div class="flex items-center justify-between"><span class="font-label text-xs uppercase tracking-widest text-gray-500">Step</span>${removeBtn}</div>
        <div class="field"><label>Title</label><input data-f="title" class="inp" value="${escAttr(v.title)}"></div>
        <div class="field"><label>Description</label><textarea data-f="description" rows="2" class="inp">${escText(v.description)}</textarea></div></div>`,

      services_list: (v = {}) => `<div class="row space-y-3">
        <div class="flex items-center justify-between"><span class="font-label text-xs uppercase tracking-widest text-gray-500">Service</span>${removeBtn}</div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field"><label>Icon (Lucide name)</label><input data-f="icon" class="inp" value="${escAttr(v.icon)}"></div>
          <div class="field"><label>Title</label><input data-f="title" class="inp" value="${escAttr(v.title)}"></div>
        </div>
        <div class="field"><label>Description</label><textarea data-f="description" rows="2" class="inp">${escText(v.description)}</textarea></div></div>`,

      estimator_types: (v = {}) => `<div class="row flex flex-wrap items-end gap-3">
        <div class="field flex-[3] min-w-[200px]"><label>Label</label><input data-f="label" class="inp" value="${escAttr(v.label)}"></div>
        <div class="field flex-1 min-w-[110px]"><label>Rate $/sq ft</label><input data-f="rate" type="number" class="inp" value="${escAttr(v.rate)}"></div>${removeBtn}</div>`,

      estimator_tiers: (v = {}) => `<div class="row flex flex-wrap items-end gap-3">
        <div class="field flex-[3] min-w-[200px]"><label>Label</label><input data-f="label" class="inp" value="${escAttr(v.label)}"></div>
        <div class="field flex-1 min-w-[110px]"><label>Multiplier</label><input data-f="mult" type="number" step="0.1" class="inp" value="${escAttr(v.mult)}"></div>${removeBtn}</div>`,

      reels_list: (v = {}) => `<div class="row space-y-3">
        <div class="flex items-center justify-between"><span class="font-label text-xs uppercase tracking-widest text-gray-500">Reel</span>${removeBtn}</div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="field"><label>Type</label><select data-f="type" class="inp"><option value="video"${v.type==='video'?' selected':''}>video (hosted MP4)</option><option value="link"${v.type==='link'?' selected':''}>link (Instagram)</option></select></div>
          <div class="field"><label>Stage label</label><input data-f="stage" class="inp" value="${escAttr(v.stage)}"></div>
          <div class="field sm:col-span-2"><label>Caption</label><input data-f="caption" class="inp" value="${escAttr(v.caption)}"></div>
          <div class="field sm:col-span-2"><label>Video URL (type: video)</label><input data-f="src" class="inp" value="${escAttr(v.src)}"></div>
          <div class="field"><label>Instagram permalink (type: link)</label><input data-f="permalink" class="inp" value="${escAttr(v.permalink)}"></div>
          <div class="field"><label>Poster image URL (type: link)</label><input data-f="poster" class="inp" value="${escAttr(v.poster)}"></div>
        </div></div>`
    };

    // ----- Render arrays -----
    function renderList(containerId, items, tplKey) {
      const c = $(containerId);
      c.innerHTML = (items && items.length ? items : []).map(templates[tplKey]).join('');
    }

    // Add / remove handling (event delegation)
    document.querySelectorAll('.add-btn').forEach(btn => btn.addEventListener('click', () => {
      const key = btn.dataset.add;
      $(key).insertAdjacentHTML('beforeend', templates[key]());
    }));
    document.querySelector('main').addEventListener('click', (e) => {
      const rm = e.target.closest('.remove-row');
      if (rm) rm.closest('.row').remove();
    });

    // ----- Populate scalar fields -----
    function fill() {
      const b = CONFIG.brand, c = CONFIG.contact, a = c.address || {}, cr = CONFIG.credentials, s = CONFIG.social, e = CONFIG.estimator, f = CONFIG.forms;
      $('b_markPrimary').value = b.markPrimary || '';
      $('b_markAccent').value  = b.markAccent || '';
      $('b_name').value        = b.name || '';
      $('b_tagline').value     = b.tagline || '';

      $('c_phone').value       = c.phone || '';
      $('c_phoneHref').value   = c.phoneHref || '';
      $('c_email').value       = c.email || '';
      $('c_line1').value       = a.line1 || '';
      $('c_line2').value       = a.line2 || '';
      $('c_city').value        = a.city || '';
      $('c_province').value    = a.province || '';
      $('c_postal').value      = a.postal || '';
      $('c_hours').value       = c.hours || '';
      $('c_serviceArea').value = c.serviceArea || '';

      $('cr_license').value     = cr.license || '';
      $('cr_established').value  = cr.established || '';
      $('cr_bonded').checked     = !!cr.bonded;
      $('cr_insured').checked    = !!cr.insured;

      $('s_instagram').value = s.instagram || '';
      $('s_linkedin').value  = s.linkedin || '';
      $('s_facebook').value  = s.facebook || '';

      $('e_defaultSqft').value = e.defaultSqft || '';
      $('e_rangeBand').value   = (e.rangeBand != null ? e.rangeBand : '');

      $('f_web3formsKey').value = f.web3formsKey || '';
      $('f_endpoint').value     = f.endpoint || '';
      $('f_subject').value      = f.subject || '';
    }

    // about_body items are plain strings, rendered separately
    function renderBody() {
      $('about_body').innerHTML = (CONFIG.about.body || []).map(templates.about_body).join('');
    }

    fill();
    renderBody();
    renderList('about_stats', CONFIG.about.stats, 'about_stats');
    renderList('about_process', CONFIG.about.process, 'about_process');
    renderList('services_list', CONFIG.services, 'services_list');
    renderList('estimator_types', CONFIG.estimator.types, 'estimator_types');
    renderList('estimator_tiers', CONFIG.estimator.tiers, 'estimator_tiers');
    renderList('reels_list', CONFIG.reels, 'reels_list');

    // ----- Collect rows back into arrays -----
    const rowsIn = (containerId) => [...$(containerId).querySelectorAll(':scope > .row')];
    const val = (row, f) => { const el = row.querySelector(`[data-f="${f}"]`); return el ? el.value.trim() : ''; };

    function collect() {
      const out = {
        brand: {
          markPrimary: $('b_markPrimary').value,
          markAccent:  $('b_markAccent').value,
          name:        $('b_name').value,
          tagline:     $('b_tagline').value
        },
        contact: {
          phone:     $('c_phone').value,
          phoneHref: $('c_phoneHref').value,
          email:     $('c_email').value,
          address: {
            line1:    $('c_line1').value,
            line2:    $('c_line2').value,
            city:     $('c_city').value,
            province: $('c_province').value,
            postal:   $('c_postal').value
          },
          hours:       $('c_hours').value,
          serviceArea: $('c_serviceArea').value
        },
        credentials: {
          license:     $('cr_license').value,
          bonded:      $('cr_bonded').checked,
          insured:     $('cr_insured').checked,
          established: parseInt($('cr_established').value, 10) || null
        },
        social: {
          instagram: $('s_instagram').value,
          linkedin:  $('s_linkedin').value,
          facebook:  $('s_facebook').value
        },
        about: {
          body:    rowsIn('about_body').map(r => val(r, 'text')).filter(Boolean),
          stats:   rowsIn('about_stats').map(r => ({ value: val(r, 'value'), label: val(r, 'label') })),
          process: rowsIn('about_process').map(r => ({ title: val(r, 'title'), description: val(r, 'description') }))
        },
        services: rowsIn('services_list').map(r => ({ icon: val(r, 'icon'), title: val(r, 'title'), description: val(r, 'description') })),
        estimator: {
          types: rowsIn('estimator_types').map(r => ({ label: val(r, 'label'), rate: parseFloat(val(r, 'rate')) || 0 })),
          tiers: rowsIn('estimator_tiers').map(r => ({ label: val(r, 'label'), mult: parseFloat(val(r, 'mult')) || 1 })),
          defaultSqft: parseInt($('e_defaultSqft').value, 10) || 0,
          rangeBand: parseFloat($('e_rangeBand').value) || 0
        },
        reels: rowsIn('reels_list').map(r => {
          const type = val(r, 'type') || 'video';
          const o = { type, caption: val(r, 'caption'), stage: val(r, 'stage') };
          if (type === 'video') { o.src = val(r, 'src'); }
          else { o.permalink = val(r, 'permalink'); o.poster = val(r, 'poster'); }
          return o;
        }),
        forms: {
          web3formsKey: $('f_web3formsKey').value,
          endpoint:     $('f_endpoint').value,
          subject:      $('f_subject').value
        }
      };
      return out;
    }

    // ----- Status helper -----
    const statusEl = $('status');
    function setStatus(kind, msg) {
      const styles = { ok: 'bg-emerald-500/15 text-emerald-400', err: 'bg-red-500/15 text-red-400', busy: 'bg-amber-500/15 text-amber-400' };
      statusEl.className = 'rounded-sm px-3 py-1.5 text-sm ' + (styles[kind] || styles.busy);
      statusEl.textContent = msg;
      statusEl.classList.remove('hidden');
    }

    // ----- Save -----
    $('saveBtn').addEventListener('click', async () => {
      const data = collect();
      setStatus('busy', 'Saving…');
      try {
        const res = await fetch(window.location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        const json = await res.json();
        if (res.ok && json.success) setStatus('ok', 'Saved ✓');
        else setStatus('err', json.message || 'Save failed.');
      } catch (err) {
        setStatus('err', 'Could not reach the server.');
      }
    });

    // ----- Download a local copy -----
    $('downloadBtn').addEventListener('click', () => {
      const blob = new Blob([JSON.stringify(collect(), null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'config.json';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  </script>
</body>
</html>
