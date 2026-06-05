<?php
// header.php — minimalistische navbar, géén brandtitel, géén plusknop, géén zoek-overlay
// Zet op elke pagina vóór include: $activeTab = 'dagboek' | 'zoeken' | 'profile' | 'tags'
if (!isset($activeTab))
  $activeTab = '';
?><!DOCTYPE html>
<html lang="nl">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $activeTab === 'dagboek' ? 'Vandaag' : ucfirst($activeTab); ?></title>
  <style>
    :root {
      --bg: #0a0a0a;
      --fg: #eaeaea;
      --border: #2b2b2b;
      --brand: #22c55e;
      --ink: #0b0b0b;
    }

    * {
      box-sizing: border-box
    }

    html,
    body {
      margin: 0;
      background: var(--bg);
      color: var(--fg);
      font-family: system-ui, Segoe UI, Inter, Roboto, Arial, sans-serif
    }

    a {
      color: inherit;
      text-decoration: none
    }

    .container {
      max-width: 1120px;
      margin: 0 auto;
      padding: 12px 16px
    }

    header.navbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: rgba(10, 10, 10, .9);
      border-bottom: 1px solid var(--border)
    }

    nav {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap
    }

    .navbtn {
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #0f0f0f;
      font-weight: 600;
      font-size: 14px
    }

    .navbtn.active {
      background: var(--brand);
      color: var(--ink);
      border-color: var(--brand)
    }
  </style>
</head>

<body>
