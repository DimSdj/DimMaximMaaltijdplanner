<?php
// planner.php — Weekoverzicht met dag-ringen (simpel & klikbaar)
$activeTab = 'planner';

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

/* ---------------- Fallback helpers (als helpers.php ze niet heeft) ---------------- */
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

/* ---------------- Doelen (bootstrap + laden) ---------------- */
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
if (!sql_select($mysqli, "SELECT 1 FROM goals WHERE id=1")) {
    sql_execute($mysqli, "INSERT INTO goals (id,kcal,protein,carb,fat,updated_at) VALUES (1,1877,150,250,70,NOW())");
}
$g = sql_select($mysqli, "SELECT kcal,protein,carb,fat FROM goals WHERE id=1");
$goals = [
    'kcal' => (int) ($g[0]['kcal'] ?? 1877),
    'protein' => (float) ($g[0]['protein'] ?? 150),
    'carb' => (float) ($g[0]['carb'] ?? 250),
    'fat' => (float) ($g[0]['fat'] ?? 70),
];

/* ---------------- Datum / weekbereik ---------------- */
$refDate = $_GET['date'] ?? date('Y-m-d');
$refTs = strtotime($refDate);
$dow = (int) date('N', $refTs);               // 1=maandag ... 7=zondag
$weekStartTs = strtotime("-" . ($dow - 1) . " days", $refTs); // maandag
$weekEndTs = strtotime("+6 days", $weekStartTs);      // zondag

$prevWeekDate = date('Y-m-d', strtotime('-7 days', $weekStartTs));
$nextWeekDate = date('Y-m-d', strtotime('+7 days', $weekStartTs));

/* ---------------- Kolom-detectie (robust) ---------------- */
function table_columns(mysqli $db, string $table): array
{
    $cols = [];
    $rows = sql_select($db, "SHOW COLUMNS FROM `$table`");
    foreach ($rows as $r)
        $cols[strtolower($r['Field'])] = $r['Field'];
    return $cols;
}
function resolve_col(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        $k = strtolower($c);
        if (isset($cols[$k]))
            return $cols[$k];
    }
    return null;
}
$foodCols = table_columns($mysqli, 'foods');
$mealCols = table_columns($mysqli, 'meals');

$FOOD_ID = resolve_col($foodCols, ['id']) ?? 'id';
$FOOD_DESC = resolve_col($foodCols, ['description', 'name', 'product_name']);
$FOOD_IMG = resolve_col($foodCols, ['image_url', 'image']);
$FOOD_BR = resolve_col($foodCols, ['brand', 'brands']);

$KCAL_F = resolve_col($foodCols, ['kcal_per_100g', 'energy_kcal_100g', 'energy_100g', 'calories_100g', 'kcal', 'calories']);
$PROT_F = resolve_col($foodCols, ['protein_per_100g', 'proteins_100g', 'protein_100g', 'protein', 'proteins']);
$CARB_F = resolve_col($foodCols, ['carb_per_100g', 'carbs_per_100g', 'carbohydrates_100g', 'carb_100g', 'carbs_100g', 'carbohydrates', 'carb', 'carbs']);
$FAT_F = resolve_col($foodCols, ['fat_per_100g', 'fat_100g', 'fats_100g', 'fat', 'fats']);
$HAS_FOOD_MACROS = $KCAL_F && $PROT_F && $CARB_F && $FAT_F;

$MEAL_ID = resolve_col($mealCols, ['id']) ?? 'id';
$MEAL_DATE = resolve_col($mealCols, ['date']) ?? 'date';
$MEAL_FID = resolve_col($mealCols, ['food_id', 'foods_id']) ?? 'food_id';
$MEAL_GR = resolve_col($mealCols, ['grams', 'gram', 'amount_grams']) ?? 'grams';

$KCAL_M = resolve_col($mealCols, ['kcal']);
$PROT_M = resolve_col($mealCols, ['protein']);
$CARB_M = resolve_col($mealCols, ['carb', 'carbs', 'carbohydrates']);
$FAT_M = resolve_col($mealCols, ['fat', 'fats']);
$HAS_MEAL_MACROS = $KCAL_M && $PROT_M && $CARB_M && $FAT_M;

/* ---------------- Meals van de hele week ophalen ---------------- */
$start = date('Y-m-d', $weekStartTs);
$end = date('Y-m-d', $weekEndTs);

$select = "
  SELECT
    m.`$MEAL_DATE` AS m_date,
    m.`$MEAL_GR`   AS grams
";
if ($HAS_MEAL_MACROS) {
    $select .= ",
    m.`$KCAL_M` AS m_kcal,
    m.`$PROT_M` AS m_protein,
    m.`$CARB_M` AS m_carb,
    m.`$FAT_M`  AS m_fat
  ";
}
if ($HAS_FOOD_MACROS) {
    $select .= ",
    f.`$KCAL_F` AS f_kcal100,
    f.`$PROT_F` AS f_prot100,
    f.`$CARB_F` AS f_carb100,
    f.`$FAT_F`  AS f_fat100
  ";
}
$select .= "
  FROM meals m
  LEFT JOIN foods f ON f.`$FOOD_ID` = m.`$MEAL_FID`
  WHERE m.`$MEAL_DATE` BETWEEN ? AND ?
  ORDER BY m.`$MEAL_DATE` ASC
";
$rows = sql_select($mysqli, $select, [$start, $end]);

/* ---------------- Aggregatie per dag ---------------- */
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i days", $weekStartTs));
    $days[$d] = ['kcal' => 0, 'protein' => 0, 'carb' => 0, 'fat' => 0];
}
foreach ($rows as $r) {
    $d = $r['m_date'];
    if (!isset($days[$d]))
        continue;
    if ($HAS_FOOD_MACROS) {
        $f = max(0, (int) $r['grams']) / 100.0;
        $days[$d]['kcal'] += (float) $r['f_kcal100'] * $f;
        $days[$d]['protein'] += (float) $r['f_prot100'] * $f;
        $days[$d]['carb'] += (float) $r['f_carb100'] * $f;
        $days[$d]['fat'] += (float) $r['f_fat100'] * $f;
    } elseif ($HAS_MEAL_MACROS) {
        $days[$d]['kcal'] += (float) $r['m_kcal'];
        $days[$d]['protein'] += (float) $r['m_protein'];
        $days[$d]['carb'] += (float) $r['m_carb'];
        $days[$d]['fat'] += (float) $r['m_fat'];
    }
}

/* ---------------- UI ---------------- */
$nlDays = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eetplanner – Planner (week)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #0f0f0f;
            --card: #171717;
            --muted: #9ca3af;
            --accent: #22c55e;
        }

        body {
            background: var(--bg);
            color: #fff;
        }

        .ring svg {
            display: block;
        }

        .tabbar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            background: #171717E6;
            border-top: 1px solid #2a2a35;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-around;
            z-index: 50;
        }
    </style>
</head>

<body class="min-h-screen pb-24">
    <header class="px-5 pt-4">
        <h1 class="text-3xl font-bold">Planner</h1>
        <div class="mt-2 flex items-center gap-3 text-white/80">
            <a href="./planner.php?date=<?= esc($prevWeekDate) ?>"
                class="px-3 py-1 rounded-lg bg-white/10 border border-white/15">← Vorige</a>
            <div class="text-sm">
                Week: <strong><?= date('d M', $weekStartTs) ?> – <?= date('d M Y', $weekEndTs) ?></strong>
            </div>
            <a href="./planner.php?date=<?= esc($nextWeekDate) ?>"
                class="px-3 py-1 rounded-lg bg-white/10 border border-white/15">Volgende →</a>
        </div>
    </header>

    <section class="px-5 mt-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4">
            <?php for ($i = 0; $i < 7; $i++):
                $dTs = strtotime("+$i days", $weekStartTs);
                $dKey = date('Y-m-d', $dTs);
                $dw = (int) date('N', $dTs);
                $isToday = (date('Y-m-d') === $dKey);
                $vals = $days[$dKey];
                $pct = $goals['kcal'] > 0 ? min(100, ($vals['kcal'] / $goals['kcal']) * 100) : 0;
                ?>
                <a href="./index.php?date=<?= esc($dKey) ?>"
                    class="rounded-2xl border <?= $isToday ? 'border-[color:var(--accent)]' : 'border-white/10' ?> bg-[color:var(--card)] p-4 block hover:border-white/30">
                    <div class="flex items-center justify-between mb-2">
                        <div class="font-semibold uppercase text-white/80"><?= $nlDays[$dw - 1] ?></div>
                        <div class="text-white/60 text-sm"><?= date('d M', $dTs) ?></div>
                    </div>

                    <div class="ring mx-auto" style="width:110px; height:110px;">
                        <?php $p = round($pct); ?>
                        <svg viewBox="0 0 36 36" width="110" height="110">
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#262626" stroke-width="3.5" />
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#22c55e" stroke-width="3.5"
                                stroke-linecap="round" stroke-dasharray="<?= $p ?>,100" transform="rotate(-90 18 18)" />
                            <text x="18" y="18" dy="4" text-anchor="middle" font-size="8" fill="#fff"
                                font-weight="700"><?= $p ?>%</text>
                        </svg>
                    </div>

                    <div class="text-center mt-2 text-sm">
                        <?= (int) round($vals['kcal']) ?> / <?= (int) $goals['kcal'] ?> kcal
                    </div>
                    <div class="mt-2 text-xs text-white/75 space-y-1">
                        <div>Eiwit: <?= (int) round($vals['protein']) ?> / <?= (int) $goals['protein'] ?> g</div>
                        <div>Kh: <?= (int) round($vals['carb']) ?> / <?= (int) $goals['carb'] ?> g</div>
                        <div>Vet: <?= (int) round($vals['fat']) ?> / <?= (int) $goals['fat'] ?> g</div>
                    </div>
                </a>
            <?php endfor; ?>
        </div>
    </section>

    <nav class="tabbar">
        <a href="./index.php" class="<?= $activeTab === 'dagboek' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
            <div class="flex flex-col items-center gap-1">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M4 6h16v12H4zM7 9h10v2H7zM7 13h6v2H7z" />
                </svg>
                <span class="text-xs">Dagboek</span>
            </div>
        </a>

        <a href="./planner.php"
            class="<?= $activeTab === 'planner' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
            <div class="flex flex-col items-center gap-1">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M6 3h2v2h8V3h2v2h2v14H4V5h2V3zm0 6h12v8H6V9z" />
                </svg>
                <span class="text-xs">Planner</span>
            </div>
        </a>

        <a href="./recepten.php"
            class="<?= $activeTab === 'recepten' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
            <div class="flex flex-col items-center gap-1">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M6 4h11a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v12h11V6H6Zm2 2h7v2H8V8Zm0 4h7v2H8v-2Z" />
                </svg>
                <span class="text-xs">Recepten</span>
            </div>
        </a>

        <a href="./tags.php" class="<?= $activeTab === 'tags' ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
            <div class="flex flex-col items-center gap-1">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M10 3H5a2 2 0 0 0-2 2v5a2 2 0 0 0 .59 1.41l8 8a2 2 0 0 0 2.82 0l5.18-5.18a2 2 0 0 0 0-2.82l-8-8A2 2 0 0 0 10 3zm-3 5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" />
                </svg>
                <span class="text-xs">Tags</span>
            </div>
        </a>

        <a href="./profile.php"
            class="<?= ($activeTab === 'profiel' || $activeTab === 'profile') ? 'text-[color:var(--accent)]' : 'text-white/70' ?>">
            <div class="flex flex-col items-center gap-1">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5z" />
                </svg>
                <span class="text-xs">Profiel</span>
            </div>
        </a>
    </nav>
</body>

</html>