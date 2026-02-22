<?php
/**
 * Prom(YML) -> Kasta(YML) converter (NO auto-mapping, NO JSON generation).
 *
 * This version ONLY generates kasta.xml using:
 *  - kasta_offer_category_map.json   (offer_overrides: offer_id -> category_id)
 *  - kasta_categories.json           (categories list: id -> path)
 *
 * Put these files near this script (same folder):
 *   - products_feed.xml                      (optional default input)
 *   - kasta_offer_category_map.json
 *   - kasta_categories.json
 *
 * Web usage:
 *   - open index.php (upload form)
 *   - index.php?download=1 (generate from default products_feed.xml and download)
 *   - index.php?source_url=https://.../feed.xml&download=1
 *   - index.php?file=products_feed.xml&download=1
 *
 * Optional web params:
 *   - map=kasta_offer_category_map.json   (filename only, same folder)
 *   - cats=kasta_categories.json          (filename only, same folder)
 *
 * CLI usage:
 *   php index.php --in=products_feed.xml --out=kasta.xml --map=kasta_offer_category_map.json --cats=kasta_categories.json
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

const DEFAULT_PROM_XML = __DIR__ . '/products_feed.xml';
const DEFAULT_MAP_JSON = __DIR__ . '/kasta_offer_category_map.json';
const DEFAULT_CATS_JSON = __DIR__ . '/kasta_categories.json';

// ---------- helpers ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function nowYmlDate(): string { return date('Y-m-d H:i'); }
function isCli(): bool { return PHP_SAPI === 'cli'; }

function cliArg(string $name, ?string $default = null): ?string {
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, "--{$name}=") === 0) return substr($a, strlen($name) + 3);
    }
    return $default;
}

function ensureUtf8(string $s): string {
    if ($s === '') return $s;
    $enc = mb_detect_encoding($s, ['UTF-8','Windows-1251','ISO-8859-1'], true);
    if ($enc && $enc !== 'UTF-8') {
        $s = @mb_convert_encoding($s, 'UTF-8', $enc);
    }
    return $s;
}

function base36crc(string $s): string {
    $v = sprintf('%u', crc32($s));
    return strtolower(base_convert($v, 10, 36));
}

/**
 * Kasta: offer id only latin/digits, no spaces/cyrillic.
 * We must sanitize Prom offer id the same way as when the mapping JSON was generated.
 */
function sanitizeOfferId(string $id): string {
    $id = trim($id);
    $id = preg_replace('~[^A-Za-z0-9\.\_\-]+~u', '_', $id) ?? '';
    $id = trim($id, '_');
    if ($id === '') $id = 'offer_' . base36crc((string)microtime(true));
    return $id;
}

/** Remove links/URLs from description (Kasta forbids links in description). */
function stripLinks(string $htmlOrText): string {
    $s = (string)$htmlOrText;
    $s = preg_replace('~<a\b[^>]*>.*?</a>~isu', ' ', $s) ?? $s;
    $s = preg_replace('~https?://\S+~iu', ' ', $s) ?? $s;
    $s = preg_replace('~www\.\S+~iu', ' ', $s) ?? $s;
    $s = strip_tags($s);
    $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
    return trim($s);
}

function loadJson(string $path): array {
    if (!is_file($path)) throw new RuntimeException("JSON not found: {$path}");
    $raw = file_get_contents($path);
    $j = json_decode($raw ?: '', true);
    if (!is_array($j)) throw new RuntimeException("Invalid JSON: {$path}");
    return $j;
}

/**
 * Load categories index from kasta_categories.json:
 * { "categories": [ {"id":"c_xxx","path":"..."} ], "total": N }
 */
function loadCategoriesIndex(string $catsPath): array {
    $j = loadJson($catsPath);
    $list = $j['categories'] ?? null;
    if (!is_array($list)) throw new RuntimeException("kasta_categories.json: missing 'categories' array");

    $idx = [];
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $id = (string)($row['id'] ?? '');
        $path = (string)($row['path'] ?? '');
        if ($id === '') continue;
        $idx[$id] = $path !== '' ? $path : $id;
    }
    if (!$idx) throw new RuntimeException("kasta_categories.json: categories index is empty");
    return $idx;
}

/**
 * Load offer map from kasta_offer_category_map.json:
 * - We use offer_overrides (seeded for every offer in your feed).
 */
function loadOfferMap(string $mapPath): array {
    $j = loadJson($mapPath);
    $over = $j['offer_overrides'] ?? null;
    if (!is_array($over)) throw new RuntimeException("kasta_offer_category_map.json: missing 'offer_overrides' object");
    return $over;
}

// ---------------- Prom feed reading ----------------
function downloadToTemp(string $url): string {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL extension is required to use source_url.');
    $tmp = tempnam(sys_get_temp_dir(), 'prom_');
    if ($tmp === false) throw new RuntimeException('Cannot create temp file');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; prom2kasta/1.0)',
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $code >= 400) {
        @unlink($tmp);
        throw new RuntimeException("Download failed ({$code}): {$err}");
    }
    file_put_contents($tmp, $data);
    return $tmp;
}

function readPromShopMeta(string $xmlPath): array {
    $meta = ['name'=>'Prom feed', 'company'=>'', 'url'=>''];
    $r = new XMLReader();
    $r->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $inShop = false; $found = 0;

    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'shop') { $inShop = true; continue; }
        if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'shop') break;
        if (!$inShop) continue;

        if ($r->nodeType === XMLReader::ELEMENT && in_array($r->name, ['name','company','url'], true)) {
            $key = $r->name;
            $r->read();
            $meta[$key] = trim(ensureUtf8((string)$r->value));
            $found++;
            if ($found >= 3) break;
        }
    }
    $r->close();

    if ($meta['name'] === '') $meta['name'] = 'Prom feed';
    return $meta;
}

function iterPromOffers(string $xmlPath, callable $cb): void {
    $r = new XMLReader();
    $r->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'offer') {
            $xml = $r->readOuterXML();
            if ($xml === '') continue;

            $sx = @simplexml_load_string($xml);
            if (!$sx) continue;

            $idAttr = (string)($sx['id'] ?? '');
            $availableAttr = (string)($sx['available'] ?? '');

            $name = trim((string)($sx->name ?? $sx->name_ua ?? $sx->title ?? ''));
            $desc = trim((string)($sx->description ?? $sx->description_ua ?? ''));
            $desc = stripLinks($desc);

            $price = trim((string)($sx->price ?? ''));
            $old   = trim((string)($sx->oldprice ?? $sx->price_old ?? $sx->old_price ?? ''));
            $promo = trim((string)($sx->price_promo ?? $sx->promo_price ?? $sx->promo_new_price ?? ''));

            $vendor = trim((string)($sx->vendor ?? ''));
            $vendorCode = trim((string)($sx->vendorCode ?? $sx->vendorcode ?? $sx->article ?? ''));

            $pics = [];
            if (isset($sx->picture)) {
                foreach ($sx->picture as $p) {
                    $u = trim((string)$p);
                    if ($u !== '') $pics[] = $u;
                }
            }
            $pics = array_values(array_unique($pics));
            if (count($pics) > 20) $pics = array_slice($pics, 0, 20);

            $stock = null;
            foreach (['stock_quantity','quantity_in_stock','stock'] as $tag) {
                if (isset($sx->{$tag})) {
                    $v = trim((string)$sx->{$tag});
                    if ($v !== '' && preg_match('~^\d+$~', $v)) { $stock = (int)$v; break; }
                }
            }

            $available = null;
            if ($availableAttr !== '') {
                $available = ($availableAttr === 'true' || $availableAttr === '1');
            }

            $params = [];
            if (isset($sx->param)) {
                foreach ($sx->param as $p) {
                    $pName = trim((string)($p['name'] ?? ''));
                    $pVal  = trim((string)$p);
                    $unit  = trim((string)($p['unit'] ?? ''));
                    if ($pName !== '' && $pVal !== '') {
                        $params[] = ['name'=>$pName, 'value'=>$pVal, 'unit'=>$unit];
                    }
                }
            }

            // try vendor from params if vendor empty
            if ($vendor === '') {
                foreach ($params as $pp) {
                    $nn = mb_strtolower((string)$pp['name'], 'UTF-8');
                    if (strpos($nn, 'бренд') !== false || strpos($nn, 'brand') !== false || strpos($nn, 'виробник') !== false || strpos($nn, 'manufacturer') !== false) {
                        $vendor = trim((string)$pp['value']);
                        if ($vendor !== '') break;
                    }
                }
            }

            $cb([
                'id'        => $idAttr,
                'id_clean'  => sanitizeOfferId($idAttr),
                'available' => $available,
                'stock'     => $stock,
                'price'     => $price,
                'old'       => $old,
                'promo'     => $promo,
                'vendor'    => ensureUtf8($vendor),
                'vendorCode'=> ensureUtf8($vendorCode),
                'name'      => ensureUtf8($name),
                'desc'      => ensureUtf8($desc),
                'pics'      => $pics,
                'params'    => $params,
            ]);
        }
    }
    $r->close();
}

// ---------------- output XML writing ----------------
function writeKastaXml(
    string $outUri,
    array $shopMeta,
    array $usedCategories, // id => path
    string $offersTempPath
): void {
    $w = new XMLWriter();
    $w->openURI($outUri);
    $w->startDocument('1.0', 'UTF-8');

    $w->startElement('yml_catalog');
    $w->writeAttribute('date', nowYmlDate());

    $w->startElement('shop');

    $w->writeElement('name', $shopMeta['name'] ?? 'Prom feed');
    if (!empty($shopMeta['company'])) $w->writeElement('company', $shopMeta['company']);
    if (!empty($shopMeta['url'])) $w->writeElement('url', $shopMeta['url']);

    // currencies (UAH only)
    $w->startElement('currencies');
    $w->startElement('currency');
    $w->writeAttribute('id', 'UAH');
    $w->writeAttribute('rate', '1');
    $w->endElement();
    $w->endElement();

    // categories: Kasta expects supplier category dictionary with stable ids (flat list)
    $w->startElement('categories');
    asort($usedCategories, SORT_STRING);
    foreach ($usedCategories as $id => $path) {
        $w->startElement('category');
        $w->writeAttribute('id', (string)$id);
        $w->text((string)$path);
        $w->endElement();
    }
    $w->endElement(); // categories

    // offers
    $w->startElement('offers');

    $fh = fopen($offersTempPath, 'rb');
    if (!$fh) throw new RuntimeException("Cannot read offers temp: {$offersTempPath}");

    while (($line = fgets($fh)) !== false) {
        $o = json_decode($line, true);
        if (!is_array($o)) continue;

        $w->startElement('offer');
        $w->writeAttribute('id', (string)$o['id']);
        $w->writeAttribute('available', !empty($o['available']) ? 'true' : 'false');

        $w->writeElement('currencyId', 'UAH');
        $w->writeElement('categoryId', (string)$o['categoryId']);

        if (isset($o['stock_quantity']) && $o['stock_quantity'] !== null) {
            $w->writeElement('stock_quantity', (string)$o['stock_quantity']);
        }

        if (!empty($o['price'])) $w->writeElement('price', (string)$o['price']);
        if (!empty($o['old']))   $w->writeElement('price_old', (string)$o['old']);
        if (!empty($o['promo'])) $w->writeElement('price_promo', (string)$o['promo']);

        foreach (($o['pics'] ?? []) as $p) {
            $w->writeElement('picture', (string)$p);
        }

        if (!empty($o['vendor'])) $w->writeElement('vendor', (string)$o['vendor']);

        // article is required: vendorCode if exists, else offer id
        $w->writeElement('article', (string)$o['article']);

        // Prefer UA name/desc
        $w->writeElement('name_ua', (string)$o['name_ua']);
        if (!empty($o['description_ua'])) $w->writeElement('description_ua', (string)$o['description_ua']);

        foreach (($o['params'] ?? []) as $p) {
            $pName = trim((string)($p['name'] ?? ''));
            $pVal  = trim((string)($p['value'] ?? ''));
            if ($pName === '' || $pVal === '') continue;

            $w->startElement('param');
            $w->writeAttribute('name', $pName);
            if (!empty($p['unit'])) $w->writeAttribute('unit', (string)$p['unit']);
            $w->text($pVal);
            $w->endElement();
        }

        $w->endElement(); // offer
    }
    fclose($fh);

    $w->endElement(); // offers
    $w->endElement(); // shop
    $w->endElement(); // yml_catalog

    $w->endDocument();
    $w->flush();
}

// ---------------- main ----------------
try {
    $inputPath = DEFAULT_PROM_XML;
    $tempInputToDelete = null;

    // mapping files
    $mapPath = DEFAULT_MAP_JSON;
    $catsPath = DEFAULT_CATS_JSON;

    if (isCli()) {
        $in = cliArg('in');
        if ($in) $inputPath = $in;

        $m = cliArg('map');
        if ($m) $mapPath = $m;

        $c = cliArg('cats');
        if ($c) $catsPath = $c;
    } else {
        if (!empty($_GET['map'])) {
            $candidate = __DIR__ . '/' . basename((string)$_GET['map']);
            if (is_file($candidate)) $mapPath = $candidate;
        }
        if (!empty($_GET['cats'])) {
            $candidate = __DIR__ . '/' . basename((string)$_GET['cats']);
            if (is_file($candidate)) $catsPath = $candidate;
        }

        if (!empty($_GET['source_url'])) {
            $tempInputToDelete = downloadToTemp((string)$_GET['source_url']);
            $inputPath = $tempInputToDelete;
        } elseif (!empty($_FILES['feed']['tmp_name']) && is_uploaded_file($_FILES['feed']['tmp_name'])) {
            $inputPath = (string)$_FILES['feed']['tmp_name'];
        } elseif (!empty($_GET['file'])) {
            $candidate = __DIR__ . '/' . basename((string)$_GET['file']);
            if (is_file($candidate)) $inputPath = $candidate;
        }
    }

    if (!is_file($inputPath)) throw new RuntimeException("Input Prom XML not found: {$inputPath}");
    if (!is_file($mapPath)) throw new RuntimeException("Map JSON not found: {$mapPath}");
    if (!is_file($catsPath)) throw new RuntimeException("Categories JSON not found: {$catsPath}");

    $offerMap = loadOfferMap($mapPath);          // offer_id -> category_id
    $catsIndex = loadCategoriesIndex($catsPath); // category_id -> path

    // fallback category: first from catsIndex
    $fallbackCatId = array_key_first($catsIndex);
    if ($fallbackCatId === null) throw new RuntimeException("Categories index is empty in {$catsPath}");

    $shopMeta = readPromShopMeta($inputPath);

    $offersTemp = tempnam(sys_get_temp_dir(), 'kasta_offers_');
    if ($offersTemp === false) throw new RuntimeException('Cannot create temp file for offers.');
    $fh = fopen($offersTemp, 'wb');
    if (!$fh) throw new RuntimeException('Cannot open temp file for offers.');

    $usedCategories = []; // id => path
    $stats = [
        'offers_total' => 0,
        'offers_written' => 0,
        'offers_skipped_no_picture' => 0,
        'offers_missing_vendor' => 0,
        'offers_missing_map' => 0,
        'offers_unknown_category' => 0,
    ];

    iterPromOffers($inputPath, function(array $o) use (
        &$fh, &$usedCategories, &$stats, $offerMap, $catsIndex, $fallbackCatId
    ) {
        $stats['offers_total']++;

        $offerId = $o['id_clean'];

        // skip if no pictures (Kasta requires at least 1 picture)
        if (empty($o['pics'])) {
            $stats['offers_skipped_no_picture']++;
            return;
        }

        // category from map
        $catId = $offerMap[$offerId] ?? null;
        if ($catId === null || $catId === '') {
            $stats['offers_missing_map']++;
            $catId = $fallbackCatId;
        }

        // category path (for <categories> block)
        $catPath = $catsIndex[$catId] ?? null;
        if ($catPath === null) {
            $stats['offers_unknown_category']++;
            $catPath = (string)$catId;
        }
        $usedCategories[$catId] = $catPath;

        // availability
        $available = $o['available'];
        $stockQty  = $o['stock'];
        if ($available === null) {
            $available = ($stockQty !== null) ? ($stockQty > 0) : true;
        }

        $vendor = trim((string)$o['vendor']);
        if ($vendor === '') {
            $stats['offers_missing_vendor']++;
            $vendor = 'NoBrand';
        }

        $name = trim((string)$o['name']);
        if ($name === '') $name = 'Offer ' . $offerId;

        $desc = (string)($o['desc'] ?? '');
        $article = trim((string)$o['vendorCode']);
        if ($article === '') $article = $offerId;

        // Ensure Color & Size params exist (safe defaults)
        $params = $o['params'];
        $hasColor = false; $hasSize = false;
        foreach ($params as $p) {
            $n = mb_strtolower((string)($p['name'] ?? ''), 'UTF-8');
            if (strpos($n, 'колір') !== false || strpos($n, 'color') !== false) $hasColor = true;
            if (strpos($n, 'розмір') !== false || strpos($n, 'size') !== false) $hasSize = true;
        }
        if (!$hasColor) $params[] = ['name'=>'Колір', 'value'=>'не визначено', 'unit'=>''];
        if (!$hasSize)  $params[] = ['name'=>'Розмір', 'value'=>'-', 'unit'=>''];

        $out = [
            'id' => $offerId,
            'available' => (bool)$available,
            'stock_quantity' => $stockQty,
            'categoryId' => $catId,
            'price' => $o['price'],
            'old'   => $o['old'],
            'promo' => $o['promo'],
            'pics'  => $o['pics'],
            'vendor'=> $vendor,
            'article'=> $article,
            'name_ua' => $name,
            'description_ua' => $desc,
            'params' => $params,
        ];

        fwrite($fh, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
        $stats['offers_written']++;
    });

    fclose($fh);

    if (isCli()) {
        $outPath = cliArg('out', __DIR__ . '/kasta.xml');
        writeKastaXml($outPath, $shopMeta, $usedCategories, $offersTemp);

        echo "OK. Written: {$outPath}\n";
        echo "Stats: " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        $download = !empty($_GET['download']) && (string)$_GET['download'] !== '0';
        if ($download) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="kasta.xml"');
            writeKastaXml('php://output', $shopMeta, $usedCategories, $offersTemp);
        } else {
            $qs = $_GET; $qs['download'] = 1;
            $dl = '?' . http_build_query($qs);

            echo "<!doctype html><html lang='uk'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
            echo "<title>Prom → Kasta XML (from JSON map)</title>";
            echo "<style>
                body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:18px;line-height:1.35}
                .card{border:1px solid #ddd;border-radius:12px;padding:14px;margin:12px 0;background:#fff}
                .btn{display:inline-block;padding:10px 14px;border:1px solid #111;border-radius:10px;text-decoration:none;color:#111}
                .muted{color:#666}
                input[type=text]{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px}
                .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
                @media(max-width:900px){.grid{grid-template-columns:1fr}}
            </style>";
            echo "</head><body>";
            echo "<h2>Prom → Kasta XML (з JSON мапінгу)</h2>";

            echo "<div class='card'>";
            echo "<div class='grid'>";
            echo "<div><b>Input:</b> " . h($inputPath) . "</div>";
            echo "<div><b>Map:</b> " . h($mapPath) . "</div>";
            echo "<div><b>Categories:</b> " . h($catsPath) . "</div>";
            echo "<div><b>Offers written:</b> " . (int)$stats['offers_written'] . " / " . (int)$stats['offers_total'] . "</div>";
            echo "<div><b>Skipped (no pics):</b> " . (int)$stats['offers_skipped_no_picture'] . "</div>";
            echo "<div><b>Missing map:</b> " . (int)$stats['offers_missing_map'] . "</div>";
            echo "<div><b>Unknown categoryId:</b> " . (int)$stats['offers_unknown_category'] . "</div>";
            echo "<div><b>Missing vendor:</b> " . (int)$stats['offers_missing_vendor'] . "</div>";
            echo "</div>";
            echo "<div style='margin-top:10px'><a class='btn' href='".h($dl)."'>Скачати kasta.xml</a></div>";
            echo "<p class='muted'>Категорія береться тільки з offer_overrides у JSON. Автоматичного підбору тут немає.</p>";
            echo "</div>";

            echo "<div class='card'><h3>Upload Prom XML</h3>";
            echo "<form method='post' enctype='multipart/form-data'>";
            echo "<input type='file' name='feed' accept='.xml' required> ";
            echo "<button class='btn' type='submit'>Згенерувати</button>";
            echo "</form></div>";

            echo "<div class='card'><h3>Або Prom XML по URL</h3>";
            echo "<form method='get'>";
            echo "<input type='text' name='source_url' placeholder='https://.../feed.xml' value='".h((string)($_GET['source_url'] ?? ''))."'>";
            echo "<div style='margin-top:10px'><button class='btn' type='submit'>Завантажити і згенерувати</button></div>";
            echo "</form></div>";

            echo "</body></html>";
        }
    }

    @unlink($offersTemp);
    if ($tempInputToDelete) @unlink($tempInputToDelete);

} catch (Throwable $e) {
    if (!isCli()) header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
