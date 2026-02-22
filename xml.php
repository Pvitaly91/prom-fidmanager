<?php
/**
 * One-file PHP script: shows category tree + products inside each category
 * Feed format: YML/Prom-like XML with <category id parentId> and <offer><categoryId>..</categoryId></offer>
 *
 * Put near products_feed.xml or pass ?file=your.xml (filename only).
 *
 * Query params:
 *   ?file=products_feed.xml   (only filename from same folder)
 *   ?limit=50                 (max products shown per category; 0 = show all)
 *   ?img=0                    (disable thumbnails; default ON)
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

$baseDir    = __DIR__;
$feedPath   = $baseDir . DIRECTORY_SEPARATOR . 'kasta.xml';
$feedError  = '';
$postError  = '';

// Filenames used for uploaded / URL-fetched feeds
const UPLOAD_DEST = '_uploaded.xml';
const URL_DEST    = '_url_feed.xml';

/**
 * Returns true if the URL points to a public host (not loopback/internal).
 * Mitigates Server-Side Request Forgery (SSRF).
 * Only blocks addresses we can positively identify as private/reserved;
 * hostnames that don't resolve are allowed (they will fail at fetch time).
 */
function isPublicUrl(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === false || $host === null || $host === '') {
        return false;
    }
    // Reject localhost aliases
    if (in_array(strtolower($host), ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
        return false;
    }
    // If the host is already an IP address, check it directly
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    // Try to resolve; if DNS fails, we can't confirm it's private — allow it
    $ip = gethostbyname($host);
    if ($ip === $host) {
        // Could not resolve — pass through (will fail at fetch time if unreachable)
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// ---- JSON API: search Kasta categories ----
if (isset($_GET['api']) && $_GET['api'] === 'categories') {
    header('Content-Type: application/json; charset=utf-8');
    $q      = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    $qWords = $q !== '' ? preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) : [];
    $file   = $baseDir . '/kasta_categories.json';
    if (!is_file($file)) {
        http_response_code(500);
        echo json_encode(['error' => 'kasta_categories.json not found']);
        exit;
    }
    $raw  = file_get_contents($file);
    $cats = $raw !== false ? (json_decode($raw, true) ?? []) : [];
    $results = [];
    foreach ($cats as $cat) {
        $text = mb_strtolower(
            implode(' ', [$cat['affiliation'], $cat['group'], $cat['subgroup'], $cat['kind']]),
            'UTF-8'
        );
        $match = true;
        foreach ($qWords as $w) {
            if (mb_strpos($text, $w, 0, 'UTF-8') === false) { $match = false; break; }
        }
        if ($match) {
            $results[] = $cat;
            if (count($results) >= 20) break;
        }
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- JSON API: assign Kasta category to offer ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_category') {
    header('Content-Type: application/json; charset=utf-8');
    $offerId = trim($_POST['offer_id'] ?? '');
    $catId   = (int)($_POST['cat_id'] ?? 0);
    if ($offerId === '' || $catId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing params']);
        exit;
    }
    $kastaCatsFile = $baseDir . '/kasta_categories.json';
    $mappingFile   = $baseDir . '/category_mapping.json';
    $catsRaw = is_file($kastaCatsFile) ? @file_get_contents($kastaCatsFile) : false;
    if ($catsRaw === false) {
        echo json_encode(['ok' => false, 'error' => 'kasta_categories.json not found']);
        exit;
    }
    $cats    = json_decode($catsRaw, true) ?? [];
    $catById = [];
    foreach ($cats as $c) { $catById[$c['id']] = $c; }
    if (!isset($catById[$catId])) {
        echo json_encode(['ok' => false, 'error' => 'Unknown category id']);
        exit;
    }
    $mapping = [];
    if (is_file($mappingFile)) {
        $raw = @file_get_contents($mappingFile);
        if ($raw !== false && $raw !== '') $mapping = json_decode($raw, true) ?? [];
    }
    $cat = $catById[$catId];
    $mapping[$offerId] = [
        'kasta_category_id' => $catId,
        'affiliation'       => $cat['affiliation'],
        'group'             => $cat['group'],
        'subgroup'          => $cat['subgroup'],
        'kind'              => $cat['kind'],
        'auto_mapped'       => false,
        'mapped_at'         => date('Y-m-d'),
    ];
    try {
        $jsonOut = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        echo json_encode(['ok' => false, 'error' => 'JSON encode error: ' . $e->getMessage()]);
        exit;
    }
    if (file_put_contents($mappingFile, $jsonOut, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write mapping file']);
        exit;
    }
    echo json_encode(['ok' => true, 'cat' => $cat], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- JSON API: toggle exclude offer/category from kasta.xml ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_exclude') {
    header('Content-Type: application/json; charset=utf-8');
    $type = $_POST['type'] ?? '';          // 'offer' or 'category'
    $id   = trim($_POST['id']   ?? '');
    if (!in_array($type, ['offer', 'category'], true) || $id === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing params']);
        exit;
    }
    $excludedFile = $baseDir . '/excluded_items.json';
    $exc = ['offers' => [], 'categories' => []];
    if (is_file($excludedFile)) {
        $raw = @file_get_contents($excludedFile);
        if ($raw !== false && $raw !== '') $exc = json_decode($raw, true) ?? $exc;
    }
    $key = ($type === 'offer') ? 'offers' : 'categories';
    $set = array_flip($exc[$key] ?? []);
    $nowExcluded = !isset($set[$id]);   // toggle
    if ($nowExcluded) {
        $set[$id] = true;
    } else {
        unset($set[$id]);
    }
    $exc[$key] = array_values(array_map('strval', array_keys($set)));
    try {
        $jsonOut = json_encode($exc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        echo json_encode(['ok' => false, 'error' => 'JSON error: ' . $e->getMessage()]);
        exit;
    }
    if (file_put_contents($excludedFile, $jsonOut, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'error' => 'Cannot write excluded_items.json']);
        exit;
    }
    echo json_encode([
        'ok'         => true,
        'excluded'   => $nowExcluded,
        'offer_count' => count($exc['offers']),
        'cat_count'   => count($exc['categories']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- JSON API: run prom_to_kasta conversion in background ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_async') {
    header('Content-Type: application/json; charset=utf-8');
    $inputName       = basename(trim($_POST['input_file'] ?? 'products_feed.xml'));
    $inputPath       = $baseDir . DIRECTORY_SEPARATOR . $inputName;
    $outputPath      = $baseDir . DIRECTORY_SEPARATOR . 'kasta.xml';
    $converterScript = $baseDir . DIRECTORY_SEPARATOR . 'prom_to_kasta.php';
    if (!is_file($converterScript)) {
        echo json_encode(['ok' => false, 'error' => 'prom_to_kasta.php не знайдено']);
        exit;
    }
    if (!is_file($inputPath)) {
        echo json_encode(['ok' => false, 'error' => 'Файл не знайдено: ' . $inputName]);
        exit;
    }
    $cmd = 'php ' . escapeshellarg($converterScript)
         . ' ' . escapeshellarg($inputPath)
         . ' ' . escapeshellarg($outputPath);
    exec($cmd . ' 2>&1', $cmdOut, $ret);
    if ($ret === 0) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => implode(' | ', array_slice($cmdOut, 0, 3))]);
    }
    exit;
}

// ---- JSON API: bulk assign Kasta category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_bulk') {
    header('Content-Type: application/json; charset=utf-8');
    $offerIdsRaw = trim($_POST['offer_ids'] ?? '');
    $catId       = (int)($_POST['cat_id'] ?? 0);
    if ($offerIdsRaw === '' || $catId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing params']);
        exit;
    }
    $offerIds = array_values(array_filter(array_map('trim', explode(',', $offerIdsRaw))));
    if (empty($offerIds)) {
        echo json_encode(['ok' => false, 'error' => 'No offer IDs provided']);
        exit;
    }
    $kastaCatsFile = $baseDir . '/kasta_categories.json';
    $mappingFile   = $baseDir . '/category_mapping.json';
    $catsRaw = is_file($kastaCatsFile) ? @file_get_contents($kastaCatsFile) : false;
    if ($catsRaw === false) {
        echo json_encode(['ok' => false, 'error' => 'kasta_categories.json not found']);
        exit;
    }
    $cats    = json_decode($catsRaw, true) ?? [];
    $catById = [];
    foreach ($cats as $c) { $catById[$c['id']] = $c; }
    if (!isset($catById[$catId])) {
        echo json_encode(['ok' => false, 'error' => 'Unknown category id']);
        exit;
    }
    $mapping = [];
    if (is_file($mappingFile)) {
        $raw = @file_get_contents($mappingFile);
        if ($raw !== false && $raw !== '') $mapping = json_decode($raw, true) ?? [];
    }
    $cat   = $catById[$catId];
    $today = date('Y-m-d');
    foreach ($offerIds as $oid) {
        $mapping[$oid] = [
            'kasta_category_id' => $catId,
            'affiliation'       => $cat['affiliation'],
            'group'             => $cat['group'],
            'subgroup'          => $cat['subgroup'],
            'kind'              => $cat['kind'],
            'auto_mapped'       => false,
            'mapped_at'         => $today,
        ];
    }
    try {
        $jsonOut = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        echo json_encode(['ok' => false, 'error' => 'JSON encode error: ' . $e->getMessage()]);
        exit;
    }
    if (file_put_contents($mappingFile, $jsonOut, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write mapping file']);
        exit;
    }
    echo json_encode(['ok' => true, 'cat' => $cat, 'count' => count($offerIds)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST: handle file upload / URL fetch / conversion ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (isset($_FILES['xmlfile']) && $_FILES['xmlfile']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['xmlfile']['tmp_name'];
            if (@simplexml_load_file($tmpName) !== false) {
                $destFile = $baseDir . DIRECTORY_SEPARATOR . UPLOAD_DEST;
                if (move_uploaded_file($tmpName, $destFile)) {
                    header('Location: ?file=' . UPLOAD_DEST);
                    exit;
                }
                $postError = 'Не вдалося зберегти файл.';
            } else {
                $postError = 'Файл не є валідним XML.';
            }
        } else {
            $codes = [
                UPLOAD_ERR_INI_SIZE  => 'Файл перевищує upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'Файл перевищує MAX_FILE_SIZE форми.',
                UPLOAD_ERR_NO_FILE   => 'Файл не вибрано.',
            ];
            $errCode   = $_FILES['xmlfile']['error'] ?? UPLOAD_ERR_NO_FILE;
            $postError = $codes[$errCode] ?? 'Помилка завантаження файлу (код ' . (int)$errCode . ').';
        }

    } elseif ($action === 'url') {
        $url = trim($_POST['feed_url'] ?? '');
        if (!preg_match('/^https?:\/\/.+/i', $url)) {
            $postError = 'Некоректний URL. Вкажіть повну адресу http:// або https://';
        } elseif (!isPublicUrl($url)) {
            $postError = 'URL не допускається: дозволені лише публічні адреси.';
        } else {
            $ctx     = stream_context_create(['http' => [
                'timeout'    => 30,
                'user_agent' => 'Mozilla/5.0 (compatible; FeedViewer/1.0)',
            ]]);
            $content = @file_get_contents($url, false, $ctx);
            if ($content !== false && $content !== '') {
                if (@simplexml_load_string($content) !== false) {
                    $destFile = $baseDir . DIRECTORY_SEPARATOR . URL_DEST;
                    if (file_put_contents($destFile, $content, LOCK_EX) !== false) {
                        header('Location: ?file=' . URL_DEST);
                        exit;
                    }
                    $postError = 'Не вдалося зберегти файл.';
                } else {
                    $postError = 'URL не повертає валідний XML-файл.';
                }
            } else {
                $postError = 'Не вдалося завантажити вміст URL.';
            }
        }

    } elseif ($action === 'convert') {
        $inputName       = basename(trim($_POST['input_file'] ?? 'products_feed.xml'));
        $inputPath       = $baseDir . DIRECTORY_SEPARATOR . $inputName;
        $remap           = !empty($_POST['remap']);
        $converterScript = $baseDir . DIRECTORY_SEPARATOR . 'prom_to_kasta.php';
        if (!is_file($converterScript)) {
            $postError = 'Скрипт prom_to_kasta.php не знайдено.';
        } elseif (!is_file($inputPath)) {
            $postError = 'Вхідний файл не знайдено: ' . $inputName;
        } else {
            $outputPath = $baseDir . DIRECTORY_SEPARATOR . 'kasta.xml';
            $cmd = 'php ' . escapeshellarg($converterScript)
                 . ' ' . escapeshellarg($inputPath)
                 . ' ' . escapeshellarg($outputPath)
                 . ($remap ? ' --remap' : '');
            exec($cmd . ' 2>&1', $cmdOutput, $retCode);
            if ($retCode === 0) {
                header('Location: ?file=kasta.xml&converted=1');
                exit;
            }
            $postError = 'Помилка конвертації: ' . implode(' | ', array_slice($cmdOutput, 0, 3));
        }
    }
}

// ---- Resolve feed path ----
if (isset($_GET['file'])) {
    $name      = basename((string)$_GET['file']); // prevent path traversal
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($candidate)) {
        $feedPath = $candidate;
    }
}

$maxPerCat  = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : 50;
// default ON; disable only if img=0
$showImages = !isset($_GET['img']) || (string)$_GET['img'] !== '0';

if (!is_file($feedPath) || !is_readable($feedPath)) {
    $feedError = 'Файл не знайдено або недоступний: ' . basename($feedPath);
}

// XML files available in the directory (for datalist in convert form)
$availableXml = array_values(array_filter(
    array_map('basename', glob($baseDir . '/*.xml') ?: []),
    fn($f) => !in_array($f, [UPLOAD_DEST, URL_DEST], true)
));

// Load category mapping for display and editing
$mappingFile = $baseDir . '/category_mapping.json';
$mapping = [];
if (is_file($mappingFile) && is_readable($mappingFile)) {
    $raw = @file_get_contents($mappingFile);
    if ($raw !== false && $raw !== '') $mapping = json_decode($raw, true) ?? [];
}

// Load excluded items
$excludedFile = $baseDir . '/excluded_items.json';
$excluded = ['offers' => [], 'categories' => []];
if (is_file($excludedFile) && is_readable($excludedFile)) {
    $raw = @file_get_contents($excludedFile);
    if ($raw !== false && $raw !== '') $excluded = json_decode($raw, true) ?? $excluded;
}
$excludedOfferSet = array_flip($excluded['offers'] ?? []);
$excludedCatSet   = array_flip($excluded['categories'] ?? []);

// Collect which Kasta category IDs are already used in this mapping
$usedKastaCatIds = [];
foreach ($mapping as $entry) {
    if (isset($entry['kasta_category_id'])) {
        $usedKastaCatIds[(int)$entry['kasta_category_id']] = true;
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Parse the feed using XMLReader for speed/memory efficiency.
 * Returns: [$categories, $productsByCat]
 *   $categories: id => ['id'=>, 'parentId'=>?, 'name'=>, 'children'=>[]]
 *   $productsByCat: catId => [ [id,name,url,price,currency,picture], ... ]
 */
function parseFeed(string $feedPath): array {
    $categories = [];
    $productsByCat = [];

    $reader = new XMLReader();
    if (!$reader->open($feedPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Cannot open XML feed.');
    }

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        // Categories
        if ($reader->name === 'category') {
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;

            $cat = @simplexml_load_string($xml);
            if ($cat === false) continue;

            $id = trim((string)$cat['id']);
            if ($id === '') continue;

            $parentId = trim((string)$cat['parentId']);
            $parentId = $parentId !== '' ? $parentId : null;

            $name = trim((string)$cat);

            // NOTE: if $id is "123" PHP may store array key as int(123) automatically — that's OK.
            $categories[$id] = [
                'id' => $id,
                'parentId' => $parentId,
                'name' => $name,
                'children' => [],
            ];
            continue;
        }

        // Offers / Products
        if ($reader->name === 'offer') {
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;

            $offer = @simplexml_load_string($xml);
            if ($offer === false) continue;

            // categoryId can exist 1..n times; take first
            $catId = '';
            if (isset($offer->categoryId)) {
                $catId = trim((string)$offer->categoryId[0]);
            }
            if ($catId === '') continue;

            // name fallbacks
            $name = '';
            if (isset($offer->name))  $name = trim((string)$offer->name);
            if ($name === '' && isset($offer->model)) $name = trim((string)$offer->model);
            if ($name === '' && isset($offer->title)) $name = trim((string)$offer->title);

            // url fallbacks
            $url = '';
            if (isset($offer->url))  $url = trim((string)$offer->url);
            if ($url === '' && isset($offer->link)) $url = trim((string)$offer->link);

            // picture fallbacks (first image)
            $picture = '';
            if (isset($offer->picture[0])) $picture = trim((string)$offer->picture[0]);
            if ($picture === '' && isset($offer->images->image[0])) $picture = trim((string)$offer->images->image[0]);
            if ($picture === '' && isset($offer->image[0])) $picture = trim((string)$offer->image[0]); // some feeds

            $price = isset($offer->price) ? trim((string)$offer->price) : '';
            $currency = isset($offer->currencyId) ? trim((string)$offer->currencyId) : '';

            $vendor = isset($offer->vendor) ? trim((string)$offer->vendor) : '';
            $desc   = isset($offer->description)
                ? trim(preg_replace('/\s+/', ' ', strip_tags((string)$offer->description)))
                : '';

            $p = [
                'id' => trim((string)$offer['id']),
                'name' => $name,
                'url' => $url,
                'price' => $price,
                'currency' => $currency,
                'picture' => $picture,
                'vendor' => $vendor,
                'desc'   => $desc,
            ];

            $productsByCat[$catId][] = $p;
            continue;
        }
    }

    $reader->close();
    return [$categories, $productsByCat];
}

/** Build children links and return list of root IDs. */
function buildTree(array &$categories): array {
    $roots = [];

    foreach ($categories as $id => &$cat) {
        $cat['children'] = [];
    }
    unset($cat);

    foreach ($categories as $id => &$cat) {
        $pid = $cat['parentId'];
        if ($pid !== null && $pid !== '' && isset($categories[$pid]) && $pid !== $id) {
            $categories[$pid]['children'][] = $id; // $id can be int|string
        } else {
            $roots[] = $id;
        }
    }
    unset($cat);

    foreach ($categories as $id => &$cat) {
        usort($cat['children'], function($a, $b) use ($categories) {
            return strcmp($categories[$a]['name'] ?? '', $categories[$b]['name'] ?? '');
        });
    }
    unset($cat);

    usort($roots, function($a, $b) use ($categories) {
        return strcmp($categories[$a]['name'] ?? '', $categories[$b]['name'] ?? '');
    });

    return $roots;
}

/**
 * Build the searchable text for a product offer:
 * name + vendor (if any) + description (if any), space-joined, HTML-escaped.
 *
 * @param  array  $p  Product array with keys: name, vendor, desc (all optional)
 * @return string     HTML-attribute-safe searchable string
 */
function offerSearchText(array $p): string
{
    $parts = array_filter([
        trim((string)($p['name'] ?? '')),
        trim((string)($p['vendor'] ?? '')),
        trim((string)($p['desc'] ?? '')),
    ]);
    return h(implode(' ', $parts));
}

/**
 * IMPORTANT: allow int|string ids (because numeric-string keys become int in PHP arrays)
 */
function computeTotals(array $rootIds, array $categories, array $productsByCat): array {
    $memo = [];

    $directCount = function(int|string $id) use ($productsByCat): int {
        return isset($productsByCat[$id]) ? count($productsByCat[$id]) : 0;
    };

    $dfs = function(int|string $id) use (&$dfs, &$memo, $categories, $directCount): int {
        if (isset($memo[$id])) return $memo[$id];
        $sum = $directCount($id);
        foreach (($categories[$id]['children'] ?? []) as $childId) {
            $sum += $dfs($childId);
        }
        return $memo[$id] = $sum;
    };

    foreach ($rootIds as $rid) {
        $dfs($rid);
    }

    return $memo;
}

/**
 * Render the Kasta-category assignment badge + edit button for a single offer.
 * @param string $pId    offer id
 * @param array  $mapping  full category_mapping data
 */
function renderOfferMapping(string $pId, array $mapping): void
{
    $m = $mapping[$pId] ?? null;
    echo '<div class="kcat-row" data-offer="' . h($pId) . '">';
    echo '🏷 ';
    if ($m) {
        $auto = !empty($m['auto_mapped']) ? ' <span class="kcat-auto" title="Автоматично призначено">авто</span>' : '';
        echo '<span class="kcat-label" title="' . h($m['affiliation'] . ' › ' . $m['group'] . ' › ' . $m['subgroup']) . '">'
           . h($m['kind']) . $auto . '</span>';
    } else {
        echo '<span class="kcat-label kcat-none">— не призначено —</span>';
    }
    echo ' <button class="btn-kcat-edit" type="button"'
       . ' onclick="openCatPicker(' . htmlspecialchars(json_encode($pId, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ',this)"'
       . ' title="Змінити категорію Каста">✏️</button>';
    echo '</div>';
}

/** Render category subtree. */
function renderCategory(
    int|string $id,
    array $categories,
    array $productsByCat,
    array $totals,
    array $mapping,
    array $excludedOfferSet,
    array $excludedCatSet,
    int $maxPerCat,
    bool $showImages,
    int $depth = 0
): void {
    $cat = $categories[$id] ?? null;
    if (!$cat) return;

    $name = $cat['name'] ?? ('#' . (string)$id);
    $direct = isset($productsByCat[$id]) ? count($productsByCat[$id]) : 0;
    $total = $totals[$id] ?? $direct;

    $hasChildren = !empty($cat['children']);
    $hasProducts = $direct > 0;
    $open = ($depth < 2) ? ' open' : '';
    $isExclCat = isset($excludedCatSet[(string)$id]);

    echo '<details class="cat' . ($isExclCat ? ' cat-excl' : '') . '" data-cat-id="' . h((string)$id) . '"' . $open . '>';
    echo '<summary>';
    echo '<span class="cat-name">' . h((string)$name) . '</span>';
    echo ' <span class="meta">(' . $direct . ' / ' . $total . ')</span>';
    echo ' <span class="meta-id">#' . h((string)$id) . '</span>';
    $exclCatTitle = $isExclCat ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста';
    echo ' <button class="btn-excl excl-btn-cat' . ($isExclCat ? ' active' : '') . '" type="button"'
       . ' data-cat="' . h((string)$id) . '"'
       . ' onclick="event.stopPropagation();toggleExclude(\'category\',' . htmlspecialchars(json_encode((string)$id, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ',this)"'
       . ' title="' . h($exclCatTitle) . '">🚫</button>';
    echo '</summary>';

    if ($hasProducts) {
        $items = $productsByCat[$id];
        usort($items, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        $shown = 0;
        echo '<ul class="products">';
        foreach ($items as $p) {
            $shown++;
            if ($maxPerCat > 0 && $shown > $maxPerCat) break;

            $pName  = (string)($p['name'] ?? '');
            $pUrl   = (string)($p['url'] ?? '');
            $pPrice = (string)($p['price'] ?? '');
            $pCur   = (string)($p['currency'] ?? '');
            $pPic   = (string)($p['picture'] ?? '');
            $pId    = (string)($p['id'] ?? '');
            $isExclOffer = isset($excludedOfferSet[$pId]);
            $pSearch = offerSearchText($p);

            echo '<li class="product' . ($isExclOffer ? ' excl-item' : '') . '" data-search="' . $pSearch . '">';
            echo '<label class="product-cb-wrap" title="Обрати товар"><input type="checkbox" class="product-cb" data-offer="' . h($pId) . '"></label>';
            if ($showImages) {
                if ($pPic !== '') {
                    // thumbnail links to full image
                    echo '<a class="thumb-wrap" target="_blank" rel="noopener" href="' . h($pPic) . '" title="Відкрити фото">';
                    echo '<img class="thumb" loading="lazy" referrerpolicy="no-referrer" src="' . h($pPic) . '" alt="">';
                    echo '</a>';
                } else {
                    echo '<div class="thumb thumb-ph" title="Фото відсутнє">—</div>';
                }
            }

            echo '<div class="pinfo">';
            if ($pUrl !== '') {
                echo '<a class="plink" target="_blank" rel="noopener" href="' . h($pUrl) . '">' . h($pName) . '</a>';
            } else {
                echo '<span class="plink">' . h($pName) . '</span>';
            }
            if ($pPrice !== '') {
                echo '<div class="price">' . h(trim($pPrice . ' ' . $pCur)) . '</div>';
            }
            if ($pId !== '') {
                echo '<div class="pid">offer#' . h($pId) . '</div>';
            }
            renderOfferMapping($pId, $mapping);
            $exclOfferTitle = $isExclOffer ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста';
            echo '<button class="btn-excl excl-btn-offer' . ($isExclOffer ? ' active' : '') . '" type="button"'
               . ' data-offer="' . h($pId) . '"'
               . ' onclick="toggleExclude(\'offer\',' . htmlspecialchars(json_encode($pId, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ',this)"'
               . ' title="' . h($exclOfferTitle) . '">🚫</button>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';

        if ($maxPerCat > 0 && $direct > $maxPerCat) {
            $more = $direct - $maxPerCat;
            $qs = $_GET;
            $qs['limit'] = 0;
            $urlAll = '?' . http_build_query($qs);
            echo '<div class="note">Показано ' . $maxPerCat . ' з ' . $direct . ' товарів. ';
            echo '<a href="' . h($urlAll) . '">Показати всі</a> (+' . $more . ')</div>';
        }
    }

    if ($hasChildren) {
        echo '<div class="children">';
        foreach ($cat['children'] as $childId) {
            renderCategory($childId, $categories, $productsByCat, $totals, $mapping, $excludedOfferSet, $excludedCatSet, $maxPerCat, $showImages, $depth + 1);
        }
        echo '</div>';
    }

    echo '</details>';
}

// ----------------- Run -----------------
$categories = $productsByCat = [];
$rootIds = $totals = $unknownProducts = [];
$catsCount = $offersCount = $unknownCount = 0;

if (!$feedError) {
    try {
        [$categories, $productsByCat] = parseFeed($feedPath);
        $rootIds = buildTree($categories);
        $totals  = computeTotals($rootIds, $categories, $productsByCat);

        $unknownProducts = [];
        foreach ($productsByCat as $cid => $list) {
            if (!isset($categories[$cid])) {
                $unknownProducts[$cid] = $list;
            }
        }

        $catsCount = count($categories);
        foreach ($productsByCat as $arr) $offersCount += count($arr);
        foreach ($unknownProducts as $arr) $unknownCount += count($arr);
    } catch (Throwable $e) {
        $feedError = 'Помилка: ' . $e->getMessage();
    }
}

// Build flat offer-id → product data index (for excluded view)
$productById = [];
if (!$feedError) {
    foreach ($productsByCat as $catId => $products) {
        foreach ($products as $p) {
            if (!empty($p['id'])) {
                $productById[$p['id']] = $p;
            }
        }
    }
}

// Current feed file for auto-convert (avoid looping kasta.xml → kasta.xml)
$autoConvertInput = (basename($feedPath) === 'kasta.xml' || $feedError !== '')
    ? 'products_feed.xml'
    : basename($feedPath);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Категорії та товари з фіда</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 16px; line-height: 1.35; }
    .topbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom: 12px; }
    .topbar .pill { padding: 6px 10px; border: 1px solid #ddd; border-radius: 999px; background: #fafafa; }
    .topbar a { color: inherit; text-decoration: none; }
    .topbar a:hover { text-decoration: underline; }
    details.cat { margin: 6px 0; padding-left: 12px; border-left: 2px solid #eee; }
    details.cat > summary { cursor:pointer; padding: 6px 4px; list-style: none; }
    details.cat > summary::-webkit-details-marker { display:none; }
    .cat-name { font-weight: 600; }
    .meta { color:#666; font-size: 12px; margin-left: 6px; }
    .meta-id { color:#999; font-size: 12px; margin-left: 6px; }
    .children { margin-left: 10px; }

    ul.products { margin: 6px 0 10px 0; padding-left: 0; }
    li.product {
      display:flex;
      gap:10px;
      margin: 8px 0;
      align-items:flex-start;
      list-style: none;
      padding: 6px 8px;
      border: 1px solid #f0f0f0;
      border-radius: 10px;
      background: #fff;
    }

    .thumb-wrap { display:block; flex: 0 0 auto; }
    .thumb {
      width: 64px;
      height: 64px;
      object-fit: cover;
      border-radius: 10px;
      border:1px solid #eee;
      background:#f5f5f5;
      flex: 0 0 auto;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#999;
      font-size:12px;
      user-select:none;
    }
    .thumb-ph { background: #fafafa; }

    .pinfo { flex: 1 1 0; min-width: 0; }
    .plink { display:block; color:#0b57d0; text-decoration:none; word-break: break-word; font-weight:600; }
    .plink:hover { text-decoration:underline; }
    .price { color:#111; font-size: 12px; margin-top: 4px; }
    .pid { color:#999; font-size: 11px; margin-top: 2px; }
    .note { color:#666; font-size: 12px; margin: 4px 0 10px 0; }
    .muted { color:#666; }

    /* ---- Source panel ---- */
    .panel { background:#f0f6ff; border:1px solid #c8d8f0; border-radius:12px; padding:12px 16px; margin-bottom:14px; }
    .panel > summary { cursor:pointer; font-weight:600; font-size:14px; color:#1a56a0; list-style:none; user-select:none; }
    .panel > summary::-webkit-details-marker { display:none; }
    .tabs { display:flex; gap:0; margin:10px 0 0; border-bottom:2px solid #c8d8f0; flex-wrap:wrap; }
    .tab-btn { padding:6px 14px; border:none; background:transparent; cursor:pointer; font-size:13px; color:#555; border-bottom:3px solid transparent; margin-bottom:-2px; }
    .tab-btn.active { color:#1a56a0; border-bottom-color:#1a56a0; font-weight:600; }
    .tab-pane { display:none; padding:10px 0 4px; }
    .tab-pane.active { display:block; }
    .form-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .form-row label { font-size:13px; color:#333; white-space:nowrap; }
    .form-row input[type=text], .form-row input[type=url] { flex:1; min-width:220px; padding:5px 8px; border:1px solid #bbc; border-radius:6px; font-size:13px; }
    .form-row input[type=file] { font-size:13px; }
    .btn { padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; white-space:nowrap; }
    .btn-primary { background:#1a56a0; color:#fff; }
    .btn-primary:hover { background:#154080; }
    .btn-convert { background:#0a7c3c; color:#fff; }
    .btn-convert:hover { background:#085e2d; }
    .msg-error   { color:#a00; background:#fff0f0; border:1px solid #fcc; border-radius:6px; padding:6px 10px; font-size:13px; margin-top:8px; }
    .msg-success { color:#0a7c3c; background:#f0fff4; border:1px solid #b2dfcc; border-radius:6px; padding:6px 10px; font-size:13px; margin-top:8px; }

    /* ---- Kasta category assignment widget ---- */
    .kcat-row { display:flex; align-items:center; gap:5px; margin-top:4px; font-size:12px; flex-wrap:wrap; }
    .kcat-label { color:#555; cursor:default; }
    .kcat-label:not(.kcat-none) { color:#0a5c2e; font-weight:600; }
    .kcat-none { color:#aaa; font-style:italic; }
    .kcat-auto { display:inline-block; background:#e8f3ff; color:#1a56a0; border-radius:4px; padding:0 4px; font-size:10px; font-weight:600; vertical-align:middle; margin-left:3px; }
    .btn-kcat-edit { background:none; border:none; cursor:pointer; padding:1px 4px; font-size:13px; opacity:0.5; }
    .btn-kcat-edit:hover { opacity:1; }

    /* ---- Product checkbox ---- */
    .product-cb-wrap { flex:0 0 auto; display:flex; align-items:center; padding-top:2px; }
    .product-cb { width:16px; height:16px; cursor:pointer; accent-color:#1a56a0; }
    li.product.selected { background:#eef5ff; border-color:#b0c8f0; }

    /* ---- Bulk action bar ---- */
    #bulk-bar { display:none; position:sticky; bottom:16px; left:0; right:0; z-index:500;
                background:#1a56a0; color:#fff; border-radius:10px; padding:10px 16px;
                margin:12px 0; box-shadow:0 4px 16px rgba(0,0,0,.25);
                align-items:center; gap:10px; flex-wrap:wrap; }
    #bulk-bar.visible { display:flex; }
    #bulk-count { font-weight:700; font-size:14px; flex:1; }
    #bulk-bar .btn-bulk { padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
    .btn-bulk-assign { background:#fff; color:#1a56a0; }
    .btn-bulk-assign:hover { background:#e8f3ff; }
    .btn-bulk-clear { background:rgba(255,255,255,.2); color:#fff; }
    .btn-bulk-clear:hover { background:rgba(255,255,255,.35); }

    /* ---- Live search bar ---- */
    .live-search-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .ls-group { display:flex; align-items:center; gap:4px; background:#fff; border:1px solid #ccd; border-radius:8px; padding:4px 8px; flex:1; min-width:220px; }
    .ls-group:focus-within { border-color:#1a56a0; box-shadow:0 0 0 2px rgba(26,86,160,.15); }
    .ls-icon { font-size:14px; }
    .ls-group input[type=search] { border:none; outline:none; flex:1; font-size:13px; min-width:120px; background:transparent; }
    .ls-group input[type=search]::-webkit-search-cancel-button { display:none; }
    .ls-clear { background:none; border:none; cursor:pointer; color:#bbb; font-size:18px; line-height:1; padding:0 2px; font-weight:400; }
    .ls-clear:hover { color:#555; }
    .ls-count { font-size:12px; color:#666; white-space:nowrap; }
    .filter-hidden { display:none !important; }

    /* ---- Category picker modal ---- */
    #cat-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
    #cat-modal-box { background:#fff; border-radius:12px; padding:20px; width:min(540px,95vw); max-height:80vh; display:flex; flex-direction:column; box-shadow:0 8px 32px rgba(0,0,0,.25); }
    #cat-modal-box h3 { margin:0 0 12px; font-size:16px; color:#1a56a0; }
    #cat-modal-name { font-size:13px; color:#555; margin-bottom:10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #cat-search { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #bbc; border-radius:8px; font-size:14px; outline:none; }
    #cat-search:focus { border-color:#1a56a0; }
    #cat-results { flex:1; overflow-y:auto; margin-top:8px; list-style:none; padding:0; }
    #cat-results li.cat-result-item { padding:8px 10px; border-radius:8px; cursor:pointer; border-bottom:1px solid #f0f0f0; }
    #cat-results li.cat-result-item:hover { background:#f0f6ff; }
    #cat-results li.cat-result-item strong { font-size:13px; color:#111; }
    #cat-results li.cat-result-item small { color:#888; font-size:11px; }
    #cat-results li.cat-result-item.used { background:#f0fff4; border-left:3px solid #0a7c3c; padding-left:7px; }
    #cat-results li.cat-result-item.used strong { color:#0a5c2e; }
    .cat-used-badge { display:inline-block; background:#0a7c3c; color:#fff; border-radius:4px; padding:0 5px; font-size:10px; font-weight:700; margin-left:5px; vertical-align:middle; }
    #cat-modal-footer { display:flex; justify-content:flex-end; margin-top:12px; }
    #cat-modal-footer button { padding:7px 18px; border:none; border-radius:8px; cursor:pointer; font-size:13px; background:#eee; }
    #cat-modal-footer button:hover { background:#ddd; }

    /* ---- Exclude button ---- */
    .btn-excl { background:none; border:none; cursor:pointer; padding:1px 4px; font-size:13px; opacity:0.35; line-height:1; }
    .btn-excl:hover { opacity:0.85; }
    .btn-excl.active { opacity:1; filter: none; }
    /* Excluded product row */
    li.product.excl-item { opacity:0.5; border-color:#fcc; background:#fff5f5; }
    /* Excluded category */
    details.cat.cat-excl > summary .cat-name { text-decoration:line-through; color:#aaa; }
    details.cat.cat-excl { border-left-color:#fcc; }

    /* ---- View tabs ---- */
    .view-tabs { display:flex; gap:6px; margin-bottom:10px; align-items:center; flex-wrap:wrap; }
    .view-tab { padding:5px 14px; border:1px solid #ccd; border-radius:8px; background:#fafafa; cursor:pointer; font-size:13px; color:#444; }
    .view-tab.active { background:#1a56a0; color:#fff; border-color:#1a56a0; font-weight:600; }
    .view-tab .badge { display:inline-block; background:#e55; color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:4px; }
    .view-tab.active .badge { background:#fff; color:#1a56a0; }

    /* ---- Excluded view ---- */
    #view-excluded { padding: 4px 0; }
    #view-excluded h3 { font-size:14px; color:#888; margin:16px 0 6px; }
    #view-excluded .excl-empty { color:#aaa; font-size:13px; font-style:italic; margin:8px 0; }
    .excl-item-entry { display:flex; gap:10px; align-items:center; padding:6px 10px; margin:4px 0;
                        border:1px solid #fcc; border-radius:8px; background:#fff5f5; flex-wrap:wrap; }
    .excl-item-entry .excl-name { flex:1; font-size:13px; color:#333; word-break:break-word; }
    .excl-item-entry .excl-sub { font-size:11px; color:#888; }
    .btn-restore { padding:4px 10px; border:none; border-radius:6px; cursor:pointer; font-size:12px;
                   background:#0a7c3c; color:#fff; white-space:nowrap; }
    .btn-restore:hover { background:#085e2d; }

    /* ---- Auto-convert toast ---- */
    #convert-toast { position:fixed; bottom:24px; right:20px; z-index:2000; padding:10px 18px;
                     border-radius:10px; font-size:13px; font-weight:600; box-shadow:0 4px 16px rgba(0,0,0,.25);
                     pointer-events:none; transition:opacity .3s; opacity:0; }
    #convert-toast.toast-loading { background:#1a56a0; color:#fff; opacity:1; }
    #convert-toast.toast-ok      { background:#0a7c3c; color:#fff; opacity:1; }
    #convert-toast.toast-err     { background:#c00;    color:#fff; opacity:1; }
  </style>
</head>
<body>

<!-- ===== Auto-convert toast ===== -->
<div id="convert-toast" aria-live="polite"></div>

  <!-- ===== Source / Conversion panel ===== -->
  <details class="panel" <?php echo ($feedError !== '' || $postError !== '') ? 'open' : ''; ?>>
    <summary>📁 Джерело фіду &amp; конвертація</summary>

    <div class="tabs">
      <button class="tab-btn active" type="button" onclick="showTab('upload',this)">📂 Файл з ПК</button>
      <button class="tab-btn"        type="button" onclick="showTab('url',this)">🔗 URL</button>
      <button class="tab-btn"        type="button" onclick="showTab('convert',this)">⚙ Конвертувати Prom→Kasta</button>
    </div>

    <!-- Tab: upload from PC -->
    <div class="tab-pane active" id="tab-upload">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">
        <div class="form-row">
          <label>XML-файл:</label>
          <input type="file" name="xmlfile" accept=".xml,text/xml,application/xml" required>
          <button type="submit" class="btn btn-primary">Завантажити</button>
        </div>
      </form>
    </div>

    <!-- Tab: load from URL -->
    <div class="tab-pane" id="tab-url">
      <form method="post">
        <input type="hidden" name="action" value="url">
        <div class="form-row">
          <label>URL фіду:</label>
          <input type="url" name="feed_url" placeholder="https://example.com/feed.xml" required>
          <button type="submit" class="btn btn-primary">Завантажити</button>
        </div>
      </form>
    </div>

    <!-- Tab: run prom_to_kasta conversion -->
    <div class="tab-pane" id="tab-convert">
      <form method="post">
        <input type="hidden" name="action" value="convert">
        <div class="form-row">
          <label>Вхідний файл Prom:</label>
          <input type="text" name="input_file" value="products_feed.xml" list="xml-files-list" required>
          <datalist id="xml-files-list">
            <?php foreach ($availableXml as $xf): ?>
              <option value="<?php echo h($xf); ?>">
            <?php endforeach; ?>
          </datalist>
          <label title="Перезапустити автоматичний мапінг категорій для всіх товарів">
            <input type="checkbox" name="remap"> перемапувати
          </label>
          <button type="submit" class="btn btn-convert">▶ Конвертувати → kasta.xml</button>
        </div>
        <div style="font-size:12px;color:#666;margin-top:6px;">
          Запускає <code>prom_to_kasta.php</code>, зберігає результат у <code>kasta.xml</code> і відображає перегляд.
        </div>
      </form>
    </div>

    <?php if ($postError !== ''): ?>
      <div class="msg-error">⚠ <?php echo h($postError); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['converted'])): ?>
      <div class="msg-success">✔ Конвертацію виконано успішно. Показано результат <strong>kasta.xml</strong></div>
    <?php endif; ?>
    <?php if ($feedError !== ''): ?>
      <div class="msg-error">⚠ <?php echo h($feedError); ?></div>
    <?php endif; ?>
  </details>

  <script>
  function showTab(id, btn) {
    var allowed = {'upload': 1, 'url': 1, 'convert': 1};
    if (!allowed[id]) return;
    document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
  }
  </script>

<?php if (!$feedError): ?>
  <div class="topbar">
    <div class="pill"><strong>Файл:</strong> <?php echo h(basename($feedPath)); ?></div>
    <div class="pill"><strong>Категорій:</strong> <?php echo (int)$catsCount; ?></div>
    <div class="pill"><strong>Товарів:</strong> <?php echo (int)$offersCount; ?></div>
    <div class="pill"><strong>Без категорії:</strong> <?php echo (int)$unknownCount; ?></div>
    <div class="pill"><strong>Ліміт/кат:</strong> <?php echo $maxPerCat === 0 ? '∞' : (int)$maxPerCat; ?></div>
    <div class="pill"><strong>Фото:</strong> <?php echo $showImages ? 'ON' : 'OFF'; ?></div>
    <?php
      $qs = $_GET;
      $qs['img'] = $showImages ? 0 : 1;
      $toggleImg = '?' . http_build_query($qs);
    ?>
    <div class="pill"><a href="<?php echo h($toggleImg); ?>">Перемкнути фото</a></div>
    <div class="pill muted">(у summary: direct / total)</div>
  </div>

  <!-- ===== Bulk action bar ===== -->
  <div id="bulk-bar" role="toolbar" aria-label="Масова дія">
    <span id="bulk-count">0 обрано</span>
    <button class="btn-bulk btn-bulk-assign" type="button" onclick="openBulkPicker()">🏷 Призначити категорію</button>
    <button class="btn-bulk btn-bulk-clear"  type="button" onclick="deselectAll()">✕ Скасувати вибір</button>
  </div>

  <!-- ===== Live search bar ===== -->
  <div class="live-search-bar">
    <div class="ls-group">
      <span class="ls-icon">📂</span>
      <input id="filter-cat" type="search" placeholder="Пошук по назві категорії…" autocomplete="off">
      <button class="ls-clear" type="button" onclick="clearFilter('filter-cat')" title="Очистити">×</button>
    </div>
    <div class="ls-group">
      <span class="ls-icon">🔍</span>
      <input id="filter-product" type="search" placeholder="Пошук по назві товару…" autocomplete="off">
      <button class="ls-clear" type="button" onclick="clearFilter('filter-product')" title="Очистити">×</button>
    </div>
    <span id="filter-count" class="ls-count"></span>
  </div>

  <!-- ===== View tabs ===== -->
  <?php
    $totalExcluded = count($excluded['offers']) + count($excluded['categories']);
  ?>
  <div class="view-tabs">
    <button class="view-tab active" type="button" id="view-tab-all" onclick="showView('all',this)">📋 Всі товари</button>
    <button class="view-tab" type="button" id="view-tab-excl" onclick="showView('excluded',this)">🚫 Виключені <span class="badge" id="excl-tab-count"><?php echo $totalExcluded; ?></span></button>
  </div>

  <!-- ===== All products view ===== -->
  <div id="view-all">

  <?php if (!empty($unknownProducts)): ?>
    <details class="cat" open>
      <summary>
        <span class="cat-name">Невідомі категорії / categoryId без опису</span>
        <span class="meta">(<?php echo (int)$unknownCount; ?>)</span>
        <button class="btn-excl" type="button" style="opacity:0.2;cursor:default" title="Не можна виключити всю групу тут">🚫</button>
      </summary>
      <div class="children">
        <?php foreach ($unknownProducts as $cid => $list): ?>
          <?php
            $direct = count($list);
            usort($list, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
            $isExclUnknownCat = isset($excludedCatSet[(string)$cid]);
          ?>
          <details class="cat<?php echo $isExclUnknownCat ? ' cat-excl' : ''; ?>" data-cat-id="<?php echo h((string)$cid); ?>">
            <summary>
              <span class="cat-name">categoryId <?php echo h((string)$cid); ?></span>
              <span class="meta">(<?php echo (int)$direct; ?>)</span>
              <?php $exclCT = $isExclUnknownCat ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста'; ?>
              <button class="btn-excl excl-btn-cat<?php echo $isExclUnknownCat ? ' active' : ''; ?>" type="button"
                data-cat="<?php echo h((string)$cid); ?>"
                onclick="event.stopPropagation();toggleExclude('category',<?php echo htmlspecialchars(json_encode((string)$cid), ENT_QUOTES, 'UTF-8'); ?>,this)"
                title="<?php echo h($exclCT); ?>">🚫</button>
            </summary>
            <ul class="products">
              <?php
                $shown = 0;
                foreach ($list as $p) {
                  $shown++;
                  if ($maxPerCat > 0 && $shown > $maxPerCat) break;

                  $pName = (string)($p['name'] ?? '');
                  $pUrl  = (string)($p['url'] ?? '');
                  $pPic  = (string)($p['picture'] ?? '');
                  $pId   = (string)($p['id'] ?? '');
                  $pPrice= (string)($p['price'] ?? '');
                  $pCur  = (string)($p['currency'] ?? '');
                  $isExclP = isset($excludedOfferSet[$pId]);
                  $pSearch = offerSearchText($p);
                  ?>
                  <li class="product<?php echo $isExclP ? ' excl-item' : ''; ?>" data-search="<?php echo $pSearch; ?>">
                    <label class="product-cb-wrap" title="Обрати товар"><input type="checkbox" class="product-cb" data-offer="<?php echo h($pId); ?>"></label>
                    <?php if ($showImages): ?>
                      <?php if ($pPic !== ''): ?>
                        <a class="thumb-wrap" target="_blank" rel="noopener" href="<?php echo h($pPic); ?>" title="Відкрити фото">
                          <img class="thumb" loading="lazy" referrerpolicy="no-referrer" src="<?php echo h($pPic); ?>" alt="">
                        </a>
                      <?php else: ?>
                        <div class="thumb thumb-ph" title="Фото відсутнє">—</div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <div class="pinfo">
                      <?php if ($pUrl !== ''): ?>
                        <a class="plink" target="_blank" rel="noopener" href="<?php echo h($pUrl); ?>"><?php echo h($pName); ?></a>
                      <?php else: ?>
                        <span class="plink"><?php echo h($pName); ?></span>
                      <?php endif; ?>
                      <?php if ($pPrice !== ''): ?>
                        <div class="price"><?php echo h(trim($pPrice . ' ' . $pCur)); ?></div>
                      <?php endif; ?>
                      <?php if ($pId !== ''): ?>
                        <div class="pid">offer#<?php echo h($pId); ?></div>
                      <?php endif; ?>
                      <?php renderOfferMapping($pId, $mapping); ?>
                      <?php $exclPT = $isExclP ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста'; ?>
                      <button class="btn-excl excl-btn-offer<?php echo $isExclP ? ' active' : ''; ?>" type="button"
                        data-offer="<?php echo h($pId); ?>"
                        onclick="toggleExclude('offer',<?php echo htmlspecialchars(json_encode($pId), ENT_QUOTES, 'UTF-8'); ?>,this)"
                        title="<?php echo h($exclPT); ?>">🚫</button>
                    </div>
                  </li>
              <?php } ?>
            </ul>
          </details>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <?php if (empty($rootIds)): ?>
    <p>Не знайдено категорій у фіді.</p>
  <?php else: ?>
    <?php foreach ($rootIds as $rid): ?>
      <?php renderCategory($rid, $categories, $productsByCat, $totals, $mapping, $excludedOfferSet, $excludedCatSet, $maxPerCat, $showImages, 0); ?>
    <?php endforeach; ?>
  <?php endif; ?>

  </div><!-- #view-all -->

  <!-- ===== Excluded items view ===== -->
  <div id="view-excluded" style="display:none">
    <?php
      // Collect excluded category entries
      $exclCatEntries = [];
      foreach ($excluded['categories'] ?? [] as $excCatId) {
          $exclCatEntries[] = [
              'id'   => (string)$excCatId,
              'name' => ($categories[$excCatId]['name'] ?? ('categoryId #' . $excCatId)),
          ];
      }
      // Collect excluded offer entries
      $exclOfferEntries = [];
      foreach ($excluded['offers'] ?? [] as $excOfferId) {
          if (isset($productById[$excOfferId])) {
              $exclOfferEntries[] = $productById[$excOfferId];
          }
      }
    ?>
    <?php if (empty($exclCatEntries) && empty($exclOfferEntries)): ?>
      <p class="excl-empty">Жодного виключеного товару або категорії. Натисніть 🚫 у фіді, щоб виключити елементи з Kasta XML.</p>
    <?php else: ?>
      <p class="excl-empty" style="display:none">Жодного виключеного товару або категорії. Натисніть 🚫 у фіді, щоб виключити елементи з Kasta XML.</p>
    <?php endif; ?>
    <h3 id="excl-cats-h3" <?php echo empty($exclCatEntries) ? 'style="display:none"' : ''; ?>>Виключені категорії (<?php echo count($exclCatEntries); ?>)</h3>
    <ul id="excl-cats-list" style="list-style:none;padding:0<?php echo empty($exclCatEntries) ? ';display:none' : ''; ?>">
      <?php foreach ($exclCatEntries as $ec): ?>
        <li class="excl-item-entry" data-cat-id="<?php echo h($ec['id']); ?>">
          <span class="excl-name"><?php echo h($ec['name']); ?></span>
          <span class="excl-sub">#<?php echo h($ec['id']); ?></span>
          <button class="btn-restore" type="button"
            onclick="toggleExclude('category',<?php echo htmlspecialchars(json_encode($ec['id']), ENT_QUOTES, 'UTF-8'); ?>,document.querySelector('.excl-btn-cat[data-cat=\'' + CSS.escape(<?php echo json_encode($ec['id']); ?>) + '\']') || this)">
            🔄 Відновити
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
    <h3 id="excl-offers-h3" <?php echo empty($exclOfferEntries) ? 'style="display:none"' : ''; ?>>Виключені товари (<?php echo count($exclOfferEntries); ?>)</h3>
    <ul id="excl-offers-list" style="list-style:none;padding:0<?php echo empty($exclOfferEntries) ? ';display:none' : ''; ?>">
      <?php foreach ($exclOfferEntries as $ep): ?>
        <?php $epId = (string)($ep['id'] ?? ''); ?>
        <li class="excl-item-entry" data-offer-id="<?php echo h($epId); ?>">
          <span class="excl-name"><?php echo h((string)($ep['name'] ?? '')); ?></span>
          <span class="excl-sub">offer#<?php echo h($epId); ?></span>
          <button class="btn-restore" type="button"
            onclick="toggleExclude('offer',<?php echo htmlspecialchars(json_encode($epId), ENT_QUOTES, 'UTF-8'); ?>,document.querySelector('.excl-btn-offer[data-offer=\'' + CSS.escape(<?php echo json_encode($epId); ?>) + '\']') || this)">
            🔄 Відновити
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div><!-- #view-excluded -->

<?php endif; ?>

<!-- ===== Kasta category picker modal ===== -->
<div id="cat-modal" role="dialog" aria-modal="true" aria-labelledby="cat-modal-title">
  <div id="cat-modal-box">
    <h3 id="cat-modal-title">Призначити категорію Каста</h3>
    <div id="cat-modal-name"></div>
    <input id="cat-search" type="search" placeholder="Пошук категорії (напр.: колектор, arduino, зарядний...)" autocomplete="off">
    <ul id="cat-results"></ul>
    <div id="cat-modal-footer">
      <button type="button" onclick="closeCatPicker()">Скасувати</button>
    </div>
  </div>
</div>

<script>
// Kasta category IDs already used in this feed's mapping (for highlighting)
var _usedCatIds = new Set(<?php echo json_encode(array_keys($usedKastaCatIds), JSON_HEX_TAG | JSON_HEX_AMP); ?>);
// Feed file to use for auto-conversion
var _autoConvertFile = <?php echo json_encode($autoConvertInput, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
// Excluded offer / category sets (updated live as user toggles)
var _excludedOffers = new Set(<?php echo json_encode(array_values($excluded['offers'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP); ?>);
var _excludedCats   = new Set(<?php echo json_encode(array_values($excluded['categories'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP); ?>);

(function() {
  var _offerId   = null;   // single-product mode
  var _offerIds  = null;   // bulk mode (array)
  var _isBulk    = false;
  var _searchTimer = null;
  var _selected  = new Set(); // offer IDs selected via checkboxes

  // ---- Checkbox / selection management ----
  function updateBulkBar() {
    var bar   = document.getElementById('bulk-bar');
    var count = document.getElementById('bulk-count');
    if (_selected.size > 0) {
      bar.classList.add('visible');
      count.textContent = _selected.size + ' обрано';
    } else {
      bar.classList.remove('visible');
    }
  }

  window.deselectAll = function() {
    _selected.clear();
    document.querySelectorAll('.product-cb:checked').forEach(function(cb) {
      cb.checked = false;
      cb.closest('li.product').classList.remove('selected');
    });
    updateBulkBar();
  };

  // Delegate checkbox change on document (works for dynamically rendered products too)
  document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('product-cb')) return;
    var offerId = e.target.dataset.offer;
    if (!offerId) return;
    var li = e.target.closest('li.product');
    if (e.target.checked) {
      _selected.add(offerId);
      if (li) li.classList.add('selected');
    } else {
      _selected.delete(offerId);
      if (li) li.classList.remove('selected');
    }
    updateBulkBar();
  });

  // ---- Open modal ----
  window.openCatPicker = function(offerId, triggerBtn) {
    _isBulk   = false;
    _offerId  = offerId;
    _offerIds = null;
    // Find product name for the modal subtitle
    var product = triggerBtn.closest('li.product');
    var pName = offerId;
    if (product) {
      var link = product.querySelector('.plink');
      if (link) pName = link.textContent;
    }
    document.getElementById('cat-modal-name').textContent = pName;
    _showModal();
  };

  window.openBulkPicker = function() {
    if (_selected.size === 0) return;
    _isBulk   = true;
    _offerId  = null;
    _offerIds = Array.from(_selected);
    document.getElementById('cat-modal-name').textContent =
      'Призначити категорію для ' + _selected.size + ' товар(ів)';
    _showModal();
  };

  function _showModal() {
    document.getElementById('cat-search').value = '';
    document.getElementById('cat-results').innerHTML = '';
    document.getElementById('cat-modal').style.display = 'flex';
    document.getElementById('cat-search').focus();
  }

  window.closeCatPicker = function() {
    document.getElementById('cat-modal').style.display = 'none';
    _offerId  = null;
    _offerIds = null;
    _isBulk   = false;
  };

  // Close on backdrop click
  document.getElementById('cat-modal').addEventListener('click', function(e) {
    if (e.target === this) closeCatPicker();
  });
  // Close on Escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('cat-modal').style.display === 'flex') closeCatPicker();
  });

  // ---- Search ----
  document.getElementById('cat-search').addEventListener('input', function() {
    clearTimeout(_searchTimer);
    var q = this.value.trim();
    if (q.length < 2) {
      document.getElementById('cat-results').innerHTML = '<li style="color:#aaa;padding:8px;list-style:none">Введіть мінімум 2 символи…</li>';
      return;
    }
    _searchTimer = setTimeout(function() { doSearch(q); }, 300);
  });

  function doSearch(q) {
    document.getElementById('cat-results').innerHTML = '<li style="color:#aaa;padding:8px;list-style:none">Пошук…</li>';
    fetch('?api=categories&q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(renderResults)
      .catch(function() {
        document.getElementById('cat-results').innerHTML = '<li style="color:#a00;padding:8px;list-style:none">Помилка запиту</li>';
      });
  }

  function renderResults(cats) {
    var ul = document.getElementById('cat-results');
    ul.innerHTML = '';
    if (!Array.isArray(cats) || !cats.length) {
      ul.innerHTML = '<li style="color:#aaa;padding:8px;list-style:none">Нічого не знайдено</li>';
      return;
    }
    cats.forEach(function(c) {
      var li = document.createElement('li');
      li.className = 'cat-result-item';
      var isUsed = _usedCatIds.has(c.id);
      if (isUsed) li.classList.add('used');

      var strong = document.createElement('strong');
      strong.textContent = c.kind;
      if (isUsed) {
        var badge = document.createElement('span');
        badge.className = 'cat-used-badge';
        badge.textContent = '✔ використовується';
        strong.appendChild(badge);
      }
      var br = document.createElement('br');
      var small = document.createElement('small');
      small.textContent = c.affiliation + ' › ' + c.group + ' › ' + c.subgroup;
      li.appendChild(strong);
      li.appendChild(br);
      li.appendChild(small);
      li.addEventListener('click', function() { assignCategory(c); });
      ul.appendChild(li);
    });
  }

  // ---- Assign ----
  function assignCategory(cat) {
    if (_isBulk) {
      _assignBulk(cat);
    } else {
      _assignSingle(cat);
    }
  }

  function _updateRowLabel(offerId, cat) {
    document.querySelectorAll('.kcat-row[data-offer="' + CSS.escape(offerId) + '"]').forEach(function(row) {
      var lbl = row.querySelector('.kcat-label');
      if (lbl) {
        lbl.className = 'kcat-label';
        lbl.title = cat.affiliation + ' › ' + cat.group + ' › ' + cat.subgroup;
        lbl.textContent = cat.kind;
      }
    });
  }

  function _assignSingle(cat) {
    var offerId = _offerId;
    if (!offerId) return;
    var body = new URLSearchParams({action: 'assign_category', offer_id: offerId, cat_id: cat.id});
    fetch(location.pathname + location.search, {method: 'POST', body: body})
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          _updateRowLabel(offerId, cat);
          _usedCatIds.add(cat.id);
          closeCatPicker();
          _triggerAutoConvert();
        } else {
          alert('Помилка збереження: ' + (data.error || '?'));
        }
      })
      .catch(function() { alert('Мережева помилка'); });
  }

  function _assignBulk(cat) {
    var ids = _offerIds;
    if (!ids || !ids.length) return;
    var body = new URLSearchParams({action: 'assign_bulk', offer_ids: ids.join(','), cat_id: cat.id});
    fetch(location.pathname + location.search, {method: 'POST', body: body})
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          ids.forEach(function(oid) { _updateRowLabel(oid, cat); });
          _usedCatIds.add(cat.id);
          closeCatPicker();
          deselectAll();
          _triggerAutoConvert();
        } else {
          alert('Помилка збереження: ' + (data.error || '?'));
        }
      })
      .catch(function() { alert('Мережева помилка'); });
  }

}());

// ---- Auto-convert: rebuild kasta.xml after every assignment ----
function _triggerAutoConvert() {
  var toast = document.getElementById('convert-toast');
  toast.textContent = '⏳ Оновлюю kasta.xml…';
  toast.className = 'toast-loading';
  var body = new URLSearchParams({action: 'convert_async', input_file: _autoConvertFile});
  fetch(location.pathname + location.search, {method: 'POST', body: body})
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) {
        toast.textContent = '✔ kasta.xml оновлено';
        toast.className = 'toast-ok';
      } else {
        toast.textContent = '⚠ ' + (data.error || 'Помилка конвертації');
        toast.className = 'toast-err';
      }
      setTimeout(function() { toast.textContent = ''; toast.className = ''; }, 4000);
    })
    .catch(function() {
      toast.textContent = '⚠ Мережева помилка';
      toast.className = 'toast-err';
      setTimeout(function() { toast.textContent = ''; toast.className = ''; }, 4000);
    });
}

// ---- Toggle exclude offer / category from kasta.xml ----
window.toggleExclude = function(type, id, originBtn) {
  var body = new URLSearchParams({action: 'toggle_exclude', type: type, id: id});
  fetch(location.pathname + location.search, {method: 'POST', body: body})
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.ok) { alert('Помилка: ' + (data.error || '?')); return; }
      var nowExcluded = data.excluded;

      if (type === 'offer') {
        // Toggle all 🚫 buttons for this offer
        document.querySelectorAll('.excl-btn-offer[data-offer="' + CSS.escape(id) + '"]').forEach(function(b) {
          b.classList.toggle('active', nowExcluded);
          b.title = nowExcluded ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста';
        });
        // Toggle product row styling
        document.querySelectorAll('li.product').forEach(function(li) {
          var cb = li.querySelector('.product-cb[data-offer="' + CSS.escape(id) + '"]');
          if (cb) li.classList.toggle('excl-item', nowExcluded);
        });
        // Update excluded view dynamically
        if (nowExcluded) {
          _excludedOffers.add(id);
          var srcLi = document.querySelector('li.product .product-cb[data-offer="' + CSS.escape(id) + '"]');
          var pName = id;
          if (srcLi) {
            var lnk = srcLi.closest('li.product').querySelector('.plink');
            if (lnk) pName = lnk.textContent;
          }
          _addExclView('offer', id, pName);
        } else {
          _excludedOffers.delete(id);
          _removeExclView('offer', id);
        }
      } else {
        // Category
        var det = document.querySelector('details.cat[data-cat-id="' + CSS.escape(id) + '"]');
        if (det) det.classList.toggle('cat-excl', nowExcluded);
        document.querySelectorAll('.excl-btn-cat[data-cat="' + CSS.escape(id) + '"]').forEach(function(b) {
          b.classList.toggle('active', nowExcluded);
          b.title = nowExcluded ? 'Відновити в фіді Каста' : 'Виключити з фіду Каста';
        });
        if (nowExcluded) {
          _excludedCats.add(id);
          var catName = id;
          if (det) { var nm = det.querySelector(':scope > summary > .cat-name'); if (nm) catName = nm.textContent; }
          _addExclView('cat', id, catName);
        } else {
          _excludedCats.delete(id);
          _removeExclView('cat', id);
        }
      }

      // Update badge count
      var badge = document.getElementById('excl-tab-count');
      if (badge) badge.textContent = data.offer_count + data.cat_count;

      // Check if excluded view empty placeholder needs update
      _syncExclEmpty();

      // Auto-convert after exclusion change
      _triggerAutoConvert();
    })
    .catch(function() { alert('Мережева помилка'); });
};

function _addExclView(type, id, name) {
  var listId = type === 'offer' ? 'excl-offers-list' : 'excl-cats-list';
  var h3Id   = type === 'offer' ? 'excl-offers-h3'   : 'excl-cats-h3';
  var list = document.getElementById(listId);
  if (!list) return;
  // Check if already exists
  var existing = list.querySelector('[data-' + (type === 'offer' ? 'offer-id' : 'cat-id') + '="' + CSS.escape(id) + '"]');
  if (existing) return;

  var li = document.createElement('li');
  li.className = 'excl-item-entry';
  if (type === 'offer') li.dataset.offerId = id; else li.dataset.catId = id;

  var nm = document.createElement('span');
  nm.className = 'excl-name';
  nm.textContent = name;

  var sub = document.createElement('span');
  sub.className = 'excl-sub';
  sub.textContent = (type === 'offer' ? 'offer#' : '#') + id;

  var btn = document.createElement('button');
  btn.className = 'btn-restore';
  btn.textContent = '🔄 Відновити';
  btn.addEventListener('click', function() {
    window.toggleExclude(type, id, btn);
  });

  li.appendChild(nm);
  li.appendChild(sub);
  li.appendChild(btn);
  list.appendChild(li);
  list.style.display = '';

  var h3 = document.getElementById(h3Id);
  if (h3) {
    h3.style.display = '';
    h3.textContent = (type === 'offer' ? 'Виключені товари' : 'Виключені категорії') + ' (' + list.children.length + ')';
  }

  _syncExclEmpty();
}

function _removeExclView(type, id) {
  var listId = type === 'offer' ? 'excl-offers-list' : 'excl-cats-list';
  var h3Id   = type === 'offer' ? 'excl-offers-h3'   : 'excl-cats-h3';
  var list = document.getElementById(listId);
  if (!list) return;
  var entry = list.querySelector('[data-' + (type === 'offer' ? 'offer-id' : 'cat-id') + '="' + CSS.escape(id) + '"]');
  if (entry) entry.remove();
  if (list.children.length === 0) {
    list.style.display = 'none';
    var h3 = document.getElementById(h3Id);
    if (h3) h3.style.display = 'none';
  } else {
    var h3 = document.getElementById(h3Id);
    if (h3) h3.textContent = (type === 'offer' ? 'Виключені товари' : 'Виключені категорії') + ' (' + list.children.length + ')';
  }
  _syncExclEmpty();
}

function _syncExclEmpty() {
  var exclDiv = document.getElementById('view-excluded');
  if (!exclDiv) return;
  var totalItems = _excludedOffers.size + _excludedCats.size;
  var emptyEl = exclDiv.querySelector('.excl-empty');
  if (emptyEl) emptyEl.style.display = totalItems === 0 ? '' : 'none';
}

// ---- View switching (all / excluded) ----
window.showView = function(name, btn) {
  var validViews = {all: true, excluded: true};
  if (!validViews[name]) return;
  document.getElementById('view-all').style.display      = name === 'all'      ? '' : 'none';
  document.getElementById('view-excluded').style.display = name === 'excluded' ? '' : 'none';
  document.querySelectorAll('.view-tab').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
};
</script>

<script>
// ---- Live feed search (category name + product name) ----
(function() {
  var _filterTimer  = null;
  var _savedOpen    = null; // Map<details, boolean> — saved before first filter

  window.scheduleFilter = function() {
    clearTimeout(_filterTimer);
    _filterTimer = setTimeout(applyFilters, 180);
  };

  window.clearFilter = function(id) {
    document.getElementById(id).value = '';
    scheduleFilter();
    document.getElementById(id).focus();
  };

  // Attach event listeners (input covers typing; change+search cover native × button)
  (function() {
    var fc = document.getElementById('filter-cat');
    var fp = document.getElementById('filter-product');
    if (!fc || !fp) return;
    ['input', 'change', 'search'].forEach(function(evt) {
      fc.addEventListener(evt, scheduleFilter);
      fp.addEventListener(evt, scheduleFilter);
    });
  }());

  function applyFilters() {
    var fcEl = document.getElementById('filter-cat');
    var fpEl = document.getElementById('filter-product');
    if (!fcEl || !fpEl) return;
    var catQ  = fcEl.value.trim().toLowerCase();
    var prodQ = fpEl.value.trim().toLowerCase();
    var active = catQ !== '' || prodQ !== '';

    // Only filter inside the "all" view
    var viewAll = document.getElementById('view-all');
    if (!viewAll || viewAll.style.display === 'none') return;

    // ---- Save open state on first activation ----
    if (active && !_savedOpen) {
      _savedOpen = new Map();
      viewAll.querySelectorAll('details.cat').forEach(function(d) {
        _savedOpen.set(d, d.open);
      });
    }

    // ---- Reset visibility ----
    viewAll.querySelectorAll('li.product').forEach(function(li) { li.classList.remove('filter-hidden'); });
    viewAll.querySelectorAll('details.cat').forEach(function(d) { d.classList.remove('filter-hidden'); });

    if (!active) {
      // Restore saved open states
      if (_savedOpen) {
        _savedOpen.forEach(function(wasOpen, d) { d.open = wasOpen; });
        _savedOpen = null;
      }
      document.getElementById('filter-count').textContent = '';
      return;
    }

    // ---- Filter individual products ----
    if (prodQ) {
      viewAll.querySelectorAll('li.product').forEach(function(li) {
        var searchText = (li.dataset.search || '').toLowerCase();
        if (searchText.indexOf(prodQ) === -1) li.classList.add('filter-hidden');
      });
    }

    // ---- Filter categories (process deepest first so parents see children's state) ----
    // Show a category if: (its name matches AND it has visible content or no product filter)
    //   OR it has a visible child category (parent kept visible by matching descendant)
    var allCats = viewAll.querySelectorAll('details.cat');
    for (var i = allCats.length - 1; i >= 0; i--) {
      var d = allCats[i];
      var nameEl = d.querySelector(':scope > summary > .cat-name');
      var catName = nameEl ? nameEl.textContent.toLowerCase() : '';
      var catMatch = !catQ || catName.indexOf(catQ) !== -1;

      var hasVisibleProduct = !!d.querySelector('li.product:not(.filter-hidden)');
      var hasVisibleChild   = !!d.querySelector(':scope > div.children > details.cat:not(.filter-hidden)');

      var show = (catMatch && (!prodQ || hasVisibleProduct)) || hasVisibleChild;
      if (!show) {
        d.classList.add('filter-hidden');
      } else {
        d.open = true; // expand matched categories
      }
    }

    // ---- Update result count ----
    var visProd = viewAll.querySelectorAll('li.product:not(.filter-hidden)').length;
    var visCat  = viewAll.querySelectorAll('details.cat:not(.filter-hidden)').length;
    document.getElementById('filter-count').textContent =
      visCat + ' кат. / ' + visProd + ' товарів';
  }
}());
</script>
</body>
</html>