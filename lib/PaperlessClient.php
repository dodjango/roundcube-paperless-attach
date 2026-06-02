<?php

/**
 * PaperlessClient — the single server-side egress to a Paperless-ngx instance.
 *
 * Design constraints (Phase 1):
 *  - This is the ONLY place that talks to Paperless. The token never leaves the
 *    server and is never logged or returned.
 *  - Redirects are DISABLED on both transports (SSRF / open-redirect guard).
 *  - Connect and read/total timeouts are always set (DoS guard).
 *  - Document ids placed into a request path MUST be integer-validated.
 *  - Transport is selected at runtime: bundled Guzzle when available, otherwise a
 *    thin cURL wrapper. No transport dependency is added to the plugin.
 *
 * @license GPL-3.0+
 */
class PaperlessClient
{
    /** @var int Connect timeout in seconds. */
    private const CONNECT_TIMEOUT = 5;

    /** @var int Read/total timeout in seconds. */
    private const TIMEOUT = 15;

    /** @var string Base URL of the Paperless instance, e.g. http://paperless-webserver:8000 */
    private $baseUrl;

    /** @var string Per-user Paperless API token (plaintext, in-memory only). */
    private $token;

    /**
     * @param string $baseUrl Server-fixed Paperless base URL (never client-supplied).
     * @param string $token   Decrypted per-user API token.
     */
    public function __construct(string $baseUrl, string $token)
    {
        // Normalize: strip a trailing slash so path concatenation is predictable.
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    /**
     * Probe connectivity + authentication against Paperless.
     *
     * Maps the upstream response to a structured, token-free result:
     *   200      → ok
     *   401/403  → badtoken
     *   transport error or any other status → unreachable
     *
     * @return array{ok: bool, reason: string, status: int}
     *   reason is one of 'ok' | 'badtoken' | 'unreachable'.
     */
    public function testConnection(): array
    {
        $res = $this->request('GET', '/api/documents/?page_size=1');

        $status = (int) $res['status'];

        if ($status === 200) {
            return ['ok' => true, 'reason' => 'ok', 'status' => $status];
        }

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'reason' => 'badtoken', 'status' => $status];
        }

        return ['ok' => false, 'reason' => 'unreachable', 'status' => $status];
    }

    /**
     * Full-text + structured-filter search over the user's documents.
     *
     * Builds GET /api/documents/ with `query`, `tags__id__in` (csv),
     * `correspondent__id`, `document_type__id`, `created__date__gte`,
     * `created__date__lte`, `ordering=-created`, `page`, and `page_size`.
     * Empty criteria are omitted. Returns the decoded {count, next, results}
     * or null on a transport/parse error.
     *
     * NOTE on param spellings: the structured-filter names below
     * (tags__id__in / correspondent__id / document_type__id /
     * created__date__gte / created__date__lte) follow the documented
     * Paperless-ngx DRF filter contract. Verify against the live
     * /api/schema/view/ if the deployed instance diverges.
     *
     * @param array $criteria {query, tags[], correspondent, document_type,
     *                         date_from, date_to, page, page_size}
     * @return array|null  {count:int, next:?string, results:array} or null
     */
    public function search(array $criteria): ?array
    {
        $params = ['ordering' => '-created'];

        if (!empty($criteria['query'])) {
            $params['query'] = (string) $criteria['query'];
        }

        if (!empty($criteria['tags']) && is_array($criteria['tags'])) {
            $ids = [];
            foreach ($criteria['tags'] as $t) {
                $ids[] = $this->validateId($t);
            }
            if ($ids) {
                $params['tags__id__in'] = implode(',', $ids);
            }
        }

        if (!empty($criteria['correspondent'])) {
            $params['correspondent__id'] = $this->validateId($criteria['correspondent']);
        }

        if (!empty($criteria['document_type'])) {
            $params['document_type__id'] = $this->validateId($criteria['document_type']);
        }

        if (!empty($criteria['date_from']) && $this->isIsoDate((string) $criteria['date_from'])) {
            $params['created__date__gte'] = (string) $criteria['date_from'];
        }

        if (!empty($criteria['date_to']) && $this->isIsoDate((string) $criteria['date_to'])) {
            $params['created__date__lte'] = (string) $criteria['date_to'];
        }

        $page      = isset($criteria['page']) ? max(1, (int) $criteria['page']) : 1;
        $page_size = isset($criteria['page_size']) ? max(1, (int) $criteria['page_size']) : 25;

        $params['page']      = $page;
        $params['page_size'] = $page_size;

        $res = $this->request('GET', '/api/documents/?' . http_build_query($params));

        if ((int) $res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'count'   => (int) ($data['count'] ?? 0),
            'next'    => $data['next'] ?? null,
            'results' => is_array($data['results'] ?? null) ? $data['results'] : [],
        ];
    }

    /**
     * Fetch a document thumbnail's raw bytes + content-type (id-validated).
     *
     * @param int $id document id (validated via validateId())
     * @return array{body: string, content_type: string}|null
     */
    public function thumb(int $id): ?array
    {
        $id = $this->validateId($id);

        $res = $this->request('GET', '/api/documents/' . $id . '/thumb/');

        if ((int) $res['status'] !== 200) {
            return null;
        }

        return [
            'body'         => (string) $res['body'],
            'content_type' => (string) ($res['content_type'] ?? ''),
        ];
    }

    /**
     * Fetch a single document's title (id-validated). Used server-side so the
     * browser-supplied title never enters the trust path for the attachment name.
     *
     * @param int $id document id (validated via validateId())
     * @return string the title, or '' if unavailable
     */
    public function getDocumentTitle(int $id): string
    {
        $id = $this->validateId($id);

        $res = $this->request('GET', '/api/documents/' . $id . '/');

        if ((int) $res['status'] !== 200) {
            return '';
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return '';
        }

        return (string) ($data['title'] ?? '');
    }

    /**
     * Fetch the metadata needed for a no-archive check (NO document bytes).
     *
     * Returns {title, has_archive}:
     *  - has_archive: true when the document-detail `archived_file_name` is a
     *    non-empty string (an OCR'd archive PDF exists). Born-digital documents
     *    have an empty/null `archived_file_name` → has_archive = false, so the
     *    caller can SKIP them (v1 attaches the archive PDF only, never the original).
     *
     * The Paperless-ngx document API exposes NO size field (no archive_size /
     * original_size on the list or detail object), so there is no pre-download
     * size here; oversize is enforced post-download from the streamed file size.
     *
     * @param int $id document id (validated via validateId())
     * @return array{title: string, has_archive: bool}|null
     *   null on a transport/parse error of the document-detail request.
     */
    public function getDocumentInfo(int $id): ?array
    {
        $id = $this->validateId($id);

        $res = $this->request('GET', '/api/documents/' . $id . '/');

        if ((int) $res['status'] !== 200) {
            return null;
        }

        $doc = json_decode($res['body'], true);
        if (!is_array($doc)) {
            return null;
        }

        $title       = (string) ($doc['title'] ?? '');
        $archiveName = $doc['archived_file_name'] ?? null;
        $has_archive = is_string($archiveName) && $archiveName !== '';

        return [
            'title'       => $title,
            'has_archive' => $has_archive,
        ];
    }

    /**
     * Stream a document's archive PDF to a temp file (NO buffering in memory).
     *
     * Hits the plain `/api/documents/{id}/download/` endpoint — the archive PDF
     * is the default; the original-file query param is deliberately NOT sent.
     * Guzzle streams via `sink`; the cURL
     * fallback writes via `CURLOPT_FILE`. Redirects are disabled and the
     * connect/read timeouts are applied on both transports. Returns true only on
     * HTTP 200 with a non-empty file; on any non-200 / transport error the
     * partial (possibly 0-byte) file is unlinked and false is returned, so a
     * truncated attachment can never enter the compose.
     *
     * @param int    $id       document id (validated via validateId())
     * @param string $destPath path the PDF is streamed to
     * @return bool true on a complete 200 download, false otherwise
     */
    public function download(int $id, string $destPath): bool
    {
        $id  = $this->validateId($id);
        $url = $this->baseUrl . '/api/documents/' . $id . '/download/';

        // NOTE: do NOT send `Accept: application/pdf` here. Paperless-ngx runs DRF
        // content negotiation on this endpoint and answers a restrictive
        // `application/pdf` Accept with HTTP 406 Not Acceptable (writing a tiny JSON
        // error body instead of the file). `*/*` lets it serve the archive PDF (200).
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Accept'        => '*/*',
        ];

        $status = 0;

        if (class_exists('\\GuzzleHttp\\Client')) {
            try {
                $client = new \GuzzleHttp\Client([
                    'allow_redirects' => false,
                    'http_errors'     => false,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout'         => self::TIMEOUT,
                ]);

                // `sink` streams the body straight to disk — never buffered in PHP.
                $response = $client->request('GET', $url, [
                    'headers' => $headers,
                    'sink'    => $destPath,
                ]);

                $status = (int) $response->getStatusCode();
            }
            catch (\Throwable $e) {
                @unlink($destPath);
                return false;
            }
        }
        else {
            $ch = curl_init();
            if ($ch === false) {
                @unlink($destPath);
                return false;
            }

            $fp = @fopen($destPath, 'w');
            if ($fp === false) {
                curl_close($ch);
                @unlink($destPath);
                return false;
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_HTTPHEADER     => $headerLines,
                // Stream to the file handle; do NOT set CURLOPT_RETURNTRANSFER.
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
            ]);

            $ok     = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            curl_close($ch);
            fclose($fp);

            if ($ok === false) {
                @unlink($destPath);
                return false;
            }
        }

        if ($status !== 200 || !is_file($destPath) || filesize($destPath) <= 0) {
            @unlink($destPath);
            return false;
        }

        return true;
    }

    /**
     * Fetch a DRF list endpoint and FOLLOW `next` across ALL pages, returning
     * the complete merged `results`. Used for tag / correspondent / document
     * type lookups so the option lists are never truncated.
     *
     * A hard page cap guards against a pathological/looping `next`.
     *
     * @param string $path list endpoint path, e.g. '/api/tags/'
     * @return array|null  merged results, or null on a transport/parse error
     */
    public function listAll(string $path): ?array
    {
        $results  = [];
        $next     = $path . (strpos($path, '?') === false ? '?' : '&') . 'page_size=250';
        $maxPages = 50;

        while ($next !== null && $maxPages-- > 0) {
            // Only ever request our own base host; if `next` is an absolute URL
            // from Paperless, reduce it to a path so the base URL stays fixed.
            $reqPath = $this->toLocalPath($next);

            $res = $this->request('GET', $reqPath);

            if ((int) $res['status'] !== 200) {
                return null;
            }

            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                return null;
            }

            foreach (($data['results'] ?? []) as $item) {
                $results[] = $item;
            }

            $next = $data['next'] ?? null;
        }

        return $results;
    }

    /**
     * Reduce an absolute Paperless URL (e.g. a DRF `next` link) to a base-relative
     * path, so the egress always targets the server-fixed base URL and never a
     * host smuggled in via the response (SSRF guard for pagination follow).
     *
     * @param string $urlOrPath
     * @return string path beginning with '/'
     */
    private function toLocalPath(string $urlOrPath): string
    {
        if ($urlOrPath === '' || $urlOrPath[0] === '/') {
            return $urlOrPath === '' ? '/' : $urlOrPath;
        }

        $parts = parse_url($urlOrPath);
        $path  = $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * Validate a YYYY-MM-DD date string (used for created__date__gte/lte).
     *
     * @param string $s
     * @return bool
     */
    private function isIsoDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }

    /**
     * Integer-validate a document id before it is placed into a request path.
     *
     * @param mixed $id
     * @return int validated id (>= 1)
     * @throws InvalidArgumentException on a non-integer / non-positive id
     */
    public function validateId($id): int
    {
        if (is_int($id)) {
            $int = $id;
        }
        elseif (is_string($id) && ctype_digit($id)) {
            $int = (int) $id;
        }
        else {
            throw new InvalidArgumentException('Invalid Paperless document id.');
        }

        if ($int < 1) {
            throw new InvalidArgumentException('Invalid Paperless document id.');
        }

        return $int;
    }

    /**
     * Perform a request to Paperless and return only {status, body}.
     *
     * Selects Guzzle when the bundled class is present, else falls back to cURL.
     * Both paths disable redirects and apply connect + read timeouts. Transport
     * errors are swallowed into status 0 so callers never see exceptions/secrets.
     *
     * @param string $method HTTP method (GET)
     * @param string $path   Absolute path beginning with '/'
     * @return array{status: int, body: string, content_type: string}
     */
    private function request(string $method, string $path): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Accept'        => 'application/json',
        ];

        if (class_exists('\\GuzzleHttp\\Client')) {
            return $this->requestGuzzle($method, $url, $headers);
        }

        return $this->requestCurl($method, $url, $headers);
    }

    /**
     * Guzzle transport. Redirects off, http_errors off, timeouts set.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string,string> $headers
     * @return array{status: int, body: string}
     */
    private function requestGuzzle(string $method, string $url, array $headers): array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'allow_redirects' => false,
                'http_errors'     => false,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout'         => self::TIMEOUT,
            ]);

            $response = $client->request($method, $url, ['headers' => $headers]);

            return [
                'status'       => (int) $response->getStatusCode(),
                'body'         => (string) $response->getBody(),
                'content_type' => (string) $response->getHeaderLine('Content-Type'),
            ];
        }
        catch (\Throwable $e) {
            // Transport-level failure (DNS, connect, timeout). Never surface details.
            return ['status' => 0, 'body' => '', 'content_type' => ''];
        }
    }

    /**
     * cURL transport fallback. Redirects off, timeouts set.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string,string> $headers
     * @return array{status: int, body: string}
     */
    private function requestCurl(string $method, string $url, array $headers): array
    {
        $ch = curl_init();

        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'content_type' => ''];
        }

        // Flatten associative headers into "Name: value" lines.
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        if ($body === false) {
            // Transport-level failure. Never surface the cURL error string.
            return ['status' => 0, 'body' => '', 'content_type' => ''];
        }

        return ['status' => $status, 'body' => (string) $body, 'content_type' => $ctype];
    }
}
