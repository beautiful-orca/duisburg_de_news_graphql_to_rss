# Duisburg.de Pressemeldungen Kategorien - RSS Feed Adapter

> **Convert Duisburg City News GraphQL API to Atom RSS Feeds for FreshRSS and other feed readers.**

---

## ✨ Features

- **GraphQL to RSS**: Fetches news articles from [duisburg.de GraphQL API](https://www.duisburg.de/api/graphql/) and converts them into **Atom RSS feeds**.
- **Category Support**: Pre-configured for sub-categories like `stadtentwicklung`, `stadtverwaltung`, `verkehr`, and `umwelt`.
- **Caching**: Multi-tier caching (feed, search, and article) to minimize API calls and improve performance.
- **Concurrent Fetching**: Uses `curl_multi` to fetch article bodies in parallel.
- **FreshRSS Compatible**: Designed to work seamlessly with [FreshRSS](https://freshrss.org/) or any other RSS reader.
- **Error Handling**: Graceful fallbacks to stale cache and HTTP 304 support for conditional requests.

---

## 📌 Use Cases

- Subscribe to **Duisburg city news** in your favorite RSS reader.
- Get **category-specific feeds** (e.g., urban development, administration, traffic, environment).
- Self-hosted, lightweight, and **Docker-friendly**.

---

## 🚀 Quick Start

### 1. **Prerequisites**

- PHP 8.0+
- `curl` and `DOM` PHP extensions enabled
- A web server (e.g., Apache, Nginx)

### 2. **Installation**

1. Clone or download this repository.
2. Place the files in your web server's document root (e.g., `/var/www/html`).
3. Ensure the `server.php` file is accessible via HTTP.

### 3. **Usage**

- Access the feed for a specific category:
  ```
  https://your-domain.com/server.php?category=stadtentwicklung
  ```
- **Available categories**: `stadtentwicklung`, `stadtverwaltung`, `verkehr`, `umwelt`.
- **Bypass cache** (for debugging):
  ```
  https://your-domain.com/server.php?category=stadtentwicklung&no-cache=1
  ```

---

## 🔧 Configuration

### Category Configuration

Categories are defined in `server.php` under `CATEGORY_CONFIG`. Each category maps to a `groups` and `categories` array for the GraphQL query.

**Default Configuration:**

```php
const CATEGORY_CONFIG = [
    'alle_news'                => ['groups' => ['8678'], 'categories' => ['1912', '2028']],
    'besondere_ereignisse'      => ['groups' => ['8678'], 'categories' => ['1912', '2034']],
    'bezirk_hamborn'           => ['groups' => ['8697'], 'categories' => ['1912', '2043']],
    'bezirk_homberg_ruhrort_baerl' => ['groups' => ['8678'], 'categories' => ['1912', '2045']],
    'bezirk_meiderich_beeck'   => ['groups' => ['8678'], 'categories' => ['1912', '2042']],
    'bezirk_mitte'             => ['groups' => ['8678'], 'categories' => ['1912', '2048']],
    'bezirk_rheinhausen'       => ['groups' => ['8678'], 'categories' => ['1912', '2046']],
    'bezirk_sued'              => ['groups' => ['8678'], 'categories' => ['1912', '2047']],
    'bezirk_walsum'            => ['groups' => ['8678'], 'categories' => ['1912', '2044']],
    'digitalisierung'          => ['groups' => ['8678'], 'categories' => ['1912', '2033']],
    'feuerwehr'                => ['groups' => ['8678'], 'categories' => ['1912', '2040']],
    'interkulturelles'         => ['groups' => ['8678'], 'categories' => ['1912', '2025']],
    'kinder_familien_senioren' => ['groups' => ['8678'], 'categories' => ['1912', '2039']],
    'kultur'                   => ['groups' => ['8678'], 'categories' => ['1912', '2026']],
    'soziales'                 => ['groups' => ['8678'], 'categories' => ['1912', '2024']],
    'sport'                    => ['groups' => ['8678'], 'categories' => ['1912', '2036']],
    'stadtbibliothek'          => ['groups' => ['8678'], 'categories' => ['1912', '2031']],
    'stadtentwicklung'         => ['groups' => ['8678'], 'categories' => ['1912', '2030']],
    'stadtverwaltung'          => ['groups' => ['8678'], 'categories' => ['1912', '2032']],
    'tourismus'                => ['groups' => ['8678'], 'categories' => ['1912', '2029']],
    'umwelt'                   => ['groups' => ['8678'], 'categories' => ['1912', '2027']],
    'veranstaltungen'          => ['groups' => ['8678'], 'categories' => ['1912', '2038']],
    'verkehr'                  => ['groups' => ['8678'], 'categories' => ['1912', '2041']],
    'vhs'                      => ['groups' => ['8678'], 'categories' => ['1912', '2037']],
    'wirtschaft'               => ['groups' => ['8678'], 'categories' => ['1912', '2035']],
];
```

---

### Caching

The project uses a **file-based cache** in the system temp directory (`/tmp/duisburg_pressemeldungen_kategorien_cache`).


| Cache Type | TTL        | Purpose                       |
| ---------- | ---------- | ----------------------------- |
| Feed       | 55 minutes | Fully assembled Atom XML feed |
| Search     | 55 minutes | Raw GraphQL search results    |
| Article    | 24 hours   | Individual article bodies     |


- **Bypass Cache**: Add `?no-cache=1` to the URL.

---

## 🛠️ How It Works

1. **GraphQL Query**: Fetches articles from the Duisburg API using a predefined query.
2. **Article Fetching**: Concurrently fetches full article content for each URL.
3. **XML Assembly**: Converts the data into a valid **Atom RSS feed**.
4. **Caching**: Stores results at multiple levels to reduce API load and improve speed.
5. **Output**: Serves the feed with proper HTTP headers (ETag, Last-Modified, Cache-Control).

---

## 📡 API Details

- **GraphQL Endpoint**: `https://www.duisburg.de/api/graphql/`
- **Base URL**: `https://www.duisburg.de`
- **Query Parameters**:
  - `category`: Required. One of the pre-configured categories.
  - `no-cache`: Optional. Set to `1` to bypass all caches.

---

## 🐳 Docker (Optional)

An example `compose.yaml` is provided for Docker deployment. Update it to include:

- PHP image with `curl` and `DOM` extensions.
- Volume mounts for the project files.
- Port mapping for web access.

---

## 🔍 Troubleshooting


| Issue                  | Solution                                                            |
| ---------------------- | ------------------------------------------------------------------- |
| **Feed not updating**  | Bypass cache with `?no-cache=1` or check cache TTL in `server.php`. |
| **Missing categories** | Add the category to `CATEGORY_CONFIG` in `server.php`.              |
| **XML parsing errors** | Ensure `cleanText()` and `cleanHref()` handle all edge cases.       |
| **cURL errors**        | Check PHP error logs and network connectivity to `duisburg.de`.     |