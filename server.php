<?php
// Duisburg Kategorie → Atom Feed Adapter
// Usage: GET /server.php?category=stadtentwicklung
// Optional: ?no-cache=1 to bypass all caches for this request

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
// File-based cache in the system temp dir (works regardless of web-root
// write permissions). Three tiers:
//   - FEED:    the fully-assembled Atom XML for a category. A hit here
//              skips the GraphQL call, every article fetch, and XML
//              assembly entirely — this is the fast path.
//   - SEARCH:  the raw GraphQL search results (used to rebuild the feed
//              when the feed cache is stale but articles might still be
//              cached individually).
//   - ARTICLE: individual article bodies, which change far less often
//              than the list of headlines.
define('CACHE_DIR', sys_get_temp_dir() . '/duisburg_kategorie_cache');
const CACHE_TTL_FEED    = 900;    // 15 minutes
const CACHE_TTL_SEARCH  = 900;    // 15 minutes
const CACHE_TTL_ARTICLE = 43200;  // 12 hours

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

/** Like cacheGet, but also returns the entry's age in seconds (for ETag/Last-Modified). */
function cacheGetWithAge(string $key, int $ttl): ?array {
    $file = cacheFilePath($key);
    if (!is_file($file)) {
        return null;
    }
    $mtime = filemtime($file);
    if (time() - $mtime > $ttl) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return null;
    }
    return ['value' => $decoded, 'mtime' => $mtime];
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
    static $search = null, $replace = null;
    if ($search === null) {
        $search  = ['Ã¤', 'Ã¶', 'Ã¼', 'Ã', 'Ã©', 'Ã¨', 'Ã', 'lÃ¤', 'lÃ¶', 'lÃ¼', 'LÃ¤', 'LÃ¶', 'LÃ¼', 'Ã¶', 'Ã¼', 'Ã¤'];
        $replace = ['ä', 'ö', 'ü', 'Á', 'é', 'è', 'À', 'lä', 'lö', 'lü', 'Lä', 'Lö', 'Lü', 'ö', 'ü', 'ä'];
    }
    $text = str_replace($search, $replace, $text);
    return htmlspecialchars($text, ENT_XML1);
}

function cleanHref(string $href): string {
    // Decode URL-encoded characters and fix obfuscated emails
    $href = urldecode($href);
    $href = strtr($href, [
        '%E2%9A%B9' => '@',
        '%E2%97%A6' => '.',
    ]);
    return $href;
}

function processParagraph(DOMXPath $xpath, DOMNode $p): string {
    $result = '';
    $hasContent = false;

    // Extract ALL text nodes and links within this paragraph (recursively)
    $nodes = $xpath->query('.//text()[normalize-space()] | .//a', $p);
    foreach ($nodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->nodeValue);
            if ($text !== '') {
                $result .= cleanText($text) . ' ';
                $hasContent = true;
            }
        } elseif ($node->nodeName === 'a') {
            $href = $node->getAttribute('href');
            $text = trim($node->nodeValue);
            if ($text !== '') {
                $result .= sprintf(
                    '<a href="%s">%s</a> ',
                    htmlspecialchars(cleanHref($href), ENT_XML1),
                    cleanText($text)
                );
                $hasContent = true;
            }
        }
    }

    return $hasContent ? "<p>" . trim($result) . "</p>" : '';
}

/**
 * Build a curl handle with shared sane defaults (gzip transfer, timeouts, UA).
 */
function newCurlHandle(string $url, array $extraOpts = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $extraOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '', // accept & auto-decode gzip/deflate/br
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TCP_KEEPALIVE  => 1,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
        ],
    ]);
    return $ch;
}

function fetchGraphQL(array $config, bool $noCache): array {
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

    // Cache key is derived from the actual request payload, so if the
    // filter/limit/sort ever changes, it naturally gets a fresh cache entry.
    $key = cacheKey('search', $payload);
    if (!$noCache) {
        $cached = cacheGet($key, CACHE_TTL_SEARCH);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = newCurlHandle(GRAPHQL_URL, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Referer: ' . BASE_URL,
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

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

    return $results;
}

/**
 * Extract article body HTML from a fetched HTML document.
 */
function extractArticleContent(string $html): string {
    $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true) ?: 'UTF-8');

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);

    $content = '';

    $paragraphs = $xpath->query('//div[contains(@class, "SP-Content__body")]//p | //article[contains(@class, "SP-Content")]//p');
    foreach ($paragraphs as $p) {
        // Skip paragraphs that only contain dates (e.g., "21. Januar 2026")
        $text = trim($p->nodeValue);
        if (preg_match('/^\d{1,2}\.\s\w+\s\d{4}$/u', $text)) {
            continue;
        }
        $content .= processParagraph($xpath, $p);
    }

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

    return trim($content);
}

/**
 * Fetch article bodies for multiple URLs concurrently using curl_multi.
 * Returns [url => content]. Cache hits are resolved without any network
 * call; only cache misses go out over the wire, and they all fetch in
 * parallel instead of one-by-one.
 *
 * @return array<string,string>
 */
function fetchArticlesContent(array $urls, bool $noCache): array {
    $urls = array_values(array_unique($urls));
    $results = [];
    $toFetch = []; // url => cache key

    foreach ($urls as $url) {
        $key = cacheKey('article', $url);
        if (!$noCache) {
            $cached = cacheGet($key, CACHE_TTL_ARTICLE);
            if ($cached !== null) {
                $results[$url] = $cached['content'] ?? '';
                continue;
            }
        }
        $toFetch[$url] = $key;
    }

    if (empty($toFetch)) {
        return $results;
    }

    $mh = curl_multi_init();
    $handles = []; // url => curl handle

    foreach ($toFetch as $url => $key) {
        $ch = newCurlHandle($url);
        $handles[$url] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    // Drive all requests concurrently
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $url => $ch) {
        $key = $toFetch[$url];
        $error = curl_error($ch);
        $html = curl_multi_getcontent($ch);

        if ($error || empty($html)) {
            // Fall back to a stale cache entry (if any); otherwise leave
            // this article out so the caller can fall back to the teaser text.
            $stale = cacheGet($key, PHP_INT_MAX);
            $results[$url] = $stale['content'] ?? '';
        } else {
            try {
                $content = extractArticleContent($html);
                cacheSet($key, ['content' => $content]);
                $results[$url] = $content;
            } catch (Throwable $e) {
                $stale = cacheGet($key, PHP_INT_MAX);
                $results[$url] = $stale['content'] ?? '';
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return $results;
}

function toAtom(string $category, array $results, array $articleContentByUrl): string {
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

        $summary = $articleContentByUrl[$url] ?? '';
        if ($summary === '') {
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

function sendFeed(string $atom, int $mtime): void {
    $etag = '"' . sha1($atom) . '"';

    header('Content-Type: application/atom+xml; charset=utf-8');
    header('Cache-Control: public, max-age=' . CACHE_TTL_FEED);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);

    // Honor conditional GETs so a poller that already has the current
    // feed gets a cheap 304 instead of the full body.
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
        http_response_code(304);
        return;
    }

    echo $atom;
}

// --- Main ---

// Never let raw PHP errors/warnings leak into what's supposed to be the XML
// body — that's what turns into "could not parse document" with an empty
// or truncated feed in the reader. Buffer output so we can discard anything
// accidentally printed before we've decided what the real response is.
ini_set('display_errors', '0');
ob_start();

function sendPlainTextError(int $status, string $message): void {
    // Discard anything already buffered (stray warnings, partial output, etc.)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
}

// Catch fatal errors (out-of-memory, uncaught TypeError, etc.) that bypass
// normal exception handling entirely.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        sendPlainTextError(500, 'Internal error: ' . $error['message']);
    }
});

$category = strtolower($_GET['category'] ?? '');

if (!$category || !isset(CATEGORY_CONFIG[$category])) {
    $available = implode(', ', array_keys(CATEGORY_CONFIG));
    sendPlainTextError(400, "Unknown category. Available: {$available}");
    exit;
}

try {
    $feedKey = cacheKey('feed', $category);

    // Fast path: the whole assembled feed is already cached and fresh —
    // skip the GraphQL call, every article fetch, and XML assembly.
    if (!$noCache) {
        $feedCached = cacheGetWithAge($feedKey, CACHE_TTL_FEED);
        if ($feedCached !== null) {
            ob_end_clean();
            sendFeed($feedCached['value']['atom'], $feedCached['mtime']);
            exit;
        }
    }

    $results = fetchGraphQL(CATEGORY_CONFIG[$category], $noCache);

    // Collect article URLs up front so they can all be fetched concurrently
    // instead of one request at a time inside the loop.
    $urls = [];
    foreach ($results as $r) {
        $t = $r['teaser'] ?? null;
        if (!$t || empty($t['headline'])) continue;
        $url = isset($t['link']['url'])
            ? (str_starts_with($t['link']['url'], 'http')
                ? $t['link']['url']
                : BASE_URL . $t['link']['url'])
            : null;
        if ($url) {
            $urls[] = $url;
        }
    }

    $articleContentByUrl = fetchArticlesContent($urls, $noCache);

    $atom = toAtom($category, $results, $articleContentByUrl);

    // Sanity-check the XML we just built before caching/serving it, so a
    // parsing bug surfaces as a clear 500 instead of a broken feed download.
    $checkDom = new DOMDocument();
    libxml_use_internal_errors(true);
    $isValid = $checkDom->loadXML($atom);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();

    if (!$isValid) {
        $firstError = $xmlErrors[0]->message ?? 'unknown XML error';
        throw new RuntimeException('Generated feed is not valid XML: ' . trim($firstError));
    }

    cacheSet($feedKey, ['atom' => $atom]);
    cacheCleanup(max(CACHE_TTL_FEED, CACHE_TTL_SEARCH, CACHE_TTL_ARTICLE) * 3);

    ob_end_clean();
    sendFeed($atom, time());
} catch (Throwable $e) {
    sendPlainTextError(500, 'Error: ' . $e->getMessage());
}