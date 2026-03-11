<?php
// db.php — stille, foutveilige mysqli-setup (géén output)

declare(strict_types=1);

// Zorg dat mysqli fouten als exceptions gooit (ipv stille waarschuwingen of echo/die)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';     // pas aan indien anders
$DB_PASS = '';         // pas aan indien anders
$DB_NAME = 'voeding';  // jouw database

// Maak verbinding (gooit mysqli_sql_exception bij fout)
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// Charset instellen
$mysqli->set_charset('utf8mb4');

// Optionele helper
function now_ts(): string
{
    return date(format: 'Y-m-d H:i:s');
}
