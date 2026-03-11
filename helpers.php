<?php
// helpers.php
function sql_select($mysqli, $sql, $params = [], $types = '')
{
    $stmt = $mysqli->prepare($sql);
    if ($params)
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function sql_exec($mysqli, $sql, $params = [], $types = '')
{
    $stmt = $mysqli->prepare($sql);
    if ($params)
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    $ok = $stmt->execute();
    $insert_id = $stmt->insert_id;
    $stmt->close();
    return [$ok, $insert_id];
}

// Haal macro's (kcal, p, f, c) per meal op basis van per_100g en grams/quantity
function meal_macros($mysqli, array $meal): array
{
    // 1) Als de pagina al _calc velden meegeeft, gebruik die (snel + consistent)
    if (array_key_exists('kcal_calc', $meal) || array_key_exists('protein_calc', $meal) || array_key_exists('fat_calc', $meal) || array_key_exists('carb_calc', $meal)) {
        return [
            'kcal' => floatval($meal['kcal_calc'] ?? 0),
            'protein' => floatval($meal['protein_calc'] ?? 0),
            'fat' => floatval($meal['fat_calc'] ?? 0),
            'carb' => floatval($meal['carb_calc'] ?? 0),
        ];
    }

    // 2) Als de maaltijd zelf macro's heeft, gebruik die
    if (array_key_exists('kcal', $meal) || array_key_exists('protein', $meal) || array_key_exists('fat', $meal) || array_key_exists('carb', $meal)) {
        $k = $meal['kcal'] ?? null;
        $p = $meal['protein'] ?? null;
        $f = $meal['fat'] ?? null;
        $c = $meal['carb'] ?? null;

        if ($k !== null || $p !== null || $f !== null || $c !== null) {
            return [
                'kcal' => floatval($k ?? 0),
                'protein' => floatval($p ?? 0),
                'fat' => floatval($f ?? 0),
                'carb' => floatval($c ?? 0),
            ];
        }
    }

    // 3) Fallback: reken uit op basis van foods.*_100g en grams
    if (!empty($meal['food_id'])) {
        $factor = 0.0;
        if (array_key_exists('grams', $meal) && $meal['grams'] !== null) {
            $factor = max(0, floatval($meal['grams'])) / 100.0;
        }
        if ($factor > 0) {
            $food = sql_select($mysqli, "SELECT kcal_100g, protein_100g, carbs_100g, fat_100g FROM foods WHERE id=?", [$meal['food_id']], 'i');
            if ($food) {
                $f0 = $food[0];
                return [
                    'kcal' => floatval($f0['kcal_100g'] ?? 0) * $factor,
                    'protein' => floatval($f0['protein_100g'] ?? 0) * $factor,
                    'fat' => floatval($f0['fat_100g'] ?? 0) * $factor,
                    'carb' => floatval($f0['carbs_100g'] ?? 0) * $factor,
                ];
            }
        }
    }

    // 4) Laatste fallback: nutrient-tabellen (als die bestaan)
    if (empty($meal['food_id']))
        return ['kcal' => 0, 'protein' => 0, 'fat' => 0, 'carb' => 0];

    $factor = 1.0;
    if (!is_null($meal['grams']))
        $factor = max(0, floatval($meal['grams'])) / 100.0;
    else
        $factor = max(1, intval($meal['quantity'] ?? 1));

    try {
        $sql = "SELECT n.tag, fn.amount
              FROM food_nutrients fn
              JOIN nutrients n ON n.id = fn.nutrient_id
              WHERE fn.food_id = ? AND fn.basis = 'per_100g'";
        $rows = sql_select($mysqli, $sql, [$meal['food_id']], 'i');

        $map = ['ENERC_KCAL' => 0, 'PROCNT' => 0, 'FAT' => 0, 'CHOCDF' => 0];
        foreach ($rows as $r) {
            $map[$r['tag']] = floatval($r['amount']);
        }
        return [
            'kcal' => $map['ENERC_KCAL'] * $factor,
            'protein' => $map['PROCNT'] * $factor,
            'fat' => $map['FAT'] * $factor,
            'carb' => $map['CHOCDF'] * $factor,
        ];
    } catch (Throwable $e) {
        return ['kcal' => 0, 'protein' => 0, 'fat' => 0, 'carb' => 0];
    }
}
