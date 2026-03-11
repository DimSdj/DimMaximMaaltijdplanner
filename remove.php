<?php
// remove.php
require __DIR__ . '/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = intval($_POST['meal_id'] ?? 0);
if ($id > 0) {
    $stmt = $mysqli->prepare("DELETE FROM meals WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}
header('Location: index.php?status=removed');
