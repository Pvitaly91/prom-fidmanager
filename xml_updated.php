<?php
/**
 * xml.php
 *
 * Mode=view (default): view Kasta XML (kasta.xml) as category tree + products.
 * Mode=map: edit mapping file kasta_offer_category_map.json (offer_overrides) using Prom feed (products_feed.xml)
 *
 * Files (same folder):
 *  - kasta.xml (optional, for mode=view)
 *  - products_feed.xml (Prom feed, for mode=map)
 *  - kasta_offer_category_map.json (mapping to edit)
 *  - kasta_categories_full.json (full categories dictionary: id -> path)
 *
 * Query params:
 *  - mode=view|map
 *  - file=... (for view: choose xml; for map: choose prom xml)
 *  - map=...  (mapping json filename)
 *  - cats=... (categories json filename)
 *
 * Map editor params:
 *  - q=... (search offers)
 *  - page=1.., per_page=50
 *  - edit=OFFER_ID (open edit page)
 *  - cat_q=... (search categories within edit page)
 *
 * POST (save mapping):
 *  - offer_id
 *  - category_id
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

$baseDir = __DIR__;

// Defaults
$defaultKastaXml = $baseDir . DIRECTORY_SEPARATOR . 'kasta.xml';
$defaultPromXml  = $baseDir . DIRECTORY_SEPARATOR . 'products_feed.xml';
$defaultMapJson  = $baseDir . DIRECTORY_SEPARATOR . 'kasta_offer_category_map.json';
$defaultCatsJson = $baseDir . DIRECTORY_SEPARATOR . 'kasta_categories_full.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base36crc(string $s): string {
    $v = sprintf('%u', crc32($s));
    return strtolower(base_convert($v, 10, 36));
}

/** Offer id sanitization must match your generator. */
function sanitizeOfferId(string $id): string {
    $id = trim($id);
    $id = preg_replace('~[^A-Za-z0-9\.\_\-]+~u', '_', $id) ?? '';
    $id = trim($id, '_');
    if ($id === '') $id = 'offer_' . base36crc((string)microtime(true));
    return $id;
}

/** Remove links/URLs from description. */
function stripLinks(string $htmlOrText): string {
    $s = (string)$htmlOrText;
    $s = preg_replace('~<a\b[^>]*>.*?</a>~isu', ' ', $s) ?? $s;
    $s = preg_replace('~https?://\S+~iu', ' ', $s) ?? $s;
    $s = preg_replace('~www\.\S+~iu', ' ', $s) ?? $s;
    $s = strip_tags($s);
    $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
    return trim($s);
}

function loadJsonFile(string $path): array {
    if (!is_file($path)) throw new RuntimeException("File not found: {$path}");
    $raw = file_get_contents($path);
    $j = json_decode($raw ?: '', true);
    if (!is_array($j)) throw new RuntimeException("Invalid JSON: {$path}");
    return $j;
}

function saveJsonFileAtomic(string $path, array $data): void {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('Cannot encode JSON.');
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException("Cannot write temp file: {$tmp}");
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot replace file: {$path}");
    }
}

/**
 * Load categories index from categories json:
 * { "categories": [ {"id":"...","path":"..."} ], "total": N }
 */
function loadCatsIndex(string $catsPath): array {
    $j = loadJsonFile($catsPath);
    $list = $j['categories'] ?? null;
    if (!is_array($list)) throw new RuntimeException("Categories JSON missing 'categories' array.");
    $idx = [];
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $id = (string)($row['id'] ?? '');
        if ($id === '') continue;
        $path = (string)($row['path'] ?? $id);
        $idx[$id] = $path;
    }
    if (!$idx) throw new RuntimeException("Categories index is empty.");
    return $idx;
}

/**
 * Load mapping JSON:
 * expected: { offer_overrides: {offer_id: category_id, ...}, ...}
 * if offer_overrides doesn't exist, but top-level is a mapping, we accept that too.
 */
function loadMapJson(string $mapPath): array {
    $j = loadJsonFile($mapPath);
    if (isset($j['offer_overrides']) && is_array($j['offer_overrides'])) {
        return $j;
    }
    // fallback: treat as direct mapping
    $allScalar = true;
    foreach ($j as $k => $v) {
        if (!is_string($k) || (!is_string($v) && !is_int($v))) { $allScalar = false; break; }
    }
    if ($allScalar) {
        return ['offer_overrides' => $j];
    }
    // last resort
    $j['offer_overrides'] = $j['offer_overrides'] ?? [];
    return $j;
}

function resolveLocalFile(string $baseDir, string $paramName, string $defaultPath): string {
    if (!empty($_GET[$paramName])) {
        $name = basename((string)$_GET[$paramName]);
        $candidate = $baseDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) return $candidate;
    }
    return $defaultPath;
}

// ---------------- Mode routing ----------------
$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'view';
if ($mode !== 'map') $mode = 'view';

// TOP NAV
function renderHeaderNav(string $mode): void {
    $q = $_GET;
    $q['mode'] = 'view';
    $viewUrl = '?' . http_build_query($q);

    $q = $_GET;
    $q['mode'] = 'map';
    $mapUrl = '?' . http_build_query($q);

    echo "<div class='nav'>";
    echo "<a class='tab " . ($mode === 'view' ? "on":"") . "' href='".h($viewUrl)."'>Перегляд Kasta XML</a>";
    echo "<a class='tab " . ($mode === 'map' ? "on":"") . "' href='".h($mapUrl)."'>Редактор мапінгу</a>";
    echo "</div>";
}

?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kasta XML / Mapping</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 16px; line-height: 1.35; }
    .nav { display:flex; gap:10px; margin-bottom: 14px; flex-wrap:wrap; }
    .tab { padding: 8px 12px; border:1px solid #ddd; border-radius: 999px; text-decoration:none; color:#111; background:#fafafa; }
    .tab.on { border-color:#111; background:#fff; }
    .card { border:1px solid #e5e5e5; border-radius: 12px; padding: 12px; margin: 12px 0; background:#fff; }
    .muted { color:#666; }
    .btn { display:inline-block; padding:8px 12px; border:1px solid #111; border-radius: 10px; text-decoration:none; color:#111; background:#fff; cursor:pointer; }
    .btn.small { padding:6px 10px; border-radius: 9px; font-size: 13px; }
    input[type=text], input[type=number] { width: 100%; padding: 10px; border:1px solid #ccc; border-radius: 10px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding: 8px; border-bottom: 1px solid #eee; vertical-align: top; text-align:left; }
    th { font-size: 12px; color:#555; text-transform: uppercase; letter-spacing: .03em; }
    .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media(max-width:900px){ .grid2{grid-template-columns:1fr;} }
    details.cat { margin: 6px 0; padding-left: 12px; border-left: 2px solid #eee; }
    details.cat > summary { cursor:pointer; padding: 6px 4px; list-style: none; }
    details.cat > summary::-webkit-details-marker { display:none; }
    .cat-name { font-weight: 600; }
    .meta { color:#666; font-size: 12px; margin-left: 6px; }
    .meta-id { color:#999; font-size: 12px; margin-left: 6px; }
    .children { margin-left: 10px; }
    ul.products { margin: 6px 0 10px 0; padding-left: 0; }
    li.product { display:flex; gap:10px; margin: 8px 0; align-items:flex-start; list-style: none; padding: 6px 8px; border: 1px solid #f0f0f0; border-radius: 10px; background: #fff; }
    .thumb { width: 58px; height: 58px; object-fit: cover; border-radius: 10px; border:1px solid #eee; background:#f5f5f5; display:block; }
    .thumb-ph { width:58px; height:58px; border-radius: 10px; border:1px solid #eee; background:#fafafa; display:flex; align-items:center; justify-content:center; color:#999; font-size:12px;}
    .plink { color:#0b57d0; text-decoration:none; word-break: break-word; }
    .plink:hover { text-decoration:underline; }
    .pill { display:inline-block; padding:4px 8px; border:1px solid #ddd; border-radius: 999px; background:#fafafa; font-size: 12px; color:#333; }
    .err { color:#b00020; }
    .ok  { color:#0b7a0b; }
    .catres { display:flex; gap:8px; align-items:flex-start; justify-content:space-between; }
    .catres code { font-size: 12px; }
  </style>
</head>
<body>
<?php renderHeaderNav($mode); ?>

<?php if ($mode === 'map'): ?>
<?php
    // -------- Mapping editor mode --------
    $promPath = resolveLocalFile($baseDir, 'file', $defaultPromXml);
    $mapPath  = resolveLocalFile($baseDir, 'map', $defaultMapJson);
    $catsPath = resolveLocalFile($baseDir, 'cats', $defaultCatsJson);

    $error = '';
    $notice = '';

    try {
        $catsIndex = loadCatsIndex($catsPath);
        $mapJson = loadMapJson($mapPath);

        // handle save
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_id'], $_POST['category_id'])) {
            $offerId = trim((string)$_POST['offer_id']);
            $catId   = trim((string)$_POST['category_id']);

            if ($offerId === '') throw new RuntimeException('offer_id is empty.');
            if ($catId === '') throw new RuntimeException('category_id is empty.');
            if (!isset($catsIndex[$catId])) throw new RuntimeException('Unknown category_id: ' . $catId);

            if (!isset($mapJson['offer_overrides']) || !is_array($mapJson['offer_overrides'])) {
                $mapJson['offer_overrides'] = [];
            }
            $mapJson['offer_overrides'][$offerId] = $catId;

            // optional meta update if exists
            if (isset($mapJson['offer_override_meta']) && is_array($mapJson['offer_override_meta'])) {
                $mapJson['offer_override_meta'][$offerId]['category_id'] = $catId;
                $mapJson['offer_override_meta'][$offerId]['path'] = $catsIndex[$catId];
                $mapJson['offer_override_meta'][$offerId]['reason'] = 'manual_override';
            }

            $mapJson['updated_at'] = date('c');
            saveJsonFileAtomic($mapPath, $mapJson);

            $notice = "Збережено: {$offerId} → {$catId}";
            // redirect to avoid resubmission
            $qs = $_GET;
            $qs['mode'] = 'map';
            $qs['edit'] = $offerId;
            header('Location: ?' . http_build_query($qs));
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $editOffer = isset($_GET['edit']) ? trim((string)$_GET['edit']) : '';
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(10, min(200, (int)$_GET['per_page'])) : 50;

    // iterate offers with XMLReader (stream)
    function iterPromOffersLite(string $xmlPath, callable $cb): void {
        if (!is_file($xmlPath) || !is_readable($xmlPath)) throw new RuntimeException("Prom XML not found: {$xmlPath}");
        $r = new XMLReader();
        $r->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'offer') {
                $xml = $r->readOuterXML();
                if ($xml === '') continue;
                $sx = @simplexml_load_string($xml);
                if (!$sx) continue;

                $idAttr = (string)($sx['id'] ?? '');
                $id = sanitizeOfferId($idAttr);

                $name = trim((string)($sx->name ?? $sx->name_ua ?? $sx->title ?? ''));
                $desc = trim((string)($sx->description ?? $sx->description_ua ?? ''));
                $desc = stripLinks($desc);

                $pic = '';
                if (isset($sx->picture[0])) $pic = trim((string)$sx->picture[0]);

                $cb([
                    'id' => $id,
                    'id_raw' => $idAttr,
                    'name' => $name,
                    'desc' => $desc,
                    'pic' => $pic,
                ]);
            }
        }
        $r->close();
    }

    // category search helper (fast contains search)
    function searchCategories(array $catsIndex, string $needle, int $limit = 50): array {
        $needle = mb_strtolower(trim($needle), 'UTF-8');
        if ($needle === '') return [];
        $out = [];
        foreach ($catsIndex as $id => $path) {
            if (mb_strpos(mb_strtolower($path, 'UTF-8'), $needle, 0, 'UTF-8') !== false) {
                $out[] = ['id'=>$id, 'path'=>$path];
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }
?>
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div><b>Prom XML:</b> <?php echo h(basename($promPath)); ?></div>
        <div><b>Map JSON:</b> <?php echo h(basename($mapPath)); ?></div>
        <div><b>Categories JSON:</b> <?php echo h(basename($catsPath)); ?></div>
      </div>
      <div class="row">
        <?php if (is_file($baseDir . '/index_from_json.php')): ?>
          <a class="btn small" href="<?php echo h('index_from_json.php?download=1'); ?>">Згенерувати kasta.xml</a>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($error !== ''): ?>
      <p class="err"><b>ERROR:</b> <?php echo h($error); ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <p class="ok"><?php echo h($notice); ?></p>
    <?php endif; ?>
  </div>

  <?php if ($editOffer !== ''): ?>
    <?php
      // Find offer details (scan until match)
      $offer = null;
      try {
          iterPromOffersLite($promPath, function($o) use (&$offer, $editOffer) {
              if ($o['id'] === $editOffer) $offer = $o;
          });
      } catch (Throwable $e) {
          $offer = null;
          $error = $e->getMessage();
      }

      $currentCatId = '';
      $currentPath = '';
      if (!$error) {
          $currentCatId = (string)($mapJson['offer_overrides'][$editOffer] ?? '');
          $currentPath = $currentCatId !== '' ? (string)($catsIndex[$currentCatId] ?? $currentCatId) : '';
      }

      $catQ = isset($_GET['cat_q']) ? trim((string)$_GET['cat_q']) : '';
      $catResults = (!$error && $catQ !== '') ? searchCategories($catsIndex, $catQ, 60) : [];
    ?>
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <div>
          <div class="pill">Редагування offer_id: <b><?php echo h($editOffer); ?></b></div>
          <?php if ($offer): ?>
            <div style="margin-top:8px; font-weight:600;"><?php echo h($offer['name']); ?></div>
            <?php if ($offer['desc'] !== ''): ?>
              <div class="muted" style="margin-top:4px;"><?php echo h(mb_substr($offer['desc'], 0, 220, 'UTF-8')); ?><?php echo mb_strlen($offer['desc'],'UTF-8')>220?'…':''; ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="muted" style="margin-top:8px;">Offer не знайдено в Prom XML.</div>
          <?php endif; ?>
        </div>
        <div class="row">
          <?php
            $qs = $_GET; unset($qs['edit'], $qs['cat_q']);
            $backUrl = '?' . http_build_query($qs);
          ?>
          <a class="btn small" href="<?php echo h($backUrl); ?>">← Назад</a>
        </div>
      </div>

      <?php if ($offer && $offer['pic'] !== ''): ?>
        <div style="margin-top:10px">
          <a target="_blank" rel="noopener" href="<?php echo h($offer['pic']); ?>">
            <img class="thumb" src="<?php echo h($offer['pic']); ?>" alt="">
          </a>
        </div>
      <?php endif; ?>

      <div class="grid2" style="margin-top:12px">
        <div>
          <div class="muted">Поточна категорія</div>
          <div style="margin-top:6px">
            <?php if ($currentCatId === ''): ?>
              <span class="err">Не задано (missing map)</span>
            <?php else: ?>
              <code><?php echo h($currentCatId); ?></code>
              <div class="muted" style="margin-top:4px"><?php echo h($currentPath); ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <form method="post">
            <input type="hidden" name="offer_id" value="<?php echo h($editOffer); ?>">
            <label class="muted" for="category_id">Нова category_id (встав або вибери через пошук)</label>
            <input id="category_id" name="category_id" type="text" value="<?php echo h($currentCatId); ?>" placeholder="c_..." required>
            <div class="row" style="margin-top:10px; justify-content:flex-end">
              <button class="btn" type="submit">Зберегти</button>
            </div>
          </form>
        </div>
      </div>

      <div style="margin-top:14px">
        <form method="get" class="row">
          <?php
            // keep params
            foreach ($_GET as $k => $v) {
              if ($k === 'cat_q') continue;
              echo "<input type='hidden' name='".h((string)$k)."' value='".h((string)$v)."'>";
            }
          ?>
          <div style="flex:1; min-width:220px">
            <input type="text" name="cat_q" value="<?php echo h($catQ); ?>" placeholder="Пошук категорій (частина назви / шляху)">
          </div>
          <div><button class="btn small" type="submit">Шукати</button></div>
        </form>

        <?php if ($catQ !== ''): ?>
          <div class="muted" style="margin-top:10px">Результати (до 60):</div>
          <div style="margin-top:8px">
            <?php foreach ($catResults as $r): ?>
              <div class="catres" style="padding:8px 0;border-bottom:1px solid #f0f0f0">
                <div>
                  <code><?php echo h($r['id']); ?></code>
                  <div class="muted"><?php echo h($r['path']); ?></div>
                </div>
                <div>
                  <button class="btn small" type="button" onclick="document.getElementById('category_id').value='<?php echo h($r['id']); ?>'; window.scrollTo({top:0,behavior:'smooth'});">Вибрати</button>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($catResults)): ?>
              <div class="muted">Нічого не знайдено.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <?php
      // list offers with pagination + search
      $offers = [];
      $totalMatched = 0;
      $start = ($page - 1) * $perPage;
      $end = $start + $perPage;

      if (!$error) {
          try {
              iterPromOffersLite($promPath, function($o) use (&$offers, &$totalMatched, $q, $start, $end) {
                  $hay = mb_strtolower($o['name'].' '.$o['desc'], 'UTF-8');
                  if ($q !== '' && mb_strpos($hay, mb_strtolower($q,'UTF-8'), 0, 'UTF-8') === false) return;

                  $idx = $totalMatched;
                  $totalMatched++;

                  if ($idx >= $start && $idx < $end) {
                      $offers[] = $o;
                  }
              });
          } catch (Throwable $e) {
              $error = $e->getMessage();
          }
      }
    ?>

    <div class="card">
      <form method="get" class="grid2">
        <input type="hidden" name="mode" value="map">
        <div>
          <label class="muted">Пошук по товарах (назва/опис)</label>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Напр. камера, xt60, type-c, honda">
        </div>
        <div class="row" style="align-items:flex-end; justify-content:flex-end">
          <div style="width:120px">
            <label class="muted">per_page</label>
            <input type="number" name="per_page" value="<?php echo (int)$perPage; ?>" min="10" max="200">
          </div>
          <div><button class="btn" type="submit">Показати</button></div>
        </div>
      </form>
      <div class="muted" style="margin-top:10px">
        Знайдено: <b><?php echo (int)$totalMatched; ?></b>, сторінка: <b><?php echo (int)$page; ?></b>
      </div>
    </div>

    <?php if ($error === ''): ?>
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>Фото</th>
              <th>offer_id</th>
              <th>Назва</th>
              <th>Категорія (з мапінгу)</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($offers as $o): ?>
              <?php
                $oid = $o['id'];
                $catId = (string)($mapJson['offer_overrides'][$oid] ?? '');
                $catPath = $catId !== '' ? (string)($catsIndex[$catId] ?? $catId) : '';
                $qs = $_GET;
                $qs['mode'] = 'map';
                $qs['edit'] = $oid;
                $editUrl = '?' . http_build_query($qs);
              ?>
              <tr>
                <td style="width:70px">
                  <?php if ($o['pic'] !== ''): ?>
                    <a target="_blank" rel="noopener" href="<?php echo h($o['pic']); ?>">
                      <img class="thumb" src="<?php echo h($o['pic']); ?>" alt="">
                    </a>
                  <?php else: ?>
                    <div class="thumb-ph">—</div>
                  <?php endif; ?>
                </td>
                <td><code><?php echo h($oid); ?></code></td>
                <td>
                  <div style="font-weight:600"><?php echo h($o['name']); ?></div>
                  <?php if ($o['desc'] !== ''): ?>
                    <div class="muted"><?php echo h(mb_substr($o['desc'], 0, 140, 'UTF-8')); ?><?php echo mb_strlen($o['desc'],'UTF-8')>140?'…':''; ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($catId === ''): ?>
                    <span class="err">missing map</span>
                  <?php else: ?>
                    <code><?php echo h($catId); ?></code>
                    <div class="muted"><?php echo h($catPath); ?></div>
                  <?php endif; ?>
                </td>
                <td style="width:100px"><a class="btn small" href="<?php echo h($editUrl); ?>">Редагувати</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($offers)): ?>
              <tr><td colspan="5" class="muted">Нічого не знайдено.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>

        <?php
          // pagination links
          $pages = (int)ceil(max(1, $totalMatched) / $perPage);
          $qs = $_GET; $qs['mode']='map';
          $prev = max(1, $page - 1);
          $next = min($pages, $page + 1);
          $qsPrev = $qs; $qsPrev['page']=$prev;
          $qsNext = $qs; $qsNext['page']=$next;
        ?>
        <div class="row" style="margin-top:12px; justify-content:space-between">
          <a class="btn small" href="<?php echo h('?' . http_build_query($qsPrev)); ?>">← Prev</a>
          <div class="muted">Pages: <?php echo (int)$pages; ?></div>
          <a class="btn small" href="<?php echo h('?' . http_build_query($qsNext)); ?>">Next →</a>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

<?php else: ?>
<?php
    // -------- Viewer mode (existing functionality) --------
    $feedPath = resolveLocalFile($baseDir, 'file', $defaultKastaXml);
    $maxPerCat  = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : 50;
    $showImages = !isset($_GET['img']) || (string)$_GET['img'] !== '0';

    if (!is_file($feedPath) || !is_readable($feedPath)) {
        echo "<div class='card err'>Kasta XML not found: " . h($feedPath) . "</div>";
    } else {

    function parseFeedKasta(string $feedPath): array {
        $categories = [];
        $productsByCat = [];

        $reader = new XMLReader();
        if (!$reader->open($feedPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Cannot open XML feed.');
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;

            if ($reader->name === 'category') {
                $xml = $reader->readOuterXML();
                if ($xml === '') continue;

                $cat = @simplexml_load_string($xml);
                if ($cat === false) continue;

                $id = (string)$cat['id'];
                if ($id === '') continue;

                $name = trim((string)$cat);
                $categories[$id] = [
                    'id' => $id,
                    'parentId' => null,
                    'name' => $name,
                    'children' => [],
                ];
                continue;
            }

            if ($reader->name === 'offer') {
                $xml = $reader->readOuterXML();
                if ($xml === '') continue;

                $offer = @simplexml_load_string($xml);
                if ($offer === false) continue;

                $catId = isset($offer->categoryId) ? trim((string)$offer->categoryId) : '';
                if ($catId === '') continue;

                $p = [
                    'id' => (string)$offer['id'],
                    'name' => trim((string)$offer->name_ua) ?: trim((string)$offer->name),
                    'url' => trim((string)$offer->url),
                    'price' => trim((string)$offer->price),
                    'currency' => trim((string)$offer->currencyId),
                    'picture' => isset($offer->picture[0]) ? trim((string)$offer->picture[0]) : '',
                ];

                $productsByCat[$catId][] = $p;
                continue;
            }
        }

        $reader->close();
        return [$categories, $productsByCat];
    }

    function buildTreeFlat(array &$categories): array {
        // Kasta categories are flat (no parentId). We'll treat all as roots.
        $roots = array_keys($categories);
        usort($roots, function($a, $b) use ($categories) {
            return strcmp($categories[$a]['name'] ?? '', $categories[$b]['name'] ?? '');
        });
        return $roots;
    }

    function computeTotalsFlat(array $rootIds, array $categories, array $productsByCat): array {
        $memo = [];
        foreach ($rootIds as $id) {
            $memo[$id] = isset($productsByCat[$id]) ? count($productsByCat[$id]) : 0;
        }
        return $memo;
    }

    function renderCategoryFlat(
        string $id,
        array $categories,
        array $productsByCat,
        array $totals,
        int $maxPerCat,
        bool $showImages
    ): void {
        $cat = $categories[$id] ?? null;
        if (!$cat) return;

        $name = $cat['name'] ?? ('#' . $id);
        $direct = isset($productsByCat[$id]) ? count($productsByCat[$id]) : 0;
        $total = $totals[$id] ?? $direct;

        echo '<details class="cat">';
        echo '<summary>';
        echo '<span class="cat-name">' . h((string)$name) . '</span>';
        echo ' <span class="meta">(' . $direct . ')</span>';
        echo ' <span class="meta-id">#' . h((string)$id) . '</span>';
        echo '</summary>';

        if ($direct > 0) {
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
                        echo '<a target="_blank" rel="noopener" href="' . h($pPic) . '">';
                        echo '<img class="thumb" loading="lazy" referrerpolicy="no-referrer" src="' . h($pPic) . '" alt="">';
                        echo '</a>';
                    } else {
                        echo '<div class="thumb-ph">—</div>';
                    }
                }
                echo '<div class="pinfo">';
                if ($pUrl !== '') {
                    echo '<a class="plink" target="_blank" rel="noopener" href="' . h($pUrl) . '">' . h($pName) . '</a>';
                } else {
                    echo '<span class="plink">' . h($pName) . '</span>';
                }
                if ($pPrice !== '') echo '<div class="muted" style="margin-top:4px">' . h(trim($pPrice . ' ' . $pCur)) . '</div>';
                if ($pId !== '') echo '<div class="muted" style="font-size:11px">offer#' . h($pId) . '</div>';
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</details>';
    }

    try {
        [$categories, $productsByCat] = parseFeedKasta($feedPath);
        $rootIds = buildTreeFlat($categories);
        $totals = computeTotalsFlat($rootIds, $categories, $productsByCat);

        $catsCount = count($categories);
        $offersCount = 0;
        foreach ($productsByCat as $arr) $offersCount += count($arr);

        echo "<div class='card'>";
        echo "<div class='row' style='justify-content:space-between'>";
        echo "<div>";
        echo "<div><b>Файл:</b> " . h(basename($feedPath)) . "</div>";
        echo "<div><b>Категорій:</b> " . (int)$catsCount . " &nbsp; <b>Товарів:</b> " . (int)$offersCount . "</div>";
        echo "</div>";
        echo "<div class='row'>";
        $qs = $_GET; $qs['img'] = $showImages ? 0 : 1; $qs['mode']='view';
        echo "<a class='btn small' href='?" . h(http_build_query($qs)) . "'>Перемкнути фото</a>";
        echo "</div>";
        echo "</div>";
        echo "</div>";

        foreach ($rootIds as $rid) {
            renderCategoryFlat((string)$rid, $categories, $productsByCat, $totals, $maxPerCat, $showImages);
        }
    } catch (Throwable $e) {
        echo "<div class='card err'>ERROR: " . h($e->getMessage()) . "</div>";
    }
    }
?>
<?php endif; ?>

</body>
</html>
