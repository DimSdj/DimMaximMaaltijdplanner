<?php
$activeTab = 'profiel';
function off_fetch_by_barcode(string $barcode): ?array
{
    $url = "https://world.openfoodfacts.org/api/v0/product/{$barcode}.json";
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false)
        return null;

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['status'] ?? 0) !== 1)
        return null;

    $p = $data['product'] ?? [];
    $nut = $p['nutriments'] ?? [];

    return [
        'external_id' => $barcode,
        'description' => $p['product_name'] ?? 'Onbekend',
        'brand' => $p['brands'] ?? null,
        'image_url' => $p['image_url'] ?? ($p['image_front_url'] ?? null),
        'kcal' => $nut['energy-kcal_100g'] ?? null,
        'protein' => $nut['proteins_100g'] ?? null,
        'fat' => $nut['fat_100g'] ?? null,
        'carb' => $nut['carbohydrates_100g'] ?? null,
    ];
}
