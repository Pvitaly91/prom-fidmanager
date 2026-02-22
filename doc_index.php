<?php
declare(strict_types=1);

$docsRoot = __DIR__ . DIRECTORY_SEPARATOR . 'DOCS';

/**
 * Simple HTML escaping helper.
 */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clamp(float $value, float $min, float $max): float
{
    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function isImageFile(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
}

function scoreFromBaseName(string $baseName): ?int
{
    if (preg_match('/_ocr([1-9]|10)$/i', $baseName, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

function removeScoreSuffix(string $baseName): string
{
    return (string) preg_replace('/_ocr(?:[1-9]|10)$/i', '', $baseName);
}

function detectDocumentType(string $baseNameWithoutScore): string
{
    if (preg_match('/^([A-Za-z]+)/', $baseNameWithoutScore, $matches) !== 1) {
        return 'Unknown';
    }

    return $matches[1];
}

function detectSideLabel(string $baseNameWithoutScore): string
{
    if (preg_match('/_(front|back)(?:_(\d+))?$/i', $baseNameWithoutScore, $matches) !== 1) {
        return 'unknown';
    }

    $side = strtolower($matches[1]);
    if (!empty($matches[2])) {
        $side .= '_' . $matches[2];
    }

    return $side;
}

function sideSortRank(string $sideLabel): int
{
    if ($sideLabel === 'front') {
        return 1;
    }

    if ($sideLabel === 'back') {
        return 2;
    }

    if (strpos($sideLabel, 'back_') === 0) {
        return 3;
    }

    return 9;
}

function collectRecords(string $docsRoot): array
{
    $records = [];
    $countryDirs = glob($docsRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    if ($countryDirs === false) {
        return $records;
    }

    sort($countryDirs, SORT_STRING);
    foreach ($countryDirs as $countryDir) {
        $country = basename($countryDir);
        $paths = glob($countryDir . DIRECTORY_SEPARATOR . '*');
        if ($paths === false) {
            continue;
        }

        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (!isImageFile($path)) {
                continue;
            }

            $fileName = basename($path);
            $baseName = (string) pathinfo($fileName, PATHINFO_FILENAME);
            $baseNameWithoutScore = removeScoreSuffix($baseName);
            $scoreInName = scoreFromBaseName($baseName);

            $records[] = [
                'country' => $country,
                'dir' => $countryDir,
                'path' => $path,
                'file_name' => $fileName,
                'ext' => strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)),
                'base_name' => $baseName,
                'base_name_without_score' => $baseNameWithoutScore,
                'type' => detectDocumentType($baseNameWithoutScore),
                'side' => detectSideLabel($baseNameWithoutScore),
                'score_in_name' => $scoreInName,
            ];
        }
    }

    return $records;
}

function canUseShellExec(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    $items = array_map('trim', explode(',', strtolower($disabled)));
    return !in_array('shell_exec', $items, true);
}

function isTesseractAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    if (!canUseShellExec()) {
        $available = false;
        return $available;
    }

    $checkCommand = PHP_OS_FAMILY === 'Windows'
        ? 'where tesseract 2>NUL'
        : 'command -v tesseract 2>/dev/null';
    $output = shell_exec($checkCommand);
    $available = is_string($output) && trim($output) !== '';
    return $available;
}

function runTesseract(string $imagePath): string
{
    if (!isTesseractAvailable()) {
        return '';
    }

    $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 -l eng 2>NUL';
    $output = shell_exec($cmd);
    if (!is_string($output)) {
        return '';
    }

    return trim((string) preg_replace('/\s+/u', ' ', $output));
}

function loadImageResource(string $path, int $imageType)
{
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($path);
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($path);
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        default:
            return false;
    }
}

function evaluateImageQuality(string $path): float
{
    $info = @getimagesize($path);
    if ($info === false) {
        return 1.0;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $imageType = (int) $info[2];
    if ($width < 1 || $height < 1) {
        return 1.0;
    }

    $resolutionScore = clamp(($width * $height) / (1200.0 * 800.0), 0.0, 1.0);

    $img = loadImageResource($path, $imageType);
    if ($img === false) {
        return clamp(2.0 + (8.0 * $resolutionScore), 1.0, 10.0);
    }

    $step = max(1, (int) floor(min($width, $height) / 240));
    $sum = 0.0;
    $sumSquares = 0.0;
    $samples = 0;
    $gradientSum = 0.0;
    $gradientSamples = 0;

    $maxX = $width - $step;
    $maxY = $height - $step;
    for ($y = 0; $y < $maxY; $y += $step) {
        for ($x = 0; $x < $maxX; $x += $step) {
            $pixel = imagecolorat($img, $x, $y);
            $r = ($pixel >> 16) & 0xFF;
            $g = ($pixel >> 8) & 0xFF;
            $b = $pixel & 0xFF;
            $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);

            $sum += $luma;
            $sumSquares += ($luma * $luma);
            $samples++;

            $right = imagecolorat($img, $x + $step, $y);
            $down = imagecolorat($img, $x, $y + $step);

            $rr = ($right >> 16) & 0xFF;
            $rg = ($right >> 8) & 0xFF;
            $rb = $right & 0xFF;
            $dr = ($down >> 16) & 0xFF;
            $dg = ($down >> 8) & 0xFF;
            $db = $down & 0xFF;

            $lumaRight = (0.299 * $rr) + (0.587 * $rg) + (0.114 * $rb);
            $lumaDown = (0.299 * $dr) + (0.587 * $dg) + (0.114 * $db);

            $gradientSum += abs($luma - $lumaRight) + abs($luma - $lumaDown);
            $gradientSamples++;
        }
    }

    imagedestroy($img);

    if ($samples < 1 || $gradientSamples < 1) {
        return clamp(2.0 + (8.0 * $resolutionScore), 1.0, 10.0);
    }

    $mean = $sum / $samples;
    $variance = max(0.0, ($sumSquares / $samples) - ($mean * $mean));
    $stdDev = sqrt($variance);
    $avgGradient = $gradientSum / $gradientSamples;

    $contrastScore = clamp($stdDev / 64.0, 0.0, 1.0);
    $sharpnessScore = clamp($avgGradient / 55.0, 0.0, 1.0);
    $exposureScore = 1.0 - clamp(abs($mean - 140.0) / 140.0, 0.0, 1.0);

    $quality = 10.0 * (
        (0.25 * $resolutionScore) +
        (0.30 * $contrastScore) +
        (0.30 * $sharpnessScore) +
        (0.15 * $exposureScore)
    );

    return clamp($quality, 1.0, 10.0);
}

function evaluateOcrQuality(string $ocrText): float
{
    if ($ocrText === '') {
        return 0.0;
    }

    $visibleChars = preg_match_all('/\S/u', $ocrText);
    $alphaNumChars = preg_match_all('/[\p{L}\p{N}]/u', $ocrText);
    if ($visibleChars === false || $alphaNumChars === false || $visibleChars < 1) {
        return 0.0;
    }

    $words = preg_split('/\s+/u', trim($ocrText));
    if (!is_array($words)) {
        $words = [];
    }

    $words = array_values(array_filter($words, static function ($word): bool {
        return mb_strlen($word, 'UTF-8') >= 2;
    }));

    $wordCount = count($words);
    $uniqueCount = count(array_unique(array_map(static function ($word): string {
        return mb_strtolower($word, 'UTF-8');
    }, $words)));

    $charScore = clamp($alphaNumChars / 220.0, 0.0, 1.0);
    $wordScore = clamp($wordCount / 35.0, 0.0, 1.0);
    $lexicalScore = $wordCount > 0 ? clamp(($uniqueCount / $wordCount) / 0.85, 0.0, 1.0) : 0.0;
    $cleanTextScore = clamp(($alphaNumChars / max(1, $visibleChars) - 0.35) / 0.55, 0.0, 1.0);

    $ocrScore = 10.0 * (
        (0.45 * $charScore) +
        (0.25 * $wordScore) +
        (0.15 * $lexicalScore) +
        (0.15 * $cleanTextScore)
    );

    return clamp($ocrScore, 1.0, 10.0);
}

function evaluateRecognizability(string $path): int
{
    $imageQuality = evaluateImageQuality($path);
    $ocrText = runTesseract($path);
    $ocrQuality = evaluateOcrQuality($ocrText);

    if ($ocrQuality > 0.0) {
        $final = (0.45 * $imageQuality) + (0.55 * $ocrQuality);
    } else {
        $final = $imageQuality;
    }

    return (int) round(clamp($final, 1.0, 10.0));
}

function makeUniqueFileName(string $dir, string $candidateFileName, string $currentPath): string
{
    $candidatePath = $dir . DIRECTORY_SEPARATOR . $candidateFileName;
    if (!file_exists($candidatePath) || realpath($candidatePath) === realpath($currentPath)) {
        return $candidateFileName;
    }

    $ext = (string) pathinfo($candidateFileName, PATHINFO_EXTENSION);
    $base = (string) pathinfo($candidateFileName, PATHINFO_FILENAME);
    $index = 2;

    while (true) {
        $newFileName = $base . '_v' . $index . ($ext !== '' ? '.' . $ext : '');
        $newPath = $dir . DIRECTORY_SEPARATOR . $newFileName;
        if (!file_exists($newPath) || realpath($newPath) === realpath($currentPath)) {
            return $newFileName;
        }
        $index++;
    }
}

function scoreAndRename(array $records): array
{
    $renamed = [];
    foreach ($records as $record) {
        $score = evaluateRecognizability($record['path']);
        $newBase = $record['base_name_without_score'] . '_ocr' . $score;
        $targetName = $newBase . '.' . $record['ext'];
        $targetName = makeUniqueFileName($record['dir'], $targetName, $record['path']);
        $targetPath = $record['dir'] . DIRECTORY_SEPARATOR . $targetName;

        if ($targetPath !== $record['path']) {
            rename($record['path'], $targetPath);
            $renamed[] = [
                'from' => $record['file_name'],
                'to' => $targetName,
                'country' => $record['country'],
            ];
        }
    }

    return $renamed;
}

function toWebPath(string $absolutePath): string
{
    $relative = ltrim(str_replace(__DIR__, '', $absolutePath), DIRECTORY_SEPARATOR);
    $relative = str_replace('\\', '/', $relative);
    $parts = explode('/', $relative);
    $parts = array_map('rawurlencode', $parts);
    return implode('/', $parts);
}

$records = collectRecords($docsRoot);
$requestRescore = isset($_GET['rescore']) && $_GET['rescore'] === '1';
$missingScores = false;
foreach ($records as $record) {
    if ($record['score_in_name'] === null) {
        $missingScores = true;
        break;
    }
}

$renameLog = [];
$didRename = false;
if ($requestRescore || $missingScores) {
    $renameLog = scoreAndRename($records);
    $didRename = true;
    $records = collectRecords($docsRoot);
}

$grouped = [];
$totalFiles = 0;
foreach ($records as $record) {
    $country = $record['country'];
    $type = $record['type'];
    $score = $record['score_in_name'];
    if ($score === null) {
        $score = evaluateRecognizability($record['path']);
    }

    $record['score'] = $score;
    $record['web_path'] = toWebPath($record['path']);
    $record['display_name'] = $record['type'] . ' / ' . $record['side'];

    $grouped[$country][$type][] = $record;
    $totalFiles++;
}

ksort($grouped, SORT_STRING);
foreach ($grouped as $country => $types) {
    ksort($types, SORT_STRING);
    foreach ($types as $type => $items) {
        usort($items, static function (array $a, array $b): int {
            $bySide = sideSortRank($a['side']) <=> sideSortRank($b['side']);
            if ($bySide !== 0) {
                return $bySide;
            }

            return strcasecmp($a['file_name'], $b['file_name']);
        });
        $grouped[$country][$type] = $items;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documents by Country and Type</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #ffffff;
            --line: #d7dde5;
            --text: #1d2733;
            --muted: #5f6f81;
            --accent: #0f6fbf;
            --ok: #0a7d38;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            width: min(1400px, 96vw);
            margin: 24px auto 48px;
        }

        .toolbar {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            color: var(--muted);
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            border: 1px solid var(--accent);
            color: #fff;
            background: var(--accent);
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .notice {
            margin-top: 12px;
            background: #f3fbf6;
            border: 1px solid #b5e2c4;
            color: var(--ok);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
        }

        .warning {
            margin-top: 12px;
            background: #fff8e6;
            border: 1px solid #f2d18f;
            color: #8a6300;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
        }

        .country {
            margin-top: 20px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 14px 16px;
        }

        .country h2 {
            margin: 0 0 8px;
            font-size: 20px;
            letter-spacing: 0.02em;
        }

        .type-title {
            margin: 16px 0 8px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            border-top: 1px dashed var(--line);
            padding-top: 10px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 260px;
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 10px;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .badge {
            border: 1px solid #cde4f7;
            background: #e9f4fe;
            color: #0d4f86;
            border-radius: 999px;
            padding: 3px 8px;
            font-weight: 700;
            white-space: nowrap;
        }

        .thumb {
            width: 100%;
            height: 180px;
            object-fit: contain;
            background: #fbfcff;
            border-bottom: 1px solid var(--line);
        }

        .card-foot {
            padding: 10px;
            font-size: 12px;
            color: var(--muted);
            word-break: break-all;
        }
    </style>
</head>
<body>
<main class="page">
    <div class="toolbar">
        <div class="meta">
            <div><strong>Total files:</strong> <?= (int) $totalFiles ?></div>
            <div><strong>Countries:</strong> <?= count($grouped) ?></div>
            <div><strong>Tesseract:</strong> <?= isTesseractAvailable() ? 'available' : 'not found (image-only score)' ?></div>
        </div>
        <a class="btn" href="?rescore=1">Rescore + rename files</a>
    </div>

    <?php if ($didRename): ?>
        <div class="notice">
            Rescore finished. Renamed files: <?= count($renameLog) ?>.
        </div>
    <?php endif; ?>

    <?php if (!isTesseractAvailable()): ?>
        <div class="warning">
            Tesseract was not found in PATH. Score is based on image quality only (resolution, contrast, sharpness, exposure).
        </div>
    <?php endif; ?>

    <?php foreach ($grouped as $country => $types): ?>
        <section class="country">
            <h2><?= h($country) ?></h2>
            <?php foreach ($types as $type => $items): ?>
                <h3 class="type-title"><?= h($type) ?></h3>
                <div class="grid">
                    <?php foreach ($items as $item): ?>
                        <article class="card">
                            <div class="card-head">
                                <span><?= h($item['display_name']) ?></span>
                                <span class="badge">OCR <?= (int) $item['score'] ?>/10</span>
                            </div>
                            <img class="thumb" src="<?= h($item['web_path']) ?>" alt="<?= h($item['file_name']) ?>" loading="lazy">
                            <div class="card-foot"><?= h($item['file_name']) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
