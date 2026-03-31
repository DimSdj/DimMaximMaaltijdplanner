<?php
// profile.php — Profiel / Instellingen (dagdoelen + persoonlijke gegevens)
$activeTab = 'profile';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/* -------------------------- Fallback helpers -------------------------- */
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

if (!function_exists('parse_int_input')) {
    function parse_int_input($value, int $fallback = 0): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        return (int) $value;
    }
}

if (!function_exists('parse_float_input')) {
    function parse_float_input($value, float $fallback = 0.0): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        return (float) str_replace(',', '.', $value);
    }
}

if (!function_exists('has_profile_data')) {
    function has_profile_data(array $profile): bool
    {
        return (int) ($profile['age'] ?? 0) > 0
            && (float) ($profile['height_cm'] ?? 0) > 0
            && (float) ($profile['weight_kg'] ?? 0) > 0;
    }
}

if (!function_exists('calculate_recommended_goals')) {
    function calculate_recommended_goals(array $profile): array
    {
        $age = (int) ($profile['age'] ?? 0);
        $height = (float) ($profile['height_cm'] ?? 0);
        $weight = (float) ($profile['weight_kg'] ?? 0);
        $sex = (string) ($profile['sex'] ?? 'male');
        $activity = (string) ($profile['activity_level'] ?? 'moderate');
        $goal = (string) ($profile['goal'] ?? 'maintain');

        if ($age <= 0 || $height <= 0 || $weight <= 0) {
            return [];
        }

        // Mifflin-St Jeor
        if ($sex === 'female') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        } elseif ($sex === 'other') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age);
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        }

        $activityFactors = [
            'sedentary' => 1.2,
            'light' => 1.375,
            'moderate' => 1.55,
            'active' => 1.725,
            'very_active' => 1.9,
        ];
        $activityFactor = $activityFactors[$activity] ?? 1.55;

        $kcal = $bmr * $activityFactor;

        if ($goal === 'lose') {
            $kcal -= 300;
            $proteinPerKg = 2.0;
            $fatPerKg = 0.8;
        } elseif ($goal === 'gain') {
            $kcal += 300;
            $proteinPerKg = 1.8;
            $fatPerKg = 1.0;
        } else {
            $proteinPerKg = 1.8;
            $fatPerKg = 0.9;
        }

        $kcal = max(1200, min(5000, round($kcal)));
        $protein = round($weight * $proteinPerKg, 1);
        $fat = round($weight * $fatPerKg, 1);

        $remainingKcal = $kcal - ($protein * 4) - ($fat * 9);
        if ($remainingKcal < 0) {
            $remainingKcal = 0;
        }

        $carb = round($remainingKcal / 4, 1);

        return [
            'kcal' => (int) $kcal,
            'protein' => $protein,
            'carb' => $carb,
            'fat' => $fat,
        ];
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

$existingGoals = sql_select($mysqli, "SELECT * FROM goals WHERE id = 1");
if (!$existingGoals) {
    sql_execute(
        $mysqli,
        "INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
         VALUES (1, 1877, 150.0, 250.0, 70.0, NOW())"
    );
    $existingGoals = sql_select($mysqli, "SELECT * FROM goals WHERE id = 1");
}
$goals = $existingGoals[0];

/* -------------------------- Profile bootstrap -------------------------- */
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

$existingProfile = sql_select($mysqli, "SELECT * FROM user_profile WHERE id = 1");
if (!$existingProfile) {
    sql_execute(
        $mysqli,
        "INSERT INTO user_profile (id, age, height_cm, weight_kg, sex, activity_level, goal, updated_at)
         VALUES (1, 0, 0, 0, 'male', 'moderate', 'maintain', NOW())"
    );
    $existingProfile = sql_select($mysqli, "SELECT * FROM user_profile WHERE id = 1");
}
$profile = $existingProfile[0];

/* -------------------------- POST verwerken -------------------------- */
$successMsg = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedSex = ['male', 'female', 'other'];
    $allowedActivity = ['sedentary', 'light', 'moderate', 'active', 'very_active'];
    $allowedGoal = ['lose', 'maintain', 'gain'];

    $age = parse_int_input($_POST['age'] ?? '', (int) $profile['age']);
    $heightCm = parse_float_input($_POST['height_cm'] ?? '', (float) $profile['height_cm']);
    $weightKg = parse_float_input($_POST['weight_kg'] ?? '', (float) $profile['weight_kg']);

    $sex = $_POST['sex'] ?? $profile['sex'];
    if (!in_array($sex, $allowedSex, true)) {
        $sex = 'male';
    }

    $activityLevel = $_POST['activity_level'] ?? $profile['activity_level'];
    if (!in_array($activityLevel, $allowedActivity, true)) {
        $activityLevel = 'moderate';
    }

    $goal = $_POST['goal'] ?? $profile['goal'];
    if (!in_array($goal, $allowedGoal, true)) {
        $goal = 'maintain';
    }

    $kcal = isset($_POST['kcal']) ? (int) $_POST['kcal'] : (int) $goals['kcal'];
    $protein = isset($_POST['protein']) ? (float) str_replace(',', '.', $_POST['protein']) : (float) $goals['protein'];
    $carb = isset($_POST['carb']) ? (float) str_replace(',', '.', $_POST['carb']) : (float) $goals['carb'];
    $fat = isset($_POST['fat']) ? (float) str_replace(',', '.', $_POST['fat']) : (float) $goals['fat'];

    // Laat formulier direct de nieuwste waarden tonen
    $profile['age'] = $age;
    $profile['height_cm'] = $heightCm;
    $profile['weight_kg'] = $weightKg;
    $profile['sex'] = $sex;
    $profile['activity_level'] = $activityLevel;
    $profile['goal'] = $goal;

    $goals['kcal'] = $kcal;
    $goals['protein'] = $protein;
    $goals['carb'] = $carb;
    $goals['fat'] = $fat;

    $needsProfileValidation = ($age > 0 || $heightCm > 0 || $weightKg > 0 || isset($_POST['calculate_from_profile']));

    if ($needsProfileValidation) {
        if ($age < 12 || $age > 100) {
            $errorMsg = "Leeftijd moet tussen 12 en 100 liggen.";
        } elseif ($heightCm < 120 || $heightCm > 250) {
            $errorMsg = "Lengte moet tussen 120 en 250 cm liggen.";
        } elseif ($weightKg < 30 || $weightKg > 300) {
            $errorMsg = "Gewicht moet tussen 30 en 300 kg liggen.";
        }
    }

    if (!$errorMsg && isset($_POST['calculate_from_profile'])) {
        $recommended = calculate_recommended_goals([
            'age' => $age,
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'sex' => $sex,
            'activity_level' => $activityLevel,
            'goal' => $goal,
        ]);

        if (!$recommended) {
            $errorMsg = "Vul eerst een geldige leeftijd, lengte en gewicht in.";
        } else {
            $kcal = $recommended['kcal'];
            $protein = $recommended['protein'];
            $carb = $recommended['carb'];
            $fat = $recommended['fat'];

            $goals['kcal'] = $kcal;
            $goals['protein'] = $protein;
            $goals['carb'] = $carb;
            $goals['fat'] = $fat;
        }
    }

    if (!$errorMsg) {
        if ($kcal < 800 || $kcal > 6000) {
            $errorMsg = "Kcal moet tussen 800 en 6000 liggen.";
        } elseif ($protein < 0 || $protein > 500 || $carb < 0 || $carb > 1000 || $fat < 0 || $fat > 300) {
            $errorMsg = "Macro-doelen buiten geldige grenzen.";
        }
    }

    if (!$errorMsg) {
        $okProfile = sql_execute($mysqli, "
            INSERT INTO user_profile (id, age, height_cm, weight_kg, sex, activity_level, goal, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                age = VALUES(age),
                height_cm = VALUES(height_cm),
                weight_kg = VALUES(weight_kg),
                sex = VALUES(sex),
                activity_level = VALUES(activity_level),
                goal = VALUES(goal),
                updated_at = VALUES(updated_at)
        ", [$age, $heightCm, $weightKg, $sex, $activityLevel, $goal]);

        $okGoals = sql_execute($mysqli, "
            INSERT INTO goals (id, kcal, protein, carb, fat, updated_at)
            VALUES (1, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                kcal = VALUES(kcal),
                protein = VALUES(protein),
                carb = VALUES(carb),
                fat = VALUES(fat),
                updated_at = VALUES(updated_at)
        ", [$kcal, $protein, $carb, $fat]);

        if ($okProfile && $okGoals) {
            if (isset($_POST['calculate_from_profile'])) {
                $successMsg = "Profiel opgeslagen en dagdoelen automatisch berekend.";
            } else {
                $successMsg = "Profiel en dagdoelen opgeslagen.";
            }

            $profile = sql_select($mysqli, "SELECT * FROM user_profile WHERE id = 1")[0];
            $goals = sql_select($mysqli, "SELECT * FROM goals WHERE id = 1")[0];
        } else {
            $errorMsg = "Opslaan mislukt.";
        }
    }
}

$recommendedPreview = has_profile_data($profile) ? calculate_recommended_goals($profile) : [];

/* -------------------------- Header -------------------------- */
require __DIR__ . '/layouts/header.php';
?>

<main class="container" style="max-width:1120px;margin:0 auto;padding:18px 16px;">
    <h1 style="font-size:32px;line-height:1.2;margin:8px 0 12px 0;font-weight:800;">Profiel</h1>
    <p style="opacity:.85;margin:0 0 18px 0;">Stel hier je profiel en dagelijkse doelen in.</p>

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

    <form method="post" action="./profile.php"
        style="max-width:760px;background:#101010;border:1px solid #2b2b2b;border-radius:16px;padding:16px;display:grid;gap:18px;">

        <section style="display:grid;gap:12px;">
            <div>
                <h2 style="margin:0 0 6px 0;font-size:20px;font-weight:800;">Persoonlijke gegevens</h2>
                <p style="margin:0;opacity:.75;">Deze gegevens kun je gebruiken om je doelen automatisch te berekenen.</p>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                <label style="display:grid;gap:6px;">
                    <span>Leeftijd</span>
                    <input
                        type="number"
                        name="age"
                        min="12"
                        max="100"
                        step="1"
                        value="<?= (int) $profile['age'] > 0 ? (int) $profile['age'] : '' ?>"
                        placeholder="Bijv. 22"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Lengte (cm)</span>
                    <input
                        type="number"
                        name="height_cm"
                        min="120"
                        max="250"
                        step="0.1"
                        value="<?= (float) $profile['height_cm'] > 0 ? esc($profile['height_cm']) : '' ?>"
                        placeholder="Bijv. 180"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Gewicht (kg)</span>
                    <input
                        type="number"
                        name="weight_kg"
                        min="30"
                        max="300"
                        step="0.1"
                        value="<?= (float) $profile['weight_kg'] > 0 ? esc($profile['weight_kg']) : '' ?>"
                        placeholder="Bijv. 75"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                <label style="display:grid;gap:6px;">
                    <span>Geslacht</span>
                    <select
                        name="sex"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                        <option value="male" <?= $profile['sex'] === 'male' ? 'selected' : '' ?>>Man</option>
                        <option value="female" <?= $profile['sex'] === 'female' ? 'selected' : '' ?>>Vrouw</option>
                        <option value="other" <?= $profile['sex'] === 'other' ? 'selected' : '' ?>>Anders</option>
                    </select>
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Activiteit</span>
                    <select
                        name="activity_level"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                        <option value="sedentary" <?= $profile['activity_level'] === 'sedentary' ? 'selected' : '' ?>>Weinig beweging</option>
                        <option value="light" <?= $profile['activity_level'] === 'light' ? 'selected' : '' ?>>Licht actief</option>
                        <option value="moderate" <?= $profile['activity_level'] === 'moderate' ? 'selected' : '' ?>>Gemiddeld actief</option>
                        <option value="active" <?= $profile['activity_level'] === 'active' ? 'selected' : '' ?>>Actief</option>
                        <option value="very_active" <?= $profile['activity_level'] === 'very_active' ? 'selected' : '' ?>>Zeer actief</option>
                    </select>
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Doel</span>
                    <select
                        name="goal"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                        <option value="lose" <?= $profile['goal'] === 'lose' ? 'selected' : '' ?>>Afvallen</option>
                        <option value="maintain" <?= $profile['goal'] === 'maintain' ? 'selected' : '' ?>>Onderhouden</option>
                        <option value="gain" <?= $profile['goal'] === 'gain' ? 'selected' : '' ?>>Aankomen</option>
                    </select>
                </label>
            </div>
        </section>

        <section style="display:grid;gap:12px;">
            <div>
                <h2 style="margin:0 0 6px 0;font-size:20px;font-weight:800;">Dagdoelen</h2>
                <p style="margin:0;opacity:.75;">Je kunt ze handmatig invullen of laten berekenen op basis van je profiel.</p>
            </div>

            <label style="display:grid;gap:6px;">
                <span>Kilocalorieën per dag</span>
                <input
                    type="number"
                    name="kcal"
                    min="800"
                    max="6000"
                    step="1"
                    value="<?= (int) $goals['kcal'] ?>"
                    style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
            </label>

            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                <label style="display:grid;gap:6px;">
                    <span>Eiwit (g)</span>
                    <input
                        type="number"
                        name="protein"
                        min="0"
                        max="500"
                        step="0.1"
                        value="<?= esc($goals['protein']) ?>"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Koolhydraten (g)</span>
                    <input
                        type="number"
                        name="carb"
                        min="0"
                        max="1000"
                        step="0.1"
                        value="<?= esc($goals['carb']) ?>"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>

                <label style="display:grid;gap:6px;">
                    <span>Vet (g)</span>
                    <input
                        type="number"
                        name="fat"
                        min="0"
                        max="300"
                        step="0.1"
                        value="<?= esc($goals['fat']) ?>"
                        style="background:#0f0f0f;border:1px solid #333;border-radius:10px;padding:10px 12px;color:#eaeaea;outline:none;">
                </label>
            </div>
        </section>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button
                type="submit"
                name="calculate_from_profile"
                value="1"
                style="background:#facc15;color:#0b0b0b;font-weight:700;border:0;border-radius:10px;padding:10px 14px;cursor:pointer;">
                Bereken advies
            </button>

            <button
                type="submit"
                style="background:#22c55e;color:#0b0b0b;font-weight:700;border:0;border-radius:10px;padding:10px 14px;cursor:pointer;">
                Opslaan
            </button>
        </div>
    </form>

    <?php if ($recommendedPreview): ?>
            <div style="max-width:760px;margin-top:14px;background:#0d1310;border:1px solid #24382c;border-radius:14px;padding:14px 16px;">
                <strong style="display:block;margin-bottom:6px;">Advies op basis van je profiel</strong>
                <div style="opacity:.9;line-height:1.6;">
                    <?= (int) $recommendedPreview['kcal'] ?> kcal ·
                    <?= esc($recommendedPreview['protein']) ?> g eiwit ·
                    <?= esc($recommendedPreview['carb']) ?> g koolhydraten ·
                    <?= esc($recommendedPreview['fat']) ?> g vet
                </div>
                <p style="margin:8px 0 0 0;opacity:.7;">
                    Dit is een schatting. Klik op <strong>Bereken advies</strong> om deze waarden direct in je dagdoelen te zetten.
                </p>
            </div>
    <?php endif; ?>

    <p style="opacity:.75;margin:12px 0 0 0;">
        Tip: na het opslaan gebruiken de planner en het dagboek deze doelen voor de ringen.
    </p>
</main>

<?php
require __DIR__ . '/layouts/footer.php';
?>