<?php
// Duisburg Kategorie → Atom Feed Adapter
// Usage: GET /server.php?category=stadtentwicklung
// Deploy anywhere PHP is available (e.g. php -S 0.0.0.0:3456)

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
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('cURL error: ' . $error);
    }

    $data = json_decode($response, true);
    if (!$data) {
        throw new RuntimeException('JSON parse error');
    }

    return $data['data']['search']['results'] ?? [];
}

function toAtom(string $category, array $results): string {
    $now      = date('c');
    $feedUrl  = BASE_URL . '/news/news-kategorieseiten/' . $category;
    $label    = ucfirst($category);

    $entries = '';
    foreach ($results as $r) {
        $t = $r['teaser'] ?? null;
        if (!$t || empty($t['headline'])) continue;

        $url = isset($t['link']['url'])
            ? (str_starts_with($t['link']['url'], 'http')
                ? $t['link']['url']
                : BASE_URL . $t['link']['url'])
            : $feedUrl;

        $date    = isset($t['date']) ? date('c', strtotime($t['date'])) : $now;
        $summary = htmlspecialchars($t['text'] ?? '', ENT_XML1);
        $title   = htmlspecialchars($t['headline'], ENT_XML1);
        $urlXml  = htmlspecialchars($url, ENT_XML1);

        $entries .= "  <entry>\n"
            . "    <id>{$urlXml}</id>\n"
            . "    <title>{$title}</title>\n"
            . "    <link href=\"{$urlXml}\"/>\n"
            . "    <updated>{$date}</updated>\n"
            . "    <summary>{$summary}</summary>\n"
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
    $atom    = toAtom($category, $results);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo $atom;
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error: ' . $e->getMessage();
}
