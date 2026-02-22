<?php
/**
 * prom_to_kasta.php
 *
 * Converts a Prom.ua YML/XML product feed to a Kasta-compatible XML feed.
 *
 * Usage (command line):
 *   php prom_to_kasta.php [path/to/products_feed.xml] [path/to/output.xml]
 *
 * Usage (web / HTTP GET):
 *   ?input=products_feed.xml   (filename only, must be in same directory)
 *   ?output=kasta.xml          (filename only, must be in same directory)
 *   ?remap=1                   (re-run auto-mapping even for already-mapped offers)
 *   ?preview=1                 (show HTML preview instead of downloading XML)
 *
 * Files used alongside this script (all in __DIR__):
 *   kasta_categories.json   – Kasta category directory extracted from _mappings.xlsx
 *   category_mapping.json   – persisted offer→Kasta-category map (auto-created / updated)
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xe(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

$baseDir = __DIR__;
$isCli   = (PHP_SAPI === 'cli');

if ($isCli) {
    $inputFile   = $argv[1] ?? ($baseDir . '/products_feed.xml');
    $outputFile  = $argv[2] ?? ($baseDir . '/kasta.xml');
    $forceRemap  = in_array('--remap', $argv, true);
    $preview     = false;
} else {
    $rawInput  = isset($_GET['input'])  ? basename((string)$_GET['input'])  : 'products_feed.xml';
    $rawOutput = isset($_GET['output']) ? basename((string)$_GET['output']) : 'kasta.xml';
    $inputFile  = $baseDir . '/' . $rawInput;
    $outputFile = $baseDir . '/' . $rawOutput;
    $forceRemap = isset($_GET['remap']) && (string)$_GET['remap'] === '1';
    $preview    = isset($_GET['preview']) && (string)$_GET['preview'] === '1';
}

$kastaCategoriesFile = $baseDir . '/kasta_categories.json';
$mappingFile         = $baseDir . '/category_mapping.json';

// ---------------------------------------------------------------------------
// Validate paths
// ---------------------------------------------------------------------------

foreach ([$inputFile, $kastaCategoriesFile] as $path) {
    if (!is_file($path) || !is_readable($path)) {
        $msg = "File not found or not readable: {$path}\n";
        if ($isCli) { fwrite(STDERR, $msg); exit(1); }
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}

// ---------------------------------------------------------------------------
// 1. Load Kasta category directory
// ---------------------------------------------------------------------------

/**
 * @return array<int, array{id:int,affiliation:string,group:string,subgroup:string,kind:string}>
 */
function loadKastaCategories(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read kasta categories: {$path}");
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid kasta categories JSON");
    }
    return $data;
}

// ---------------------------------------------------------------------------
// 2. Load / save offer→category mapping
// ---------------------------------------------------------------------------

function loadMapping(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function saveMapping(string $path, array $mapping): void
{
    $json = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($path, $json, LOCK_EX);
}

// ---------------------------------------------------------------------------
// 3. Parse Prom feed (XMLReader for memory efficiency)
// ---------------------------------------------------------------------------

/**
 * @return array{offers: array<int, array>, promCategories: array<string, string>}
 */
function parsePromFeed(string $feedPath): array
{
    $promCategories = [];
    $offers = [];

    $reader = new XMLReader();
    if (!$reader->open($feedPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Cannot open XML feed: ' . $feedPath);
    }

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($reader->name === 'category') {
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;
            $cat = @simplexml_load_string($xml);
            if ($cat === false) continue;
            $id = trim((string)$cat['id']);
            if ($id !== '') {
                $promCategories[$id] = trim((string)$cat);
            }
            continue;
        }

        if ($reader->name === 'offer') {
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;
            $o = @simplexml_load_string($xml);
            if ($o === false) continue;

            $id = trim((string)$o['id']);
            if ($id === '') continue;

            // name
            $name = '';
            foreach (['name', 'model', 'title'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $name = trim((string)$o->$t);
                    break;
                }
            }

            // description
            $description = '';
            foreach (['description', 'description_ua'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $description = trim((string)$o->$t);
                    break;
                }
            }

            // url
            $url = '';
            foreach (['url', 'link'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $url = trim((string)$o->$t);
                    break;
                }
            }

            // pictures (up to 20)
            $pictures = [];
            if (isset($o->picture)) {
                foreach ($o->picture as $pic) {
                    $p = trim((string)$pic);
                    if ($p !== '') $pictures[] = $p;
                    if (count($pictures) >= 20) break;
                }
            }

            // vendor / brand
            $vendor = '';
            foreach (['vendor', 'brand', 'manufacturer'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $vendor = trim((string)$o->$t);
                    break;
                }
            }

            // article
            $article = '';
            foreach (['article', 'vendorcode', 'sku'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $article = trim((string)$o->$t);
                    break;
                }
            }
            // fallback: use offer id as article
            if ($article === '') {
                $article = $id;
            }

            $price    = isset($o->price)      ? trim((string)$o->price)      : '';
            $oldPrice = '';
            foreach (['price_old', 'old_price'] as $t) {
                if (isset($o->$t) && trim((string)$o->$t) !== '') {
                    $oldPrice = trim((string)$o->$t);
                    break;
                }
            }
            $currency  = isset($o->currencyId) ? trim((string)$o->currencyId) : 'UAH';
            $available = strtolower(trim((string)$o['available'])) !== 'false';

            // collect params
            $params = [];
            if (isset($o->param)) {
                foreach ($o->param as $param) {
                    $pName  = trim((string)$param['name']);
                    $pValue = trim((string)$param);
                    if ($pName !== '' && $pValue !== '') {
                        $params[$pName] = $pValue;
                    }
                }
            }

            $offers[] = [
                'id'          => $id,
                'available'   => $available,
                'name'        => $name,
                'description' => $description,
                'url'         => $url,
                'pictures'    => $pictures,
                'vendor'      => $vendor,
                'article'     => $article,
                'price'       => $price,
                'old_price'   => $oldPrice,
                'currency'    => $currency,
                'params'      => $params,
            ];
        }
    }

    $reader->close();
    return ['offers' => $offers, 'promCategories' => $promCategories];
}

// ---------------------------------------------------------------------------
// 4. Auto-map offer to best Kasta category using text similarity
// ---------------------------------------------------------------------------

/**
 * Common Ukrainian/Russian stop words (prepositions, conjunctions, particles).
 */
function stopWords(): array
{
    return array_flip([
        // Ukrainian
        'для','від','при','про','під','над','між','без','через','після',
        'перед','проти','біля','поряд','навколо','всередині','зовні',
        'та','але','або','якщо','коли','хоча','щоб','тому','бо','адже',
        'вже','ще','лише','тільки','навіть','саме','дуже','більш','менш',
        'не','ні','так','де','як','що','хто','це','той','ця','ці','те',
        'він','вона','воно','вони','його','її','їх','їхній',
        'всі','кожен','інший','свій','наш','ваш','мій','твій',
        'нові','нова','новий','нове','старий','великий','малий',
        'також','разом','між','проти','щодо','зокрема','наприклад',
        'зі','зо','із','за','на','по','до','вз','із','зо',
        // Russian
        'для','от','при','про','под','над','без','через','после',
        'перед','против','около','внутри','снаружи',
        'и','но','или','если','когда','хотя','чтобы','потому','так',
        'уже','еще','только','даже','самый','очень','более','менее',
        'не','да','где','как','что','кто','это','тот','та',
        'он','она','оно','они','его','её','их',
        'все','каждый','другой','свой','наш','ваш','мой','твой',
        'новый','новая','новые','старый','большой','малый',
        'также','вместе',
        'со','из','за','на','по','до',
    ]);
}

/**
 * Build an inverted index: word → list of category indexes (into $categories array).
 * Words come from affiliation + group + subgroup + kind joined together.
 *
 * @param array $categories
 * @return array<string, int[]>
 */
function buildCategoryIndex(array $categories): array
{
    $index = [];
    foreach ($categories as $i => $cat) {
        $text = implode(' ', [
            $cat['affiliation'] ?? '',
            $cat['group']       ?? '',
            $cat['subgroup']    ?? '',
            $cat['kind']        ?? '',
        ]);
        $words = tokenize($text);
        foreach (array_unique($words) as $word) {
            $index[$word][] = $i;
        }
    }
    return $index;
}

function tokenize(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    // split on non-alphanumeric (keep Cyrillic & Latin letters and digits)
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stops = stopWords();
    // filter: min 4 chars, not a stop word
    return array_values(array_filter(
        $words,
        fn($w) => mb_strlen($w, 'UTF-8') >= 4 && !isset($stops[$w])
    ));
}

/**
 * Find the Kasta category that best matches the given product name + description.
 *
 * Strategy:
 *  1. Score categories using product NAME tokens only (high confidence).
 *  2. If no name-token matches or tie, boost with description tokens (lower weight).
 *
 * IDF: words appearing in fewer categories get higher weight (discriminative).
 *
 * @param string  $name
 * @param string  $description
 * @param array   $categories       full kasta categories array
 * @param array   $index            inverted index from buildCategoryIndex()
 * @return array  matched category entry (with id, affiliation, group, subgroup, kind)
 */
function findBestCategory(string $name, string $description, array $categories, array $index): array
{
    $nameWords = array_unique(tokenize($name));
    $descWords = array_unique(tokenize(strip_tags($description)));

    $scores = [];
    $totalCats = count($categories);

    // Score from product name (weight = 3.0 × IDF)
    foreach ($nameWords as $word) {
        if (!isset($index[$word])) continue;
        $df = count($index[$word]);
        // IDF: log(N / df) — higher for rare terms
        $idf = log(($totalCats + 1) / ($df + 1));
        if ($idf <= 0) continue; // skip words too common across categories
        $weight = 3.0 * $idf;
        foreach ($index[$word] as $catIdx) {
            $scores[$catIdx] = ($scores[$catIdx] ?? 0.0) + $weight;
        }
    }

    // Score from description (weight = 1.0 × IDF) — only for unique desc words not in name
    $descOnlyWords = array_diff($descWords, $nameWords);
    foreach ($descOnlyWords as $word) {
        if (!isset($index[$word])) continue;
        $df = count($index[$word]);
        $idf = log(($totalCats + 1) / ($df + 1));
        if ($idf <= 0) continue;
        foreach ($index[$word] as $catIdx) {
            $scores[$catIdx] = ($scores[$catIdx] ?? 0.0) + $idf;
        }
    }

    if (empty($scores)) {
        return $categories[0];
    }

    arsort($scores);
    $bestIdx = array_key_first($scores);
    return $categories[$bestIdx];
}

// ---------------------------------------------------------------------------
// 5. Generate Kasta XML
// ---------------------------------------------------------------------------

function generateKastaXml(array $offers, array $mapping, array $categoriesById): string
{
    // Collect used categories
    $usedCatIds = [];
    foreach ($offers as $offer) {
        $oid = $offer['id'];
        if (isset($mapping[$oid])) {
            $cid = (int)$mapping[$oid]['kasta_category_id'];
            if (!isset($usedCatIds[$cid])) {
                $usedCatIds[$cid] = true;
            }
        }
    }

    $date = date('Y-m-d H:i');

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<yml_catalog date="' . xe($date) . '">' . "\n";
    $xml .= '  <shop>' . "\n";
    $xml .= '    <currencies>' . "\n";
    $xml .= '      <currency id="UAH" rate="1"/>' . "\n";
    $xml .= '    </currencies>' . "\n";

    // Categories
    $xml .= '    <categories>' . "\n";
    foreach (array_keys($usedCatIds) as $cid) {
        $cat = $categoriesById[$cid] ?? null;
        if (!$cat) continue;
        $catName = implode(' > ', array_filter([
            $cat['affiliation'],
            $cat['group'],
            $cat['subgroup'],
            $cat['kind'],
        ]));
        $xml .= '      <category id="' . $cid . '">' . xe($catName) . '</category>' . "\n";
    }
    $xml .= '    </categories>' . "\n";

    // Offers
    $xml .= '    <offers>' . "\n";
    foreach ($offers as $offer) {
        $oid  = $offer['id'];
        $avail = $offer['available'] ? 'true' : 'false';

        // Sanitise offer id for Kasta (only Aa-Zz, 0-9 allowed)
        $safeId = preg_replace('/[^A-Za-z0-9]/', '', $oid);
        if ($safeId === '') $safeId = 'offer' . $oid;

        $catId = '';
        if (isset($mapping[$oid])) {
            $catId = (string)(int)$mapping[$oid]['kasta_category_id'];
        }

        $xml .= '      <offer id="' . xe($safeId) . '" available="' . $avail . '">' . "\n";

        if ($catId !== '') {
            $xml .= '        <categoryId>' . xe($catId) . '</categoryId>' . "\n";
        }

        $xml .= '        <currencyId>UAH</currencyId>' . "\n";

        if ($offer['price'] !== '') {
            $xml .= '        <price>' . xe($offer['price']) . '</price>' . "\n";
        }
        if ($offer['old_price'] !== '') {
            $xml .= '        <price_old>' . xe($offer['old_price']) . '</price_old>' . "\n";
        }

        foreach ($offer['pictures'] as $pic) {
            $xml .= '        <picture>' . xe($pic) . '</picture>' . "\n";
        }

        if ($offer['vendor'] !== '') {
            $xml .= '        <vendor>' . xe($offer['vendor']) . '</vendor>' . "\n";
        }

        $xml .= '        <article>' . xe($offer['article']) . '</article>' . "\n";

        if ($offer['name'] !== '') {
            $xml .= '        <name_ua>' . xe($offer['name']) . '</name_ua>' . "\n";
        }

        if ($offer['url'] !== '') {
            $xml .= '        <url>' . xe($offer['url']) . '</url>' . "\n";
        }

        if ($offer['description'] !== '') {
            // Strip HTML tags from description, trim
            $descClean = trim(strip_tags($offer['description']));
            if ($descClean !== '') {
                $xml .= '        <description_ua>' . xe($descClean) . '</description_ua>' . "\n";
            }
        }

        // Colour param (default "комбінований" if not set)
        $colour = $offer['params']['Колір'] ?? $offer['params']['Цвет'] ?? 'комбінований';
        $xml .= '        <param name="Колір">' . xe($colour) . '</param>' . "\n";

        // Size param (default "-" if not set)
        $size = $offer['params']['Розмір'] ?? $offer['params']['Размер'] ?? '-';
        $xml .= '        <param name="Розмір">' . xe($size) . '</param>' . "\n";

        // Pass through other params
        $skipParams = ['Колір', 'Цвет', 'Розмір', 'Размер'];
        foreach ($offer['params'] as $pn => $pv) {
            if (!in_array($pn, $skipParams, true)) {
                $xml .= '        <param name="' . xe($pn) . '">' . xe($pv) . '</param>' . "\n";
            }
        }

        $xml .= '      </offer>' . "\n";
    }
    $xml .= '    </offers>' . "\n";
    $xml .= '  </shop>' . "\n";
    $xml .= '</yml_catalog>' . "\n";

    return $xml;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

try {
    // Load Kasta categories
    $kastaCategories = loadKastaCategories($kastaCategoriesFile);
    // Build lookup by id
    $categoriesById = [];
    foreach ($kastaCategories as $cat) {
        $categoriesById[$cat['id']] = $cat;
    }

    // Load persisted mapping
    $mapping = loadMapping($mappingFile);

    // Parse Prom feed
    $feedData      = parsePromFeed($inputFile);
    $offers        = $feedData['offers'];
    $promCategories = $feedData['promCategories'];

    $totalOffers   = count($offers);
    $newlyMapped   = 0;
    $alreadyMapped = 0;

    // Build index lazily only if needed
    $index = null;

    foreach ($offers as &$offer) {
        $oid = $offer['id'];

        if (!$forceRemap && isset($mapping[$oid])) {
            // Verify the stored kasta_category_id still exists; if not, remap
            $storedCatId = (int)$mapping[$oid]['kasta_category_id'];
            if (isset($categoriesById[$storedCatId])) {
                $alreadyMapped++;
                continue;
            }
        }

        // Need to auto-map this offer
        if ($index === null) {
            $index = buildCategoryIndex($kastaCategories);
        }

        $bestCat = findBestCategory(
            $offer['name'],
            $offer['description'],
            $kastaCategories,
            $index
        );

        $mapping[$oid] = [
            'kasta_category_id' => $bestCat['id'],
            'affiliation'       => $bestCat['affiliation'],
            'group'             => $bestCat['group'],
            'subgroup'          => $bestCat['subgroup'],
            'kind'              => $bestCat['kind'],
            'auto_mapped'       => true,
            'mapped_at'         => date('Y-m-d'),
        ];
        $newlyMapped++;
    }
    unset($offer);

    // Persist mapping
    saveMapping($mappingFile, $mapping);

    // Generate Kasta XML
    $kastaXml = generateKastaXml($offers, $mapping, $categoriesById);

    // Write output file
    file_put_contents($outputFile, $kastaXml, LOCK_EX);

    $mappedCount = count(array_filter($offers, fn($o) => isset($mapping[$o['id']])));

    if ($isCli) {
        echo "Done.\n";
        echo "Total offers   : {$totalOffers}\n";
        echo "Newly mapped   : {$newlyMapped}\n";
        echo "Already mapped : {$alreadyMapped}\n";
        echo "Output written : {$outputFile}\n";
        echo "Mapping file   : {$mappingFile}\n";
    } elseif ($preview) {
        // HTML preview
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Kasta Feed Preview</title>';
        echo '<style>body{font-family:system-ui,sans-serif;margin:16px;line-height:1.4}';
        echo 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;font-size:13px}';
        echo 'th{background:#f5f5f5}tr:nth-child(even){background:#fafafa}';
        echo '.pill{display:inline-block;padding:4px 10px;border:1px solid #ddd;border-radius:999px;background:#fff;margin:4px}';
        echo '</style></head><body>';
        echo '<h2>Kasta Feed Preview</h2>';
        echo '<div>';
        echo '<span class="pill"><b>Offers:</b> ' . (int)$totalOffers . '</span>';
        echo '<span class="pill"><b>Newly mapped:</b> ' . (int)$newlyMapped . '</span>';
        echo '<span class="pill"><b>Already mapped:</b> ' . (int)$alreadyMapped . '</span>';
        echo '<span class="pill"><b>Output:</b> ' . h(basename($outputFile)) . '</span>';
        echo '<span class="pill"><a href="?output=' . h(basename($outputFile)) . '&download=1">⬇ Download XML</a></span>';
        echo '</div>';
        echo '<table><thead><tr><th>#</th><th>offer_id</th><th>Name</th><th>Kasta category</th><th>Kind</th><th>Price</th></tr></thead><tbody>';
        $i = 0;
        foreach ($offers as $offer) {
            $i++;
            $oid = $offer['id'];
            $m   = $mapping[$oid] ?? null;
            $catLabel = $m
                ? h($m['affiliation'] . ' › ' . $m['group'] . ' › ' . $m['subgroup'])
                : '<span style="color:red">unmapped</span>';
            $kind = $m ? h($m['kind']) : '';
            echo '<tr>';
            echo '<td>' . $i . '</td>';
            echo '<td>' . h($oid) . '</td>';
            echo '<td>' . h(mb_substr($offer['name'], 0, 80, 'UTF-8')) . '</td>';
            echo '<td>' . $catLabel . '</td>';
            echo '<td>' . $kind . '</td>';
            echo '<td>' . h($offer['price']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
    } else {
        // Download the XML
        $filename = basename($outputFile);
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($kastaXml));
        echo $kastaXml;
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
    exit;
}
