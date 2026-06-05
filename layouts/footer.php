<?php
// layouts/footer.php — uniforme bottom nav met identieke solid-stijl iconen
if (!isset($activeTab)) {
    $activeTab = '';
}
?>
<style>
    :root {
        --bg: #0a0a0a;
        --fg: #eaeaea;
        --muted: #9aa0a6;
        --border: #2b2b2b;
        --brand: #22c55e;
    }

    body {
        padding-bottom: 72px;
    }

    .tabbar {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        height: 64px;
        background: rgba(10, 10, 10, .95);
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-around;
        align-items: center;
        backdrop-filter: saturate(1.1) blur(4px);
        z-index: 1000;
    }

    .tabbar a.tab {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: var(--muted);
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        gap: 6px;
        min-width: 90px;
        transition: color .2s ease, transform .2s ease;
    }

    .tabbar a.tab svg {
        width: 24px;
        height: 24px;
        fill: currentColor;
        transition: color .2s ease, transform .2s ease;
    }

    .tabbar a.tab.active {
        color: var(--brand);
    }

    .tabbar a.tab.active svg {
        transform: scale(1.08);
    }
</style>

<nav class="tabbar" aria-label="Hoofdnavigatie">
    <!-- DAGBOEK -->
    <a href="index.php" class="tab <?php echo $activeTab === 'dagboek' ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M12 3 3 10v10a1 1 0 0 0 1 1h6v-6h4v6h6a1 1 0 0 0 1-1V10l-9-7Z" />
        </svg>
        <span>Dagboek</span>
    </a>

    <!-- PLANNER -->
    <a href="planner.php" class="tab <?php echo $activeTab === 'planner' ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M7 2h2v2h6V2h2v2h3a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3V2Zm-3 6h16v12H4V8Zm4 3h5v5H8v-5Z" />
        </svg>
        <span>Planner</span>
    </a>

    <!-- RECEPTEN -->
    <a href="recepten.php" class="tab <?php echo $activeTab === 'recepten' ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M6 4h11a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v12h11V6H6Zm2 2h7v2H8V8Zm0 4h7v2H8v-2Z" />
        </svg>
        <span>Recepten</span>
    </a>

    <!-- TAGS -->
    <a href="tags.php" class="tab <?php echo $activeTab === 'tags' ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M10 3H5a2 2 0 0 0-2 2v5a2 2 0 0 0 .59 1.41l8 8a2 2 0 0 0 2.82 0l5.18-5.18a2 2 0 0 0 0-2.82l-8-8A2 2 0 0 0 10 3zm-3 5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" />
        </svg>
        <span>Tags</span>
    </a>

    <!-- PROFIEL -->
    <a href="profile.php" class="tab <?php echo ($activeTab === 'profile' || $activeTab === 'profiel') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5Z" />
        </svg>
        <span>Profiel</span>
    </a>
</nav>

<script>
(function(){
  const overlay = document.querySelector('#search-overlay');
  const input   = document.querySelector('#off-search-input');
  const list    = document.querySelector('#off-results');
  if (!overlay || !input || !list) return;

  const badge   = document.querySelector('#off-result-badge');
  const openBtn = document.querySelector('#fab-add');

  function openOverlay(){ overlay.style.display='block'; setTimeout(()=>input.focus(),50); }
  function closeOverlay(){ overlay.style.display='none'; list.innerHTML=''; input.value=''; }
  openBtn && openBtn.addEventListener('click', openOverlay);
  document.querySelector('#off-close')?.addEventListener('click', closeOverlay);
  overlay.addEventListener('click', e=>{ if(e.target===overlay) closeOverlay(); });

  const tabs = Array.from(document.querySelectorAll('.off-tab'));
  let mode = 'products';
  tabs.forEach(t => t.addEventListener('click', () => {
    tabs.forEach(x => x.classList.toggle('active', x===t));
    mode = t.dataset.offTab;
    triggerSearch();
  }));

  const debounce = (fn, ms=300) => { let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms);} };
  const n = v => (v==null || isNaN(v)) ? 0 : +v;
  const fmt = (v,u='') => (v==null||isNaN(v)) ? '—' : `${(+v).toFixed(1)}${u}`;

  function makePanel(it){
    const kcal100 = n(it.kcal_100g ?? it['energy-kcal_100g'] ?? it.energy_kcal_100g ?? it.calories_100g ?? (it['energy-kj_100g'] ? it['energy-kj_100g']/4.184 : 0));
    const p100    = n(it.protein_100g);
    const c100    = n(it.carbs_100g);
    const f100    = n(it.fat_100g);

    const wrap = document.createElement('div');
    wrap.className = 'off-panel';
    wrap.innerHTML = `
      <div class="off-panel-inner">
        <div class="row">
          <label>Hoeveelheid</label>
          <div class="qty">
            <input type="number" min="1" step="1" value="100" class="qty-input">
            <select class="qty-unit">
              <option value="g" selected>g</option>
              <option value="ml">ml</option>
            </select>
          </div>
        </div>
        <div class="preview">—</div>
        <div class="actions">
          <button type="button" class="add">Toevoegen</button>
          <button type="button" class="cancel">Sluiten</button>
        </div>
      </div>
    `;

    const qty    = wrap.querySelector('.qty-input');
    const unit   = wrap.querySelector('.qty-unit');
    const prev   = wrap.querySelector('.preview');
    const add    = wrap.querySelector('.add');
    const cancel = wrap.querySelector('.cancel');

    function updatePreview(){
      const amount = Math.max(1, +qty.value || 1);
      const factor = amount / 100;
      const kcal = kcal100 * factor;
      const pr   = p100 * factor;
      const ch   = c100 * factor;
      const fat  = f100 * factor;
      prev.textContent = `≈ ${fmt(kcal,' kcal')} • eiwit ${fmt(pr,' g')} • kh ${fmt(ch,' g')} • vet ${fmt(fat,' g')}`;
    }

    qty.addEventListener('input', updatePreview);
    unit.addEventListener('change', updatePreview);
    updatePreview();

    cancel.addEventListener('click', () => wrap.closest('.off-card')?.classList.remove('open') || wrap.remove());

    add.addEventListener('click', async () => {
      const grams = Math.max(1, +qty.value || 1);
      const fd = new FormData();
      fd.append('source', 'off');
      fd.append('name', it.name || '');
      fd.append('brand', it.brand || '');
      fd.append('code', it.code || '');
      fd.append('image', it.image || '');
      fd.append('grams', grams);
      fd.append('unit', unit.value);
      fd.append('kcal_100g', kcal100 || '');
      fd.append('protein_100g', p100 || '');
      fd.append('carbs_100g', c100 || '');
      fd.append('fat_100g', f100 || '');
      fd.append('slot', window.currentSlot || 'Snack');

      try {
        add.disabled = true;
        add.textContent = 'Toevoegen…';
        const r = await fetch('add.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Kon niet toevoegen');
        closeOverlay();
        location.reload();
      } catch (e) {
        alert(e.message);
        add.disabled = false;
        add.textContent = 'Toevoegen';
      }
    });

    return wrap;
  }

  const render = (items = []) => {
    list.innerHTML = '';
    if (!items.length) {
      list.innerHTML = '<div class="muted">Geen resultaten…</div>';
      if (badge) badge.textContent = '';
      return;
    }
    if (badge) badge.textContent = items.length;

    for (const it of items) {
      const kcal100 = it.kcal_100g ?? it['energy-kcal_100g'] ?? it.energy_kcal_100g ?? it.calories_100g ?? (it['energy-kj_100g'] ? it['energy-kj_100g']/4.184 : null);

      const row = document.createElement('div');
      row.className = 'off-card clickable';
      row.innerHTML = `
        <div class="off-card-left">
          <img src="${it.image||''}" alt="" onerror="this.style.display='none'">
        </div>
        <div class="off-card-mid">
          <div class="name">${it.name||'Onbekend product'}</div>
          <div class="brand">${it.brand||''}</div>
          <div class="meta">
            <span>${fmt(kcal100,' kcal')} / 100g</span>
            <span>eiwit ${fmt(it.protein_100g,' g')}</span>
            <span>kh ${fmt(it.carbs_100g,' g')}</span>
            <span>vet ${fmt(it.fat_100g,' g')}</span>
          </div>
        </div>
        <div class="chev">➤</div>
      `;
      row.dataset.item = JSON.stringify(it);
      list.appendChild(row);
    }
  };

  function openPanel(row){
    if (row.classList.contains('open')) {
      row.classList.remove('open');
      row.querySelector('.off-panel')?.remove();
      return;
    }

    list.querySelectorAll('.off-card.open').forEach(el => {
      el.classList.remove('open');
      el.querySelector('.off-panel')?.remove();
    });

    row.classList.add('open');
    const it = JSON.parse(row.dataset.item || '{}');
    const panel = makePanel(it);
    row.appendChild(panel);
    panel.querySelector('.qty-input')?.focus();
  }

  list.addEventListener('click', (e) => {
    const row = e.target.closest('.off-card.clickable');
    if (!row) return;
    openPanel(row);
  });

  list.addEventListener('keydown', (e) => {
    const row = e.target.closest('.off-card.clickable');
    if (!row) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openPanel(row);
    }
  });

  let currentAbort = null;
  const triggerSearch = debounce(async () => {
    const q = input.value.trim();
    if (!q) {
      render([]);
      return;
    }
    if (mode !== 'products') {
      render([]);
      return;
    }

    try {
      currentAbort?.abort();
      currentAbort = new AbortController();
      const r = await fetch('off_search.php?q=' + encodeURIComponent(q) + '&page_size=20&lang=nl', {
        signal: currentAbort.signal
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Zoekfout');
      render(j.results);
    } catch (e) {
      if (e.name !== 'AbortError') {
        console.error(e);
        render([]);
      }
    }
  }, 250);

  input.addEventListener('input', triggerSearch);

  const css = `
    #search-overlay .off-card{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #2b2b2b;border-radius:12px;margin:8px 0;background:#111;transition:background .15s, border-color .15s; outline:none}
    #search-overlay .off-card.clickable{cursor:pointer}
    #search-overlay .off-card:hover{background:#131313;border-color:#3a3a3a}
    #search-overlay .off-card:focus-visible{box-shadow:0 0 0 2px #22c55e66}
    #search-overlay .off-card-left img{width:54px;height:54px;object-fit:cover;border-radius:8px;background:#222}
    #search-overlay .off-card-mid{flex:1;min-width:0}
    #search-overlay .off-card-mid .name{font-weight:700}
    #search-overlay .off-card-mid .brand{opacity:.7;font-size:.9rem;margin-bottom:6px}
    #search-overlay .off-card-mid .meta{display:flex;gap:10px;opacity:.85;font-size:.85rem;flex-wrap:wrap}
    #search-overlay .chev{opacity:.5;margin-left:auto}
    #search-overlay .off-card.open .chev{transform:rotate(90deg); opacity:.9}

    #search-overlay .off-panel{width:100%; margin-top:10px}
    #search-overlay .off-panel-inner{border:1px solid #333;border-radius:12px;padding:12px;background:#0f0f0f}
    #search-overlay .off-panel .row{display:flex;align-items:center;gap:12px;margin-bottom:8px}
    #search-overlay .off-panel label{opacity:.8;min-width:96px}
    #search-overlay .off-panel .qty{display:flex;gap:8px;align-items:center}
    #search-overlay .off-panel .qty-input{width:120px;background:#0c0c0c;border:1px solid #333;border-radius:10px;padding:8px 10px;color:#eaeaea;outline:none}
    #search-overlay .off-panel select.qty-unit{background:#0c0c0c;border:1px solid #333;border-radius:10px;padding:8px 10px;color:#eaeaea;outline:none}
    #search-overlay .off-panel .preview{opacity:.85;margin:8px 0}
    #search-overlay .off-panel .actions{display:flex;gap:8px;justify-content:flex-end}
    #search-overlay .off-panel .actions .add{background:#22c55e;color:#0b0b0b;border:0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
    #search-overlay .off-panel .actions .cancel{background:#1b1b1b;color:#ddd;border:1px solid #333;border-radius:10px;padding:8px 12px;cursor:pointer}
  `;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);
})();
</script>

</body>
</html>
