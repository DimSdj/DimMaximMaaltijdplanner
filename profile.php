<?php
// profile.php — Profiel / Instellingen (dagdoelen)
$activeTab = 'profile';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/* -------------------------- Fallback helpers -------------------------- */
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
        return $rows ?: [];
    }
}
if (!function_exists('esc')) {
    function esc($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* -------------------------- Goals bootstrap -------------------------- */
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

$existing = sql_select($mysqli, "SELECT * FROM goals WHERE id=1");
if (!$existing) {
    sql_execute(
        $mysqli,
        "INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
     VALUES (1, 1877, 150.0, 250.0, 70.0, NOW())"
    );
    $existing = sql_select($mysqli, "SELECT * FROM goals WHERE id=1");
}
$goals = $existing[0];

/* -------------------------- POST verwerken -------------------------- */
$successMsg = $errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kcal = isset($_POST['kcal']) ? (int) $_POST['kcal'] : (int) $goals['kcal'];
    $protein = isset($_POST['protein']) ? (float) str_replace(',', '.', $_POST['protein']) : (float) $goals['protein'];
    $carb = isset($_POST['carb']) ? (float) str_replace(',', '.', $_POST['carb']) : (float) $goals['carb'];
    $fat = isset($_POST['fat']) ? (float) str_replace(',', '.', $_POST['fat']) : (float) $goals['fat'];

    if ($kcal < 800 || $kcal > 6000) {
        $errorMsg = "Kcal moet tussen 800 en 6000 liggen.";
    } elseif ($protein < 0 || $protein > 500 || $carb < 0 || $carb > 1000 || $fat < 0 || $fat > 300) {
        $errorMsg = "Macro-doelen buiten geldige grenzen.";
    } else {
        $ok = sql_execute($mysqli, "
      INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
      VALUES (1, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        kcal=VALUES(kcal),
        protein=VALUES(protein),
        carb=VALUES(carb),
        fat=VALUES(fat),
        updated_at=VALUES(updated_at)
    ", [$kcal, $protein, $carb, $fat]);

        if ($ok) {
            $successMsg = "Dagdoelen opgeslagen.";
            $goals = sql_select($mysqli, "SELECT * FROM goals WHERE id=1")[0];
        } else {
            $errorMsg = "Opslaan mislukt.";
        }
    }
}

/* -------------------------- Header -------------------------- */
// LET OP: header en footer staan in /layouts/
require __DIR__ . '/layouts/header.php';
?>

<!-- PAGE CONTENT -->
<main class="container" style="max-width:1120px;margin:0 auto;padding:18px 16px;">
    <h1 style="font-size:32px;line-height:1.2;margin:8px 0 12px 0;font-weight:800;">Profiel</h1>
    <p style="opacity:.85;margin:0 0 18px 0;">Stel hier je dagelijkse doelen in.</p>

    <?php if ($successMsg): ?>
        <div
            style="background:#093; color:#d8ffe1; border:1px solid #1f7a3a; border-radius:12px; padding:10px 12px; margin:0 0 12px 0;">
            <?= esc($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div
            style="background:#551414; color:#ffd6d6; border:1px solid #7a2a2a; border-radius:12px; padding:10px 12px; margin:0 0 12px 0;">
            <?= esc($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="./profile.php"
        style="max-width:520px;background:#101010;border:1px solid #2b2b2b;border-radius:16px;padding:16px;display:grid;gap:12px;">
        <label style="display:grid;gap:6px;">
            <span>Kilocalorieën per dag</span>
            <input type="number" name="kcal" min="800" max="6000" step="1" value="<?= (int) $goals['kcal'] ?>"
                style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <label style="display:grid;gap:6px;">
                <span>Eiwit (g)</span>
                <input type="number" name="protein" min="0" max="500" step="0.1" value="<?= (float) $goals['protein'] ?>"
                    style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
            </label>

            <label style="display:grid;gap:6px;">
                <span>Koolhydraten (g)</span>
                <input type="number" name="carb" min="0" max="1000" step="0.1" value="<?= (float) $goals['carb'] ?>"
                    style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
            </label>

            <label style="display:grid;gap:6px;">
                <span>Vet (g)</span>
                <input type="number" name="fat" min="0" max="300" step="0.1" value="<?= (float) $goals['fat'] ?>"
                    style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
            </label>
        </div>

        <div style="display:flex;justify-content:flex-end;">
            <button type="submit"
                style="background:#22c55e;color:#0b0b0b;font-weight:700;border:0;border-radius:10px;padding:10px 14px;cursor:pointer;">
                Opslaan
            </button>
        </div>
    </form>

    <p style="opacity:.75;margin:12px 0 0 0;">
        Tip: na het opslaan gebruiken de planner en het dagboek deze doelen voor de ringen.
    </p>
</main>

<?php
/* -------------------------- Footer -------------------------- */
require __DIR__ . '/layouts/footer.php'; // sluit body + html en toont bottom tabbar
