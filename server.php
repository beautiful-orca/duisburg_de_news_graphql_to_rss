<?php
// Duisburg Kategorie → Atom Feed Adapter
// Usage: GET /server.php?category=stadtentwicklung

const BASE_URL = 'https://www.duisburg.de';
const GRAPHQL_URL = BASE_URL . '/api/graphql/';

const CATEGORY_CONFIG = [
    'stadtentwicklung' => ['groups' => ['8678'], 'categories' => ['1912', '2030']],
    'stadtverwaltung'  => ['groups' => ['8678'], 'categories' => ['1912', '2032']],
    'verkehr'          => ['groups' => ['8678'], 'categories' => ['1912', '2041']],
    'umwelt'           => ['groups' => ['8678'], 'categories' => ['1912', '2027']],
];

const GQL_QUERY = 'query Search($searchInput: SearchInput!) {
  search(input: $searchInput) {
    total
    results {
      id
      teaser {
        ... on NewsTeaser {
          headline
          text
          date
          link { url }
        }
      }
    }
  }
}';

// ---- Caching ----
// File-based cache. Search results (the GraphQL call) get a short TTL since
// they're a list of headlines that can change often. Individual article
// bodies get a much longer TTL since published text rarely changes.
// Cache lives in the system temp dir rather than the web root, so it works
// regardless of web-root write permissions.
define('CACHE_DIR', sys_get_temp_dir() . '/duisburg_kategorie_cache');
const CACHE_TTL_SEARCH  = 900;    // 15 minutes
const CACHE_TTL_ARTICLE = 43200;  // 12 hours
// ?no-cache=1 bypasses reading the cache for this request (still refreshes it)
$noCache = isset($_GET['no-cache']) && $_GET['no-cache'] == '1';

function ensureCacheDir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
}

function cacheFilePath(string $key): string {
    return CACHE_DIR . '/' . $key . '.json';
}

function cacheGet(string $key, int $ttl) {
    $file = cacheFilePath($key);
    if (!is_file($file)) {
        return null;
    }
    if (time() - filemtime($file) > $ttl) {
        return null; // expired; caller will refetch and overwrite
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return $decoded === null ? null : $decoded;
}

function cacheSet(string $key, $value): void {
    ensureCacheDir();
    $file = cacheFilePath($key);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($value)) !== false) {
        @rename($tmp, $file); // avoids readers seeing a half-written file
    } else {
        @unlink($tmp);
    }
}

function cacheKey(string $prefix, string $input): string {
    return $prefix . '_' . sha1($input);
}

/**
 * Opportunistically remove expired cache files.
 * Runs on a small fraction of requests to keep overhead low.
 */
function cacheCleanup(int $maxAge): void {
    if (!is_dir(CACHE_DIR)) {
        return;
    }
    if (mt_rand(1, 100) > 5) {
        return; // only run cleanup ~5% of the time
    }
    foreach (glob(CACHE_DIR . '/*.json') ?: [] as $file) {
        if (time() - filemtime($file) > $maxAge) {
            @unlink($file);
        }
    }
}

function cleanText(string $text): string {
    // Fix common encoding issues (fallback for UTF-8 misinterpretation)
    $text = str_replace(
        ['Ã¤', 'Ã¶', 'Ã¼', 'Ã', 'Ã©', 'Ã¨', 'Ã', 'lÃ¤', 'lÃ¶', 'lÃ¼', 'LÃ¤', 'LÃ¶', 'LÃ¼', 'Ã¶', 'Ã¼', 'Ã¤'],
        ['ä', 'ö', 'ü', 'Á', 'é', 'è', 'À', 'lä', 'lö', 'lü', 'Lä', 'Lö', 'Lü', 'ö', 'ü', 'ä'],
        $text
    );
    return htmlspecialchars($text, ENT_XML1);
}

function cleanHref(string $href): string {
    // Decode URL-encoded characters and fix obfuscated emails
    $href = urldecode($href);
    $href = preg_replace('/%E2%9A%B9/', '@', $href);
    $href = preg_replace('/%E2%97%A6/', '.', $href);
    return $href;
}

function processParagraph(DOMNode $p): string {
    $dom = $p->ownerDocument;
    $xpath = new DOMXPath($dom);
    $result = '';
    $hasContent = false;

    // Extract ALL text nodes and links within this paragraph (recursively)
    $nodes = $xpath->query('.//text()[normalize-space()] | .//a', $p);
    foreach ($nodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->nodeValue);
            if (!empty($text)) {
                $result .= cleanText($text) . ' ';
                $hasContent = true;
            }
        } elseif ($node->nodeName === 'a') {
            $href = $node->getAttribute('href');
            $text = trim($node->nodeValue);
            if (!empty($text)) {
                $result .= sprintf(
                    '<a href="%s">%s</a> ',
                    cleanHref($href),
                    cleanText($text)
                );
                $hasContent = true;
            }
        }
    }

    return $hasContent ? "<p>" . trim($result) . "</p>" : '';
}

function fetchGraphQL(array $config): array {
    $payload = json_encode([
        'operationName' => 'Search',
        'query'         => GQL_QUERY,
        'variables'     => [
            'searchInput' => [
                'filter' => [
                    ['query' => 'sp_contenttype:article'],
                    ['groups' => $config['groups']],
                    ['categories' => $config['categories']],
                    ['relativeDateRange' => ['from' => '-P365D']],
                ],
                'limit'     => 25,
                'offset'    => 0,
                'sort'      => [['date' => 'DESC']],
                'spellcheck'=> false,
            ],
        ],
    ]);

    global $noCache;

    // Cache key is derived from the actual request payload, so if the
    // filter/limit/sort ever changes, it naturally gets a fresh cache entry.
    $key = cacheKey('search', $payload);
    if (!$noCache) {
        $cached = cacheGet($key, CACHE_TTL_SEARCH);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init(GRAPHQL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Referer: ' . BASE_URL,
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);

    if ($error) {
        // Fall back to a stale cache entry (if any) rather than failing outright
        $stale = cacheGet($key, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        throw new RuntimeException('cURL error: ' . $error);
    }

    $data = json_decode($response, true);
    if (!$data) {
        $stale = cacheGet($key, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        throw new RuntimeException('JSON parse error');
    }

    $results = $data['data']['search']['results'] ?? [];
    cacheSet($key, $results);
    cacheCleanup(max(CACHE_TTL_SEARCH, CACHE_TTL_ARTICLE) * 3);

    return $results;
}

function fetchArticleContent(string $url): string {
    global $noCache;

    $key = cacheKey('article', $url);
    if (!$noCache) {
        $cached = cacheGet($key, CACHE_TTL_ARTICLE);
        if ($cached !== null) {
            return $cached['content'] ?? '';
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $html = curl_exec($ch);
    $error = curl_error($ch);

    if ($error) {
        // Fall back to a stale cache entry (if any) rather than failing outright
        $stale = cacheGet($key, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale['content'] ?? '';
        }
        throw new RuntimeException("Failed to fetch article: $error");
    }

    // Ensure UTF-8 encoding
    $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true));

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);

    $content = '';

    // Extract all paragraphs from the article body
    $paragraphs = $xpath->query('//div[contains(@class, "SP-Content__body")]//p | //article[contains(@class, "SP-Content")]//p');
    foreach ($paragraphs as $p) {
        // Skip paragraphs that only contain dates (e.g., "21. Januar 2026")
        $text = trim($p->nodeValue);
        if (preg_match('/^\d{1,2}\.\s\w+\s\d{4}$/u', $text)) {
            continue;
        }
        $content .= processParagraph($p);
    }

    // Extract link list items from SP-LinkList__item
    $linkItems = $xpath->query('//div[contains(@class, "SP-LinkList__item")]');
    foreach ($linkItems as $item) {
        $link = $item->getElementsByTagName('a')->item(0);
        if ($link) {
            $href = cleanHref($link->getAttribute('href'));
            $textNode = $xpath->query('.//span[contains(@class, "SP-Link__text")]', $item)->item(0);
            $text = $textNode ? trim($textNode->nodeValue) : 'Link';
            $content .= sprintf(
                '<p><a href="%s">%s</a></p>',
                htmlspecialchars($href, ENT_XML1),
                cleanText($text)
            );
        }
    }

    $content = trim($content);
    cacheSet($key, ['content' => $content]);

    return $content;
}

function toAtom(string $category, array $results): string {
    $now = date('c');
    $feedUrl = BASE_URL . '/news/news-kategorieseiten/' . $category;
    $label = ucfirst($category);

    $entries = '';
    foreach ($results as $r) {
        $t = $r['teaser'] ?? null;
        if (!$t || empty($t['headline'])) continue;

        $url = isset($t['link']['url'])
            ? (str_starts_with($t['link']['url'], 'http')
                ? $t['link']['url']
                : BASE_URL . $t['link']['url'])
            : $feedUrl;

        $date = isset($t['date']) ? date('c', strtotime($t['date'])) : $now;
        $title = htmlspecialchars($t['headline'], ENT_XML1);
        $urlXml = htmlspecialchars($url, ENT_XML1);

        try {
            $articleContent = fetchArticleContent($url);
            $summary = $articleContent;
        } catch (RuntimeException $e) {
            $summary = htmlspecialchars($t['text'] ?? '', ENT_XML1);
        }

        $entries .= "  <entry>\n"
            . "    <id>{$urlXml}</id>\n"
            . "    <title>{$title}</title>\n"
            . "    <link href=\"{$urlXml}\"/>\n"
            . "    <updated>{$date}</updated>\n"
            . "    <content type=\"xhtml\"><div xmlns=\"http://www.w3.org/1999/xhtml\">{$summary}</div></content>\n"
            . "  </entry>\n";
    }

    $feedUrlXml = htmlspecialchars($feedUrl, ENT_XML1);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <id>{$feedUrlXml}</id>
  <title>Duisburg – {$label}</title>
  <link href="{$feedUrlXml}"/>
  <updated>{$now}</updated>
  {$entries}</feed>
XML;
}

// --- Main ---
$category = strtolower($_GET['category'] ?? '');

if (!$category || !isset(CATEGORY_CONFIG[$category])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    $available = implode(', ', array_keys(CATEGORY_CONFIG));
    echo "Unknown category. Available: {$available}";
    exit;
}

try {
    $results = fetchGraphQL(CATEGORY_CONFIG[$category]);
    $atom = toAtom($category, $results);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo $atom;
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error: ' . $e->getMessage();
}