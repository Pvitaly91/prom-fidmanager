<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

/**
 * Convert Prom feed XML to Kasta feed XML with automatic category mapping.
 *
 * Usage (CLI):
 *   php convert_prom_to_kasta.php --input=products_feed.xml --output=kasta_feed.xml --xlsx=_mappings.xlsx --mapping=product_category_map.json
 *
 * Usage (HTTP):
 *   convert_prom_to_kasta.php?input=products_feed.xml&output=kasta_feed.xml&xlsx=_mappings.xlsx&mapping=product_category_map.json
 */

$baseDir = __DIR__;
[$inputFeed, $outputFeed, $xlsxFile, $mappingFile] = resolveInput($baseDir);

if (!is_file($inputFeed) || !is_readable($inputFeed)) {
    fail("Input feed not found/readable: {$inputFeed}");
}
if (!is_file($xlsxFile) || !is_readable($xlsxFile)) {
    fail("Mappings xlsx not found/readable: {$xlsxFile}");
}

$kastaCategories = loadKastaCategoriesFromXlsx($xlsxFile, 'Довідник Каста');
if ($kastaCategories === []) {
    fail('No Kasta categories loaded from sheet "Довідник Каста".');
}

$storedMap = loadMappingFile($mappingFile);
$tokenIdf = buildTokenIdf($kastaCategories);
$result = convertFeed($inputFeed, $outputFeed, $kastaCategories, $tokenIdf, $storedMap);
saveMappingFile($mappingFile, $result['mapping']);

success([
    'input' => $inputFeed,
    'output' => $outputFeed,
    'mapping_file' => $mappingFile,
    'offers_total' => $result['stats']['total'],
    'offers_mapped_reused' => $result['stats']['reused'],
    'offers_mapped_auto' => $result['stats']['auto'],
]);

function resolveInput(string $baseDir): array
{
    $defaults = [
        'input' => 'products_feed.xml',
        'output' => 'kasta_feed.xml',
        'xlsx' => '_mappings.xlsx',
        'mapping' => 'product_category_map.json',
    ];

    $params = $defaults;
    if (PHP_SAPI === 'cli') {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                continue;
            }
            [$k, $v] = explode('=', substr($arg, 2), 2);
            if (isset($params[$k]) && $v !== '') {
                $params[$k] = basename($v);
            }
        }
    } else {
        foreach ($params as $k => $v) {
            if (!empty($_GET[$k])) {
                $params[$k] = basename((string)$_GET[$k]);
            }
        }
    }

    return [
        $baseDir . DIRECTORY_SEPARATOR . $params['input'],
        $baseDir . DIRECTORY_SEPARATOR . $params['output'],
        $baseDir . DIRECTORY_SEPARATOR . $params['xlsx'],
        $baseDir . DIRECTORY_SEPARATOR . $params['mapping'],
    ];
}

function loadKastaCategoriesFromXlsx(string $xlsxPath, string $sheetName): array
{
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException("Cannot open xlsx: {$xlsxPath}");
    }

    $sharedStrings = loadSharedStrings($zip);
    $sheetPath = findWorksheetPathByName($zip, $sheetName);
    $xml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException("Worksheet xml not found: {$sheetPath}");
    }

    $sx = simplexml_load_string($xml);
    if ($sx === false) {
        throw new RuntimeException('Invalid worksheet XML.');
    }

    $sx->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = $sx->xpath('//x:sheetData/x:row') ?: [];

    $categories = [];
    foreach ($rows as $i => $row) {
        if ($i === 0) {
            continue;
        }

        $vals = readRowValues($row, $sharedStrings);
        $attachment = trim((string)($vals['A'] ?? ''));
        $group = trim((string)($vals['B'] ?? ''));
        $subgroup = trim((string)($vals['C'] ?? ''));
        $type = trim((string)($vals['D'] ?? ''));
        $season = trim((string)($vals['E'] ?? ''));

        if ($attachment === '' || $group === '' || $subgroup === '' || $type === '') {
            continue;
        }

        $categories[] = [
            'attachment' => $attachment,
            'group' => $group,
            'subgroup' => $subgroup,
            'type' => $type,
            'season' => $season,
            'tokens' => tokenize("{$attachment} {$group} {$subgroup} {$type}"),
        ];
    }

    return $categories;
}

function loadSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $sx = simplexml_load_string($xml);
    if ($sx === false) {
        return [];
    }

    $sx->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    foreach ($sx->xpath('//x:si') ?: [] as $si) {
        $parts = [];
        $si->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($si->xpath('.//x:t') ?: [] as $t) {
            $parts[] = (string)$t;
        }
        $strings[] = implode('', $parts);
    }

    return $strings;
}

function findWorksheetPathByName(ZipArchive $zip, string $sheetName): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        throw new RuntimeException('Invalid xlsx workbook metadata.');
    }

    $wb = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($wb === false || $rels === false) {
        throw new RuntimeException('Cannot parse workbook metadata.');
    }

    $wb->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $targetRid = null;
    foreach ($wb->xpath('//x:sheets/x:sheet') ?: [] as $sheet) {
        if ((string)$sheet['name'] === $sheetName) {
            $targetRid = (string)$sheet->attributes('r', true)['id'];
            break;
        }
    }

    if ($targetRid === null) {
        throw new RuntimeException("Sheet not found: {$sheetName}");
    }

    foreach ($rels->xpath('//pr:Relationship') ?: [] as $rel) {
        if ((string)$rel['Id'] === $targetRid) {
            return 'xl/' . ltrim((string)$rel['Target'], '/');
        }
    }

    throw new RuntimeException("Worksheet rel not found for {$sheetName}");
}

function readRowValues(SimpleXMLElement $row, array $sharedStrings): array
{
    $row->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $vals = [];

    foreach ($row->xpath('./x:c') ?: [] as $c) {
        $ref = (string)$c['r'];
        preg_match('/^[A-Z]+/', $ref, $m);
        $col = $m[0] ?? '';
        if ($col === '') {
            continue;
        }

        $type = (string)$c['t'];
        $c->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $valueNode = $c->xpath('./x:v');
        $v = $valueNode ? (string)$valueNode[0] : '';

        if ($type === 's') {
            $vals[$col] = $sharedStrings[(int)$v] ?? '';
        } else {
            $vals[$col] = $v;
        }
    }

    return $vals;
}

function loadMappingFile(string $path): array
{
    if (!is_file($path)) {
        return [
            'meta' => ['version' => 1],
            'manual_overrides' => [],
            'offers' => [],
        ];
    }

    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        return [
            'meta' => ['version' => 1],
            'manual_overrides' => [],
            'offers' => [],
        ];
    }

    $data['manual_overrides'] = is_array($data['manual_overrides'] ?? null) ? $data['manual_overrides'] : [];
    $data['offers'] = is_array($data['offers'] ?? null) ? $data['offers'] : [];

    return $data;
}

function convertFeed(string $inputPath, string $outputPath, array $kastaCategories, array $tokenIdf, array $storedMap): array
{
    $reader = new XMLReader();
    if (!$reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Cannot open input XML feed.');
    }

    $out = fopen($outputPath, 'wb');
    if (!$out) {
        throw new RuntimeException('Cannot open output file for writing.');
    }

    fwrite($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($out, "<yml_catalog date=\"" . date('Y-m-d H:i') . "\">\n  <shop>\n    <offers>\n");

    $stats = ['total' => 0, 'reused' => 0, 'auto' => 0];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'offer') {
            continue;
        }

        $offerXml = $reader->readOuterXML();
        if ($offerXml === '') {
            continue;
        }
        $offer = @simplexml_load_string($offerXml);
        if ($offer === false) {
            continue;
        }

        $stats['total']++;

        $offerData = extractOfferData($offer);
        $offerId = $offerData['id'] ?: sha1(($offerData['name'] ?? '') . '|' . ($offerData['url'] ?? ''));
        $signature = sha1(mb_strtolower($offerData['name'] . '|' . $offerData['description'] . '|' . $offerData['vendor'], 'UTF-8'));

        $category = null;
        $mappingSource = 'auto';

        if (isset($storedMap['manual_overrides'][$offerId]) && is_array($storedMap['manual_overrides'][$offerId])) {
            $category = $storedMap['manual_overrides'][$offerId];
            $mappingSource = 'manual';
            $stats['reused']++;
        } elseif (isset($storedMap['offers'][$offerId]['signature'], $storedMap['offers'][$offerId]['category'])
            && $storedMap['offers'][$offerId]['signature'] === $signature
        ) {
            $category = $storedMap['offers'][$offerId]['category'];
            $mappingSource = 'reused';
            $stats['reused']++;
        }

        if (!is_array($category)) {
            $category = pickBestCategory($offerData['name'] . ' ' . $offerData['description'], $kastaCategories, $tokenIdf);
            $stats['auto']++;
        }

        $storedMap['offers'][$offerId] = [
            'signature' => $signature,
            'category' => [
                'attachment' => $category['attachment'] ?? '',
                'group' => $category['group'] ?? '',
                'subgroup' => $category['subgroup'] ?? '',
                'type' => $category['type'] ?? '',
                'season' => $category['season'] ?? '',
            ],
            'updated_at' => date(DATE_ATOM),
            'source' => $mappingSource,
            'name' => $offerData['name'],
        ];

        writeOffer($out, $offerData, $category);
    }

    fwrite($out, "    </offers>\n  </shop>\n</yml_catalog>\n");
    fclose($out);
    $reader->close();

    $storedMap['meta']['updated_at'] = date(DATE_ATOM);
    $storedMap['meta']['offers_count'] = count($storedMap['offers']);

    return ['mapping' => $storedMap, 'stats' => $stats];
}

function extractOfferData(SimpleXMLElement $offer): array
{
    $pictures = [];
    foreach ($offer->picture as $pic) {
        $p = trim((string)$pic);
        if ($p !== '') {
            $pictures[] = $p;
        }
    }

    return [
        'id' => trim((string)$offer['id']),
        'available' => trim((string)$offer['available']) !== '' ? trim((string)$offer['available']) : 'true',
        'url' => trim((string)($offer->url ?? '')),
        'price' => trim((string)($offer->price ?? '')),
        'currencyId' => trim((string)($offer->currencyId ?? 'UAH')),
        'name' => trim((string)($offer->name ?? $offer->model ?? '')),
        'vendor' => trim((string)($offer->vendor ?? '')),
        'description' => trim((string)($offer->description ?? '')),
        'pictures' => $pictures,
    ];
}


function buildTokenIdf(array $categories): array
{
    $df = [];
    $n = max(1, count($categories));

    foreach ($categories as $category) {
        foreach (array_unique($category['tokens']) as $tok) {
            $df[$tok] = ($df[$tok] ?? 0) + 1;
        }
    }

    $idf = [];
    foreach ($df as $tok => $count) {
        $idf[$tok] = log(($n + 1) / ($count + 1)) + 1.0;
    }

    return $idf;
}

function pickBestCategory(string $productText, array $categories, array $tokenIdf): array
{
    $tokens = tokenize($productText);
    $tokenMap = array_fill_keys($tokens, true);

    $best = null;
    $bestScore = -INF;

    foreach ($categories as $category) {
        $score = 0.0;
        foreach ($category['tokens'] as $tok) {
            if (isset($tokenMap[$tok])) {
                $score += $tokenIdf[$tok] ?? 1.0;
            }
        }

        $phrase = mb_strtolower(trim(($category['subgroup'] ?? '') . ' ' . ($category['type'] ?? '')), 'UTF-8');
        $pText = mb_strtolower($productText, 'UTF-8');
        if ($phrase !== '' && mb_stripos($pText, $phrase, 0, 'UTF-8') !== false) {
            $score += 8.0;
        }

        if (str_contains($pText, 'honda') || str_contains($pText, 'civic') || str_contains($pText, 'авто')) {
            if (str_contains(mb_strtolower(($category['attachment'] ?? '') . ' ' . ($category['group'] ?? ''), 'UTF-8'), 'авто')) {
                $score += 3.0;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $category;
        }
    }

    return $best ?? $categories[0];
}

function tokenize(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    $parts = preg_split('/\s+/u', trim($text)) ?: [];

    $stop = [
        'для', 'та', 'або', 'the', 'and', 'with', 'без', 'new', 'нова', 'новий', 'шт',
        'мм', 'см', 'м', 'кг', 'г', 'л', 'мл', 'в', 'на', 'по', 'до', 'від', 'із', 'у', 'ua',
    ];
    $stopMap = array_fill_keys($stop, true);

    $tokens = [];
    foreach ($parts as $p) {
        if (mb_strlen($p, 'UTF-8') < 2 || isset($stopMap[$p])) {
            continue;
        }
        $tokens[] = $p;
    }

    return array_values(array_unique($tokens));
}

function writeOffer($out, array $offer, array $cat): void
{
    $id = xmlAttr($offer['id']);
    $available = xmlAttr($offer['available']);
    fwrite($out, "      <offer id=\"{$id}\" available=\"{$available}\">\n");

    writeTag($out, 'url', $offer['url']);
    writeTag($out, 'price', $offer['price']);
    writeTag($out, 'currencyId', $offer['currencyId']);
    writeTag($out, 'name', $offer['name']);
    writeTag($out, 'vendor', $offer['vendor']);
    writeTag($out, 'description', $offer['description']);

    foreach ($offer['pictures'] as $pic) {
        writeTag($out, 'picture', $pic);
    }

    writeParam($out, 'Приналежність*:6', $cat['attachment'] ?? '');
    writeParam($out, 'Група*:13', $cat['group'] ?? '');
    writeParam($out, 'Підгрупа*:14', $cat['subgroup'] ?? '');
    writeParam($out, 'Вид*:21', $cat['type'] ?? '');
    if (!empty($cat['season'])) {
        writeParam($out, 'Сезонність*:5', (string)$cat['season']);
    }

    fwrite($out, "      </offer>\n");
}

function writeTag($out, string $tag, string $value): void
{
    if ($value === '') {
        return;
    }
    fwrite($out, "        <{$tag}>" . xmlText($value) . "</{$tag}>\n");
}

function writeParam($out, string $name, string $value): void
{
    if ($value === '') {
        return;
    }
    fwrite($out, "        <param name=\"" . xmlAttr($name) . "\">" . xmlText($value) . "</param>\n");
}

function xmlText(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xmlAttr(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function saveMappingFile(string $path, array $mapping): void
{
    $json = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Cannot encode mapping JSON.');
    }

    file_put_contents($path, $json . PHP_EOL);
}

function success(array $payload): void
{
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => true, 'result' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

function fail(string $message): void
{
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8', true, 400);
    }
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
