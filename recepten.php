<?php
// recepten.php — User Story 1 (Maxim): recepten opslaan + bewerken + verwijderen met bevestiging
$activeTab = 'recepten';

require_once __DIR__ . '/db.php';

// helpers.php is optioneel, maar als hij bestaat gebruiken we hem
if (file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}

require_once __DIR__ . '/layouts/header.php';

/* ---------------- Fallback helpers (als helpers.php dit niet heeft) ---------------- */
if (!function_exists('sql_select')) {
    function sql_select(mysqli $mysqli, string $sql, array $params = [], string $types = ''): array
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($params) {
            if ($types === '') $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
if (!function_exists('sql_exec')) {
    function sql_exec(mysqli $mysqli, string $sql, array $params = [], string $types = ''): bool
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) return false;
        if ($params) {
            if ($types === '') $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Zorg dat kolommen bestaan ----------------
   We slaan recepten op in 'foods' zodat het past bij de rest van de app.
   Kolommen die we nodig hebben:
   - is_recipe, ingredients, image_url
   - kcal_per_portion, portion_grams
   - kcal_100g, protein_100g, carbs_100g, fat_100g
*/
function ensure_recipe_columns(mysqli $mysqli): void
{
    // Dit is de FIX: geen \" meer, gewoon normale quotes
    $alters = [
        "ALTER TABLE foods ADD COLUMN is_recipe TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE foods ADD COLUMN ingredients TEXT NULL",
        "ALTER TABLE foods ADD COLUMN image_url TEXT NULL",
        "ALTER TABLE foods ADD COLUMN kcal_per_portion INT NOT NULL DEFAULT 0",
        "ALTER TABLE foods ADD COLUMN portion_grams INT NOT NULL DEFAULT 100",
        "ALTER TABLE foods ADD COLUMN kcal_100g DECIMAL(10,1) NOT NULL DEFAULT 0",
        "ALTER TABLE foods ADD COLUMN protein_100g DECIMAL(10,1) NOT NULL DEFAULT 0",
        "ALTER TABLE foods ADD COLUMN carbs_100g DECIMAL(10,1) NOT NULL DEFAULT 0",
        "ALTER TABLE foods ADD COLUMN fat_100g DECIMAL(10,1) NOT NULL DEFAULT 0",
    ];

    foreach ($alters as $sql) {
        try {
            $mysqli->query($sql);
        } catch (mysqli_sql_exception $e) {
            // 1060 = Duplicate column name (bestaat al, is prima)
            if ((int)$e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
}
ensure_recipe_columns($mysqli);

$successMsg = '';
$errorMsg   = '';

/* ---------------- Acties ---------------- */
$editId   = (int)($_GET['edit'] ?? 0);
$deleteId = (int)($_GET['delete'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim((string)($_POST['title'] ?? ''));
        $ingredients = trim((string)($_POST['ingredients'] ?? ''));
        $image_url   = trim((string)($_POST['image_url'] ?? ''));

        $kcal_portie = (int)($_POST['kcal_portie'] ?? 0);
        $portie_gram = (int)($_POST['portie_gram'] ?? 100);
        if ($portie_gram <= 0) $portie_gram = 100;

        $protein_100g = (float)($_POST['protein_100g'] ?? 0);
        $carbs_100g   = (float)($_POST['carbs_100g'] ?? 0);
        $fat_100g     = (float)($_POST['fat_100g'] ?? 0);

        // kcal per portie is verplicht (US5)
        if ($title === '') {
            $errorMsg = 'Titel is verplicht.';
        } elseif ($ingredients === '') {
            $errorMsg = 'Vul minimaal één ingrediënt in.';
        } elseif ($kcal_portie <= 0) {
            $errorMsg = 'kcal per portie is verplicht (minimaal 1).';
        } else {
            // kcal/100g berekenen uit portie
            $kcal_100g = round(($kcal_portie / $portie_gram) * 100, 1);

            try {
                if ($id > 0) {
                    // UPDATE
                    $sql = "UPDATE foods
                            SET description=?, ingredients=?, image_url=?, kcal_per_portion=?, portion_grams=?, kcal_100g=?, protein_100g=?, carbs_100g=?, fat_100g=?
                            WHERE id=? AND is_recipe=1";
                    sql_exec(
                        $mysqli,
                        $sql,
                        [$title, $ingredients, $image_url, $kcal_portie, $portie_gram, $kcal_100g, $protein_100g, $carbs_100g, $fat_100g, $id],
                        'sssiiddddi'
                    );
                    $successMsg = 'Recept bijgewerkt.';
                    $editId = 0;
                } else {
                    // INSERT (hier zat bij jou een fout: verkeerde aantal placeholders)
                    $sql = "INSERT INTO foods
                            (description, ingredients, image_url, kcal_per_portion, portion_grams, kcal_100g, protein_100g, carbs_100g, fat_100g, is_recipe)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    sql_exec(
                        $mysqli,
                        $sql,
                        [$title, $ingredients, $image_url, $kcal_portie, $portie_gram, $kcal_100g, $protein_100g, $carbs_100g, $fat_100g, 1],
                        'sssiiddddi'
                    );
                    $successMsg = 'Recept opgeslagen.';
                }
            } catch (Throwable $e) {
                $errorMsg = 'Opslaan is niet gelukt. Controleer de database.';
            }
        }
    }

    if ($action === 'delete_confirm') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                sql_exec($mysqli, "DELETE FROM foods WHERE id=? AND is_recipe=1", [$id], 'i');
                $successMsg = 'Recept verwijderd.';
                $deleteId = 0;
            } catch (Throwable $e) {
                $errorMsg = 'Verwijderen is niet gelukt.';
            }
        }
    }
}

/* ---------------- Data ophalen ---------------- */
$editRecipe = null;
if ($editId > 0) {
    $rows = sql_select($mysqli, "SELECT * FROM foods WHERE id=? AND is_recipe=1", [$editId], 'i');
    if ($rows) $editRecipe = $rows[0];
}

$recipes = sql_select($mysqli, "
    SELECT id, description, image_url, ingredients,
           kcal_per_portion, portion_grams, kcal_100g, protein_100g, carbs_100g, fat_100g
    FROM foods
    WHERE is_recipe=1
    ORDER BY id DESC
");

if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trim = '...') {
        $s = (string)$s;
        if (strlen($s) <= $width) return $s;
        return substr($s, 0, $width) . $trim;
    }
}

?>
<main class="container" style="max-width:1120px;margin:0 auto;padding:18px 16px;">
    <h1 style="font-size:32px;line-height:1.2;margin:8px 0 12px 0;font-weight:800;">Recepten</h1>
    <p style="opacity:.85;margin:0 0 18px 0;">Hier kan je je eigen recepten opslaan en aanpassen.</p>

    <?php if ($successMsg): ?>
        <div style="background:#093;color:#d8ffe1;border:1px solid #184b24;border-radius:12px;padding:10px 12px;margin:0 0 12px 0;">
            <?= esc($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div style="background:#551414;color:#ffd6d6;border:1px solid #4a2a2a;border-radius:12px;padding:10px 12px;margin:0 0 12px 0;">
            <?= esc($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($deleteId > 0): ?>
        <section style="border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px 14px;margin:0 0 16px 0;background:rgba(255,255,255,.03);">
            <h2 style="margin:0 0 8px 0;font-size:18px;">Weet je het zeker?</h2>
            <p style="margin:0 0 12px 0;opacity:.9;">Dit recept wordt verwijderd. Dit kan je niet terug draaien.</p>
            <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">
                <input type="hidden" name="action" value="delete_confirm">
                <input type="hidden" name="id" value="<?= (int)$deleteId ?>">
                <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#b91c1c;color:#fff;font-weight:700;cursor:pointer;">
                    Ja verwijderen
                </button>
                <a href="recepten.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#eaeaea;font-weight:700;text-decoration:none;">
                    Nee terug
                </a>
            </form>
        </section>
    <?php endif; ?>

    <section style="border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px 14px;margin:0 0 16px 0;background:rgba(255,255,255,.03);">
        <h2 style="margin:0 0 10px 0;font-size:18px;"><?= $editRecipe ? 'Recept bewerken' : 'Nieuw recept' ?></h2>

        <form method="post" style="display:grid;gap:12px;">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editRecipe['id'] ?? 0) ?>">

            <div>
                <label style="display:block;margin:0 0 6px 0;opacity:.9;">Titel (verplicht)</label>
                <input name="title" value="<?= esc($editRecipe['description'] ?? '') ?>" placeholder="Bijvoorbeeld Pasta pesto"
                       style="width:100%;padding:12px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
            </div>

            <div>
                <label style="display:block;margin:0 0 6px 0;opacity:.9;">Ingrediënten (verplicht)</label>
                <textarea name="ingredients" rows="4" placeholder="Bijvoorbeeld: pasta, pesto, kip, tomaat"
                          style="width:100%;padding:12px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;resize:vertical;"><?= esc($editRecipe['ingredients'] ?? '') ?></textarea>
                <div style="opacity:.75;margin-top:6px;font-size:13px;">Tip: zet ingrediënten met komma's.</div>
            </div>

            <div>
                <label style="display:block;margin:0 0 6px 0;opacity:.9;">Afbeelding URL (mag leeg)</label>
                <input name="image_url" value="<?= esc($editRecipe['image_url'] ?? '') ?>" placeholder="https://..."
                       style="width:100%;padding:12px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
            </div>

            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">kcal per portie (verplicht)</label>
                    <input type="number" step="1" min="1" name="kcal_portie" value="<?= esc($editRecipe['kcal_per_portion'] ?? 0) ?>"
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
                </div>
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">gram per portie</label>
                    <input type="number" step="1" min="1" name="portie_gram" value="<?= esc($editRecipe['portion_grams'] ?? 100) ?>"
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">eiwit / 100g</label>
                    <input type="number" step="0.1" name="protein_100g" value="<?= esc($editRecipe['protein_100g'] ?? 0) ?>"
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
                </div>
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">koolh. / 100g</label>
                    <input type="number" step="0.1" name="carbs_100g" value="<?= esc($editRecipe['carbs_100g'] ?? 0) ?>"
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
                </div>
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">vet / 100g</label>
                    <input type="number" step="0.1" name="fat_100g" value="<?= esc($editRecipe['fat_100g'] ?? 0) ?>"
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#0f0f0f;color:#eaeaea;">
                </div>
                <div>
                    <label style="display:block;margin:0 0 6px 0;opacity:.9;">kcal / 100g (auto)</label>
                    <input type="number" step="0.1" value="<?= esc($editRecipe['kcal_100g'] ?? 0) ?>" readonly
                           style="width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:#111;color:#aaa;">
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:var(--brand);color:var(--ink);font-weight:800;cursor:pointer;">
                    <?= $editRecipe ? 'Opslaan' : 'Toevoegen' ?>
                </button>
                <?php if ($editRecipe): ?>
                    <a href="recepten.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#eaeaea;font-weight:700;text-decoration:none;">
                        Annuleren
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section style="border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px 14px;background:rgba(255,255,255,.03);">
        <h2 style="margin:0 0 10px 0;font-size:18px;">Jouw recepten</h2>

        <?php if (!$recipes): ?>
            <p style="margin:0;opacity:.85;">Nog geen recepten opgeslagen.</p>
        <?php else: ?>
            <div style="display:grid;gap:10px;">
                <?php foreach ($recipes as $r): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;background:rgba(0,0,0,.25);">
                        <div style="width:56px;height:56px;border-radius:12px;overflow:hidden;background:#111;border:1px solid rgba(255,255,255,.08);flex:0 0 auto;">
                            <?php if (!empty($r['image_url'])): ?>
                                <img src="<?= esc($r['image_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;opacity:.6;font-weight:800;">IMG</div>
                            <?php endif; ?>
                        </div>

                        <div style="flex:1 1 auto;">
                            <div style="font-weight:900;font-size:16px;"><?= esc($r['description']) ?></div>

                            <div style="opacity:.85;margin-top:4px;font-size:13px;">
                                <?= esc(mb_strimwidth(str_replace(["\r","\n"], ' ', (string)$r['ingredients']), 0, 140, '...')) ?>
                            </div>

                            <div style="opacity:.75;margin-top:6px;font-size:13px;">
                                <?= (int)round((float)$r['kcal_100g']) ?> kcal / 100g • <?= (int)$r['kcal_per_portion'] ?> kcal / portie
                            </div>
                        </div>

                        <div style="display:flex;flex-direction:column;gap:8px;flex:0 0 auto;">
                            <a href="recepten.php?edit=<?= (int)$r['id'] ?>" style="padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);text-decoration:none;color:#eaeaea;font-weight:700;text-align:center;">
                                Bewerken
                            </a>
                            <a href="recepten.php?delete=<?= (int)$r['id'] ?>" style="padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);text-decoration:none;color:#fff;background:#7f1d1d;font-weight:800;text-align:center;">
                                Verwijderen
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>
<?php require_once __DIR__ . '/layouts/footer.php'; ?>
