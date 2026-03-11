<?php
// off_search.php — Open Food Facts zoekproxy (ultra-compat kcal output)
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(50, max(5, (int) ($_GET['page_size'] ?? 20)));
$lang = $_GET['lang'] ?? 'nl';

if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing query parameter q']);
    exit;
}

/* ---------------- LOCAL_FOODS ----------------
   Zoek ook in eigen foods tabel, niet alleen recepten.
   We zoeken op description + brand.
------------------------------------------------ */
$localResults = [];
try {
    require __DIR__ . '/db.php';

    $like = '%' . $q . '%';
    $stmt = $mysqli->prepare("
        SELECT
            id,
            description,
            brand,
            image_url,
            kcal_100g,
            protein_100g,
            carbs_100g,
            fat_100g,
            portion_grams,
            is_recipe
        FROM foods
        WHERE description LIKE ?
           OR brand LIKE ?
        ORDER BY
            CASE
                WHEN description LIKE ? THEN 0
                WHEN brand LIKE ? THEN 1
                ELSE 2
            END,
            id DESC
        LIMIT 20
    ");

    if ($stmt) {
        $stmt->bind_param('ssss', $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($rows as $r) {
            $pg = (int) ($r['portion_grams'] ?? 100);
            if ($pg <= 0)
                $pg = 100;

            $isRecipe = (int) ($r['is_recipe'] ?? 0);

            $localResults[] = [
                'food_id' => (int) $r['id'],
                'name' => $r['description'] ?? '',
                'brand' => $r['brand'] ?? '',
                'image' => $r['image_url'] ?? '',
                'serving' => $isRecipe ? ('1 portie (' . $pg . ' g)') : '100 gram / ml',
                'is_recipe' => $isRecipe,
                'portion_grams' => $pg,

                // direct bruikbare velden voor de front-end
                'kcal_100g' => (float) ($r['kcal_100g'] ?? 0),
                'protein_100g' => (float) ($r['protein_100g'] ?? 0),
                'carbs_100g' => (float) ($r['carbs_100g'] ?? 0),
                'fat_100g' => (float) ($r['fat_100g'] ?? 0),

                // compat met OFF-structuur
                'nutriments' => [
                    'energy-kcal_100g' => (float) ($r['kcal_100g'] ?? 0),
                    'proteins_100g' => (float) ($r['protein_100g'] ?? 0),
                    'carbohydrates_100g' => (float) ($r['carbs_100g'] ?? 0),
                    'fat_100g' => (float) ($r['fat_100g'] ?? 0),
                ],
            ];
        }
    }
} catch (Throwable $e) {
    $localResults = [];
}
/* -------------- /LOCAL_FOODS -------------- */



$fields = implode(',', [
    'code',
    'product_name',
    'brands',
    'image_front_small_url',
    'image_url',
    'serving_size',
    'quantity',
    'nutriscore_grade',
    'nutrition_grade_fr',
    'categories',
    'nutrition_data_per',
    'nutriments',
]);

$query = http_build_query([
    'search_terms' => $q,
    'page' => $page,
    'page_size' => $pageSize,
    'search_simple' => 1,
    'json' => 1,
    'fields' => $fields,
    'lc' => $lang,
]);

$offUrl = "https://world.openfoodfacts.org/cgi/search.pl?$query";

$opts = [
    'http' => [
        'method' => 'GET',
        'timeout' => 6,
        'header' => "User-Agent: dim-eetplanner/1.0\r\n"
    ]
];
$ctx = stream_context_create($opts);
$raw = @file_get_contents($offUrl, false, $ctx);

if ($raw === false) {
    // Als OFF faalt, geef in ieder geval lokale resultaten terug
    if (!empty($localResults)) {
        echo json_encode([
            'ok' => true,
            'query' => $q,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => count($localResults),
            'results' => $localResults,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => false,
        'error' => 'OFF request failed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
$products = $data['products'] ?? [];
$count = $data['count'] ?? 0;

$gf = function (array $a, string $k) {
    if (!isset($a[$k]) || $a[$k] === '' || $a[$k] === null)
        return null;
    return is_numeric($a[$k]) ? (float) $a[$k] : null;
};

$calc_kcal_100g = function (array $n) use ($gf) {
    // 1) direct kcal
    $ekcal = $gf($n, 'energy-kcal_100g');
    if ($ekcal !== null)
        return $ekcal;

    // 2) energy_100g + unit
    $energy = $gf($n, 'energy_100g');
    $unit = $n['energy_unit'] ?? ($n['energy_100g_unit'] ?? null);
    $unit = $unit ? strtolower($unit) : null;
    if ($energy !== null && $unit === 'kcal')
        return $energy;

    // 3) kJ -> kcal
    $ekj = $gf($n, 'energy-kj_100g');
    if ($ekj !== null)
        return round($ekj / 4.184, 1);

    // 4) energy_100g zonder unit => aannemen kJ (meest voorkomend)
    if ($energy !== null && (!$unit || $unit === 'kj'))
        return round($energy / 4.184, 1);

    // 5) *_value varianten
    $ekcalv = $gf($n, 'energy-kcal_value');
    if ($ekcalv !== null)
        return $ekcalv;
    $ekjv = $gf($n, 'energy-kj_value');
    if ($ekjv !== null)
        return round($ekjv / 4.184, 1);

    return null;
};

$out = [];
foreach ($products as $p) {
    $n = $p['nutriments'] ?? [];

    $kcal100 = $calc_kcal_100g($n);
    $kj100 = $gf($n, 'energy-kj_100g');

    // macro’s
    $prot100 = $gf($n, 'proteins_100g');
    $carb100 = $gf($n, 'carbohydrates_100g');
    $sug100 = $gf($n, 'sugars_100g');
    $fat100 = $gf($n, 'fat_100g');
    $sat100 = $gf($n, 'saturated-fat_100g');
    $fib100 = $gf($n, 'fiber_100g');
    $salt100 = $gf($n, 'salt_100g');
    $sod100 = $gf($n, 'sodium_100g');

    // super-compat alias-velden zodat elke front-end het pakt
    $aliases = [
        'kcal_100g' => $kcal100,
        'energy_kcal_100g' => $kcal100,
        'energy-kcal_100g' => $kcal100,
        'calories_100g' => $kcal100,
        'calories' => $kcal100,
        'kcal' => $kcal100,
        'energy_per_100g_kcal' => $kcal100,
        // kJ alias
        'kj_100g' => $kj100,
        'energy_kj_100g' => $kj100,
        'energy-kj_100g' => $kj100,
    ];

    $item = [
        'code' => $p['code'] ?? null,
        'name' => $p['product_name'] ?? null,
        'brand' => $p['brands'] ?? null,
        'image' => $p['image_front_small_url'] ?? ($p['image_url'] ?? null),
        'serving' => $p['serving_size'] ?? null,
        'quantity' => $p['quantity'] ?? null,
        'nutriscore' => $p['nutriscore_grade'] ?? ($p['nutrition_grade_fr'] ?? null),
        'categories' => $p['categories'] ?? null,
        'nutrition_data_per' => $p['nutrition_data_per'] ?? '100g',

        // canonieke velden
        'kcal_100g' => $kcal100,
        'kj_100g' => $kj100,
        'protein_100g' => $prot100,
        'carbs_100g' => $carb100,
        'sugars_100g' => $sug100,
        'fat_100g' => $fat100,
        'satfat_100g' => $sat100,
        'fiber_100g' => $fib100,
        'salt_100g' => $salt100,
        'sodium_100g' => $sod100,

        // display helper (optioneel voor UI)
        'kcal_display' => $kcal100 !== null ? (round($kcal100, 1) . ' kcal') : 'onbekend',
    ] + $aliases;

    $out[] = $item;
}


// Voeg lokale recepten vooraan toe
if (!empty($localResults) && isset($out) && is_array($out)) {
    $out = array_merge($localResults, $out);
    $count = count($out);
}

echo json_encode([
    'ok' => true,
    'query' => $q,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $count,
    'results' => $out,
], JSON_UNESCAPED_UNICODE);
