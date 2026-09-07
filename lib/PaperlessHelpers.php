<?php

/**
 * PaperlessHelpers — pure, dependency-free helpers extracted from the plugin.
 *
 * The plugin class (`paperless_attach`) extends `rcube_plugin` and cannot be
 * loaded outside the Roundcube runtime, which makes its logic awkward to test.
 * The genuinely pure bits — byte-shorthand parsing, human sizes, filename/title
 * sanitisation, the async consume-task status mapping (incl. Paperless's
 * duplicate detection), and the compose-attachment storage-path selection —
 * live here instead as static methods so they can be unit tested directly.
 * The plugin delegates to them; behaviour is unchanged.
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

    /**
     * Does this Roundcube core own compose attachments in the `uploads` DB table?
     *
     * Roundcube 1.7 moved compose-attachment bookkeeping out of
     * `$_SESSION['compose_data_<id>']['attachments']` and into a `uploads`
     * table fronted by the `rcube_uploads` trait. Every consumer now reads
     * `list_uploaded_files($group)` — send.php (what actually gets sent),
     * compose.php (the attachment list, the `max_message_size` accounting) and
     * `filesystem_attachments::cleanup()` (unlinking the temp files). A row that
     * exists only in the session is invisible to all of them.
     *
     * `insert_uploaded_file()` is the trait's writer and is the reliable feature
     * probe: absent on 1.6.x, present from 1.7 on.
     *
     * @param object $rcmail the rcmail instance
     * @return bool true on Roundcube 1.7+ (uploads table), false on 1.6.x (session)
     */
    public static function usesUploadsTable($rcmail): bool
    {
        return is_object($rcmail) && method_exists($rcmail, 'insert_uploaded_file');
    }

    /**
     * Build the session row for the LEGACY (Roundcube 1.6.x) storage path.
     *
     * Mirrors compose.php's own handling: drop the transient keys that must not
     * be persisted, then mark the row so the `message_ready` hook can re-attach
     * the document at send time (see `paperless_attach::attach_at_send()` — on
     * 1.6.x a slow attach request makes core miss the attachment otherwise).
     *
     * Only used on 1.6.x; on 1.7+ core persists the descriptor itself and the
     * marker must NOT be set, or the document would be attached twice.
     *
     * @param array $att descriptor as returned by the `attachment_save` hook
     * @return array the row to session-append
     */
    public static function legacyAttachmentRow(array $att): array
    {
        unset($att['data'], $att['status'], $att['content_id'], $att['abort']);

        $att['paperless'] = true;

        return $att;
    }
}
