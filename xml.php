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

$baseDir  = __DIR__;
$feedPath = $baseDir . DIRECTORY_SEPARATOR . 'kasta.xml';

if (isset($_GET['file'])) {
    $name = basename((string)$_GET['file']); // prevent path traversal
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($candidate)) {
        $feedPath = $candidate;
    }
}

$maxPerCat  = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : 50;
// default ON; disable only if img=0
$showImages = !isset($_GET['img']) || (string)$_GET['img'] !== '0';

if (!is_file($feedPath) || !is_readable($feedPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Feed file not found or not readable: {$feedPath}\n";
    echo "Put products_feed.xml near this script or pass ?file=YOUR.xml (filename only).\n";
    exit;
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
try {
    [$categories, $productsByCat] = parseFeed($feedPath);
    $rootIds = buildTree($categories);
    $totals = computeTotals($rootIds, $categories, $productsByCat);

    // Products linked to categoryId that is missing in <categories>
    $unknownProducts = [];
    foreach ($productsByCat as $cid => $list) {
        if (!isset($categories[$cid])) {
            $unknownProducts[$cid] = $list;
        }
    }

    $catsCount = count($categories);
    $offersCount = 0;
    foreach ($productsByCat as $arr) $offersCount += count($arr);

    $unknownCount = 0;
    foreach ($unknownProducts as $arr) $unknownCount += count($arr);

    header('Content-Type: text/html; charset=utf-8');
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: " . $e->getMessage() . "\n";
    exit;
}
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
  </style>
</head>
<body>
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
</body>
</html>