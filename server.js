// Duisburg Kategorie → Atom Feed Adapter
// Usage: GET /feed?category=stadtentwicklung
// FreshRSS subscribes to: http://duisburg-rss:3456/feed?category=stadtentwicklung

import http from 'http';
import https from 'https';

const PORT = 3456;
const BASE_URL = 'https://www.duisburg.de';
const GRAPHQL_URL = `${BASE_URL}/api/graphql/`;

// Category config extracted from data-sp-central-search-app on each page.
// groups is always ["8678"], only the second category ID differs.
const CATEGORY_CONFIG = {
  stadtentwicklung: { groups: ['8678'], categories: ['1912', '2030'] },
  stadtverwaltung:  { groups: ['8678'], categories: ['1912', '2032'] },
  verkehr:          { groups: ['8678'], categories: ['1912', '2041'] },
  umwelt:           { groups: ['8678'], categories: ['1912', '2027'] },
};

const GQL_QUERY = `
query Search($searchInput: SearchInput!) {
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
}`.trim();

function fetchGraphQL(config) {
  return new Promise((resolve, reject) => {
    const body = JSON.stringify({
      operationName: 'Search',
      query: GQL_QUERY,
      variables: {
        searchInput: {
          filter: [
            { query: 'sp_contenttype:article' },
            { groups: config.groups },
            { categories: config.categories },
            { relativeDateRange: { from: '-P365D' } },
          ],
          limit: 25,
          offset: 0,
          sort: [{ date: 'DESC' }],
          spellcheck: false,
        },
      },
    });

    const req = https.request(GRAPHQL_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        'Referer': BASE_URL,
        'User-Agent': 'Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
      },
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(new Error('JSON parse error: ' + data.slice(0, 200))); }
      });
    });

    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

function escapeXml(str) {
  if (!str) return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

function toAtom(category, results) {
  const now = new Date().toISOString();
  const feedUrl = `${BASE_URL}/news/news-kategorieseiten/${category}`;
  const label = category.charAt(0).toUpperCase() + category.slice(1);

  const entries = results
    .filter(r => r.teaser && r.teaser.headline)
    .map(r => {
      const t = r.teaser;
      const url = t.link?.url
        ? (t.link.url.startsWith('http') ? t.link.url : `${BASE_URL}${t.link.url}`)
        : feedUrl;
      const date = t.date ? new Date(t.date).toISOString() : now;
      const summary = t.text ? escapeXml(t.text) : '';

      return `  <entry>
    <id>${escapeXml(url)}</id>
    <title>${escapeXml(t.headline)}</title>
    <link href="${escapeXml(url)}"/>
    <updated>${date}</updated>
    <summary>${summary}</summary>
  </entry>`;
    }).join('\n');

  return `<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <id>${escapeXml(feedUrl)}</id>
  <title>Duisburg – ${escapeXml(label)}</title>
  <link href="${escapeXml(feedUrl)}"/>
  <updated>${now}</updated>
${entries}
</feed>`;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);

  if (url.pathname !== '/feed') {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found. Use /feed?category=stadtentwicklung');
    return;
  }

  const category = url.searchParams.get('category')?.toLowerCase();

  if (!category || !CATEGORY_CONFIG[category]) {
    const available = Object.keys(CATEGORY_CONFIG).join(', ');
    res.writeHead(400, { 'Content-Type': 'text/plain' });
    res.end(`Unknown category. Available: ${available}`);
    return;
  }

  try {
    const data = await fetchGraphQL(CATEGORY_CONFIG[category]);
    const results = data?.data?.search?.results ?? [];
    const atom = toAtom(category, results);
    res.writeHead(200, { 'Content-Type': 'application/atom+xml; charset=utf-8' });
    res.end(atom);
  } catch (err) {
    console.error(err);
    res.writeHead(500, { 'Content-Type': 'text/plain' });
    res.end('Error fetching data: ' + err.message);
  }
});

server.listen(PORT, () => {
  console.log(`Duisburg RSS adapter running on http://localhost:${PORT}`);
  console.log(`Available feeds:`);
  Object.keys(CATEGORY_CONFIG).forEach(cat => {
    console.log(`  http://localhost:${PORT}/feed?category=${cat}`);
  });
});
