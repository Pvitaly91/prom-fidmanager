<?php
/**
 * kasta_feed.php
 *
 * Converts a Prom.ua YML/XML product feed to a Kasta-compatible XML feed.
 *
 * Usage (CLI):
 *   php kasta_feed.php [input_file] [output_file]
 *   php kasta_feed.php products_feed.xml kasta.xml
 *
 * Usage (Web):
 *   ?file=products_feed.xml              — process feed and output XML
 *   ?file=products_feed.xml&preview=1    — show HTML report instead of XML
 *   ?remap=1                             — force re-mapping even for already mapped products
 *
 * Supporting files (same directory):
 *   kasta_categories.json   — Kasta category list (extracted from _mappings.xlsx)
 *   kasta_mapping.json      — stored product→category mappings (auto-created if missing)
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const KASTA_SHOP_NAME    = 'DIY mag';
const KASTA_SHOP_COMPANY = 'DIY mag';
const KASTA_SHOP_URL     = 'https://diystore.prom.ua/';
const MAX_PICTURES       = 20;   // Kasta limit

$baseDir         = __DIR__;
$categoriesFile  = $baseDir . '/kasta_categories.json';
$mappingFile     = $baseDir . '/kasta_mapping.json';

// Resolve input/output
if (PHP_SAPI === 'cli') {
    $inputFile   = $argv[1] ?? ($baseDir . '/products_feed.xml');
    $outputFile  = $argv[2] ?? ($baseDir . '/kasta.xml');
    $previewMode = false;
    $forceRemap  = in_array('--remap', $argv, true);
} else {
    $inputName   = isset($_GET['file']) ? basename((string)$_GET['file']) : 'products_feed.xml';
    $inputFile   = $baseDir . '/' . $inputName;
    $outputFile  = $baseDir . '/kasta.xml';
    $previewMode = !empty($_GET['preview']);
    $forceRemap  = !empty($_GET['remap']);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * HTML-escape a string.
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Tokenise Ukrainian/Latin text into lowercase word tokens.
 * Removes stop-words and very short tokens.
 */
function tokenize(string $text): array
{
    static $stopWords = [
        // Ukrainian prepositions / conjunctions / pronouns
        'для', 'та', 'або', 'але', 'що', 'як', 'якщо', 'тому', 'бо', 'то',
        'це', 'той', 'ці', 'цей', 'цього', 'цих', 'ним', 'його', 'нею',
        'він', 'вона', 'вони', 'яка', 'який', 'які', 'яке', 'якій', 'якого',
        'при', 'під', 'над', 'між', 'через', 'від', 'до', 'по', 'на',
        'за', 'без', 'про', 'зі', 'із', 'уже', 'ще', 'вже', 'тут', 'там',
        'не', 'ні', 'так', 'дуже', 'більш', 'менш', 'тощо', 'після', 'також',
        'коли', 'поки', 'щоб', 'хоча', 'зате', 'хоч', 'ніж', 'коли', 'тоді',
        'можна', 'треба', 'потрібно', 'цьому', 'цієї', 'цих', 'лише', 'тільки',
        'нові', 'нова', 'новий', 'нове', 'вашого', 'вашій', 'ваших', 'вашим',
        'якщо', 'також', 'самих', 'самому', 'цим', 'цієї', 'тими',
        // English stop words
        'all', 'for', 'and', 'the', 'with', 'in', 'of', 'to', 'is',
        'are', 'was', 'has', 'have', 'this', 'that', 'from', 'into',
    ];

    $text  = mb_strtolower($text, 'UTF-8');
    // Split on anything that is not a letter or digit
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return [];
    }

    $tokens = [];
    foreach ($parts as $w) {
        if (mb_strlen($w, 'UTF-8') < 3) {
            continue;
        }
        if (in_array($w, $stopWords, true)) {
            continue;
        }
        $tokens[] = $w;
    }
    return $tokens;
}

// ---------------------------------------------------------------------------
// Load Kasta categories
// ---------------------------------------------------------------------------

/**
 * Load Kasta categories from JSON.
 * Each entry: {id, affiliation, group, subgroup, kind}
 *
 * @return array<int, array{id:int,affiliation:string,group:string,subgroup:string,kind:string}>
 */
function loadKastaCategories(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("Kasta categories file not found: {$file}");
    }
    $json = file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException("Cannot read: {$file}");
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in: {$file}");
    }
    // Index by id
    $indexed = [];
    foreach ($data as $cat) {
        $indexed[(int)$cat['id']] = $cat;
    }
    return $indexed;
}

/**
 * Build an inverted word-index over the Kasta category list and compute IDF.
 *
 * Returns: [index, idf]
 *   index: word => [ [catId, fieldWeight], ... ]
 *   idf:   word => float  (log(N / df))
 *
 * Field weights: kind=3, subgroup=2, group=1, affiliation=1
 */
function buildWordIndex(array $categories): array
{
    $index = [];
    $df    = []; // word => number of categories containing the word

    foreach ($categories as $cat) {
        $id = (int)$cat['id'];
        $fields = [
            'kind'        => 3,
            'subgroup'    => 2,
            'group'       => 1,
            'affiliation' => 1,
        ];
        $seenInCat = [];
        foreach ($fields as $field => $weight) {
            foreach (tokenize((string)$cat[$field]) as $word) {
                $index[$word][] = [$id, $weight];
                if (!isset($seenInCat[$word])) {
                    $df[$word] = ($df[$word] ?? 0) + 1;
                    $seenInCat[$word] = true;
                }
            }
        }
    }

    // Compute IDF: log(N / df)
    $N   = count($categories);
    $idf = [];
    foreach ($df as $word => $freq) {
        $idf[$word] = log($N / $freq);
    }

    return [$index, $idf];
}

// ---------------------------------------------------------------------------
// Mapping store
// ---------------------------------------------------------------------------

function loadMapping(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $json = file_get_contents($file);
    if ($json === false || $json === '') {
        return [];
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (\Throwable) {
        return [];
    }
}

function saveMapping(string $file, array $mapping): void
{
    $json = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write mapping file: {$file}");
    }
}

// ---------------------------------------------------------------------------
// Category finder
// ---------------------------------------------------------------------------

/**
 * Find the best Kasta category ID for a product given its text.
 * Uses IDF-weighted scoring: score(c) = Σ IDF(w) × field_weight(w, c) for matching words w.
 *
 * Strategy:
 *   1. Match on name + vendor tokens (primary)
 *   2. Add domain-bias tokens if automotive/phone/electronics keywords detected
 *   3. If zero score, extend with description tokens (fallback)
 *   4. Apply domain validation to filter obviously wrong matches
 *
 * @param array<string,list<array{0:int,1:int}>> $wordIndex
 * @param array<string,float>                    $idf
 */
function findBestCategoryId(
    string $productName,
    string $productVendor,
    string $productDescription,
    array  $categories,
    array  $wordIndex,
    array  $idf
): int {
    $productTextLower = mb_strtolower($productName . ' ' . $productVendor, 'UTF-8');

    // Tier 1: name + vendor (most precise, avoid description noise)
    $primaryWords = array_unique(tokenize($productName . ' ' . $productName . ' ' . $productVendor));

    // Add domain-bias tokens to steer matching toward the right affiliation
    // These tokens appear in Kasta category paths and help resolve ambiguous words
    $biasTokens = getDomainBiasTokens($productTextLower);
    if (!empty($biasTokens)) {
        $primaryWords = array_unique(array_merge($primaryWords, $biasTokens));
    }

    $scores = scoreWords($primaryWords, $wordIndex, $idf);

    // Tier 2: if no matches, extend with first 200 chars of description
    if (empty($scores)) {
        $descWords = array_unique(tokenize(mb_substr($productDescription, 0, 200, 'UTF-8')));
        $descWords = array_values(array_diff($descWords, $primaryWords));
        $scores    = scoreWords($descWords, $wordIndex, $idf);
    }

    if (empty($scores)) {
        return array_key_first($categories);
    }

    arsort($scores);

    // Pick the best category that passes domain validation
    $bestFallback = null;

    foreach (array_keys($scores) as $candidateId) {
        $candidateId = (int)$candidateId;
        if (!isset($categories[$candidateId])) {
            continue;
        }
        if (isCategoryValidForProduct($candidateId, $productTextLower, $categories)) {
            return $candidateId;
        }
        if ($bestFallback === null) {
            $bestFallback = $candidateId;
        }
    }

    // All scored candidates failed domain validation — use best scored anyway
    return $bestFallback ?? array_key_first($categories);
}

/**
 * Returns additional "bias" tokens to add to product search when domain is detected.
 * These extra tokens steer scoring toward the correct Kasta affiliation.
 */
function getDomainBiasTokens(string $productTextLower): array
{
    // Automotive: add affiliation + key automotive spare parts tokens
    $carBrands = ['honda', 'civic', 'toyota', 'bmw', 'audi', 'ford', 'chevrolet',
                  'mercedes', 'volkswagen', 'nissan', 'hyundai', 'mazda', 'opel',
                  'mitsubishi', 'subaru', 'renault', 'peugeot', 'kia', 'skoda'];
    foreach ($carBrands as $brand) {
        if (str_contains($productTextLower, $brand)) {
            return ['автотовари', 'запасні', 'частини', 'авто', 'мото'];
        }
    }

    // Phone / tablet
    $phoneKeywords = ['google pixel', 'iphone', 'samsung galaxy', 'xiaomi', 'huawei',
                      'телефон', 'смартфон', 'планшет'];
    foreach ($phoneKeywords as $kw) {
        if (str_contains($productTextLower, $kw)) {
            return ['смартфони', 'телефони', 'аксесуари'];
        }
    }

    return [];
}

/**
 * Returns false if the category is obviously wrong for the product domain.
 * Uses lightweight keyword heuristics to avoid the worst cross-domain mismatch.
 *
 * @param array<int, array{affiliation:string, group:string, subgroup:string, kind:string}> $categories
 */
function isCategoryValidForProduct(int $catId, string $productTextLower, array $categories): bool
{
    $cat = $categories[$catId] ?? null;
    if (!$cat) {
        return true;
    }

    $catAffiliation = $cat['affiliation'];

    // Automotive/vehicle product indicators
    $autoKeywords = ['honda', 'civic', 'toyota', 'bmw', 'audi', 'ford', 'chevrolet',
                     'mercedes', 'volkswagen', 'nissan', 'hyundai', 'opel', 'mazda',
                     'subaru', 'mitsubishi', 'kia', 'skoda', 'renault', 'peugeot',
                     'колектор', 'підвіск', 'важіль', 'важел', 'стабілізатор',
                     'кузов', 'двигун', 'розпірк', 'стакан', 'шестерн',
                     'бризковик', 'радіатор', 'гальм', 'коліс', 'шини',
                     'запчасти', 'тюнінг'];

    $isAutomotive = false;
    foreach ($autoKeywords as $kw) {
        if (str_contains($productTextLower, $kw)) {
            $isAutomotive = true;
            break;
        }
    }

    // If product is automotive, reject non-automotive affiliations that have
    // homonyms causing false positives
    if ($isAutomotive) {
        $rejectedAffiliations = [
            'жінкам', 'дівчаткам', 'хлопчикам', 'дітям',
            'краса і здоров\'я', 'зоотовари', 'спорт',
            'послуги',
        ];
        foreach ($rejectedAffiliations as $rej) {
            if (str_contains($catAffiliation, $rej)) {
                return false;
            }
        }
        // If product has a specific car brand, also reject home appliances and TV
        $carBrands = ['honda', 'civic', 'toyota', 'bmw', 'audi', 'ford',
                      'mercedes', 'volkswagen', 'nissan', 'hyundai', 'mazda'];
        foreach ($carBrands as $brand) {
            if (str_contains($productTextLower, $brand)) {
                $alsoRejected = [
                    'побутова техніка', 'сантехніка', 'дім > колекціон',
                    'телевізори', 'смартфони', 'ноутбуки', 'акустика',
                    'дім > декор', 'дім > текстиль', 'дітям',
                ];
                foreach ($alsoRejected as $rej) {
                    $haystack = $catAffiliation . ' > ' . $cat['group'] . ' > ' . $cat['subgroup'];
                    if (str_contains($haystack, $rej)) {
                        return false;
                    }
                }
                break;
            }
        }
    }

    // Phone/tablet product indicators
    $phoneKeywords = ['google', 'pixel', 'iphone', 'samsung', 'xiaomi', 'huawei',
                      'телефон', 'смартфон', 'планшет', 'ipad', 'android'];
    $isPhone = false;
    foreach ($phoneKeywords as $kw) {
        if (str_contains($productTextLower, $kw)) {
            $isPhone = true;
            break;
        }
    }

    // Phone products should not be in automotive categories (unless car-related accessories)
    if ($isPhone) {
        if (str_contains($catAffiliation, 'жінкам') ||
            str_contains($catAffiliation, 'дівчаткам') ||
            str_contains($catAffiliation, 'дітям')) {
            return false;
        }
    }

    return true;
}

/**
 * Score categories by IDF-weighted word matching.
 *
 * @param  list<string>                              $words
 * @param  array<string,list<array{0:int,1:int}>>   $wordIndex
 * @param  array<string,float>                       $idf
 * @return array<int,float>
 */
function scoreWords(array $words, array $wordIndex, array $idf): array
{
    $scores = [];
    foreach ($words as $word) {
        if (!isset($wordIndex[$word])) {
            continue;
        }
        $wordIdf = $idf[$word] ?? 0.0;
        foreach ($wordIndex[$word] as [$catId, $fieldWeight]) {
            $scores[$catId] = ($scores[$catId] ?? 0.0) + $wordIdf * $fieldWeight;
        }
    }
    return $scores;
}

// ---------------------------------------------------------------------------
// Prom feed parser
// ---------------------------------------------------------------------------

/**
 * Parse a Prom.ua YML/XML feed.
 *
 * @return array{shopName:string, shopUrl:string, offers:list<array>}
 */
function parsePromFeed(string $feedPath): array
{
    $reader = new XMLReader();
    if (!$reader->open($feedPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Cannot open XML feed: {$feedPath}");
    }

    $shopName = KASTA_SHOP_NAME;
    $shopUrl  = KASTA_SHOP_URL;
    $offers   = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($reader->name === 'name' || $reader->name === 'url') {
            // Top-level shop info (inside <shop> but before <categories>)
            // We'll skip – use constants
            continue;
        }

        if ($reader->name === 'offer') {
            $xml = $reader->readOuterXML();
            if ($xml === '') {
                continue;
            }
            $offer = @simplexml_load_string($xml);
            if ($offer === false) {
                continue;
            }

            $offerId   = trim((string)$offer['id']);
            $available = strtolower(trim((string)$offer['available'])) !== 'false';

            // Name (prefer Ukrainian name tags)
            $name = '';
            foreach (['name_ua', 'name_uk', 'ua_name', 'uk_name', 'name', 'model', 'title'] as $tag) {
                if (isset($offer->$tag) && trim((string)$offer->$tag) !== '') {
                    $name = trim((string)$offer->$tag);
                    break;
                }
            }

            // Description (prefer Ukrainian)
            $description = '';
            foreach (['description_ua', 'description_uk', 'description'] as $tag) {
                if (isset($offer->$tag) && trim((string)$offer->$tag) !== '') {
                    $description = trim((string)$offer->$tag);
                    break;
                }
            }

            // URL
            $url = '';
            foreach (['url', 'link'] as $tag) {
                if (isset($offer->$tag) && trim((string)$offer->$tag) !== '') {
                    $url = trim((string)$offer->$tag);
                    break;
                }
            }

            // Price
            $price    = isset($offer->price)      ? trim((string)$offer->price)      : '';
            $priceOld = isset($offer->price_old)  ? trim((string)$offer->price_old)  : '';
            if ($priceOld === '') {
                $priceOld = isset($offer->old_price) ? trim((string)$offer->old_price) : '';
            }
            $currency = isset($offer->currencyId) ? trim((string)$offer->currencyId) : 'UAH';

            // Vendor / brand
            $vendor = isset($offer->vendor) ? trim((string)$offer->vendor) : '';

            // Article – Prom feeds rarely include this; fall back to offer id
            $article = '';
            foreach (['article', 'vendorcode', 'vendor_code'] as $tag) {
                if (isset($offer->$tag) && trim((string)$offer->$tag) !== '') {
                    $article = trim((string)$offer->$tag);
                    break;
                }
            }
            // Check param named "Артикул" as fallback
            if ($article === '' && isset($offer->param)) {
                foreach ($offer->param as $param) {
                    $pName = strtolower(trim((string)$param['name']));
                    if ($pName === 'артикул' || $pName === 'article') {
                        $article = trim((string)$param);
                        break;
                    }
                }
            }
            if ($article === '') {
                $article = $offerId; // use offer ID as article fallback
            }

            // Pictures (up to MAX_PICTURES)
            $pictures = [];
            if (isset($offer->picture)) {
                foreach ($offer->picture as $pic) {
                    $p = trim((string)$pic);
                    if ($p !== '') {
                        $pictures[] = $p;
                        if (count($pictures) >= MAX_PICTURES) {
                            break;
                        }
                    }
                }
            }
            // Alternative image tags
            if (empty($pictures)) {
                if (isset($offer->images->image)) {
                    foreach ($offer->images->image as $img) {
                        $p = trim((string)$img);
                        if ($p !== '') {
                            $pictures[] = $p;
                            if (count($pictures) >= MAX_PICTURES) {
                                break;
                            }
                        }
                    }
                }
            }

            // Stock
            $stock = null;
            foreach (['stock_quantity', 'quantity_in_stock', 'stock'] as $tag) {
                if (isset($offer->$tag)) {
                    $val = trim((string)$offer->$tag);
                    if (is_numeric($val) && (int)$val >= 0) {
                        $stock = (int)$val;
                        break;
                    }
                }
            }
            if ($stock === null) {
                $stock = $available ? 1 : 0;
            }

            // Params
            $params = [];
            if (isset($offer->param)) {
                foreach ($offer->param as $param) {
                    $pName = trim((string)$param['name']);
                    $pVal  = trim((string)$param);
                    if ($pName !== '' && $pVal !== '') {
                        $params[] = ['name' => $pName, 'value' => $pVal];
                    }
                }
            }

            $offers[] = [
                'id'          => $offerId,
                'available'   => $available,
                'name'        => $name,
                'description' => $description,
                'url'         => $url,
                'price'       => $price,
                'price_old'   => $priceOld,
                'currency'    => $currency,
                'vendor'      => $vendor,
                'article'     => $article,
                'pictures'    => $pictures,
                'stock'       => $stock,
                'params'      => $params,
            ];
        }
    }

    $reader->close();

    return [
        'shopName' => $shopName,
        'shopUrl'  => $shopUrl,
        'offers'   => $offers,
    ];
}

// ---------------------------------------------------------------------------
// Kasta XML generator
// ---------------------------------------------------------------------------

/**
 * Build the hierarchical category tree for used Kasta categories.
 * Returns [categoriesXml:string, catIdMap:array<int,int>]
 *   catIdMap maps original Kasta cat id → xml output id (leaf node)
 *
 * The XML IDs are assigned by hierarchical level:
 *   affiliation → group → subgroup → kind (leaf)
 * IDs are compacted to only categories actually used.
 */
function buildKastaCategoryXml(array $usedCatIds, array $categories): array
{
    // Gather used categories
    $usedCats = [];
    foreach ($usedCatIds as $catId) {
        if (isset($categories[$catId])) {
            $usedCats[$catId] = $categories[$catId];
        }
    }

    // Build a 4-level hierarchy
    // affKey → affiliationId
    // affKey/groupKey → groupId
    // affKey/groupKey/subKey → subgroupId
    // catId (kind) → kindId (= catId itself, used in <offer>)

    $nodeIds    = []; // path => id
    $nodeNames  = []; // id => name
    $nodeParent = []; // id => parentId|null
    $nextId     = 1;

    foreach ($usedCats as $catId => $cat) {
        // Level 1: affiliation
        $affKey = $cat['affiliation'];
        if (!isset($nodeIds[$affKey])) {
            $nodeIds[$affKey]    = $nextId;
            $nodeNames[$nextId]  = $cat['affiliation'];
            $nodeParent[$nextId] = null;
            $nextId++;
        }
        $affId = $nodeIds[$affKey];

        // Level 2: group
        $grpKey = $affKey . '///' . $cat['group'];
        if (!isset($nodeIds[$grpKey])) {
            $nodeIds[$grpKey]    = $nextId;
            $nodeNames[$nextId]  = $cat['group'];
            $nodeParent[$nextId] = $affId;
            $nextId++;
        }
        $grpId = $nodeIds[$grpKey];

        // Level 3: subgroup
        $subKey = $grpKey . '///' . $cat['subgroup'];
        if (!isset($nodeIds[$subKey])) {
            $nodeIds[$subKey]    = $nextId;
            $nodeNames[$nextId]  = $cat['subgroup'];
            $nodeParent[$nextId] = $grpId;
            $nextId++;
        }
        $subId = $nodeIds[$subKey];

        // Level 4: kind — use catId as the XML id for direct offer reference
        // (offset by a large number to avoid collisions with level 1-3 ids)
        $kindXmlId = 100000 + $catId;
        $nodeNames[$kindXmlId]  = $cat['kind'];
        $nodeParent[$kindXmlId] = $subId;
    }

    // Build XML
    $xml  = "  <categories>\n";
    foreach ($nodeNames as $xmlId => $name) {
        $parentId = $nodeParent[$xmlId];
        if ($parentId !== null) {
            $xml .= '    <category id="' . $xmlId . '" parentId="' . $parentId . '">'
                  . h((string)$name) . "</category>\n";
        } else {
            $xml .= '    <category id="' . $xmlId . '">'
                  . h((string)$name) . "</category>\n";
        }
    }
    $xml .= "  </categories>\n";

    // catIdMap: original Kasta id → XML kind id used in offers
    $catIdMap = [];
    foreach ($usedCats as $catId => $cat) {
        $catIdMap[$catId] = 100000 + $catId;
    }

    return [$xml, $catIdMap];
}

/**
 * Generate the full Kasta XML output.
 */
function generateKastaXml(
    array $feedData,
    array $mapping,       // offerId => kastaCategoryId
    array $categories,    // Kasta categories indexed by id
    bool  $includeUnavailable = false
): string {
    $offers     = $feedData['offers'];
    $shopName   = $feedData['shopName'];
    $shopUrl    = $feedData['shopUrl'];

    // Collect used category IDs
    $usedCatIds = [];
    foreach ($offers as $offer) {
        if (!$includeUnavailable && !$offer['available']) {
            continue;
        }
        $offerId = $offer['id'];
        if (isset($mapping[$offerId])) {
            $catId = (int)$mapping[$offerId]['kasta_category_id'];
            $usedCatIds[$catId] = true;
        }
    }

    [$categoriesXml, $catIdMap] = buildKastaCategoryXml(array_keys($usedCatIds), $categories);

    $date = date('Y-m-d H:i');

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<yml_catalog date="' . $date . '">' . "\n";
    $xml .= "<shop>\n";
    $xml .= '  <name>'    . h($shopName) . "</name>\n";
    $xml .= '  <company>' . h($shopName) . "</company>\n";
    $xml .= '  <url>'     . h($shopUrl)  . "</url>\n";
    $xml .= "  <currencies>\n";
    $xml .= '    <currency id="UAH" rate="1"/>' . "\n";
    $xml .= "  </currencies>\n";
    $xml .= $categoriesXml;
    $xml .= "  <offers>\n";

    foreach ($offers as $offer) {
        $offerId   = $offer['id'];
        $available = $offer['available'] ? 'true' : 'false';

        if (!$includeUnavailable && !$offer['available']) {
            continue;
        }

        if (!isset($mapping[$offerId])) {
            continue; // no mapping, skip (shouldn't happen after processing)
        }

        $kastaCatId  = (int)$mapping[$offerId]['kasta_category_id'];
        $xmlCatId    = $catIdMap[$kastaCatId] ?? null;

        if ($xmlCatId === null) {
            continue; // category not in output tree
        }

        $name        = xmlEscape($offer['name']);
        $description = xmlEscape($offer['description']);
        $vendor      = xmlEscape($offer['vendor']);
        $article     = xmlEscape($offer['article']);
        $price       = $offer['price'];
        $priceOld    = $offer['price_old'];
        $stock       = (int)$offer['stock'];

        $xml .= '    <offer id="' . h($offerId) . '" available="' . $available . '">' . "\n";
        $xml .= '      <currencyId>UAH</currencyId>' . "\n";
        $xml .= '      <categoryId>' . $xmlCatId . '</categoryId>' . "\n";
        if ($price !== '') {
            $xml .= '      <price>' . h($price) . '</price>' . "\n";
        }
        if ($priceOld !== '') {
            $xml .= '      <price_old>' . h($priceOld) . '</price_old>' . "\n";
        }
        $xml .= '      <stock_quantity>' . $stock . '</stock_quantity>' . "\n";

        foreach ($offer['pictures'] as $pic) {
            $xml .= '      <picture>' . h($pic) . '</picture>' . "\n";
        }

        if ($vendor !== '') {
            $xml .= '      <vendor>' . $vendor . '</vendor>' . "\n";
        }
        if ($article !== '') {
            $xml .= '      <article>' . $article . '</article>' . "\n";
        }
        if ($name !== '') {
            $xml .= '      <name_ua>' . $name . '</name_ua>' . "\n";
        }
        if ($description !== '') {
            $xml .= '      <description_ua>' . $description . '</description_ua>' . "\n";
        }
        if ($offer['url'] !== '') {
            $xml .= '      <url>' . h($offer['url']) . '</url>' . "\n";
        }

        // Params
        foreach ($offer['params'] as $param) {
            $pName = xmlEscape($param['name']);
            $pVal  = xmlEscape($param['value']);
            $xml  .= '      <param name="' . $pName . '">' . $pVal . '</param>' . "\n";
        }

        $xml .= "    </offer>\n";
    }

    $xml .= "  </offers>\n";
    $xml .= "</shop>\n";
    $xml .= "</yml_catalog>\n";

    return $xml;
}

/**
 * Escape a string for use as XML text content (not attribute).
 */
function xmlEscape(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

try {
    // Validate input
    if (!is_file($inputFile) || !is_readable($inputFile)) {
        $msg = "Input feed not found or not readable: {$inputFile}";
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
        }
        exit($msg . PHP_EOL);
    }

    // Load Kasta categories
    $kastaCategories = loadKastaCategories($categoriesFile);
    [$wordIndex, $idf] = buildWordIndex($kastaCategories);

    // Load existing mappings
    $mapping = loadMapping($mappingFile);

    // Parse Prom feed
    $feedData = parsePromFeed($inputFile);
    $offers   = $feedData['offers'];

    $mappingUpdated = false;
    $newlyMapped    = 0;
    $alreadyMapped  = 0;

    foreach ($offers as $offer) {
        $offerId = $offer['id'];

        // Skip if already mapped (unless force remap)
        if (!$forceRemap && isset($mapping[$offerId])) {
            $alreadyMapped++;
            continue;
        }

        $bestCatId = findBestCategoryId(
            $offer['name'],
            $offer['vendor'],
            $offer['description'],
            $kastaCategories,
            $wordIndex,
            $idf
        );

        $mapping[$offerId] = [
            'kasta_category_id' => $bestCatId,
            'kasta_category'    => implode(' > ', array_filter([
                $kastaCategories[$bestCatId]['affiliation'] ?? '',
                $kastaCategories[$bestCatId]['group']       ?? '',
                $kastaCategories[$bestCatId]['subgroup']    ?? '',
                $kastaCategories[$bestCatId]['kind']        ?? '',
            ])),
            'product_name' => $offer['name'],
            'mapped_at'    => date('Y-m-d H:i:s'),
        ];

        $mappingUpdated = true;
        $newlyMapped++;
    }

    // Save updated mapping
    if ($mappingUpdated) {
        saveMapping($mappingFile, $mapping);
    }

    // Generate Kasta XML
    $kastaXml = generateKastaXml($feedData, $mapping, $kastaCategories);

    // Write output file
    if ($outputFile !== null) {
        if (file_put_contents($outputFile, $kastaXml, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write output file: {$outputFile}");
        }
    }

    // ---------------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------------
    if (PHP_SAPI === 'cli') {
        $totalOffers = count($offers);
        echo "✓ Processed {$totalOffers} offers\n";
        echo "  Already mapped : {$alreadyMapped}\n";
        echo "  Newly mapped   : {$newlyMapped}\n";
        echo "  Output written : {$outputFile}\n";
        echo "  Mapping saved  : {$mappingFile}\n";
    } elseif ($previewMode) {
        // HTML preview
        header('Content-Type: text/html; charset=utf-8');
        $totalOffers  = count($offers);
        $mappedCount  = count($mapping);
        $catFrequency = [];
        foreach ($mapping as $offerId => $m) {
            $catKey = $m['kasta_category'];
            $catFrequency[$catKey] = ($catFrequency[$catKey] ?? 0) + 1;
        }
        arsort($catFrequency);
        echo '<!doctype html><html lang="uk"><head><meta charset="utf-8">'
           . '<title>Kasta Feed Preview</title>'
           . '<style>body{font-family:system-ui;margin:16px;line-height:1.4}'
           . 'table{border-collapse:collapse;width:100%}'
           . 'th,td{border:1px solid #ddd;padding:6px 10px;text-align:left}'
           . 'th{background:#f5f5f5}'
           . '.pill{display:inline-block;padding:4px 10px;border:1px solid #ccc;'
           . 'border-radius:999px;margin:4px;background:#fafafa}'
           . '</style></head><body>';
        echo '<h2>Kasta Feed Preview</h2>';
        echo '<p>';
        echo '<span class="pill"><b>Всього товарів:</b> ' . (int)$totalOffers . '</span>';
        echo '<span class="pill"><b>З маппінгом:</b> ' . (int)$mappedCount . '</span>';
        echo '<span class="pill"><b>Нові маппінги:</b> ' . (int)$newlyMapped . '</span>';
        echo '</p>';
        echo '<h3>Розподіл по категоріях Kasta</h3>';
        echo '<table><tr><th>Категорія</th><th>Кількість товарів</th></tr>';
        foreach ($catFrequency as $cat => $count) {
            echo '<tr><td>' . h($cat) . '</td><td>' . (int)$count . '</td></tr>';
        }
        echo '</table>';
        echo '<h3>Товари та маппінги</h3>';
        echo '<table><tr><th>Offer ID</th><th>Назва товару</th><th>Категорія Kasta</th></tr>';
        foreach ($mapping as $offerId => $m) {
            echo '<tr>'
               . '<td>' . h($offerId) . '</td>'
               . '<td>' . h($m['product_name']) . '</td>'
               . '<td>' . h($m['kasta_category']) . '</td>'
               . '</tr>';
        }
        echo '</table>';
        echo '<p><a href="?file=' . h(basename($inputFile)) . '">Завантажити Kasta XML</a></p>';
        echo '</body></html>';
    } else {
        // Output XML
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: inline; filename="kasta.xml"');
        echo $kastaXml;
    }
} catch (\Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        exit;
    }
}
