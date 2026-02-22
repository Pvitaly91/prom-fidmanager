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

            $p = [
                'id' => trim((string)$offer['id']),
                'name' => $name,
                'url' => $url,
                'price' => $price,
                'currency' => $currency,
                'picture' => $picture,
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
 * Compute total products in category subtree (direct + children), memoized.
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

/** Render category subtree. */
function renderCategory(
    int|string $id,
    array $categories,
    array $productsByCat,
    array $totals,
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

    echo '<details class="cat"' . $open . '>';
    echo '<summary>';
    echo '<span class="cat-name">' . h((string)$name) . '</span>';
    echo ' <span class="meta">(' . $direct . ' / ' . $total . ')</span>';
    echo ' <span class="meta-id">#' . h((string)$id) . '</span>';
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

            echo '<li class="product">';
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
            renderCategory($childId, $categories, $productsByCat, $totals, $maxPerCat, $showImages, $depth + 1);
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

    .pinfo { min-width: 0; }
    .plink { color:#0b57d0; text-decoration:none; word-break: break-word; }
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
  </style>
</head>
<body>

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

  <?php if (!empty($unknownProducts)): ?>
    <details class="cat" open>
      <summary>
        <span class="cat-name">Невідомі категорії / categoryId без опису</span>
        <span class="meta">(<?php echo (int)$unknownCount; ?>)</span>
      </summary>
      <div class="children">
        <?php foreach ($unknownProducts as $cid => $list): ?>
          <?php
            $direct = count($list);
            usort($list, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
          ?>
          <details class="cat">
            <summary>
              <span class="cat-name">categoryId <?php echo h((string)$cid); ?></span>
              <span class="meta">(<?php echo (int)$direct; ?>)</span>
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
                  ?>
                  <li class="product">
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
      <?php renderCategory($rid, $categories, $productsByCat, $totals, $maxPerCat, $showImages, 0); ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>