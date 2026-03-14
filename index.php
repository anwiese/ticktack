<?php
// --- Session & CSRF ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Reset (Score + Level)
if (isset($_GET['reset'])) {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    header('Location: index.php');
    exit;
}

// Level wechseln (Score bleibt erhalten)
if (isset($_GET['changelevel'])) {
    $score  = $_SESSION['score']  ?? 0;
    $errors = $_SESSION['errors'] ?? 0;
    $animals = $_SESSION['collected_animals'] ?? [];
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    $_SESSION['score']            = $score;
    $_SESSION['errors']           = $errors;
    $_SESSION['collected_animals'] = $animals;
    header('Location: index.php');
    exit;
}

// --- Spielkonfiguration ---
$milestoneEvery = 10; // Alle X Punkte gibt es ein Tier-Emoji als Belohnung

// --- Level-Konfiguration ---
// true  = gesperrt (nicht spielbar)
// false = freigeschaltet
$levelLocked = [
    1 => true,
    2 => false,
    3 => false,
    4 => false,
];

// Level setzen
if (isset($_GET['setlevel'])) {
    $lvl = (int)$_GET['setlevel'];
    if (($lvl === 1 || $lvl === 2 || $lvl === 3 || $lvl === 4) && !($levelLocked[$lvl] ?? true)) {
        $_SESSION['level']       = $lvl;
        $_SESSION['answered']    = false;
        $_SESSION['last_result'] = null;
        $_SESSION['last_answer'] = '';
        unset($_SESSION['q_hour'], $_SESSION['q_minute'], $_SESSION['correct_text'],
              $_SESSION['q_options'], $_SESSION['q_offset_label']);
        // Score & Fehler nur beim echten Neustart zurücksetzen (via ?reset=1)
        if (!isset($_SESSION['score']))  $_SESSION['score']  = 0;
        if (!isset($_SESSION['errors'])) $_SESSION['errors'] = 0;
    }
    header('Location: index.php');
    exit;
}

// Weiter → neue Frage
if (isset($_GET['next'])) {
    $_SESSION['answered']    = false;
    $_SESSION['last_result'] = null;
    $_SESSION['last_answer'] = '';
    header('Location: index.php');
    exit;
}

// CSRF-Token initialisieren
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Score & Fehler initialisieren
if (!isset($_SESSION['score']))  $_SESSION['score']  = 0;
if (!isset($_SESSION['errors'])) $_SESSION['errors'] = 0;

// --- Hilfsfunktionen ---

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Level 1: nur volle Stunden, viertel, halb, dreiviertel
 */
function timeToGermanL1(int $hour, int $minute): string {
    $hour = (($hour - 1) % 12) + 1;
    $next = ($hour % 12) + 1;
    switch ($minute) {
        case 0:  return $hour . ' Uhr';
        case 15: return 'viertel nach ' . $hour;
        case 30: return 'halb ' . $next;
        case 45: return 'viertel vor ' . $next;
    }
    return '';
}

/**
 * Level 2: alle 5-Minuten-Schritte
 */
function timeToGermanL2(int $hour, int $minute): string {
    $hour = (($hour - 1) % 12) + 1;
    $next = ($hour % 12) + 1;
    switch ($minute) {
        case 0:  return $hour . ' Uhr';
        case 5:  return '5 nach ' . $hour;
        case 10: return '10 nach ' . $hour;
        case 15: return 'viertel nach ' . $hour;
        case 20: return '20 nach ' . $hour;
        case 25: return '5 vor halb ' . $next;
        case 30: return 'halb ' . $next;
        case 35: return '5 nach halb ' . $next;
        case 40: return '20 vor ' . $next;
        case 45: return 'viertel vor ' . $next;
        case 50: return '10 vor ' . $next;
        case 55: return '5 vor ' . $next;
    }
    return '';
}

function timeToGerman(int $hour, int $minute, int $level): string {
    return ($level === 2 || $level === 3 || $level === 4)
        ? timeToGermanL2($hour, $minute)
        : timeToGermanL1($hour, $minute);
}

/**
 * Generiert 4 Antwortoptionen (1 korrekt, 3 Distraktoren), gemischt.
 */
function generateOptions(int $correctHour, int $correctMinute, int $level): array {
    $correctText = timeToGerman($correctHour, $correctMinute, $level);
    $allMinutes  = ($level === 2 || $level === 3 || $level === 4)
        ? [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]
        : [0, 15, 30, 45];

    $allTimes = [];
    for ($h = 1; $h <= 12; $h++) {
        foreach ($allMinutes as $m) {
            $text = timeToGerman($h, $m, $level);
            if ($text !== $correctText) {
                $allTimes[] = $text;
            }
        }
    }

    $allTimes = array_values(array_unique($allTimes));
    shuffle($allTimes);
    $distractors = array_slice($allTimes, 0, 3);

    $options = [['text' => $correctText, 'correct' => true]];
    foreach ($distractors as $d) {
        $options[] = ['text' => $d, 'correct' => false];
    }
    shuffle($options);
    return $options;
}

// --- POST: Antwort auswerten ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Ungültige Anfrage.');
    }

    $correctText = $_SESSION['correct_text'] ?? '';
    $answer      = $_POST['answer'] ?? '';

    if ($answer === $correctText) {
        $oldScore = $_SESSION['score'];
        $_SESSION['score'] += 1;
        $_SESSION['last_result'] = 'correct';
        // Alle $milestoneEvery Punkte: Tier-Belohnung
        if (floor($_SESSION['score'] / $milestoneEvery) > floor($oldScore / $milestoneEvery)) {
            $animals = ['🐶','🐱','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵',
                        '🐧','🦆','🦉','🦋','🐝','🐢','🐙','🐬','🐳','🦓','🐘','🦒','🦘',
                        '🦙','🦥','🦦','🦔','🐿️','🦜','🦩','🦚','🦞','🦀','🐡','🦭','🦈'];
            $earnedAnimal = $animals[array_rand($animals)];
            $_SESSION['milestone_animal'] = $earnedAnimal;
            if (!isset($_SESSION['collected_animals'])) $_SESSION['collected_animals'] = [];
            $_SESSION['collected_animals'][] = $earnedAnimal;
        }
    } else {
        $_SESSION['errors'] += 1;
        $_SESSION['last_result'] = 'wrong';
    }
    $_SESSION['last_answer'] = $answer;
    $_SESSION['answered']    = true;

    header('Location: index.php');
    exit;
}

// --- Kein Level gewählt → Level-Auswahl anzeigen ---
$level = $_SESSION['level'] ?? null;
if ($level !== null && !in_array($level, [1, 2, 3, 4], true)) {
    $level = null;
    unset($_SESSION['level']);
}

if ($level === null) {
    // Nur Level-Auswahl-Seite rendern
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticktack – Uhr lernen</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #e8f4fb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 16px 40px;
        }
        .title {
            font-size: 2rem;
            font-weight: 800;
            color: #1a3a5c;
            letter-spacing: -0.5px;
            margin-bottom: 10px;
            text-align: center;
        }
        .title span { color: #3b9de8; }
        .subtitle {
            color: #5b7fa0;
            font-size: 1rem;
            margin-bottom: 40px;
            text-align: center;
        }
        .level-grid {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .btn-level {
            background: #fff;
            border: 3px solid #b8d8f0;
            border-radius: 20px;
            padding: 28px 36px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            color: #1a3a5c;
            transition: border-color 0.15s, transform 0.1s, background 0.15s;
            width: 180px;
        }
        .btn-level:hover {
            background: #d6ecfb;
            border-color: #3b9de8;
            transform: translateY(-3px);
        }
        .btn-level .lv-num {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .btn-level .lv-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .btn-level .lv-desc {
            font-size: 0.82rem;
            color: #5b7fa0;
            line-height: 1.4;
        }
        .btn-level.locked {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .btn-level.locked:hover { background: #f1f5f9; border-color: #cbd5e1; transform: none; }
        .btn-level.locked .lv-desc { color: #94a3b8; }
        .lock-icon { font-size: 1.6rem; margin-bottom: 6px; }
    </style>
</head>
<body>
    <div class="title">Tick<span>tack</span></div>
    <p class="subtitle">Wähle dein Level:</p>
    <div class="level-grid">
        <?php
        $levels = [
            1 => ['title' => 'Anfänger',       'desc' => 'Volle Stunden, viertel nach, halb, viertel vor'],
            2 => ['title' => 'Leicht',          'desc' => 'Alle 5-Minuten-Schritte, z.&nbsp;B. „5 vor halb 7", „10 nach 4"'],
            3 => ['title' => 'Mittel',          'desc' => 'Wie Leicht, aber die Uhr zeigt nur 12, 3, 6 und 9'],
            4 => ['title' => 'Schwer',          'desc' => 'Wie Mittel – aber was zeigt die Uhr in einer Viertelstunde, einer halben Stunde oder einer Stunde?'],
        ];
        foreach ($levels as $lv => $info):
            $locked = $levelLocked[$lv] ?? false;
        ?>
        <?php if ($locked): ?>
        <div class="btn-level locked">
            <div class="lock-icon">&#x1F512;</div>
            <div class="lv-num"><?= $lv ?></div>
            <div class="lv-title"><?= $info['title'] ?></div>
            <div class="lv-desc"><?= $info['desc'] ?></div>
        </div>
        <?php else: ?>
        <a href="index.php?setlevel=<?= $lv ?>" class="btn-level">
            <div class="lv-num"><?= $lv ?></div>
            <div class="lv-title"><?= $info['title'] ?></div>
            <div class="lv-desc"><?= $info['desc'] ?></div>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
<?php
    exit;
}

// --- GET: Neue Frage oder Ergebnis anzeigen ---

$answered   = $_SESSION['answered']    ?? false;
$lastResult = $_SESSION['last_result'] ?? null;
$lastAnswer = $_SESSION['last_answer'] ?? '';
$correctText = '';

$allMinutes = ($level === 2 || $level === 3 || $level === 4)
    ? [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]
    : [0, 15, 30, 45];

if ($answered) {
    $hour           = $_SESSION['q_hour'];
    $minute         = $_SESSION['q_minute'];
    $correctText    = $_SESSION['correct_text'];
    $options        = $_SESSION['q_options'];
    $offsetLabel    = $_SESSION['q_offset_label'] ?? '';
} else {
    $hour   = random_int(1, 12);
    $minute = $allMinutes[array_rand($allMinutes)];
    $offsetLabel = '';

    if ($level === 4) {
        $offsets     = [15, 30, 60];
        $offset      = $offsets[array_rand($offsets)];
        $offsetLabel = match($offset) {
            15 => 'einer Viertelstunde',
            30 => 'einer halben Stunde',
            60 => 'einer Stunde',
        };
        $totalMinutes = $hour * 60 + $minute + $offset;
        $targetHour   = (int)($totalMinutes / 60) % 12;
        if ($targetHour === 0) $targetHour = 12;
        $targetMinute = $totalMinutes % 60;
        $correctText  = timeToGerman($targetHour, $targetMinute, $level);
        $options      = generateOptions($targetHour, $targetMinute, $level);
        $_SESSION['q_offset_label'] = $offsetLabel;
    } else {
        $correctText = timeToGerman($hour, $minute, $level);
        $options     = generateOptions($hour, $minute, $level);
        $_SESSION['q_offset_label'] = '';
    }

    $_SESSION['q_hour']       = $hour;
    $_SESSION['q_minute']     = $minute;
    $_SESSION['correct_text'] = $correctText;
    $_SESSION['q_options']    = $options;
    $_SESSION['answered']     = false;
    $_SESSION['last_result']  = null;
    $_SESSION['last_answer']  = '';
}

// --- SVG-Uhr berechnen ---
$minuteAngle = $minute * 6;
$hourAngle   = (($hour % 12) + $minute / 60) * 30;

$score  = $_SESSION['score'];
$errors = $_SESSION['errors'];
$milestoneAnimal  = $_SESSION['milestone_animal'] ?? null;
$collectedAnimals = $_SESSION['collected_animals'] ?? [];
unset($_SESSION['milestone_animal']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticktack – Uhr lernen</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #e8f4fb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 16px 40px;
        }

        /* Header */
        .header {
            width: 100%;
            max-width: 480px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a3a5c;
            letter-spacing: -0.5px;
        }
        .title span { color: #3b9de8; }
        .title .level-tag {
            font-size: 0.75rem;
            font-weight: 700;
            background: #3b9de8;
            color: #fff;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .stats {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .score-badge {
            background: #1a3a5c;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 999px;
        }
        .score-badge .pts { font-size: 0.75rem; font-weight: 400; opacity: 0.8; margin-left: 2px; }
        .error-badge {
            background: #fee2e2;
            color: #991b1b;
            font-size: 1rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 999px;
            border: 2px solid #fca5a5;
        }
        .error-badge .pts { font-size: 0.75rem; font-weight: 400; opacity: 0.8; margin-left: 2px; }

        /* Uhr-Wrapper */
        .clock-wrap {
            margin-bottom: 28px;
            filter: drop-shadow(0 8px 24px rgba(26,58,92,0.18));
        }
        .clock-wrap svg { display: block; }

        /* Frage-Text */
        .question {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a3a5c;
            margin-bottom: 20px;
            text-align: center;
        }

        /* Antwort-Grid */
        .options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            width: 100%;
            max-width: 420px;
            margin-bottom: 20px;
        }
        .btn-option {
            min-height: 64px;
            border: 3px solid #b8d8f0;
            border-radius: 16px;
            background: #fff;
            color: #1a3a5c;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, transform 0.1s;
            padding: 10px 14px;
            line-height: 1.3;
        }
        .btn-option:hover:not(:disabled) {
            background: #d6ecfb;
            border-color: #3b9de8;
            transform: translateY(-2px);
        }
        .btn-option:active:not(:disabled) { transform: translateY(0); }
        .btn-option:disabled { cursor: default; }

        /* Feedback-Farben */
        .btn-option.correct { background: #d4f7e0; border-color: #22c55e; color: #166534; }
        .btn-option.wrong   { background: #fee2e2; border-color: #ef4444; color: #991b1b; }

        /* Feedback-Box */
        .feedback {
            width: 100%;
            max-width: 420px;
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 20px;
        }
        .feedback.correct { background: #d4f7e0; color: #166534; border: 2px solid #22c55e; }
        .feedback.wrong   { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }
        .feedback img { width: 1.2em; height: 1.2em; vertical-align: middle; }

        /* Weiter-Button */
        .btn-next {
            background: #1a3a5c;
            color: #fff;
            border: none;
            border-radius: 14px;
            padding: 14px 36px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-next:hover { background: #3b9de8; transform: translateY(-2px); }

        /* Links unten */
        .bottom-links {
            margin-top: 24px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .reset-link {
            color: #6b8fae;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .reset-link:hover { color: #ef4444; text-decoration: underline; }

        /* Tier-Sammlung */
        .animal-collection {
            margin-top: 28px;
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .animal-collection-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #5b7fa0;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
        }
        .animal-collection-label img { width: 1em; height: 1em; vertical-align: middle; }
        .animal-collection-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
        }
        .animal-chip {
            font-size: 1.6rem;
            line-height: 1;
            background: #fff;
            border: 2px solid #b8d8f0;
            border-radius: 10px;
            padding: 4px 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .animal-chip img { width: 1.5rem; height: 1.5rem; }

        /* Tier-Belohnung Overlay */
        .animal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            cursor: pointer;
            animation: overlay-in 0.25s ease-out;
        }
        @keyframes overlay-in {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .animal-emoji {
            width: min(60vw, 60vh);
            height: min(60vw, 60vh);
            line-height: 1;
            animation: animal-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            user-select: none;
        }
        .animal-emoji img {
            width: 100%;
            height: 100%;
        }
        @keyframes animal-pop {
            0%   { transform: scale(0.2) rotate(-15deg); opacity: 0; }
            100% { transform: scale(1)   rotate(0deg);   opacity: 1; }
        }
        .animal-label {
            margin-top: 24px;
            color: #fff;
            font-size: 1.4rem;
            font-weight: 800;
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
            animation: overlay-in 0.4s 0.3s ease-out both;
        }
        .label-emoji { display: inline-block; vertical-align: middle; }
        .label-emoji img { width: 1.1rem; height: 1.1rem; }
        .animal-hint {
            margin-top: 10px;
            color: rgba(255,255,255,0.7);
            font-size: 0.9rem;
            animation: overlay-in 0.4s 0.5s ease-out both;
        }

    </style>
</head>
<body>

<?php if ($milestoneAnimal): ?>
<div class="animal-overlay" id="animalOverlay" onclick="document.getElementById('animalOverlay').remove()">
    <div class="animal-emoji"><?= e($milestoneAnimal) ?></div>
    <div class="animal-label"><span class="label-emoji">&#x1F389;</span> <?= $score ?> Punkte! Weiter so!</div>
    <div class="animal-hint">Antippen zum Weiterspielen</div>
</div>
<?php endif; ?>


<div class="header">
    <div class="title">
        Tick<span>tack</span>
        <span class="level-tag">Level <?= $level ?></span>
    </div>
    <div class="stats">
        <?php if ($errors > 0): ?>
        <div class="error-badge"><?= $errors ?><span class="pts"> Fehler</span></div>
        <?php endif; ?>
        <div class="score-badge"><?= $score ?><span class="pts"> Punkte</span></div>
    </div>
</div>

<!-- Analoge SVG-Uhr -->
<div class="clock-wrap">
    <svg width="288" height="288" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Analoge Uhr">
        <circle cx="100" cy="100" r="96" fill="white" stroke="#1a3a5c" stroke-width="4"/>

        <!-- Minutenstriche -->
        <?php for ($i = 0; $i < 60; $i++):
            $isHour = ($i % 5 === 0);
            $len    = $isHour ? 10 : 5;
            $sw     = $isHour ? 2.5 : 1;
            $angle  = $i * 6;
            $rad    = deg2rad($angle - 90);
            $x1     = 100 + 86 * cos($rad);
            $y1     = 100 + 86 * sin($rad);
            $x2     = 100 + (86 - $len) * cos($rad);
            $y2     = 100 + (86 - $len) * sin($rad);
        ?>
        <line x1="<?= round($x1,2) ?>" y1="<?= round($y1,2) ?>"
              x2="<?= round($x2,2) ?>" y2="<?= round($y2,2) ?>"
              stroke="#1a3a5c" stroke-width="<?= $sw ?>" stroke-linecap="round"/>
        <?php endfor; ?>

        <!-- Zahlen -->
        <?php
        $shownNumbers = ($level === 3) ? [12, 3, 6, 9] : range(1, 12);
        foreach ($shownNumbers as $i):
            $angle = ($i * 30 - 90);
            $rad   = deg2rad($angle);
            $x     = 100 + 63 * cos($rad);
            $y     = 100 + 63 * sin($rad);
        ?>
        <text x="<?= round($x,2) ?>" y="<?= round($y,2) ?>"
              text-anchor="middle" dominant-baseline="central"
              font-family="'Segoe UI', system-ui, sans-serif"
              font-size="13" font-weight="700" fill="#1a3a5c"><?= $i ?></text>
        <?php endforeach; ?>

        <!-- Stundenzeiger -->
        <line x1="100" y1="100" x2="100" y2="58"
              stroke="#1a3a5c" stroke-width="6" stroke-linecap="round"
              transform="rotate(<?= round($hourAngle, 2) ?>, 100, 100)"/>

        <!-- Minutenzeiger -->
        <line x1="100" y1="100" x2="100" y2="46"
              stroke="#1a3a5c" stroke-width="3.5" stroke-linecap="round"
              transform="rotate(<?= round($minuteAngle, 2) ?>, 100, 100)"/>

        <circle cx="100" cy="100" r="5" fill="#1a3a5c"/>
    </svg>
</div>

<p class="question">
<?php if ($level === 4): ?>
    Wie viel Uhr ist es in <?= e($offsetLabel) ?>?
<?php else: ?>
    Wie viel Uhr ist es?
<?php endif; ?>
</p>

<?php if ($answered): ?>
    <?php if ($lastResult === 'correct'): ?>
        <div class="feedback correct">Super! Das war richtig! &#x1F389;</div>
    <?php else: ?>
        <div class="feedback wrong">Nicht ganz &ndash; die richtige Antwort: <strong><?= e($correctText) ?></strong></div>
    <?php endif; ?>

    <div class="options">
        <?php foreach ($options as $opt): ?>
            <?php
            $cls = '';
            if ($opt['correct']) $cls = 'correct';
            elseif ($opt['text'] === $lastAnswer && !$opt['correct']) $cls = 'wrong';
            ?>
            <button class="btn-option <?= $cls ?>" disabled><?= e($opt['text']) ?></button>
        <?php endforeach; ?>
    </div>

    <a href="index.php?next=1" class="btn-next" onclick="this.style.opacity='0.6'">Weiter &rarr;</a>

<?php else: ?>
    <form method="post" action="index.php" class="options">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <?php foreach ($options as $opt): ?>
            <button type="submit" name="answer" value="<?= e($opt['text']) ?>" class="btn-option">
                <?= e($opt['text']) ?>
            </button>
        <?php endforeach; ?>
    </form>
<?php endif; ?>

<?php if (!empty($collectedAnimals)): ?>
<div class="animal-collection" id="animalCollection">
    <div class="animal-collection-label">Meine Tiere &#x1F3C6;</div>
    <div class="animal-collection-row">
        <?php foreach ($collectedAnimals as $a): ?>
        <span class="animal-chip"><?= e($a) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="bottom-links">
    <a href="index.php?reset=1" class="reset-link">Neu starten (Punkte zurücksetzen)</a>
    <a href="index.php?changelevel=1" class="reset-link" style="color:#3b9de8">Level wechseln</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/twemoji@14.0.2/dist/twemoji.min.js"
        crossorigin="anonymous"
        integrity="sha384-32KMvAMS4DUBcQtHG6fzADguo/tpN1Nh6BAJa2QqZc6/i0K+YPQE+bWiqBRAWuFs"></script>
<script>
twemoji.parse(document.body, { folder: 'svg', ext: '.svg' });
</script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.4/dist/confetti.browser.min.js"
        crossorigin="anonymous"
        integrity="sha384-JSZXO0kKYHTylAsDYTb+7Kg2eUyalm19b8Pydcdf8sQ1cCKYZr9lLahoKT9+LFY5"></script>
<?php if ($lastResult === 'correct'): ?>
<script>
(function () {
    if (typeof confetti !== 'function') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    // Von der rechten oberen Ecke nach links unten schießen
    var origin = { x: 1.0, y: 0.5 };
    var base = { origin: origin, angle: 180, ticks: 90, gravity: 1.8, scalar: 1, drift: 0 };

    confetti(Object.assign({}, base, { particleCount: 100, spread: 55,  startVelocity: 48, decay: 0.88 }));
    confetti(Object.assign({}, base, { particleCount:  45, spread: 80,  startVelocity: 38, decay: 0.88, scalar: 0.9 }));
    confetti(Object.assign({}, base, { particleCount:  25, spread: 100, startVelocity: 28, decay: 0.88, scalar: 0.8 }));
}());
</script>
<?php endif; ?>

</body>
</html>
