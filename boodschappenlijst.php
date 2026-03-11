<?php
// boodschappenlijst.php — User Story 3 (Maxim): automatische boodschappenlijst uit de planning
$activeTab = 'boodschappen';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/layouts/header.php';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Week-bereik (ma t/m zo)
$refDate = $_GET['date'] ?? date('Y-m-d');
$refTs = strtotime($refDate);
$dow = (int)date('N', $refTs);           // 1=ma, 7=zo
$weekStartTs = strtotime('-' . ($dow - 1) . ' days', $refTs);
$weekEndTs = strtotime('+6 days', $weekStartTs);
$start = date('Y-m-d', $weekStartTs);
$end   = date('Y-m-d', $weekEndTs);

$prevWeekDate = date('Y-m-d', strtotime('-7 days', $weekStartTs));
$nextWeekDate = date('Y-m-d', strtotime('+7 days', $weekStartTs));

$rows = sql_select($mysqli, "
    SELECT
        LOWER(TRIM(COALESCE(f.description, 'onbekend'))) AS name_norm,
        TRIM(COALESCE(f.brand, ''))                      AS brand,
        SUM(COALESCE(m.grams, 0))                        AS total_grams
    FROM meals m
    LEFT JOIN foods f ON f.id = m.food_id
    WHERE m.date BETWEEN ? AND ?
    GROUP BY name_norm, brand
    HAVING total_grams > 0
    ORDER BY name_norm ASC
", [$start, $end], 'ss');

function format_qty_g($grams) {
    $g = (int)round((float)$grams);
    if ($g >= 1000) {
        $kg = $g / 1000;
        $kg_disp = (floor($kg) == $kg) ? number_format($kg, 0, ',', '') : number_format($kg, 1, ',', '');
        return $kg_disp . ' kg';
    }
    return $g . ' g';
}
?>

<main class="container" style="max-width:1120px;margin:0 auto;padding:18px 16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:32px;line-height:1.2;margin:8px 0 8px 0;font-weight:800;">Boodschappenlijst</h1>
            <p style="opacity:.85;margin:0;">
                Week <?= esc(date('d M', $weekStartTs)) ?> – <?= esc(date('d M Y', $weekEndTs)) ?>
            </p>
        </div>

        <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="?date=<?= esc($prevWeekDate) ?>" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);text-decoration:none;color:#eaeaea;">
                ← Vorige week
            </a>
            <a href="?date=<?= esc($nextWeekDate) ?>" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);text-decoration:none;color:#eaeaea;">
                Volgende week →
            </a>
            <button type="button" onclick="window.print()" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:var(--brand);color:var(--ink);font-weight:800;cursor:pointer;">
                Print
            </button>
        </div>
    </div>

    <form method="get" class="no-print" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <div>
            <label style="display:block;margin:0 0 6px 0;opacity:.9;">Kies een datum in deze week</label>
            <input type="date" name="date" value="<?= esc($refDate) ?>"
                style="padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
        </div>
        <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#1b1b1b;color:#eaeaea;font-weight:800;cursor:pointer;">
            Toon
        </button>
    </form>

    <section style="margin-top:16px;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px;background:rgba(255,255,255,.03);">
        <?php if (empty($rows)): ?>
            <p style="margin:0;opacity:.85;">Er staan nog geen geplande items in deze week.</p>
        <?php else: ?>
            <div style="display:grid;gap:10px;">
                <?php foreach ($rows as $r): ?>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;border-bottom:1px solid rgba(255,255,255,.06);padding:10px 0;">
                        <div>
                            <div style="font-weight:900;"><?= esc(ucwords($r['name_norm'])) ?></div>
                            <?php if (!empty($r['brand'])): ?>
                                <div style="opacity:.75;font-size:13px;"><?= esc($r['brand']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="font-weight:900;"><?= esc(format_qty_g($r['total_grams'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="opacity:.75;margin:12px 0 0 0;font-size:13px;">
                Let op: in deze versie tellen we alleen alles bij elkaar op in <strong>gram</strong>.
            </p>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
