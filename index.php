<?php
// index.php (met FAB + zoekoverlay)
$activeTab = 'dagboek';

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

/* --------------------------- Fallback helpers --------------------------- */
if (!function_exists('sql_execute')) {
    function sql_execute(mysqli $mysqli, string $sql, array $params = []): bool
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt)
            return false;
        if ($params) {
            $types = '';
            $vals = [];
            foreach ($params as $p) {
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
                $vals[] = $p;
            }
            $stmt->bind_param($types, ...$vals);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
if (!function_exists('sql_select')) {
    function sql_select(mysqli $mysqli, string $sql, array $params = []): array
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt)
            return [];
        if ($params) {
            $types = '';
            $vals = [];
            foreach ($params as $p) {
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
                $vals[] = $p;
            }
            $stmt->bind_param($types, ...$vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
if (!function_exists('esc')) {
    function esc($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('meal_macros')) {
    // ⚠️ AANGEPAST: gebruikt kcal_calc/protein_calc/... uit de query als fallback
    function meal_macros(mysqli $mysqli, array $meal): array
    {
        $k = isset($meal['kcal_calc']) ? (float) $meal['kcal_calc'] : (float) ($meal['kcal'] ?? 0);
        $p = isset($meal['protein_calc']) ? (float) $meal['protein_calc'] : (float) ($meal['protein'] ?? 0);
        $c = isset($meal['carb_calc']) ? (float) $meal['carb_calc'] : (float) ($meal['carb'] ?? 0);
        $f = isset($meal['fat_calc']) ? (float) $meal['fat_calc'] : (float) ($meal['fat'] ?? 0);
        return ['kcal' => $k, 'protein' => $p, 'carb' => $c, 'fat' => $f];
    }
}

/* --------------------------- Goals bootstrap --------------------------- */
sql_execute($mysqli, "
  CREATE TABLE IF NOT EXISTS goals (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    kcal INT NOT NULL DEFAULT 1800,
    protein DECIMAL(6,1) NOT NULL DEFAULT 120.0,
    carb DECIMAL(6,1) NOT NULL DEFAULT 250.0,
    fat DECIMAL(6,1) NOT NULL DEFAULT 70.0,
    updated_at TIMESTAMP NULL DEFAULT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$exists = sql_select($mysqli, "SELECT 1 FROM goals WHERE id=1");
if (!$exists) {
    sql_execute($mysqli, "INSERT INTO goals (id, kcal, protein, carb, fat, updated_at) VALUES (1, 1877, 150.0, 250.0, 70.0, NOW())");
}
$g = sql_select($mysqli, "SELECT kcal, protein, carb, fat FROM goals WHERE id=1");
$goalKcal = (int) ($g[0]['kcal'] ?? 1877);
$goalP = (float) ($g[0]['protein'] ?? 150);
$goalC = (float) ($g[0]['carb'] ?? 250);
$goalF = (float) ($g[0]['fat'] ?? 70);

/* ------------------------------ Data vandaag ------------------------------ */
$today = date('Y-m-d');
$slots = ['Ontbijt', 'Lunch', 'Diner', 'Snack'];

/* ⚠️ AANGEPAST QUERY: berekent kcal/prot/kh/vet on-the-fly als meals-waarden 0/NULL zijn */
$mealsToday = sql_select($mysqli, "
  SELECT
    m.*,
    f.description AS food_name,
    f.brand,
    f.image_url,

    CASE WHEN (m.kcal IS NULL OR m.kcal = 0)
         THEN (IFNULL(f.kcal_100g,0)    * (m.grams/100.0)) ELSE m.kcal END    AS kcal_calc,
    CASE WHEN (m.protein IS NULL OR m.protein = 0)
         THEN (IFNULL(f.protein_100g,0) * (m.grams/100.0)) ELSE m.protein END AS protein_calc,
    CASE WHEN (m.carb IS NULL OR m.carb = 0)
         THEN (IFNULL(f.carbs_100g,0)   * (m.grams/100.0)) ELSE m.carb END    AS carb_calc,
    CASE WHEN (m.fat IS NULL OR m.fat = 0)
         THEN (IFNULL(f.fat_100g,0)     * (m.grams/100.0)) ELSE m.fat END     AS fat_calc

  FROM meals m
  LEFT JOIN foods f ON f.id = m.food_id
  WHERE m.date = ?
  ORDER BY FIELD(m.slot,'Ontbijt','Lunch','Diner','Snack'), m.id DESC
", [$today]);

$totals = ['kcal' => 0, 'protein' => 0, 'fat' => 0, 'carb' => 0];
foreach ($mealsToday as $i => $meal) {
    $mac = meal_macros($mysqli, $meal);
    $mealsToday[$i]['mac'] = $mac;
    $totals['kcal'] += $mac['kcal'];
    $totals['protein'] += $mac['protein'];
    $totals['fat'] += $mac['fat'];
    $totals['carb'] += $mac['carb'];
}
$eaten = (int) round($totals['kcal']);
$remaining = max(0, $goalKcal - $eaten);

$kcalWarning = '';
if ($goalKcal > 0) {
    $low = $goalKcal * 0.90;
    $high = $goalKcal * 1.10;
    if ($eaten > $high) {
        $kcalWarning = 'Let op, je zit boven 110% van je doel vandaag.';
    } elseif ($eaten > 0 && $eaten < $low) {
        $kcalWarning = 'Let op, je zit onder 90% van je doel vandaag.';
    }
}

$progress = $goalKcal > 0 ? min(100, ($eaten / $goalKcal) * 100) : 0;

$grouped = array_fill_keys($slots, []);
foreach ($mealsToday as $meal) {
    $grouped[$meal['slot']][] = $meal;
}

/* Inline placeholder voor lijst-afbeeldingen (geen externe host) */
if (!function_exists('ph_svg_data_uri')) {
    function ph_svg_data_uri(int $w = 50, int $h = 50, string $bg = '#262626', string $fg = '#999'): string
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d"><rect width="100%%" height="100%%" fill="%3$s"/><text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" font-size="%4$d" fill="%5$s">%1$dx%2$d</text></svg>',
            $w,
            $h,
            $bg,
            max(8, (int) round(min($w, $h) / 5)),
            $fg
        );
        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}
$ph50 = ph_svg_data_uri(50, 50);

?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Eetplanner – Vandaag</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #0f0f0f;
            --card: #171717;
            --muted: #9ca3af;
            --ring-carb: #ef4444;
            --ring-prot: #22c55e;
            --ring-fat: #fb923c;
            --accent: #22c55e;
        }

        body {
            background: var(--bg);
        }

        .ring-chart circle.bg {
            stroke: #262626;
        }

        .ring-chart text {
            fill: #fff;
            font-weight: 700;
        }
    </style>
</head>

<body class="text-white min-h-screen flex flex-col pb-24">
    <div class="h-4"></div>

    <!-- Header -->
    <header class="px-5">
        <div class="flex items-center justify-between">
            <h1 class="text-3xl font-bold">Vandaag</h1>
        </div>
        <p class="mt-2 text-sm">
            <span class="text-white/80">Je kunt nog</span>
            <span class="font-semibold"><?= $remaining ?></span>
            <span class="text-white/80">calorieën eten</span>
        </p>
        <div class="mt-3">
            <div class="flex justify-between text-xs text-white/70">
                <span><?= $eaten ?> calorieën gegeten</span>
                <span>Doel: <?= $goalKcal ?></span>
            </div>
            <?php if (!empty($kcalWarning)): ?>
                <div class="mt-2 text-xs text-yellow-300"><?= esc($kcalWarning) ?></div>
            <?php endif; ?>
            <div class="mt-2 h-2 rounded-full bg-white/10 overflow-hidden">

                <div class="h-full bg-[color:var(--accent)]" style="width:<?= $progress ?>%"></div>
            </div>
            <div class="mt-2 text-xs text-white/70">0 kcal</div>
        </div>
    </header>

    <!-- Macro ringen -->
    <section class="px-5 mt-5">
        <div class="grid grid-cols-3 gap-4">
            <?php
            $pp = $goalP > 0 ? min(100, ($totals['protein'] / $goalP) * 100) : 0;
            $pf = $goalF > 0 ? min(100, ($totals['fat'] / $goalF) * 100) : 0;
            $pc = $goalC > 0 ? min(100, ($totals['carb'] / $goalC) * 100) : 0;
            ?>
            <!-- Carb -->
            <div class="flex flex-col items-center">
                <div class="ring-chart relative">
                    <svg width="96" height="96" viewBox="0 0 36 36" class="block">
                        <circle class="bg" cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" />
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="var(--ring-carb)" stroke-width="3.5"
                            stroke-linecap="round" stroke-dasharray="<?= round($pc) ?>,100"
                            transform="rotate(-90 18 18)" />
                        <text x="18" y="18" dy="4" text-anchor="middle" font-size="9"><?= round($pc) ?>%</text>
                    </svg>
                </div>
                <div class="mt-2 text-center">
                    <div class="text-sm">Koolhydraten</div>
                    <div class="text-[11px] text-white/70"><?= number_format(max(0, $goalC - $totals['carb']), 1) ?>g
                        over</div>
                </div>
            </div>
            <!-- Protein -->
            <div class="flex flex-col items-center">
                <div class="ring-chart relative">
                    <svg width="96" height="96" viewBox="0 0 36 36" class="block">
                        <circle class="bg" cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" />
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="var(--ring-prot)" stroke-width="3.5"
                            stroke-linecap="round" stroke-dasharray="<?= round($pp) ?>,100"
                            transform="rotate(-90 18 18)" />
                        <text x="18" y="18" dy="4" text-anchor="middle" font-size="9"><?= round($pp) ?>%</text>
                    </svg>
                </div>
                <div class="mt-2 text-center">
                    <div class="text-sm">eiwit</div>
                    <div class="text-[11px] text-white/70"><?= number_format(max(0, $goalP - $totals['protein']), 1) ?>g
                        over</div>
                </div>
            </div>
            <!-- Fat -->
            <div class="flex flex-col items-center">
                <div class="ring-chart relative">
                    <svg width="96" height="96" viewBox="0 0 36 36" class="block">
                        <circle class="bg" cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" />
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="var(--ring-fat)" stroke-width="3.5"
                            stroke-linecap="round" stroke-dasharray="<?= round($pf) ?>,100"
                            transform="rotate(-90 18 18)" />
                        <text x="18" y="18" dy="4" text-anchor="middle" font-size="9"><?= round($pf) ?>%</text>
                    </svg>
                </div>
                <div class="mt-2 text-center">
                    <div class="text-sm">Vet</div>
                    <div class="text-[11px] text-white/70"><?= number_format(max(0, $goalF - $totals['fat']), 1) ?>g
                        over</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Meals per slot -->
    <main class="px-5 mt-4 space-y-6">
        <div class="flex gap-3">
            <a href="./recepten.php"
                class="px-4 py-2 rounded-xl border border-white/10 bg-white/5 text-sm font-semibold">
                Recepten
            </a>
            <a href="./boodschappenlijst.php"
                class="px-4 py-2 rounded-xl border border-white/10 bg-white/5 text-sm font-semibold">
                Boodschappenlijst
            </a>
        </div>

        <?php foreach ($slots as $slot):
            $items = $grouped[$slot] ?? [];
            if (!count($items))
                continue;
            $slotKcal = 0;
            foreach ($items as $meal)
                $slotKcal += $meal['mac']['kcal'];
            ?>
            <section>
                <div class="flex items-center justify-between">
                    <h2 class="text-white/90 text-sm uppercase tracking-wide"><?= esc($slot) ?></h2>
                    <div class="text-xs text-[color:var(--accent)]"><?= (int) round($slotKcal) ?> calorieën</div>
                </div>
                <div class="mt-3 space-y-3">
                    <?php foreach ($items as $meal): ?>
                        <article class="bg-[color:var(--card)] rounded-2xl p-3 flex gap-3 items-center shadow">
                            <img src="<?= esc($meal['image_url'] ?: $ph50) ?>" class="w-12 h-12 rounded-lg object-cover" alt="">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-medium truncate">
                                    <?= esc($meal['food_name'] ?: 'Product') ?>
                                    <?php if (!empty($meal['brand'])): ?>
                                        <span class="text-white/50"><?= esc($meal['brand']) ?></span>
                                    <?php endif; ?>
                                </h3>
                                <p class="text-xs text-white/60">
                                    <?= (int) round($meal['mac']['kcal']) ?> kcal •
                                    <?= number_format($meal['mac']['protein'], 1) ?>p /
                                    <?= number_format($meal['mac']['fat'], 1) ?>f /
                                    <?= number_format($meal['mac']['carb'], 1) ?>kh
                                </p>
                            </div>
                            <form method="POST" action="remove.php" class="shrink-0">
                                <input type="hidden" name="meal_id" value="<?= (int) $meal['id'] ?>">
                                <button class="p-2 rounded-full bg-white/5 hover:bg-white/10" title="Verwijderen">×</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="h-6"></div>
    </main>

    <!-- FAB (plusknop) -->
    <button id="fabAdd"
        class="fixed right-5 bottom-24 w-14 h-14 rounded-full bg-[color:var(--accent)] text-black font-bold shadow-lg grid place-items-center z-[70]"
        title="Voeg toe">+</button>

    <!-- Overlay + Bottom Sheet zoeken -->
    <div id="addSheet" class="fixed inset-0 hidden z-[60]">
        <button id="addOverlay" class="absolute inset-0 bg-black/60" aria-label="Sluiten"></button>
        <div
            class="absolute inset-x-0 bottom-0 bg-[color:var(--card)] rounded-t-3xl border-t border-white/10 max-h-[85vh] h-[85vh] flex flex-col">
            <!-- Top bar -->
            <div class="p-4 pb-2 flex items-center justify-between">
                <button id="sheetClose" class="p-2 -ml-2 text-white/80" aria-label="Sluiten">✕</button>
                <div class="opacity-0">.</div>
            </div>

            <!-- Zoekbalk -->
            <div class="px-4">
                <div class="relative">
                    <input id="searchInput" type="text" placeholder="Zoek producten"
                        class="w-full text-white placeholder-white/60 bg-white/10 rounded-2xl px-4 py-3 pr-24 outline-none focus:ring-2 ring-[color:var(--accent)]" />
                    <button id="scanBtn" class="absolute right-3 top-1/2 -translate-y-1/2 p-2 rounded-xl bg-white/10"
                        title="Barcode scannen">▦</button>
                    <span class="absolute right-14 top-1/2 -translate-y-1/2 opacity-70">🔎</span>
                </div>
            </div>

            <!-- Filter pills -->
            <div class="px-4 mt-3 flex items-center gap-3 overflow-x-auto">
                <button
                    class="px-4 py-1.5 rounded-full bg-[color:var(--accent)] text-black font-semibold whitespace-nowrap">Alle
                    producten</button>
                <button class="px-4 py-1.5 rounded-full bg-white/10 text-white/80 whitespace-nowrap">Maaltijden</button>
                <button class="px-4 py-1.5 rounded-full bg-white/10 text-white/80 whitespace-nowrap">Door mij
                    aangemaakt</button>
            </div>

            <!-- Results states -->
            <div class="px-4 mt-3 text-sm text-white/60 hidden" id="searchLoading">Zoeken…</div>
            <div class="px-4 mt-3 text-sm text-white/60 hidden" id="searchEmpty">Geen resultaten…</div>
            <div class="px-4 mt-3 text-sm text-red-400 hidden" id="searchError">Er ging iets mis met zoeken.</div>

            <!-- Resultaten -->
            <div id="searchResults" class="px-4 mt-2 overflow-auto space-y-2 pb-4 flex-1"></div>
        </div>
    </div>

    <!-- NAV -->
    <nav class="fixed bottom-0 inset-x-0 bg-[color:var(--card)]/95 backdrop-blur border-t border-white/5 z-[50]">
        <div class="flex items-center justify-around h-16">
            <a href="./index.php"
                class="flex flex-col items-center gap-1 <?= $activeTab === 'dagboek' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                    <path d="M4 6h16v12H4zM7 9h10v2H7zM7 13h6v2H7z" />
                </svg>
                <span class="text-xs">Dagboek</span>
            </a>
            <a href="./planner.php"
                class="flex flex-col items-center gap-1 <?= $activeTab === 'planner' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                    <path d="M6 3h2v2h8V3h2v2h2v14H4V5h2V3zm0 6h12v8H6V9z" />
                </svg>
                <span class="text-xs">Planner</span>
            </a>
            
            <a href="./recepten.php"
                class="flex flex-col items-center gap-1 <?= $activeTab === 'recepten' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                    <path d="M6 4h11a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v12h11V6H6Zm2 2h7v2H8V8Zm0 4h7v2H8v-2Z" />
                </svg>
                <span class="text-xs">Recepten</span>
            </a>

            <a href="./profile.php"
                class="flex flex-col items-center gap-1 <?= $activeTab === 'profiel' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5z" />
                </svg>
                <span class="text-xs">Profiel</span>
            </a>
        </div>
    </nav>

 <script>
  // === Endpoints ===
  const SEARCH_ENDPOINT = 'off_search.php';
  const ADD_ENDPOINT    = 'add.php';

  // === FAB open/close ===
  const fab     = document.getElementById('fabAdd');
  const sheet   = document.getElementById('addSheet');
  const closeBtn= document.getElementById('sheetClose');
  const overlay = document.getElementById('addOverlay');

  const openSheet  = () => { sheet.classList.remove('hidden'); fab.classList.add('hidden'); };
  const closeSheet = () => { sheet.classList.add('hidden');   fab.classList.remove('hidden'); };

  fab.addEventListener('click', openSheet);
  closeBtn.addEventListener('click', closeSheet);
  overlay.addEventListener('click', closeSheet);

  // === Helpers ===
  function todayStr() { return new Date().toISOString().slice(0, 10); }
  function autoSlot() {
    const h = new Date().getHours();
    if (h >= 5 && h < 11) return 'Ontbijt';
    if (h >= 11 && h < 15) return 'Lunch';
    if (h >= 17 && h < 22) return 'Diner';
    return 'Snack';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }

  // Strikte nummer-parser: '' of '  ' wordt null (niet 0)
  const num = (v) => {
    if (v === undefined || v === null) return null;
    if (typeof v === 'string' && v.trim() === '') return null;
    const n = +v;
    return Number.isFinite(n) ? n : null;
  };

  // Normalizers voor OFF + varianten
  function kcalFrom(it) {
    const kcal =
      it.kcal_100g ??
      it['energy-kcal_100g'] ??
      it.energy_kcal_100g ??
      it.calories_100g ??
      (it['energy-kj_100g'] != null ? (+it['energy-kj_100g'] / 4.184) : null);
    return num(kcal);
  }
  function protFrom(it) {
    return num(it.protein_100g ?? it.proteins_100g ?? it.protein);
  }
  function carbFrom(it) {
    return num(it.carbs_100g ?? it.carbohydrates_100g ?? it.carb ?? it.carbohydrate);
  }
  function fatFrom(it) {
    return num(it.fat_100g ?? it.fat);
  }

  // Inline placeholder (geen externe host)
  const PH56 = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56"><rect width="100%" height="100%" fill="#262626"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="10" fill="#999">56x56</text></svg>';

  // POST helper → altijd JSON terug (of nette fout)
  async function postExpectJSON(url, body) {
    const resp = await fetch(url, { method: 'POST', body, headers: { 'Accept': 'application/json' } });
    const text = await resp.text();
    try { return JSON.parse(text); }
    catch { return { ok:false, error:'Server gaf geen geldige JSON', raw:text }; }
  }

  // === Zoeken ===
  const qInput  = document.getElementById('searchInput');
  const resBox  = document.getElementById('searchResults');
  const elEmpty = document.getElementById('searchEmpty');
  const elLoad  = document.getElementById('searchLoading');
  const elErr   = document.getElementById('searchError');

  // Enter = zoeken
  qInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const q = qInput.value.trim();
      if (q.length >= 2) doSearch(q);
    }
  });

  let debounce;
  qInput.addEventListener('input', () => {
    clearTimeout(debounce);
    const q = qInput.value.trim();
    if (q.length < 2) {
      resBox.innerHTML = '';
      elEmpty.classList.add('hidden');
      return;
    }
    debounce = setTimeout(() => doSearch(q), 300);
  });

async function doSearch(q) {
  elErr.classList.add('hidden');
  elEmpty.classList.add('hidden');
  elLoad.classList.remove('hidden');
  resBox.innerHTML = '';

  try {
    const r = await fetch(SEARCH_ENDPOINT + '?q=' + encodeURIComponent(q), {
      headers: { 'Accept': 'application/json' }
    });

    const text = await r.text();
    let data;

    try {
      data = JSON.parse(text);
    } catch (e) {
      throw new Error('Ongeldige JSON van search endpoint');
    }

    elLoad.classList.add('hidden');

    if (!r.ok || data.ok === false) {
      console.error('Search error:', data);
      elErr.classList.remove('hidden');
      return;
    }

    const items = Array.isArray(data.results) ? data.results : [];
    if (items.length === 0) {
      elEmpty.classList.remove('hidden');
      return;
    }

    for (const item of items) {
      const barcode = item.barcode || item.code || item.product_code || null;
      const foodId  = item.food_id || null;

      const k100 = kcalFrom(item);
      const p100 = protFrom(item);
      const c100 = carbFrom(item);
      const f100 = fatFrom(item);

      const kcalText = (k100 != null) ? `${Math.round(k100)} kcal` : 'kcal onbekend';
      const protText = (p100 != null) ? `${Math.round(p100)}p` : '';
      const fatText  = (f100 != null) ? `${Math.round(f100)}f` : '';
      const carbText = (c100 != null) ? `${Math.round(c100)}kh` : '';
      const extraLine = [protText, fatText, carbText].filter(Boolean).join(' / ');
      const serveText = item.serving || '100 gram / ml';

      const row = document.createElement('div');
      row.className = 'bg-white/5 rounded-2xl p-3 flex gap-3 items-center';
      row.innerHTML = `
        <img src="${item.image || PH56}" class="w-12 h-12 rounded-lg object-cover" alt=""/>
        <div class="flex-1 min-w-0">
          <div class="font-semibold truncate">
            ${escapeHtml(item.name || 'Product')}
            ${item.brand ? '<span class="text-white/60 font-normal"> ' + escapeHtml(item.brand) + '</span>' : ''}
          </div>
          <div class="text-xs">
            <span class="text-green-400 font-semibold">${kcalText}</span>
            <span class="text-white/60"> • ${escapeHtml(serveText)}</span>
            ${extraLine ? `<span class="text-white/50"> • ${extraLine}</span>` : ''}
          </div>
        </div>
        <button class="px-3 py-2 rounded-xl bg-[color:var(--accent)] text-black font-semibold shrink-0">
          Toevoegen
        </button>
      `;

      const btn = row.querySelector('button');

      btn.addEventListener('click', async () => {
        let grams = 100;

        if (item && item.is_recipe) {
          const porties = parseFloat(prompt('Hoeveel porties wil je toevoegen?', '1')) || 1;
          const pg = parseFloat(item.portion_grams || 100) || 100;
          grams = porties * pg;
        } else {
          grams = parseFloat(prompt('Hoeveel gram/ml wil je toevoegen?', '100')) || 100;
        }

        if (foodId) {
          const body = new URLSearchParams();
          body.set('date', todayStr());
          body.set('slot', autoSlot());
          body.set('grams', String(grams));
          body.set('food_id', String(foodId));

          btn.disabled = true;
          btn.textContent = 'Toevoegen…';

          try {
            const out = await postExpectJSON(ADD_ENDPOINT, body);
            if (out.ok) {
              closeSheet();
              location.reload();
            } else {
              alert('Toevoegen mislukt: ' + (out.error || 'onbekend'));
            }
          } catch (e) {
            alert('Netwerkfout.');
          } finally {
            btn.disabled = false;
            btn.textContent = 'Toevoegen';
          }

          return;
        }

        const fd = new FormData();
        fd.set('date', todayStr());
        fd.set('slot', autoSlot());
        fd.set('grams', String(grams));
        fd.set('name', item.name || '');
        fd.set('brand', item.brand || '');

        if (barcode) fd.set('code', barcode);
        if (item.image && !item.image.startsWith('data:image')) fd.set('image', item.image);

        if (k100 != null) fd.set('kcal_100g', String(k100));
        if (p100 != null) fd.set('protein_100g', String(p100));
        if (c100 != null) fd.set('carbs_100g', String(c100));
        if (f100 != null) fd.set('fat_100g', String(f100));

        btn.disabled = true;
        btn.textContent = 'Toevoegen…';

        try {
          const out = await postExpectJSON(ADD_ENDPOINT, fd);
          if (out.ok) {
            closeSheet();
            location.reload();
          } else {
            alert('Toevoegen mislukt: ' + (out.error || 'onbekend'));
          }
        } catch (e) {
          alert('Netwerkfout bij toevoegen.');
        } finally {
          btn.disabled = false;
          btn.textContent = 'Toevoegen';
        }
      });

      resBox.appendChild(row);
    }
  } catch (e) {
    console.error(e);
    elLoad.classList.add('hidden');
    elErr.classList.remove('hidden');
  }
}
</script>
</body>
</html>