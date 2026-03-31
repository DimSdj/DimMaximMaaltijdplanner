<?php
$activeTab = 'tags';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('sql_execute')) {
    function sql_execute(mysqli $mysqli, string $sql, array $params = []): bool
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return false;
        }

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
        if (!$stmt) {
            return [];
        }

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

if (!function_exists('tag_label')) {
    function tag_label(string $tag): string
    {
        $map = [
            'eiwitrijk' => 'Eiwitrijk',
            'duurzaam' => 'Duurzaam',
            'bulk' => 'Bulk',
        ];

        return $map[$tag] ?? ucfirst($tag);
    }
}

if (!function_exists('bootstrap_profile_and_goals')) {
    function bootstrap_profile_and_goals(mysqli $mysqli): void
    {
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

        sql_execute($mysqli, "
            CREATE TABLE IF NOT EXISTS user_profile (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                age SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                height_cm DECIMAL(5,1) NOT NULL DEFAULT 0,
                weight_kg DECIMAL(5,1) NOT NULL DEFAULT 0,
                sex VARCHAR(10) NOT NULL DEFAULT 'male',
                activity_level VARCHAR(20) NOT NULL DEFAULT 'moderate',
                goal VARCHAR(20) NOT NULL DEFAULT 'maintain',
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        sql_execute($mysqli, "
            CREATE TABLE IF NOT EXISTS tag_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                active_tag VARCHAR(50) NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $goalExists = sql_select($mysqli, "SELECT 1 FROM goals WHERE id = 1 LIMIT 1");
        if (!$goalExists) {
            sql_execute($mysqli, "
                INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
                VALUES (1, 1877, 150.0, 250.0, 70.0, NOW())
            ");
        }

        $profileExists = sql_select($mysqli, "SELECT 1 FROM user_profile WHERE id = 1 LIMIT 1");
        if (!$profileExists) {
            sql_execute($mysqli, "
                INSERT INTO user_profile (id, age, height_cm, weight_kg, sex, activity_level, goal, updated_at)
                VALUES (1, 0, 0, 0, 'male', 'moderate', 'maintain', NOW())
            ");
        }

        $tagExists = sql_select($mysqli, "SELECT 1 FROM tag_settings WHERE id = 1 LIMIT 1");
        if (!$tagExists) {
            sql_execute($mysqli, "
                INSERT INTO tag_settings (id, active_tag, updated_at)
                VALUES (1, '', NOW())
            ");
        }
    }
}

if (!function_exists('get_profile_settings')) {
    function get_profile_settings(mysqli $mysqli): array
    {
        $rows = sql_select($mysqli, "
            SELECT
                p.age,
                p.weight_kg,
                p.height_cm,
                p.sex,
                p.activity_level,
                p.goal,
                t.active_tag
            FROM user_profile p
            LEFT JOIN tag_settings t ON t.id = p.id
            WHERE p.id = 1
            LIMIT 1
        ");

        $row = $rows[0] ?? [];

        $age = isset($row['age']) ? (int) $row['age'] : 0;
        $weight = isset($row['weight_kg']) ? (float) $row['weight_kg'] : 0;
        $height = isset($row['height_cm']) ? (float) $row['height_cm'] : 0;

        return [
            'age' => $age > 0 ? $age : null,
            'weight_kg' => $weight > 0 ? $weight : null,
            'height_cm' => $height > 0 ? $height : null,
            'sex' => (string) ($row['sex'] ?? 'male'),
            'activity_level' => (string) ($row['activity_level'] ?? 'moderate'),
            'goal' => (string) ($row['goal'] ?? 'maintain'),
            'active_tag' => (string) ($row['active_tag'] ?? ''),
        ];
    }
}

if (!function_exists('get_goals')) {
    function get_goals(mysqli $mysqli): array
    {
        $rows = sql_select($mysqli, "
            SELECT kcal, protein, carb, fat
            FROM goals
            WHERE id = 1
            LIMIT 1
        ");

        $row = $rows[0] ?? [];

        return [
            'kcal' => (int) ($row['kcal'] ?? 1877),
            'protein' => (float) ($row['protein'] ?? 150),
            'carb' => (float) ($row['carb'] ?? 250),
            'fat' => (float) ($row['fat'] ?? 70),
        ];
    }
}

if (!function_exists('save_goals')) {
    function save_goals(mysqli $mysqli, int $kcal, float $protein, float $carb, float $fat): bool
    {
        return sql_execute($mysqli, "
            INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
            VALUES (1, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                kcal = VALUES(kcal),
                protein = VALUES(protein),
                carb = VALUES(carb),
                fat = VALUES(fat),
                updated_at = VALUES(updated_at)
        ", [$kcal, $protein, $carb, $fat]);
    }
}

if (!function_exists('save_active_tag')) {
    function save_active_tag(mysqli $mysqli, string $activeTag): bool
    {
        return sql_execute($mysqli, "
            INSERT INTO tag_settings (id, active_tag, updated_at)
            VALUES (1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                active_tag = VALUES(active_tag),
                updated_at = VALUES(updated_at)
        ", [$activeTag]);
    }
}

if (!function_exists('calculate_tag_goals')) {
    function calculate_tag_goals(string $tag, array $profile): array
    {
        $weight = isset($profile['weight_kg']) && $profile['weight_kg'] !== null ? (float) $profile['weight_kg'] : null;
        $height = isset($profile['height_cm']) && $profile['height_cm'] !== null ? (float) $profile['height_cm'] : null;
        $age = isset($profile['age']) && $profile['age'] !== null ? (int) $profile['age'] : null;
        $sex = (string) ($profile['sex'] ?? 'male');
        $activity = (string) ($profile['activity_level'] ?? 'moderate');

        if (!$weight || !$height || !$age) {
            return [
                'ok' => false,
                'error' => 'Vul eerst je leeftijd, gewicht en lengte in bij Profiel.'
            ];
        }

        if ($sex === 'female') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        } elseif ($sex === 'other') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age);
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        }

        $activityFactors = [
            'sedentary'   => 1.2,
            'light'       => 1.375,
            'moderate'    => 1.55,
            'active'      => 1.725,
            'very_active' => 1.9,
        ];

        $activityFactor = $activityFactors[$activity] ?? 1.55;
        $maintenance = (int) round($bmr * $activityFactor);

        switch ($tag) {
            case 'eiwitrijk':
                $kcal = $maintenance;
                $protein = round($weight * 2.0, 1);
                $fat = round($weight * 0.8, 1);
                $carb = round(max(0, ($kcal - ($protein * 4) - ($fat * 9)) / 4), 1);
                break;

            case 'duurzaam':
                $kcal = max(1400, $maintenance - 300);
                $protein = round($weight * 1.8, 1);
                $fat = round($weight * 0.9, 1);
                $carb = round(max(0, ($kcal - ($protein * 4) - ($fat * 9)) / 4), 1);
                break;

            case 'bulk':
                $kcal = max(3500, $maintenance + 800);
                $protein = round(max($weight * 2.2, 180), 1);
                $fat = round(max($weight * 1.25, 100), 1);
                $carb = round(max(0, ($kcal - ($protein * 4) - ($fat * 9)) / 4), 1);
                break;

            default:
                return [
                    'ok' => false,
                    'error' => 'Onbekende tag.'
                ];
        }

        return [
            'ok' => true,
            'kcal' => (int) $kcal,
            'protein' => (float) $protein,
            'carb' => (float) $carb,
            'fat' => (float) $fat,
        ];
    }
}

bootstrap_profile_and_goals($mysqli);

$profile = get_profile_settings($mysqli);
$goals = get_goals($mysqli);
$successMsg = null;
$errorMsg = null;
$previewTag = (string) ($profile['active_tag'] ?? '');
$previewGoals = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'apply_tag') {
        $tag = (string) ($_POST['tag'] ?? '');
        $calc = calculate_tag_goals($tag, $profile);

        if (!($calc['ok'] ?? false)) {
            $errorMsg = (string) ($calc['error'] ?? 'De tag kon niet worden toegepast.');
        } else {
            $okGoals = save_goals(
                $mysqli,
                (int) $calc['kcal'],
                (float) $calc['protein'],
                (float) $calc['carb'],
                (float) $calc['fat']
            );

            $okTag = save_active_tag($mysqli, $tag);

            if ($okGoals && $okTag) {
                $profile = get_profile_settings($mysqli);
                $goals = get_goals($mysqli);
                $previewTag = $tag;
                $previewGoals = $calc;
                $successMsg = tag_label($tag) . ' is toegepast op je doelen.';
            } else {
                $errorMsg = 'Opslaan mislukt.';
            }
        }
    }

    if ($action === 'clear_tag') {
        $okTag = save_active_tag($mysqli, '');

        if ($okTag) {
            $profile = get_profile_settings($mysqli);
            $previewTag = '';
            $previewGoals = null;
            $successMsg = 'De actieve tag is uitgezet. Je handmatige doelen blijven gewoon staan.';
        } else {
            $errorMsg = 'De tag kon niet worden uitgezet.';
        }
    }
}

if ($previewGoals === null && $previewTag !== '') {
    $calc = calculate_tag_goals($previewTag, $profile);
    if ($calc['ok'] ?? false) {
        $previewGoals = $calc;
    }
}

$tagCards = [
    'eiwitrijk' => [
        'title' => 'Eiwitrijk',
        'text' => 'Voor deze tag zet de app je eiwitten op 2 gram per kilo lichaamsgewicht. Daarna worden vetten en koolhydraten hier netjes omheen verdeeld.',
        'accent' => '#22c55e',
    ],
    'duurzaam' => [
        'title' => 'Duurzaam',
        'text' => 'Deze tag verlaagt je calorieën iets voor rustig afvallen, terwijl eiwitten hoger blijven om je spieren beter te ondersteunen.',
        'accent' => '#38bdf8',
    ],
    'bulk' => [
        'title' => 'Bulk',
        'text' => 'Deze tag zet je calorieën hoger voor spieropbouw. Je krijgt ook meer koolhydraten en genoeg vetten voor een stevige bulk.',
        'accent' => '#f59e0b',
    ],
];

require __DIR__ . '/layouts/header.php';
?>

<style>
    @media (max-width: 980px) {
        #tags-layout {
            grid-template-columns: 1fr !important;
        }

        #tag-cards {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<main class="container" style="max-width:1120px;margin:0 auto;padding:18px 16px;">
    <h1 style="font-size:32px;line-height:1.2;margin:8px 0 12px 0;font-weight:800;">Tags</h1>
    <p style="opacity:.85;margin:0 0 18px 0;">Klik op een tag om automatisch doelen in te vullen op basis van je profiel.</p>

    <?php if ($successMsg): ?>
        <div style="background:#093;color:#d8ffe1;border:1px solid #1f7a3a;border-radius:12px;padding:10px 12px;margin:0 0 12px 0;">
            <?= esc($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div style="background:#551414;color:#ffd6d6;border:1px solid #7a2a2a;border-radius:12px;padding:10px 12px;margin:0 0 12px 0;">
            <?= esc($errorMsg) ?>
        </div>
    <?php endif; ?>

    <section id="tags-layout" style="display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start;">
        <div id="tag-cards" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;">
            <?php foreach ($tagCards as $slug => $card): ?>
                <?php $isActive = (($profile['active_tag'] ?? '') === $slug); ?>
                <article
                    style="background:#101010;border:1px solid <?= $isActive ? esc($card['accent']) : '#2b2b2b' ?>;border-radius:18px;padding:16px;display:grid;gap:14px;box-shadow:<?= $isActive ? '0 0 0 1px ' . esc($card['accent']) . '33' : 'none' ?>;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                        <div>
                            <div
                                style="width:44px;height:44px;border-radius:12px;background:<?= esc($card['accent']) ?>1f;border:1px solid <?= esc($card['accent']) ?>55;display:flex;align-items:center;justify-content:center;font-weight:800;color:<?= esc($card['accent']) ?>;margin-bottom:10px;">
                                <?= strtoupper(substr($card['title'], 0, 1)) ?>
                            </div>
                            <h2 style="margin:0 0 6px 0;font-size:20px;">
                                <?= esc($card['title']) ?>
                            </h2>
                            <?php if ($isActive): ?>
                                <span
                                    style="display:inline-flex;padding:4px 8px;border-radius:999px;background:<?= esc($card['accent']) ?>22;color:<?= esc($card['accent']) ?>;font-size:12px;font-weight:700;">actief</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p style="margin:0;line-height:1.55;opacity:.82;">
                        <?= esc($card['text']) ?>
                    </p>

                    <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="apply_tag">
                        <input type="hidden" name="tag" value="<?= esc($slug) ?>">
                        <button type="submit"
                            style="width:100%;background:<?= esc($card['accent']) ?>;color:#0b0b0b;font-weight:800;border:0;border-radius:12px;padding:12px 14px;cursor:pointer;">
                            Gebruik <?= esc($card['title']) ?>
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>

        <aside style="background:#101010;border:1px solid #2b2b2b;border-radius:18px;padding:16px;display:grid;gap:14px;">
            <div>
                <h2 style="margin:0 0 6px 0;font-size:20px;">Jouw berekening</h2>
                <p style="margin:0;opacity:.75;">De app gebruikt je leeftijd, gewicht en lengte uit je profiel. Daarna kan je in Profiel de cijfers nog zelf aanpassen.</p>
            </div>

            <div style="border:1px solid #2d2d2d;background:#0f0f0f;border-radius:14px;padding:12px;display:grid;gap:6px;">
                <div><strong>Leeftijd:</strong> <?= $profile['age'] !== null ? esc($profile['age']) : 'nog leeg' ?></div>
                <div>
                    <strong>Gewicht:</strong>
                    <?= $profile['weight_kg'] !== null ? esc($profile['weight_kg']) . ' kg' : 'nog leeg' ?>
                </div>
                <div>
                    <strong>Lengte:</strong>
                    <?= $profile['height_cm'] !== null ? esc($profile['height_cm']) . ' cm' : 'nog leeg' ?>
                </div>
            </div>

            <?php if ($previewGoals): ?>
                <div style="border:1px solid #2d2d2d;background:#0f0f0f;border-radius:14px;padding:12px;display:grid;gap:7px;">
                    <div style="opacity:.72;font-size:13px;">Actieve tag</div>
                    <div style="font-size:22px;font-weight:800;"><?= esc(tag_label($previewTag)) ?></div>
                    <div><strong><?= (int) $previewGoals['kcal'] ?></strong> kcal</div>
                    <div><strong><?= number_format((float) $previewGoals['protein'], 1) ?></strong> g eiwit</div>
                    <div><strong><?= number_format((float) $previewGoals['carb'], 1) ?></strong> g koolhydraten</div>
                    <div><strong><?= number_format((float) $previewGoals['fat'], 1) ?></strong> g vet</div>
                </div>
            <?php else: ?>
                <div style="border:1px solid #2d2d2d;background:#0f0f0f;border-radius:14px;padding:12px;line-height:1.55;opacity:.86;">
                    Vul eerst je profiel in en klik daarna op een tag. Dan rekent de app je kcal, eiwitten, koolhydraten en vetten uit.
                </div>
            <?php endif; ?>

            <div style="display:grid;gap:10px;">
                <a href="profile.php"
                    style="display:inline-flex;align-items:center;justify-content:center;background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:12px 14px;color:#eaeaea;font-weight:700;text-decoration:none;">
                    Profiel openen
                </a>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="clear_tag">
                    <button type="submit"
                        style="width:100%;background:transparent;color:#eaeaea;border:1px solid #333;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer;">
                        Actieve tag uitzetten
                    </button>
                </form>
            </div>

            <p style="opacity:.72;margin:0;line-height:1.5;">
                Bij eiwitrijk gebruikt de app 2 gram eiwit per kilo lichaamsgewicht. Bij duurzaam verlaagt de app de calorieën wat voor rustiger afvallen. Bij bulk gaan de calorieën juist omhoog voor spiergroei.
            </p>
        </aside>
    </section>
</main>

<?php require __DIR__ . '/layouts/footer.php'; ?>