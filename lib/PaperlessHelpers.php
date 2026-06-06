<?php

/**
 * PaperlessHelpers — pure, dependency-free helpers extracted from the plugin.
 *
 * The plugin class (`paperless_attach`) extends `rcube_plugin` and cannot be
 * loaded outside the Roundcube runtime, which makes its logic awkward to test.
 * The genuinely pure bits — byte-shorthand parsing, human sizes, filename/title
 * sanitisation, and the async consume-task status mapping (incl. Paperless's
 * duplicate detection) — live here instead as static methods so they can be unit
 * tested directly. The plugin delegates to them; behaviour is unchanged.
 *
 * @license GPL-3.0+
 */
class PaperlessHelpers
{
    /**
     * Parse a PHP byte-shorthand string ("15M", "256M", "2G", "1024K",
     * "16777216") into bytes. An empty value or "-1" (unlimited) returns 0 so
     * callers can exclude it from a min(). NOTE: a plain `(int) "15M"` yields 15
     * *bytes* — this multiplier parse is the whole point.
     *
     * @param string $val
     * @return int bytes, or 0 for empty/unlimited
     */
    public static function parseBytes(string $val): int
    {
        $val = trim($val);

        if ($val === '' || $val === '-1') {
            return 0;
        }

        $last = strtolower($val[strlen($val) - 1]);
        $num  = (int) $val;

        switch ($last) {
            case 'g':
                $num *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $num *= 1024 * 1024;
                break;
            case 'k':
                $num *= 1024;
                break;
        }

        return $num > 0 ? $num : 0;
    }

    /**
     * Format a byte count as a compact human-readable size (B / KB / MB).
     * Non-positive input returns '' (so an unknown size renders as nothing).
     *
     * @param int $bytes
     * @return string
     */
    public static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    /**
     * Sanitise a Paperless document title into a safe "<title>.pdf" filename:
     * strip path separators + control chars, collapse blanks, fall back to
     * "document", cap the length. (XSS on render is handled separately by
     * rcube::Q(); this guards filesystem/mail safety.)
     *
     * @param string $title raw document title
     * @return string sanitised "<name>.pdf"
     */
    public static function sanitizePdfName(string $title): string
    {
        $base = preg_replace('/[\\/\\\\:*?"<>|\\x00-\\x1F]+/', '_', $title);
        $base = trim((string) $base);

        if ($base === '') {
            $base = 'document';
        }

        if (strlen($base) > 200) {
            $base = substr($base, 0, 200);
        }

        return $base . '.pdf';
    }

    /**
     * Derive a Paperless document title from an attachment filename by dropping a
     * single trailing extension ("Rechnung_2024.pdf" → "Rechnung_2024"). Returns
     * the unchanged name when there is no usable extension.
     *
     * @param string $filename
     * @return string
     */
    public static function titleFromFilename(string $filename): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $filename);
        $base = trim((string) $base);

        return $base !== '' ? $base : $filename;
    }

    /**
     * Map a Paperless consume-task's Celery state + result text to the plugin's
     * coarse outcome the client polls for.
     *
     * Paperless reports a DUPLICATE as a FAILED task whose result names the
     * existing document ("Not consuming …: It is a duplicate of … (#7).") — so a
     * FAILURE whose result mentions a duplicate / "already exists" is surfaced as
     * `duplicate`, every other FAILURE as `failure`, SUCCESS as `success`, and
     * anything else (PENDING / STARTED / RETRY / empty) as `pending`.
     *
     * @param string $upstreamStatus Celery state (case-insensitive)
     * @param string $result         task result text
     * @return string one of: success | duplicate | failure | pending
     */
    public static function mapTaskStatus(string $upstreamStatus, string $result): string
    {
        $upstream = strtoupper(trim($upstreamStatus));
        $result   = strtolower($result);

        if ($upstream === 'SUCCESS') {
            return 'success';
        }

        if ($upstream === 'FAILURE') {
            return (strpos($result, 'duplicate') !== false || strpos($result, 'already exists') !== false)
                ? 'duplicate'
                : 'failure';
        }

        return 'pending';
    }
}
