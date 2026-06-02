<?php

/**
 * Paperless Attach
 *
 * Roundcube (Elastic skin) plugin that bridges a Paperless-ngx instance into the
 * mail compose flow. Phase 1 delivers the installable plugin skeleton, per-user
 * encrypted Paperless token storage, and a server-side connectivity check.
 *
 * The plugin directory name, this file name, and the class name MUST match exactly
 * (`paperless_attach`) — Roundcube silently fails to load the plugin otherwise.
 *
 * @license GPL-3.0+
 */
class paperless_attach extends rcube_plugin
{
    /**
     * Restrict the plugin to the tasks it actually touches. Settings hosts the
     * preferences UI + the test-connection AJAX action; mail hosts the compose
     * toolbar button and the search/thumb/lookup proxy actions (Phase 2).
     */
    public $task = 'settings|mail';

    /**
     * Result page size for the Paperless search list. Overridable via the
     * `paperless_search_page_size` config key (CONTEXT decision; default 25).
     */
    private const DEFAULT_PAGE_SIZE = 25;

    /**
     * Default Paperless base URL when config.inc.php does not override it.
     * The URL is ALWAYS server-fixed (SSRF guard) — never accepted from the browser.
     */
    private const DEFAULT_BASE_URL = 'http://paperless-webserver:8000';

    /**
     * Plugin bootstrap. Roundcube calls this once per request after the plugin
     * is loaded. Keep it lean and gate feature wiring by task/action.
     */
    public function init()
    {
        $rcmail = rcmail::get_instance();

        // Localized strings, exposed to both PHP (gettext) and JS (rcmail.gettext).
        $this->add_texts('localization/', true);

        // Plugin config (config.inc.php / config.inc.php.dist) — provides paperless_url.
        $this->load_config();

        // Observable load marker so plugin loading can be confirmed in container logs.
        rcube::write_log('paperless_attach', 'paperless_attach plugin loaded');

        // ---------------------------------------------------------------------
        // Preferences UI (dedicated "Paperless" section) — registered for the
        // settings task. Implemented in plan 01-02.
        // ---------------------------------------------------------------------
        if ($rcmail->task === 'settings') {
            $this->add_hook('preferences_sections_list', [$this, 'prefs_sections_list']);
            $this->add_hook('preferences_list', [$this, 'prefs_list']);
            $this->add_hook('preferences_save', [$this, 'prefs_save']);

            $this->include_script('js/paperless.js');
            $this->include_stylesheet($this->local_skin_path() . '/paperless.css');
        }

        // ---------------------------------------------------------------------
        // Server-side test-connection action. Always registered so the AJAX
        // endpoint is reachable under the settings task. Implemented in 01-02.
        // ---------------------------------------------------------------------
        $this->register_action('plugin.paperless.test_connection', [$this, 'action_test_connection']);

        // ---------------------------------------------------------------------
        // Phase 2 — Search & Pick proxy actions.
        //
        // These run under task=mail as AJAX endpoints invoked from the compose
        // picker dialog. They are registered unconditionally (Roundcube only
        // dispatches them when the matching _action arrives) so the dialog can
        // reach them regardless of the compose sub-action (compose/reply/forward
        // all share the same `compose` action). Each handler decrypts the
        // per-user token + resolves the server-fixed base URL server-side only;
        // the token and base URL never appear in any response.
        // ---------------------------------------------------------------------
        $this->register_action('plugin.paperless.search', [$this, 'action_search']);
        $this->register_action('plugin.paperless.thumb', [$this, 'action_thumb']);
        $this->register_action('plugin.paperless.tags', [$this, 'action_tags']);
        $this->register_action('plugin.paperless.correspondents', [$this, 'action_correspondents']);
        $this->register_action('plugin.paperless.doctypes', [$this, 'action_doctypes']);

        // ---------------------------------------------------------------------
        // Phase 3 — Attach action.
        //
        // Downloads each selected document's archive PDF server-side and injects
        // it into the open compose session as a native attachment (ATTACH-01,
        // ATTACH-02). Registered unconditionally; Roundcube only dispatches it
        // when the matching _action arrives.
        // ---------------------------------------------------------------------
        $this->register_action('plugin.paperless.attach', [$this, 'action_attach']);

        // Re-attach Paperless documents to the outgoing message at send time.
        // Required because a slow attach request (PDF download) leaves the attachment
        // in $_SESSION but not in send.php's $COMPOSE reference, so core never
        // attaches it. See attach_at_send() for the full explanation.
        $this->add_hook('message_ready', [$this, 'attach_at_send']);

        // ---------------------------------------------------------------------
        // Phase 3 — De-risking SPIKE (flag-gated, NEVER ships enabled).
        //
        // Proves the load-bearing injection mechanism (attachment_save hook with
        // a temp-file path + the mirrored 2-arg add2attachment_list) by attaching
        // a locally-generated dummy PDF to the open compose — before any Paperless
        // wiring. Only registered when `paperless_spike` is truthy in config.
        // ---------------------------------------------------------------------
        if ($rcmail->config->get('paperless_spike')) {
            $this->register_action('plugin.paperless.spike_attach', [$this, 'action_spike_attach']);
        }

        // ---------------------------------------------------------------------
        // Phase 2 — Compose attach button (compose AND reply AND forward).
        //
        // Reply and forward reuse the compose template/action, so a single
        // registration on the `compose` action surfaces the button in all three.
        // The button lives inside the "Optionen und Anhänge" attachments widget
        // (NOT the top toolbar) — appended client-side by paperless.js, mirroring
        // the bundled vcard_attachments plugin. It opens the Paperless picker
        // dialog ON CLICK (never on page load — avoids the Elastic
        // off-screen-on-load bug).
        // ---------------------------------------------------------------------
        if ($rcmail->task === 'mail' && in_array((string) $rcmail->action, ['compose', ''], true)) {
            $this->include_script('js/paperless.js');
            $this->include_stylesheet($this->local_skin_path() . '/paperless.css');

            // Expose ONLY the integer effective upload limit so the picker can
            // disable oversize rows without hardcoding 15M. The token + base URL
            // are NEVER placed into rcmail.env.
            $rcmail->output->set_env('paperless_upload_limit', $this->effective_upload_limit());
        }
    }

    /**
     * Append a dedicated "Paperless" section to the Settings sections list.
     *
     * @param array $args preferences_sections_list hook args
     * @return array modified args
     */
    public function prefs_sections_list($args)
    {
        $args['list']['paperless'] = [
            'id'      => 'paperless',
            'section' => rcube::Q($this->gettext('paperlesssection')),
        ];

        return $args;
    }

    /**
     * Render the Paperless preferences block.
     *
     * The token is NEVER echoed into the field value, rcmail.env, or any JSON.
     * We only signal whether a token currently exists (boolean), rendering the
     * field empty with a "configured" hint when one is stored.
     *
     * @param array $args preferences_list hook args
     * @return array modified args
     */
    public function prefs_list($args)
    {
        if ($args['section'] !== 'paperless') {
            return $args;
        }

        $rcmail = rcmail::get_instance();

        // Existence only — do NOT decrypt or place the token into the markup.
        $has_token = (string) $rcmail->config->get('paperless_token', '') !== '';

        // Masked token input: value is always empty.
        $token_input = new html_inputfield([
            'name'         => '_paperless_token',
            'id'           => 'paperless-token',
            'type'         => 'password',
            'autocomplete' => 'off',
            'size'         => 40,
            'value'        => '',
        ]);

        $token_field = $token_input->show();

        if ($has_token) {
            $token_field .= html::span(
                ['class' => 'paperless-configured', 'id' => 'paperless-token-status'],
                rcube::Q($this->gettext('tokenconfigured'))
            );
        }

        // "Remove token" checkbox — clears the stored token on save.
        $remove_checkbox = new html_checkbox([
            'name'  => '_paperless_token_remove',
            'id'    => 'paperless-token-remove',
            'value' => '1',
        ]);

        $remove_field = $remove_checkbox->show(0)
            . ' '
            . html::label('paperless-token-remove', rcube::Q($this->gettext('tokenremove')));

        // Test-connection button + inline result container.
        $test_button = html::tag('button', [
            'type'  => 'button',
            'id'    => 'paperless-test-btn',
            'class' => 'button',
        ], rcube::Q($this->gettext('testconnection')));

        $test_result = html::span(['id' => 'paperless-test-result', 'class' => 'paperless-test-result'], '');

        $args['blocks']['paperless'] = [
            'name'    => rcube::Q($this->gettext('paperlesssection')),
            'options' => [
                'token' => [
                    'title'   => html::label('paperless-token', rcube::Q($this->gettext('tokenlabel'))),
                    'content' => $token_field,
                ],
                'token_remove' => [
                    'title'   => '',
                    'content' => $remove_field,
                ],
                'test' => [
                    'title'   => '',
                    'content' => $test_button . ' ' . $test_result,
                ],
            ],
        ];

        return $args;
    }

    /**
     * Persist the submitted token.
     *
     * Logic:
     *  - remove checkbox checked  → clear the stored token
     *  - non-empty token submitted → encrypt and replace
     *  - blank token submitted     → leave the stored value untouched
     *
     * The plaintext token is NEVER written back into $args['prefs'] for re-render.
     *
     * @param array $args preferences_save hook args
     * @return array modified args
     */
    public function prefs_save($args)
    {
        if ($args['section'] !== 'paperless') {
            return $args;
        }

        $rcmail = rcmail::get_instance();

        $remove = (bool) rcube_utils::get_input_value('_paperless_token_remove', rcube_utils::INPUT_POST);
        $token  = rcube_utils::get_input_value('_paperless_token', rcube_utils::INPUT_POST);
        $token  = $token !== null ? trim($token) : '';

        if ($remove) {
            $args['prefs']['paperless_token'] = '';
        }
        elseif ($token !== '') {
            $args['prefs']['paperless_token'] = $rcmail->encrypt($token);
        }
        // else: blank submit with no remove → keep existing stored value (don't set key).

        return $args;
    }

    /**
     * Server-side test-connection action.
     *
     * Decrypts the stored token (server-side only), constructs a PaperlessClient,
     * probes GET /api/documents/?page_size=1, and returns a localized ✓/✗ message.
     * The token and raw upstream body NEVER leave the server.
     */
    public function action_test_connection()
    {
        $rcmail = rcmail::get_instance();

        $token = $this->get_token();

        // No token stored → prompt the user to configure one.
        if ($token === null) {
            $this->send_test_result(false, $this->gettext('testnotoken'));
            return;
        }

        // Decrypt failed (e.g. after a des_key change) → prompt re-entry,
        // do NOT send a broken Authorization header to Paperless.
        if ($token === false) {
            $this->send_test_result(false, $this->gettext('testdecryptfail'));
            return;
        }

        require_once __DIR__ . '/lib/PaperlessClient.php';

        $base_url = (string) $rcmail->config->get('paperless_url', self::DEFAULT_BASE_URL);

        $client = new PaperlessClient($base_url, $token);
        $result = $client->testConnection();

        switch ($result['reason']) {
            case 'ok':
                $this->send_test_result(true, $this->gettext('testok'));
                break;
            case 'badtoken':
                $this->send_test_result(false, $this->gettext('testbadtoken'));
                break;
            case 'unreachable':
            default:
                $this->send_test_result(false, $this->gettext('testunreachable'));
                break;
        }
    }

    /**
     * Push a structured test-connection result to the client. Message only —
     * never the token, never the raw upstream body.
     *
     * @param bool   $ok      whether the probe succeeded
     * @param string $message localized result message
     */
    private function send_test_result($ok, $message)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.paperless.test_result', [
            'ok'      => (bool) $ok,
            'message' => $message,
        ]);
        $rcmail->output->send();
    }

    // =====================================================================
    // Phase 2 — Search & Pick proxy actions.
    //
    // Every handler funnels through resolve_client(): it decrypts the token
    // and resolves the server-fixed base URL. On a missing token it sends the
    // `no_token` envelope (so the JS renders the "configure in Settings" state)
    // and returns null; on decrypt failure or any transport error it sends the
    // generic `error` envelope. Responses carry ONLY document/proxy data —
    // never the token, never the base URL.
    // =====================================================================

    /**
     * Build a PaperlessClient for the current user, or send an error envelope.
     *
     * @return PaperlessClient|null  client on success; null after an envelope was sent
     */
    private function resolve_client()
    {
        $rcmail = rcmail::get_instance();
        $token  = $this->get_token();

        if ($token === null) {
            $this->send_envelope(['ok' => false, 'error' => 'no_token']);
            return null;
        }

        if ($token === false) {
            // Stored but undecryptable (e.g. des_key rotation) — treat like an
            // error state; never send a broken Authorization header upstream.
            $this->send_envelope(['ok' => false, 'error' => 'error']);
            return null;
        }

        require_once __DIR__ . '/lib/PaperlessClient.php';

        $base_url = (string) $rcmail->config->get('paperless_url', self::DEFAULT_BASE_URL);

        return new PaperlessClient($base_url, $token);
    }

    /**
     * Send a JSON envelope to the client and end the request. The envelope
     * carries only metadata / {ok,error}; the token and base URL never cross.
     *
     * @param array $data
     */
    private function send_envelope(array $data)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.paperless.response', $data);
        $rcmail->output->send();
    }

    /**
     * plugin.paperless.search — full-text + structured filter search.
     *
     * Reads {query, tags[], correspondent, document_type, date_from, date_to,
     * page} from the request, calls PaperlessClient::search(), and returns
     * normalized rows. correspondent_name is resolved server-side from the
     * (cached) correspondents lookup so each row needs no extra client call.
     */
    public function action_search()
    {
        $client = $this->resolve_client();
        if ($client === null) {
            return;
        }

        $rcmail = rcmail::get_instance();

        $query         = (string) rcube_utils::get_input_value('query', rcube_utils::INPUT_POST);
        $tags          = rcube_utils::get_input_value('tags', rcube_utils::INPUT_POST);
        $correspondent = (string) rcube_utils::get_input_value('correspondent', rcube_utils::INPUT_POST);
        $document_type = (string) rcube_utils::get_input_value('document_type', rcube_utils::INPUT_POST);
        $date_from     = (string) rcube_utils::get_input_value('date_from', rcube_utils::INPUT_POST);
        $date_to       = (string) rcube_utils::get_input_value('date_to', rcube_utils::INPUT_POST);
        $page          = (int) rcube_utils::get_input_value('page', rcube_utils::INPUT_POST);

        if ($page < 1) {
            $page = 1;
        }

        // Normalize tag ids to a clean list of positive integers (SSRF/typing guard).
        $tag_ids = [];
        if (is_array($tags)) {
            foreach ($tags as $t) {
                if (ctype_digit((string) $t) && (int) $t > 0) {
                    $tag_ids[] = (int) $t;
                }
            }
        }

        $page_size = (int) $rcmail->config->get('paperless_search_page_size', self::DEFAULT_PAGE_SIZE);
        if ($page_size < 1) {
            $page_size = self::DEFAULT_PAGE_SIZE;
        }

        $criteria = [
            'query'         => $query,
            'tags'          => $tag_ids,
            'correspondent' => ctype_digit($correspondent) ? (int) $correspondent : null,
            'document_type' => ctype_digit($document_type) ? (int) $document_type : null,
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'page'          => $page,
            'page_size'     => $page_size,
        ];

        try {
            $res = $client->search($criteria);
        }
        catch (\Throwable $e) {
            $this->send_envelope(['ok' => false, 'error' => 'error']);
            return;
        }

        if ($res === null) {
            $this->send_envelope(['ok' => false, 'error' => 'error']);
            return;
        }

        // Resolve correspondent ids → names from the complete (cached) lookup,
        // so rows carry a human label without an extra round trip per row.
        $corr_names = [];
        try {
            foreach ($client->listAll('/api/correspondents/') as $c) {
                if (isset($c['id'])) {
                    $corr_names[(int) $c['id']] = (string) ($c['name'] ?? '');
                }
            }
        }
        catch (\Throwable $e) {
            $corr_names = [];
        }

        $rows = [];
        foreach (($res['results'] ?? []) as $doc) {
            if (!isset($doc['id'])) {
                continue;
            }

            $corr_id   = isset($doc['correspondent']) ? (int) $doc['correspondent'] : 0;
            $corr_name = $corr_id && isset($corr_names[$corr_id]) ? $corr_names[$corr_id] : '';

            // NOTE: the Paperless /api/documents/ list carries NO size field
            // (no archive_size/original_size). We therefore expose no per-row
            // size and rely on the post-download filesize() guard for oversize.
            $rows[] = [
                'id'                => (int) $doc['id'],
                'title'             => (string) ($doc['title'] ?? ''),
                'created'           => (string) ($doc['created'] ?? ''),
                'correspondent_name' => $corr_name,
                'mime'             => (string) ($doc['mime_type'] ?? ''),
            ];
        }

        $this->send_envelope([
            'ok'       => true,
            'count'    => (int) ($res['count'] ?? count($rows)),
            'has_more' => !empty($res['next']),
            'page'     => $page,
            'results'  => $rows,
        ]);
    }

    /**
     * plugin.paperless.thumb — stream a document thumbnail through the backend.
     *
     * The document id is integer-validated server-side; the bytes are streamed
     * with their upstream content-type. The token and Paperless URL never reach
     * the browser (no direct <img src> to Paperless).
     */
    public function action_thumb()
    {
        $client = $this->resolve_client();
        if ($client === null) {
            // Missing/undecryptable token → 404 image rather than an envelope,
            // since this endpoint feeds an <img>, not the dialog state machine.
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $id_raw = rcube_utils::get_input_value('id', rcube_utils::INPUT_GPC);

        try {
            $id = $client->validateId($id_raw);
        }
        catch (\Throwable $e) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }

        try {
            $thumb = $client->thumb($id);
        }
        catch (\Throwable $e) {
            $thumb = null;
        }

        if ($thumb === null || $thumb['body'] === '') {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $ctype = $thumb['content_type'] !== '' ? $thumb['content_type'] : 'image/png';

        header('Content-Type: ' . $ctype);
        header('Content-Length: ' . strlen($thumb['body']));
        // Token-auth content — keep it private to the user's session.
        header('Cache-Control: private, max-age=300');
        echo $thumb['body'];
        exit;
    }

    /** plugin.paperless.tags — complete (paginated) tag list as [{id,name}]. */
    public function action_tags()
    {
        $this->send_lookup('/api/tags/', 'tags');
    }

    /** plugin.paperless.correspondents — complete correspondent list. */
    public function action_correspondents()
    {
        $this->send_lookup('/api/correspondents/', 'correspondents');
    }

    /** plugin.paperless.doctypes — complete document-type list. */
    public function action_doctypes()
    {
        $this->send_lookup('/api/document_types/', 'doctypes');
    }

    /**
     * Shared body for the three filter lookups: fetch the full (DRF-paginated)
     * list and return [{id,name}] — never truncated, never carrying the token.
     *
     * The `lookup` kind is echoed back in the envelope so the client can route
     * each payload to the correct <select> without guessing — the 3 lookups all
     * return on the shared plugin.paperless.response channel, so without this
     * correlation the JS could land (e.g.) correspondents into the Tags select.
     *
     * @param string $path Paperless list endpoint path
     * @param string $kind lookup kind: 'tags' | 'correspondents' | 'doctypes'
     */
    private function send_lookup(string $path, string $kind)
    {
        $client = $this->resolve_client();
        if ($client === null) {
            return;
        }

        try {
            $items = $client->listAll($path);
        }
        catch (\Throwable $e) {
            $this->send_envelope(['ok' => false, 'error' => 'error']);
            return;
        }

        if ($items === null) {
            $this->send_envelope(['ok' => false, 'error' => 'error']);
            return;
        }

        $out = [];
        foreach ($items as $it) {
            if (isset($it['id'])) {
                $out[] = ['id' => (int) $it['id'], 'name' => (string) ($it['name'] ?? '')];
            }
        }

        $this->send_envelope(['ok' => true, 'items' => $out, 'lookup' => $kind]);
    }

    /**
     * Format a byte count as a compact human-readable size (B / KB / MB).
     *
     * @param int $bytes
     * @return string
     */
    private function human_size(int $bytes): string
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
     * Compute the EFFECTIVE max attachment size in bytes at runtime.
     *
     * The enforced cap is the MINIMUM of the Roundcube upload ceiling
     * (`max_message_size`, the value the ROUNDCUBEMAIL_UPLOAD_MAX_FILESIZE env maps
     * to) and the PHP limits `upload_max_filesize`, `post_max_size`, `memory_limit`.
     * PHP can silently cap below the env value (PITFALL 11), so we never hardcode
     * 15M — we honestly report the true ceiling. A value of -1 / empty (unlimited)
     * is excluded from the min; if nothing positive remains we fall back to 15M.
     *
     * @return int effective limit in bytes
     */
    private function effective_upload_limit(): int
    {
        $rcmail   = rcmail::get_instance();
        $fallback = 15 * 1024 * 1024;

        $candidates = [];

        // Roundcube-configured upload ceiling (maps from ROUNDCUBEMAIL_UPLOAD_MAX_FILESIZE).
        // `max_message_size` is stored as a SHORTHAND STRING (e.g. "15M") — a plain
        // (int) cast would truncate "15M" to 15 bytes and collapse the whole limit.
        // Use core's global parse_bytes() (always loaded via bootstrap.php), the same
        // helper core itself applies to this key in compose.php/attachment_upload.php.
        $rcRaw = $rcmail->config->get('max_message_size', 0);
        $rcMax = function_exists('parse_bytes')
            ? (int) parse_bytes((string) $rcRaw)
            : $this->parse_php_bytes((string) $rcRaw);
        if ($rcMax > 0) {
            $candidates[] = $rcMax;
        }

        // PHP limits — each parsed from shorthand; -1/unlimited excluded.
        foreach (['upload_max_filesize', 'post_max_size', 'memory_limit'] as $key) {
            $bytes = $this->parse_php_bytes((string) ini_get($key));
            if ($bytes > 0) {
                $candidates[] = $bytes;
            }
        }

        if (empty($candidates)) {
            return $fallback;
        }

        return min($candidates);
    }

    /**
     * Parse a PHP byte-shorthand string (e.g. "15M", "256M", "2G", "1024K", "16777216")
     * into bytes. An empty value or "-1" (unlimited) returns 0 so the caller can
     * exclude it from a min().
     *
     * @param string $val
     * @return int bytes, or 0 for empty/unlimited
     */
    private function parse_php_bytes(string $val): int
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
     * Retrieve the decrypted Paperless token for the current user.
     *
     * @return string|false|null
     *   - string: the plaintext token
     *   - null:   no token stored
     *   - false:  a token is stored but decryption failed (prompt re-entry)
     */
    private function get_token()
    {
        $rcmail = rcmail::get_instance();
        $stored = (string) $rcmail->config->get('paperless_token', '');

        if ($stored === '') {
            return null;
        }

        $plain = $rcmail->decrypt($stored);

        // decrypt() returns false (or empty) when the ciphertext cannot be read,
        // e.g. after a des_key rotation. Signal failure so the caller re-prompts.
        if ($plain === false || $plain === null || $plain === '') {
            return false;
        }

        return $plain;
    }

    // =====================================================================
    // Phase 3 — Attach injection (shared mechanism + spike + real action).
    //
    // The injection deliberately replicates the *tail* of the deployed core's
    // rcmail_action_mail_compose::save_attachment() rather than calling it:
    // that method's `path`-from-disk form is NON-FUNCTIONAL in this core (its
    // is_string($message) branch buffers in-memory data, and the null/$path
    // form silently no-ops). The attachment_upload hook is also unusable for a
    // server-downloaded file (filesystem_attachments::upload() calls
    // move_uploaded_file(), which only accepts a genuine HTTP upload). So we
    // drive the attachment_save HOOK directly with a temp-file `path`
    // (data => null) and mirror attachment_success()'s add2attachment_list
    // markup with TWO args (no iframe $uploadid placeholder). Verified against
    // /srv/eplamat/www/program/actions/mail/{compose.php,attachment_upload.php}.
    // =====================================================================

    /**
     * Inject a temp file into the open compose session as a native attachment.
     *
     * Drives the `attachment_save` storage hook with a PATH (never in-memory
     * data — the PDF is already streamed to disk), session-appends the returned
     * descriptor to `compose_data_<id>.attachments`, and emits the mirrored
     * 2-arg `add2attachment_list` so the row renders like a native upload.
     *
     * @param string $compose_id the compose group id (session key suffix)
     * @param string $path       path to the already-downloaded temp file
     * @param string $name       display filename, e.g. "<title>.pdf"
     * @param string $mimetype   forced mimetype, e.g. "application/pdf"
     * @return string|false the attachment id on success; false on hook failure
     */
    private function inject_attachment($compose_id, $path, $name, $mimetype)
    {
        $rcmail = rcmail::get_instance();

        if (!is_file($path) || filesize($path) <= 0) {
            @unlink($path);
            return false;
        }

        // Build the attachment descriptor exactly as compose.php does before the
        // attachment_save hook — but with `path` set and `data` null (no buffer).
        $att = [
            'group'      => $compose_id,
            'name'       => $name,
            'mimetype'   => $mimetype,
            'content_id' => null,
            'data'       => null,
            'path'       => $path,
            'size'       => filesize($path),
            'charset'    => null,
        ];

        $att = $rcmail->plugins->exec_hook('attachment_save', $att);

        if (empty($att['status']) || !empty($att['abort'])) {
            // Storage backend rejected it — clean up the temp file, don't crash.
            @unlink($path);
            return false;
        }

        // Mirror compose.php: strip transient keys before persisting the row.
        unset($att['data'], $att['status'], $att['content_id'], $att['abort']);

        // Persist the attachment via rcube_session::append() — the EXACT mechanism
        // core uses for native uploads (attachment_upload.php:132 / compose.php:1735).
        //
        // CRITICAL: append() calls reload() when the request is older than 0.5s
        // (rcube_session.php:354). Our request always is — we just streamed a PDF
        // from Paperless. That mid-request reload() rebuilds $_SESSION via
        // session_decode + array_merge_recursive, which stores the attachment in a
        // way that, at send time, lands in the $_SESSION superglobal but NOT in
        // send.php's `$COMPOSE =& $_SESSION['compose_data_<id>']` reference — so
        // add_attachments() never sees it and the file is missing from the sent mail
        // (verified: native fast uploads, which never reload, attach fine; ours did
        // not). Native uploads win only because they finish in <0.5s and skip reload.
        //
        // Mark this as a Paperless-injected attachment so the message_ready hook
        // (attach_at_send) can re-attach it to the outgoing message at send time.
        // This is the load-bearing path: a slow attach request (PDF download) leaves
        // the attachment in $_SESSION but NOT in send.php's `$COMPOSE =& $_SESSION[...]`
        // reference, so core's add_attachments() never attaches it. We add it
        // ourselves at message_ready, reading from the session entry that IS present.
        $att['paperless'] = true;

        $rcmail->session->append('compose_data_' . $compose_id . '.attachments', $att['id'], $att);

        $this->emit_attachment_row($att);

        return $att['id'];
    }

    /**
     * Emit the client `add2attachment_list` command for an attachment row.
     *
     * Mirrors the deployed core's attachment_success() markup EXACTLY (rcmfile<id>
     * element id, load-attachment / remove-attachment commands, attachment-name /
     * attachment-size spans, html::a + rcube::Q escaping) — but with TWO args
     * (no $uploadid 3rd arg), because this is a plain rcmail.http_post, not the
     * upload iframe, so there is no placeholder row to replace.
     *
     * @param array $att attachment descriptor with id, name, mimetype, size
     */
    private function emit_attachment_row(array $att)
    {
        $rcmail = rcmail::get_instance();
        $id     = $att['id'];

        $link_content = sprintf(
            '<span class="attachment-name">%s</span><span class="attachment-size">(%s)</span>',
            rcube::Q($att['name']),
            rcmail_action_mail_compose::show_bytes((int) $att['size'])
        );

        $content_link = html::a([
            'href'    => '#load',
            'class'   => 'filename',
            'onclick' => sprintf(
                "return %s.command('load-attachment','rcmfile%s', this, event)",
                rcmail_output::JS_OBJECT_NAME,
                $id
            ),
        ], $link_content);

        $delete_link = html::a([
            'href'       => '#delete',
            'onclick'    => sprintf(
                "return %s.command('remove-attachment','rcmfile%s', this, event)",
                rcmail_output::JS_OBJECT_NAME,
                $id
            ),
            'title'      => $rcmail->gettext('delete'),
            'class'      => 'delete',
            'aria-label' => $rcmail->gettext('delete') . ' ' . $att['name'],
        ], '');

        $content = $content_link . $delete_link;

        $rcmail->output->command('add2attachment_list', "rcmfile$id", [
            'html'      => $content,
            'name'      => $att['name'],
            'mimetype'  => $att['mimetype'],
            'classname' => rcube_utils::file2class($att['mimetype'], $att['name']),
            'complete'  => true,
        ]);
    }

    /**
     * Resolve + validate the compose id from the AJAX request against the
     * session. A foreign/stale id is rejected — this pins attachments to the
     * CORRECT compose tab (two concurrent tabs each have their own id).
     *
     * @return string|null the validated compose id, or null (after no envelope)
     */
    private function resolve_compose_id()
    {
        $cid = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);

        if (empty($cid) || empty($_SESSION['compose_data_' . $cid])) {
            return null;
        }

        return $cid;
    }

    /**
     * plugin.paperless.spike_attach — flag-gated de-risking spike (NEVER ships).
     *
     * Generates a minimal valid one-page PDF to a temp file and injects it via
     * inject_attachment() — the exact mechanism the real attach loop uses. Its
     * only purpose is to prove the row renders + the mail sends, BEFORE any
     * Paperless wiring exists. This is the one place where holding bytes in a
     * PHP string is acceptable, because the dummy is tiny and throwaway.
     */
    public function action_spike_attach()
    {
        $rcmail = rcmail::get_instance();

        $cid = $this->resolve_compose_id();
        if ($cid === null) {
            $rcmail->output->command('display_message', 'spike: no compose session', 'error');
            $rcmail->output->send();
            return;
        }

        $tmp = rcube_utils::temp_filename('attmnt');
        file_put_contents($tmp, $this->dummy_pdf_bytes());

        $ok = $this->inject_attachment($cid, $tmp, 'spike.pdf', 'application/pdf');

        $rcmail->output->command(
            'display_message',
            $ok !== false ? 'spike attached' : 'spike failed',
            $ok !== false ? 'confirmation' : 'error'
        );
        $rcmail->output->send();
    }

    /**
     * Minimal valid one-page PDF (throwaway, for the spike only).
     *
     * @return string raw PDF bytes
     */
    private function dummy_pdf_bytes(): string
    {
        return "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] "
            . "/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n"
            . "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
            . "5 0 obj\n<< /Length 58 >>\nstream\n"
            . "BT /F1 18 Tf 20 100 Td (Paperless spike PDF) Tj ET\n"
            . "endstream\nendobj\n"
            . "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n"
            . "0000000058 00000 n \n0000000115 00000 n \n0000000241 00000 n \n"
            . "0000000316 00000 n \ntrailer\n<< /Size 6 /Root 1 0 R >>\n"
            . "startxref\n426\n%%EOF\n";
    }

    /**
     * plugin.paperless.attach — download selected documents server-side and
     * inject each as a native compose attachment (ATTACH-01, ATTACH-02).
     *
     * Reads `_id` (compose id) + `selected_ids[]` from the POST, validates the
     * compose id against the session (correct-tab pinning), then loops per id:
     * integer-validate → stream the archive PDF to a temp file → re-fetch the
     * title server-side (the browser title is untrusted) → sanitize "<title>.pdf"
     * → inject via the shared mechanism. The token + base URL are resolved and
     * used server-side ONLY; responses carry just attachment rows + a status
     * message. Basic non-crashing handling only — polished oversize / per-item
     * reporting is Phase 4.
     */
    public function action_attach()
    {
        $rcmail = rcmail::get_instance();

        $cid = $this->resolve_compose_id();
        if ($cid === null) {
            // Invalid/foreign compose session — non-crashing, generic error.
            $this->send_attach_result(['attached' => 0, 'total' => 0, 'items' => [], 'error' => 'bad_request']);
            return;
        }

        $ids = rcube_utils::get_input_value('selected_ids', rcube_utils::INPUT_POST);
        if (!is_array($ids) || count($ids) === 0) {
            $this->send_attach_result(['attached' => 0, 'total' => 0, 'items' => [], 'error' => 'bad_request']);
            return;
        }

        $token = $this->get_token();
        if ($token === null || $token === false) {
            // No / undecryptable token — surface the no-token category so the JS can
            // link to Settings; NEVER send a broken Authorization header upstream.
            $this->send_attach_result(['attached' => 0, 'total' => count($ids), 'items' => [], 'error' => 'no_token']);
            return;
        }

        require_once __DIR__ . '/lib/PaperlessClient.php';

        $base_url = (string) $rcmail->config->get('paperless_url', self::DEFAULT_BASE_URL);
        $client   = new PaperlessClient($base_url, $token);

        $limit       = $this->effective_upload_limit();
        $limit_human = $this->human_size($limit);

        // Per-compose duplicate registry: ids already attached THIS compose session.
        $attachedIds = (array) ($_SESSION['compose_data_' . $cid]['paperless_attached_ids'] ?? []);

        $items    = [];
        $attached = 0;

        foreach ($ids as $raw) {
            // 1) Integer-validate the id (SSRF / typing guard).
            try {
                $docId = $client->validateId($raw);
            }
            catch (\Throwable $e) {
                $items[] = ['id' => 0, 'title' => '', 'status' => 'error'];
                continue;
            }

            // 2) Duplicate guard — already attached this compose session.
            if (in_array($docId, $attachedIds, true)) {
                $items[] = ['id' => $docId, 'title' => '', 'status' => 'duplicate'];
                continue;
            }

            // 3) Pre-download metadata: title + archive presence. The Paperless
            //    API exposes NO size on the document, so there is no pre-download
            //    oversize check — oversize is enforced post-download below from
            //    the actual streamed file size.
            try {
                $info = $client->getDocumentInfo($docId);
            }
            catch (\Throwable $e) {
                $info = null;
            }

            if ($info === null) {
                $items[] = ['id' => $docId, 'title' => '', 'status' => 'error'];
                continue;
            }

            $title = (string) $info['title'];

            // 4) No-archive (born-digital) — skip; never attach the original (v1).
            if (empty($info['has_archive'])) {
                $items[] = ['id' => $docId, 'title' => $title, 'status' => 'no_archive'];
                continue;
            }

            // 5) Download (stream to temp file) + inject.
            $tmp = rcube_utils::temp_filename('attmnt');

            try {
                $ok = $client->download($docId, $tmp);
            }
            catch (\Throwable $e) {
                $ok = false;
            }

            if (!$ok) {
                @unlink($tmp);
                $items[] = ['id' => $docId, 'title' => $title, 'status' => 'error'];
                continue;
            }

            // Authoritative oversize guard: the API gives us no size up front, so
            // we enforce the effective upload limit on the downloaded bytes —
            // never attach an oversize file.
            $actual = (int) @filesize($tmp);
            if ($actual > $limit) {
                @unlink($tmp);
                $items[] = [
                    'id'     => $docId,
                    'title'  => $title,
                    'status' => 'oversize',
                    'detail' => ['size_human' => $this->human_size($actual), 'limit_human' => $limit_human],
                ];
                continue;
            }

            $name = $this->sanitize_pdf_name($title);

            if ($this->inject_attachment($cid, $tmp, $name, 'application/pdf') !== false) {
                $items[]       = ['id' => $docId, 'title' => $title, 'status' => 'ok'];
                $attachedIds[] = $docId;
                $attached++;
            }
            else {
                $items[] = ['id' => $docId, 'title' => $title, 'status' => 'error'];
            }
        }

        // Persist the duplicate registry for this compose session.
        $_SESSION['compose_data_' . $cid]['paperless_attached_ids'] = array_values(array_unique($attachedIds));

        $this->send_attach_result([
            'attached'    => $attached,
            'total'       => count($ids),
            'items'       => $items,
            'limit_human' => $limit_human,
        ]);
    }

    /**
     * message_ready hook — fires in send.php (line ~240) with the fully assembled
     * outgoing Mail_mime, AFTER core's add_attachments(). Re-attaches every
     * Paperless-injected document for this compose to the message.
     *
     * Why this is necessary: when a Paperless attachment is injected during a
     * compose request that runs longer than 0.5s (it must stream the PDF from
     * Paperless), rcube_session::reload() rebuilds $_SESSION mid-flight. At send
     * time the attachment is present in the $_SESSION superglobal but NOT in
     * send.php's `$COMPOSE =& $_SESSION['compose_data_<id>']` reference, so core's
     * add_attachments() never adds it (verified: native fast uploads attach fine,
     * Paperless ones did not). We read the still-present session entry here and
     * add the file ourselves, deduping by filename against parts core already added.
     *
     * @param array $args ['message' => Mail_mime]
     * @return array
     */
    public function attach_at_send($args)
    {
        $message = $args['message'] ?? null;
        if (!is_object($message) || !method_exists($message, 'addAttachment')) {
            return $args;
        }

        $cid = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        if ($cid === '' || empty($_SESSION['compose_data_' . $cid]['attachments'])
            || !is_array($_SESSION['compose_data_' . $cid]['attachments'])) {
            return $args;
        }

        // Names already present on the message (core may have attached some, e.g.
        // native uploads in the same compose) — skip those to avoid duplicates.
        $existing = [];
        try {
            $ref = new \ReflectionObject($message);
            if ($ref->hasProperty('parts')) {
                $p = $ref->getProperty('parts');
                $p->setAccessible(true);
                foreach ((array) $p->getValue($message) as $part) {
                    if (is_array($part) && isset($part['name'])) {
                        $existing[(string) $part['name']] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // best-effort dedup only
        }

        $added = 0;
        foreach ($_SESSION['compose_data_' . $cid]['attachments'] as $att) {
            if (empty($att['paperless']) || empty($att['path'])) {
                continue;
            }
            $name = (string) ($att['name'] ?? '');
            if ($name !== '' && isset($existing[$name])) {
                continue; // already attached by core — don't duplicate
            }
            if (!is_file($att['path'])) {
                rcube::write_log('paperless_attach', sprintf(
                    'attach_at_send: temp file missing for "%s" (%s)', $name, $att['path']
                ));
                continue;
            }

            $message->addAttachment(
                $att['path'],
                $att['mimetype'] ?? 'application/pdf',
                $name,
                true,            // isfile
                'base64',
                'attachment',
                $att['charset'] ?? null
            );
            $existing[$name] = true;
            $added++;
        }

        if ($added > 0) {
            rcube::write_log('paperless_attach', sprintf(
                'attach_at_send: added %d Paperless attachment(s) to outgoing message (cid=%s)',
                $added, $cid
            ));
        }

        return $args;
    }

    /**
     * Push the structured per-item attach result to the client and end the request.
     *
     * The payload carries ONLY {attached, total, items[{id,title,status,detail?}],
     * limit_human, error?} — never the token, base URL, or raw upstream bodies.
     *
     * @param array $data
     */
    private function send_attach_result(array $data)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.paperless.attach_result', $data);
        $rcmail->output->send();
    }

    /**
     * Sanitize a Paperless document title into a safe "<title>.pdf" filename:
     * strip path separators + control chars, collapse blanks, fall back to
     * "document", cap the length. XSS on render is handled separately by
     * rcube::Q() in emit_attachment_row(); this guards filesystem/mail safety.
     *
     * @param string $title raw document title
     * @return string sanitized "<name>.pdf"
     */
    private function sanitize_pdf_name(string $title): string
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
}
