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

    /** @var int Upload (POST document) total timeout in seconds — uploads can be
     *  larger than a metadata GET, so allow more headroom than self::TIMEOUT. */
    private const UPLOAD_TIMEOUT = 30;

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

        $status = $this->downloadTransport($url, $destPath);

        // A truncated attachment must never enter the compose: require a clean 200
        // AND a non-empty file, else unlink the (possibly partial) output.
        if ($status !== 200 || !is_file($destPath) || filesize($destPath) <= 0) {
            @unlink($destPath);
            return false;
        }

        return true;
    }

    /**
     * Wire transport for download(): stream a GET straight to `$destPath` and
     * return the HTTP status (0 on any transport failure). Redirects off,
     * connect/read timeouts set. Seamed out (protected) so tests can drive
     * download()'s status/file-cleanup logic without real network I/O.
     *
     * NOTE: do NOT send `Accept: application/pdf`. Paperless-ngx runs DRF content
     * negotiation here and answers a restrictive Accept with HTTP 406 (a tiny JSON
     * error body instead of the file). A wildcard Accept lets it serve the archive
     * PDF (200).
     *
     * @param string $url
     * @param string $destPath
     * @return int HTTP status, or 0 on transport failure
     */
    protected function downloadTransport(string $url, string $destPath): int
    {
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Accept'        => '*/*',
        ];

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

                return (int) $response->getStatusCode();
            }
            catch (\Throwable $e) {
                return 0;
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            return 0;
        }

        $fp = @fopen($destPath, 'w');
        if ($fp === false) {
            curl_close($ch);
            return 0;
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

        return $ok === false ? 0 : $status;
    }

    /**
     * Upload a file to Paperless for consumption (the reverse of download()).
     *
     * POSTs multipart/form-data to `/api/documents/post_document/` with the file
     * under the `document` field and an optional `title`. Paperless consumes the
     * document ASYNCHRONOUSLY and returns the consume task UUID (a JSON-quoted
     * string) on HTTP 200 — NOT the final document. Poll getTaskStatus() with the
     * returned UUID to learn the outcome (success / duplicate / failure).
     *
     * As with download(), a wildcard Accept header is sent (Paperless answers a
     * restrictive Accept with HTTP 406). Redirects are disabled and connect/upload
     * timeouts are
     * applied on both transports. The file is streamed from `$path` (Guzzle: a file
     * handle; cURL: CURLFile) — never buffered whole in PHP. The token never leaves
     * this method.
     *
     * @param string      $path     path to the file to upload (already on disk)
     * @param string      $filename the filename Paperless should record
     * @param string      $mimetype the file's MIME type
     * @param string|null $title    optional document title (omitted when null/empty)
     * @return string|null the consume task UUID on success, or null on failure
     */
    public function uploadDocument(string $path, string $filename, string $mimetype, ?string $title = null): ?string
    {
        if (!is_file($path) || filesize($path) <= 0) {
            return null;
        }

        $url = $this->baseUrl . '/api/documents/post_document/';

        $res = $this->uploadTransport($url, $path, $filename, $mimetype, $title);

        if ((int) $res['status'] !== 200) {
            return null;
        }

        // The body is the task UUID as a JSON string, e.g. "\"f1d2…\"". Decode if
        // it is valid JSON, otherwise fall back to trimming surrounding quotes.
        $body    = (string) $res['body'];
        $decoded = json_decode($body, true);
        $taskId  = is_string($decoded) ? $decoded : trim($body, " \t\n\r\0\x0B\"");

        $taskId = trim((string) $taskId);

        return $taskId !== '' ? $taskId : null;
    }

    /**
     * Wire transport for uploadDocument(): POST the file as multipart/form-data
     * and return only `{status, body}` (status 0 on any transport failure).
     * Redirects off, connect/upload timeouts set, wildcard Accept (406 otherwise).
     * The file streams from disk (Guzzle: a handle; cURL: CURLFile) — never
     * buffered whole in PHP. Seamed out (protected) so tests can drive
     * uploadDocument()'s UUID parsing without real network I/O.
     *
     * @param string      $url
     * @param string      $path
     * @param string      $filename
     * @param string      $mimetype
     * @param string|null $title
     * @return array{status: int, body: string}
     */
    protected function uploadTransport(string $url, string $path, string $filename, string $mimetype, ?string $title): array
    {
        if (class_exists('\\GuzzleHttp\\Client')) {
            try {
                $client = new \GuzzleHttp\Client([
                    'allow_redirects' => false,
                    'http_errors'     => false,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout'         => self::UPLOAD_TIMEOUT,
                ]);

                $multipart = [[
                    'name'     => 'document',
                    'contents' => fopen($path, 'r'),
                    'filename' => $filename,
                    'headers'  => ['Content-Type' => $mimetype],
                ]];
                if ($title !== null && $title !== '') {
                    $multipart[] = ['name' => 'title', 'contents' => $title];
                }

                $response = $client->request('POST', $url, [
                    'headers'   => [
                        'Authorization' => 'Token ' . $this->token,
                        'Accept'        => '*/*',
                    ],
                    'multipart' => $multipart,
                ]);

                return [
                    'status' => (int) $response->getStatusCode(),
                    'body'   => (string) $response->getBody(),
                ];
            }
            catch (\Throwable $e) {
                return ['status' => 0, 'body' => ''];
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }

        // CURLOPT_POSTFIELDS as an array makes cURL set the multipart
        // Content-Type + boundary itself — do NOT add a manual Content-Type.
        $post = ['document' => new \CURLFile($path, $mimetype, $filename)];
        if ($title !== null && $title !== '') {
            $post['title'] = $title;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $this->token,
                'Accept: */*',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Look up the status of an async consume task created by uploadDocument().
     *
     * GET `/api/tasks/?task_id=<uuid>` returns a list with (at most) one task
     * object. The UUID is format-validated before it enters the query string.
     *
     * @param string $taskId the consume task UUID returned by uploadDocument()
     * @return array{status: string, result: string, related_document: int|null}|null
     *   normalized task record (status upper-cased, e.g. PENDING/STARTED/SUCCESS/
     *   FAILURE), or null on a transport/parse error or an empty result.
     */
    public function getTaskStatus(string $taskId): ?array
    {
        // Injection guard: task ids are UUIDs (hex + dashes). Reject anything else
        // before it reaches the query string.
        if (!preg_match('/^[0-9a-fA-F-]{8,64}$/', $taskId)) {
            return null;
        }

        $res = $this->request('GET', '/api/tasks/?task_id=' . rawurlencode($taskId));

        if ((int) $res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return null;
        }

        // The endpoint may return a bare list, or a DRF-paginated {results:[…]}.
        $list = isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;
        $task = is_array($list) ? reset($list) : null;

        if (!is_array($task)) {
            return null;
        }

        $related = $task['related_document'] ?? null;

        return [
            'status'           => strtoupper((string) ($task['status'] ?? '')),
            'result'           => (string) ($task['result'] ?? ''),
            'related_document' => $related !== null && ctype_digit((string) $related) ? (int) $related : null,
        ];
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
    protected function request(string $method, string $path): array
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
