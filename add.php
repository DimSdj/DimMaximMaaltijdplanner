<?php
// add.php — Voeg een maaltijd toe en geef ALTIJD nette JSON terug.

declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

// Geen notices naar output; fouten geven we als JSON terug
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Laad DB ($mysqli)
require_once __DIR__ . '/db.php';

// ---------- helpers ----------
function j_ok(array $data = []): never
{
    $noise = trim(ob_get_clean() ?? '');
    if ($noise !== '') {
        echo json_encode(['ok' => false, 'error' => 'Server output vóór JSON', 'debug' => $noise], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}
function j_err(string $msg, array $extra = []): never
{
    $noise = trim(ob_get_clean() ?? '');
    $out = ['ok' => false, 'error' => $msg] + ($extra ?: []);
    if ($noise !== '')
        $out['debug'] = $noise;
    http_response_code(200); // frontend verwacht JSON (geen HTML error page)
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
function s(mixed $v): string
{
    return trim((string) $v);
}
function n(mixed $v, ?float $def = null): ?float
{
    if ($v === null || $v === '')
        return $def;
    if (is_string($v))
        $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float) $v : $def;
}

// ---------- main ----------
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
        j_err('Alleen POST toegestaan.');
    if (!isset($mysqli) || !($mysqli instanceof mysqli))
        j_err('Geen DB-connectie ($mysqli) beschikbaar. Check db.php.');

    // Input
    $food_id = isset($_POST['food_id']) ? (int) $_POST['food_id'] : null;

    $date = s($_POST['date'] ?? date('Y-m-d'));
    $slot = s($_POST['slot'] ?? 'Snack');
    $grams = n($_POST['grams'] ?? 100, 100);

    $name = s($_POST['name'] ?? '');
    $brand = s($_POST['brand'] ?? '');
    $code = s($_POST['barcode'] ?? ($_POST['code'] ?? ''));
    $image = s($_POST['image'] ?? '');

    // === per 100g (steun OFF-varianten + kJ → kcal) ===
    $kcal_100g = n($_POST['kcal_100g'] ?? $_POST['kcal'] ?? $_POST['energy-kcal_100g'] ?? null, null);
    if ($kcal_100g === null) {
        $kj = n($_POST['energy-kj_100g'] ?? null, null);
        if ($kj !== null)
            $kcal_100g = $kj / 4.184;
    }
    $prot_100g = n($_POST['protein_100g'] ?? $_POST['proteins_100g'] ?? $_POST['protein'] ?? $_POST['prot'] ?? null, null);
    $carb_100g = n($_POST['carbs_100g'] ?? $_POST['carbohydrates_100g'] ?? $_POST['carb'] ?? $_POST['carbohydrate'] ?? null, null);
    $fat_100g = n($_POST['fat_100g'] ?? $_POST['fat'] ?? null, null);

    // Validatie
    if ($grams === null || $grams <= 0)
        j_err('Ongeldige hoeveelheid (grams).');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        j_err('Ongeldige datum (YYYY-MM-DD vereist).');
    if ($slot === '')
        $slot = 'Snack';

    // ---------- ROUTE 1 — bestaand product via food_id ----------
    if ($food_id) {
        $stmt = $mysqli->prepare("SELECT kcal_100g, protein_100g, carbs_100g, fat_100g FROM foods WHERE id = ?");
        $stmt->bind_param('i', $food_id);
        $stmt->execute();
        $food = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$food)
            j_err('Food niet gevonden voor opgegeven food_id.');

        // Vul foods aan als caller per-100g waarden meestuurt
        if ($kcal_100g !== null || $prot_100g !== null || $carb_100g !== null || $fat_100g !== null) {
            $stmt = $mysqli->prepare("
                UPDATE foods
                SET kcal_100g    = COALESCE(?, kcal_100g),
                    protein_100g = COALESCE(?, protein_100g),
                    carbs_100g   = COALESCE(?, carbs_100g),
                    fat_100g     = COALESCE(?, fat_100g)
                WHERE id = ?
            ");
            $stmt->bind_param('ddddi', $kcal_100g, $prot_100g, $carb_100g, $fat_100g, $food_id);
            $stmt->execute();
            $stmt->close();

            // opnieuw ophalen na update
            $stmt = $mysqli->prepare("SELECT kcal_100g, protein_100g, carbs_100g, fat_100g FROM foods WHERE id = ?");
            $stmt->bind_param('i', $food_id);
            $stmt->execute();
            $food = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // Fallback: als DB leeg is, gebruik POST-waarden; anders 0
        $k100 = n($food['kcal_100g'], $kcal_100g ?? null) ?? 0.0;
        $p100 = n($food['protein_100g'], $prot_100g ?? null) ?? 0.0;
        $c100 = n($food['carbs_100g'], $carb_100g ?? null) ?? 0.0;
        $f100 = n($food['fat_100g'], $fat_100g ?? null) ?? 0.0;

        $factor = $grams / 100.0;
        $kcal = $k100 * $factor;
        $p = $p100 * $factor;
        $c = $c100 * $factor;
        $f = $f100 * $factor;

        $stmt = $mysqli->prepare("
            INSERT INTO meals (date, slot, food_id, grams, kcal, protein, carb, fat)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssiddddd', $date, $slot, $food_id, $grams, $kcal, $p, $c, $f);
        $stmt->execute();
        $mealId = $stmt->insert_id;
        $stmt->close();

        j_ok(['meal_id' => $mealId]);
    }

    // ---------- ROUTE 2 — nieuw/OF product ----------
    if ($name === '' && $code === '')
        j_err('Ontbrekende productnaam of code (barcode).');

    $mysqli->begin_transaction();
    try {
        // Upsert op code indien aanwezig
        if ($code !== '') {
            $stmt = $mysqli->prepare("SELECT id FROM foods WHERE code = ?");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $foodId = (int) $row['id'];
                $stmt = $mysqli->prepare("
                    UPDATE foods
                    SET description   = COALESCE(NULLIF(?,''), description),
                        brand         = COALESCE(NULLIF(?,''), brand),
                        image_url     = COALESCE(NULLIF(?,''), image_url),
                        kcal_100g     = COALESCE(?, kcal_100g),
                        protein_100g  = COALESCE(?, protein_100g),
                        carbs_100g    = COALESCE(?, carbs_100g),
                        fat_100g      = COALESCE(?, fat_100g)
                    WHERE id = ?
                ");
                $stmt->bind_param('sssddddi', $name, $brand, $image, $kcal_100g, $prot_100g, $carb_100g, $fat_100g, $foodId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO foods (code, description, brand, image_url, kcal_100g, protein_100g, carbs_100g, fat_100g)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('ssssdddd', $code, $name, $brand, $image, $kcal_100g, $prot_100g, $carb_100g, $fat_100g);
                $stmt->execute();
                $foodId = $stmt->insert_id;
                $stmt->close();
            }
        } else {
            // Geen code → op naam invoegen
            $stmt = $mysqli->prepare("
                INSERT INTO foods (description, brand, image_url, kcal_100g, protein_100g, carbs_100g, fat_100g)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssdddd', $name, $brand, $image, $kcal_100g, $prot_100g, $carb_100g, $fat_100g);
            $stmt->execute();
            $foodId = $stmt->insert_id;
            $stmt->close();
        }

        // Per-100g waarden opnieuw uit DB halen
        $stmt = $mysqli->prepare("SELECT kcal_100g, protein_100g, carbs_100g, fat_100g FROM foods WHERE id=?");
        if (!$stmt)
            j_err('DB-fout (prep fetch food after upsert): ' . $mysqli->error);
        $stmt->bind_param('i', $foodId);
        $stmt->execute();
        $food = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Fallback: gebruik POST-waarden als DB nog leeg is
        $k100 = n($food['kcal_100g'], $kcal_100g ?? null) ?? 0.0;
        $p100 = n($food['protein_100g'], $prot_100g ?? null) ?? 0.0;
        $c100 = n($food['carbs_100g'], $carb_100g ?? null) ?? 0.0;
        $f100 = n($food['fat_100g'], $fat_100g ?? null) ?? 0.0;

        // Portie berekenen
        $factor = $grams / 100.0;
        $kcal = $k100 * $factor;
        $p = $p100 * $factor;
        $c = $c100 * $factor;
        $f = $f100 * $factor;

        // Meal vastleggen
        $stmt = $mysqli->prepare("
            INSERT INTO meals (date, slot, food_id, grams, kcal, protein, carb, fat)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssiddddd', $date, $slot, $foodId, $grams, $kcal, $p, $c, $f);
        $stmt->execute();
        $mealId = $stmt->insert_id;
        $stmt->close();

        $mysqli->commit();
        j_ok(['meal_id' => $mealId]);

    } catch (Throwable $tx) {
        $mysqli->rollback();
        throw $tx;
    }

} catch (Throwable $e) {
    j_err('Serverfout: ' . $e->getMessage());
}
