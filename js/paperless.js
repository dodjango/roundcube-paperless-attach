/**
 * Paperless Attach — client glue.
 *
 *  - Settings page: wires the "Test connection" button (Phase 1).
 *  - Compose page (Phase 2): registers the "Aus Paperless anhängen" toolbar
 *    command, opens the Elastic picker dialog ON CLICK (never on page load),
 *    runs explicit-trigger full-text + filter search via the server-side proxy,
 *    renders thumbnailed result rows, paginates with "Mehr laden", and lets the
 *    user multi-select documents across pages.
 *
 * No token or Paperless URL ever leaves the browser: every Paperless call is
 * proxied through plugin.paperless.* actions, and thumbnails are streamed via
 * plugin.paperless.thumb (never a direct browser->Paperless <img src>).
 *
 * PHASE 2 SCOPE: the footer "N anhängen" button is a NO-OP stub. The actual
 * attach injection (download bytes + save_attachment, threaded by compose_id)
 * is Phase 3 — see the marked insertion point in onAttachClick().
 */
/* global rcmail, $ */

if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        paperlessInitSettings();
        paperlessInitCompose();
    });
}

// =========================================================================
// Settings page (Phase 1) — Test connection button.
// =========================================================================
function paperlessInitSettings() {
    var $btn = $('#paperless-test-btn');
    if (!$btn.length) {
        return;
    }

    $btn.on('click', function (e) {
        e.preventDefault();

        var $result = $('#paperless-test-result');
        $result
            .removeClass('paperless-ok paperless-fail')
            .text(rcmail.get_label('testconnection', 'paperless_attach') + '…');

        $btn.prop('disabled', true);

        // Empty payload: the server reads the stored (encrypted) token itself.
        rcmail.http_post('plugin.paperless.test_connection', {}, true);
    });

    rcmail.addEventListener('plugin.paperless.test_result', function (data) {
        data = data || {};

        var $result = $('#paperless-test-result');
        $result
            .removeClass('paperless-ok paperless-fail')
            .addClass(data.ok ? 'paperless-ok' : 'paperless-fail')
            .text(data.message || '');

        $('#paperless-test-btn').prop('disabled', false);
    });
}

// =========================================================================
// Compose page (Phase 2) — Paperless picker dialog.
// =========================================================================

// Module-scoped dialog session state. Reset every time the dialog opens.
var paperlessState = null;

function paperlessNewState() {
    return {
        composeId:    null,   // captured rcmail.env.compose_id (read-only this phase)
        $dialog:      null,   // jQuery popup element
        page:         1,      // current result page
        hasMore:      false,  // DRF `next` present?
        count:        0,      // total Paperless `count`
        loadedCount:  0,      // rows rendered so far
        selected:     {},     // selection set keyed by document id -> {id, title}
        attachedIds:  {},     // ids confirmed attached THIS dialog session (client duplicate guard)
        lastCriteria: null,   // last search criteria (for retry)
        filtersLoaded: false, // lazy-load guard for dropdown option lists
        filterCache:  {       // cached, fully-paginated option lists per session
            tags:           null,
            correspondents: null,
            doctypes:       null
        },
        currentAction: null,  // 'search' | 'load_more' — what to retry on error
        attaching:     false, // in-flight guard: true while an attach POST is pending
        attachWatchdog: null  // setTimeout id that clears a stuck guard on HTTP error
    };
}

// Registered ONCE (guard against re-registration across repeated dialog opens):
// the response listener that clears the attach in-flight guard.
var paperlessAttachListenerBound = false;

function paperlessInitCompose() {
    // Only wire up where a compose toolbar exists.
    if (rcmail.env.task !== 'mail') {
        return;
    }

    // Enable the command while a compose is active so the toolbar button is live.
    rcmail.register_command('plugin.paperless.open', paperlessOpenDialog, true);

    // Register the command as a compose command (mirror vcardattach.js). Without
    // this, invoking it goes through the generic command path and Elastic treats
    // it as a navigation that triggers the "discard unsaved changes" prompt.
    if (rcmail.env.compose_commands) {
        rcmail.env.compose_commands.push('plugin.paperless.open');
    }

    // Elastic: place the "Aus Paperless anhängen" button INSIDE the compose
    // attachments widget ("Optionen und Anhänge"), next to the native Datei /
    // vCard buttons — NOT in the top toolbar. Mirrors vcard_attachments. Guarded
    // on the widget's presence so it only runs on the compose screen.
    if (rcmail.env.action === 'compose') {
        var $form = $('#compose-attachments > div');
        if ($form.length) {
            var $btn = $('<button class="btn btn-secondary attach paperless-attach" type="button">')
                .attr({
                    tabindex: $('button,input', $form).first().attr('tabindex') || 0
                })
                .text(rcmail.gettext('attach_from_paperless', 'paperless_attach'))
                .appendTo($form)
                .on('click', function () {
                    rcmail.command('plugin.paperless.open');
                });
        }
    }

    // Single response channel for all proxy actions (search + lookups).
    rcmail.addEventListener('plugin.paperless.response', paperlessOnResponse);

    // Clear the attach in-flight guard once the attach POST completes (success
    // OR failure). Registered exactly once so repeated dialog opens don't stack
    // duplicate listeners. The server-emitted add2attachment_list rows are
    // handled by core; here we only re-enable the button + close the dialog.
    if (!paperlessAttachListenerBound) {
        rcmail.addEventListener('responseafterplugin.paperless.attach', paperlessOnAttachResponse);
        // Structured per-item batch result (Phase 4) — replaces the single message.
        rcmail.addEventListener('plugin.paperless.attach_result', paperlessOnAttachResult);
        paperlessAttachListenerBound = true;
    }
}

// -------------------------------------------------------------------------
// Phase 4 — consume the structured attach_result and render a per-item summary.
//
// data = { attached, total, items:[{id,title,status,detail?}], limit_human, error? }
// status ∈ ok | oversize | no_archive | duplicate | error
// -------------------------------------------------------------------------
function paperlessOnAttachResult(data) {
    data = data || {};
    if (!paperlessState || !paperlessState.$dialog) {
        return;
    }

    var t = function (k) { return rcmail.gettext(k, 'paperless_attach'); };
    var $dialog = paperlessState.$dialog;
    var $b = $dialog.find('.paperless-region-b');

    // Whole-batch error envelopes.
    if (data.error === 'no_token') {
        var $warn = $('<div class="alert alert-warning paperless-attach-summary" role="alert">');
        $warn.append($('<p>').text(t('attach_no_token')));
        var $link = $('<a href="#" class="paperless-settings-link">').text(t('attach_no_token_link'));
        $link.on('click', function (e) {
            e.preventDefault();
            rcmail.goto_url('settings/edit-prefs', '_section=paperless', false);
        });
        $warn.append($link);
        $b.find('.paperless-attach-summary').remove();
        $b.prepend($warn);
        return;
    }
    if (data.error === 'bad_request') {
        rcmail.display_message(t('attacherror'), 'error');
        return;
    }

    var items = data.items || [];

    // Record the freshly-attached ids into the client duplicate guard, and
    // un-check + disable those rows in the visible list.
    $.each(items, function (i, item) {
        if (item.status === 'ok') {
            paperlessState.attachedIds[item.id] = true;
            delete paperlessState.selected[item.id];
            var $row = $b.find('.paperless-result[data-id="' + item.id + '"]');
            $row.removeClass('paperless-selected').addClass('paperless-attached-already');
            $row.find('.paperless-row-cb').prop('checked', false).prop('disabled', true);
        }
    });
    setSelectionCount(paperlessSelectionCount());

    // Build the summary: headline + a list of the NON-ok items (reason per status).
    var $summary = $('<div class="paperless-attach-summary" role="status">');
    $summary.append($('<p class="paperless-attach-summary-head">')
        .text(paperlessFormat(t('attach_summary'), [data.attached || 0, data.total || 0])));

    var failed = $.grep(items, function (it) { return it.status !== 'ok'; });
    if (failed.length) {
        var $ul = $('<ul class="paperless-attach-summary-list">');
        $.each(failed, function (i, item) {
            var title = item.title || '';
            var line;
            if (item.status === 'oversize') {
                var d = item.detail || {};
                line = paperlessFormat(t('attach_too_large'),
                    [title, d.size_human || '', d.limit_human || (data.limit_human || '')]);
            } else if (item.status === 'no_archive') {
                line = paperlessFormat(t('attach_no_archive'), [title]);
            } else if (item.status === 'duplicate') {
                line = paperlessFormat(t('attach_duplicate'), [title]);
            } else {
                line = paperlessFormat(t('attach_item_error'), [title]);
            }
            // .text() escapes the (untrusted) title — never .html().
            $ul.append($('<li>').text(line));
        });
        $summary.append($ul);
    }

    $b.find('.paperless-attach-summary').remove();
    $b.prepend($summary);

    // Auto-close ONLY on full success so failures stay visible for the user.
    var fullSuccess = (data.total || 0) > 0 && (data.attached || 0) === (data.total || 0) && !failed.length;
    if (fullSuccess) {
        try {
            $dialog.closest('.ui-dialog-content').dialog('close');
        } catch (e) {
            // Dialog already torn down.
        }
    }
}

// Re-enable the attach button + clear the spinner once the attach request
// settles; on a (non-error) completion close the dialog so the new native-looking
// attachment rows are visible in the compose. Non-crashing if the dialog is gone.
function paperlessOnAttachResponse() {
    if (!paperlessState) {
        return;
    }

    // Cancel the failure watchdog — a real response arrived.
    if (paperlessState.attachWatchdog) {
        clearTimeout(paperlessState.attachWatchdog);
        paperlessState.attachWatchdog = null;
    }

    paperlessState.attaching = false;

    var $dialog = paperlessState.$dialog;
    if ($dialog) {
        var $btn = $dialog.find('.paperless-attach-btn');
        $btn.prop('disabled', false)
            .removeClass('paperless-attaching')
            .text(paperlessAttachLabel(paperlessSelectionCount()));

        // NOTE: closing is now driven by paperlessOnAttachResult (Phase 4) — it
        // auto-closes ONLY on full success so the per-item summary (partial
        // failures) stays visible. We only re-enable the button here.
    }
}

// -------------------------------------------------------------------------
// Dialog open — ON CLICK ONLY (never on page load; avoids the Elastic
// off-screen-on-load bug).
// -------------------------------------------------------------------------
function paperlessOpenDialog() {
    paperlessState = paperlessNewState();

    // Capture the active compose group so Phase 3 can thread it. Read-only here.
    paperlessState.composeId = rcmail.env.compose_id || null;

    var $content = paperlessBuildDialog();

    // Empty buttons array → suppress the generic jQuery-UI buttonpane; our own
    // footer (count + attach + cancel) lives inside the dialog body (UI-SPEC §7).
    var $dialog = rcmail.show_popup_dialog(
        $content,
        rcmail.gettext('attach_from_paperless', 'paperless_attach'),
        [],
        {
            width:   Math.min(720, $(window).width() - 20),
            height:  Math.min(640, Math.round($(window).height() * 0.8)),
            classes: { 'ui-dialog': 'paperless-dialog' },
            dialogClass: 'paperless-dialog'
        }
    );

    paperlessState.$dialog = $content;

    // Bind controls now that the markup is in the DOM.
    paperlessBindDialog($content);

    // Render the initial idle state, then focus the search box.
    paperlessRenderState('idle');
    setTimeout(function () { $content.find('.paperless-search-input').trigger('focus'); }, 50);
}

// -------------------------------------------------------------------------
// Dialog markup (UI-SPEC §3-§7). Region A (search + filters), Region B
// (scrolling result list / state machine), Region C (fixed footer).
// -------------------------------------------------------------------------
function paperlessBuildDialog() {
    var t = function (k) { return rcmail.gettext(k, 'paperless_attach'); };

    var $root = $('<div class="paperless-picker">');

    // --- Region A: search + collapsible filters ---
    var $regionA = $('<div class="paperless-region-a">');

    var $searchRow = $(
        '<div class="paperless-searchbox">' +
        '  <span class="paperless-search-icon" aria-hidden="true"></span>' +
        '  <input type="text" class="paperless-search-input" />' +
        '  <button type="button" class="button mainaction paperless-search-btn"></button>' +
        '</div>'
    );
    $searchRow.find('.paperless-search-input')
        .attr('placeholder', t('search_placeholder'))
        .attr('aria-label', t('search_placeholder'));
    $searchRow.find('.paperless-search-btn').text(t('btn_search'));
    $regionA.append($searchRow);

    // Collapsible filter disclosure (collapsed by default).
    var $filterToggle = $(
        '<button type="button" class="paperless-filter-toggle" aria-expanded="false" ' +
        'aria-controls="paperless-filter-row">' +
        '  <span class="paperless-chevron" aria-hidden="true"></span>' +
        '  <span class="paperless-filter-toggle-label"></span>' +
        '</button>'
    );
    $filterToggle.find('.paperless-filter-toggle-label').text(t('filters'));
    $regionA.append($filterToggle);

    var $filterRow = $(
        '<div class="paperless-filter-row" id="paperless-filter-row" hidden>' +
        '  <label class="paperless-filter paperless-filter-tags">' +
        '    <span class="paperless-filter-label"></span>' +
        '    <select multiple class="paperless-f-tags"></select>' +
        '  </label>' +
        '  <label class="paperless-filter paperless-filter-corr">' +
        '    <span class="paperless-filter-label"></span>' +
        '    <select class="paperless-f-corr"><option value=""></option></select>' +
        '  </label>' +
        '  <label class="paperless-filter paperless-filter-doctype">' +
        '    <span class="paperless-filter-label"></span>' +
        '    <select class="paperless-f-doctype"><option value=""></option></select>' +
        '  </label>' +
        '  <label class="paperless-filter paperless-filter-from">' +
        '    <span class="paperless-filter-label"></span>' +
        '    <input type="date" class="paperless-f-from" />' +
        '  </label>' +
        '  <label class="paperless-filter paperless-filter-to">' +
        '    <span class="paperless-filter-label"></span>' +
        '    <input type="date" class="paperless-f-to" />' +
        '  </label>' +
        '  <span class="paperless-filter paperless-filter-reset-col">' +
        '    <span class="paperless-filter-label" aria-hidden="true">&nbsp;</span>' +
        '    <button type="button" class="button paperless-filter-reset"></button>' +
        '  </span>' +
        '</div>'
    );
    $filterRow.find('.paperless-filter-tags .paperless-filter-label')
        .text(t('filter_tags'))
        .append($('<span class="paperless-filter-count paperless-f-tags-count" aria-live="polite"></span>'));
    $filterRow.find('.paperless-filter-corr .paperless-filter-label').text(t('filter_correspondent'));
    $filterRow.find('.paperless-filter-doctype .paperless-filter-label').text(t('filter_doctype'));
    $filterRow.find('.paperless-filter-from .paperless-filter-label').text(t('filter_date_from'));
    $filterRow.find('.paperless-filter-to .paperless-filter-label').text(t('filter_date_to'));
    $filterRow.find('.paperless-filter-reset').text(t('filter_reset'));
    $regionA.append($filterRow);

    $root.append($regionA);

    // --- Region B: scrolling result list / state machine host ---
    var $regionB = $('<div class="paperless-region-b" role="region" aria-live="polite"></div>');
    $root.append($regionB);

    // --- Region C: fixed footer ---
    var $regionC = $('<div class="paperless-region-c">');
    $regionC.append(
        $('<span class="paperless-count" aria-live="polite"></span>')
            .text(paperlessSelectedLabel(0))
    );
    var $attachBtn = $('<button type="button" class="button mainaction paperless-attach-btn" disabled></button>')
        .text(paperlessAttachLabel(0));
    var $cancelBtn = $('<button type="button" class="button paperless-cancel-btn"></button>')
        .text(t('btn_cancel'));
    $regionC.append($('<span class="paperless-footer-actions">').append($attachBtn).append($cancelBtn));
    $root.append($regionC);

    return $root;
}

function paperlessBindDialog($root) {
    // Search triggers: button click + Enter in the search field. NO debounce.
    $root.find('.paperless-search-btn').on('click', paperlessRunSearch);
    $root.find('.paperless-search-input').on('keydown', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            paperlessRunSearch();
        }
    });

    // Tags multi-select: a plain click toggles each option (no Ctrl needed)
    // and — crucially — allows deselecting the LAST remaining tag down to zero,
    // which the native <select multiple> single-click behavior otherwise blocks.
    $root.find('.paperless-f-tags').on('mousedown', 'option', function (e) {
        e.preventDefault();
        var sel = this.parentNode;
        var keep = sel ? sel.scrollTop : 0;
        this.selected = !this.selected;
        if (sel) {
            var $sel = $(sel);
            // Two native <select multiple> quirks scroll the list away from the
            // click: (1) mutating option.selected asynchronously scrolls the
            // FIRST selected option into view a few frames later (independent of
            // focus); (2) holding the button and dragging over options
            // auto-scrolls (native range-select). Both are unwanted here — we
            // toggle on click, not drag-select. Pin the scroll and revert those
            // auto-scrolls until the button is released (covers the drag) plus a
            // short tail (covers the async post-toggle scroll). Release early on
            // a real wheel/keydown so a deliberate scroll is never fought.
            // Only engaged for option presses — a scrollbar press targets the
            // <select>, not an <option>, so the scrollbar still scrolls normally.
            var release = function () {
                $sel.off('.ppin');
                $(document).off('.ppin');
            };
            release();
            $sel.on('scroll.ppin', function () {
                if (Math.abs(sel.scrollTop - keep) > 1) {
                    sel.scrollTop = keep;
                }
            });
            $sel.on('wheel.ppin keydown.ppin', release);
            $(document).on('mouseup.ppin', function () {
                $(document).off('mouseup.ppin');
                window.setTimeout(release, 300);
            });
            sel.focus({ preventScroll: true });
            sel.scrollTop = keep;
            $sel.trigger('change');
        }
        return false;
    });

    // Live counter beside the Tags label. The toggle handler above fires
    // 'change', as do native keyboard/Ctrl-click selections, so a single
    // change listener keeps the count current for every selection path.
    $root.find('.paperless-f-tags').on('change', function () {
        paperlessUpdateTagsCount($root);
    });

    // Filter disclosure: toggle + lazy-load option lists on first expand.
    $root.find('.paperless-filter-toggle').on('click', function () {
        var $btn = $(this);
        var expanded = $btn.attr('aria-expanded') === 'true';
        $btn.attr('aria-expanded', expanded ? 'false' : 'true');
        $root.find('#paperless-filter-row').prop('hidden', expanded);
        $root.toggleClass('paperless-filters-open', !expanded);
        if (!expanded) {
            paperlessLoadFilters();
        }
    });

    // Filter reset — does NOT auto-search (UI-SPEC §4.2).
    $root.find('.paperless-filter-reset').on('click', function () {
        $root.find('.paperless-f-tags option:selected').prop('selected', false);
        $root.find('.paperless-f-corr, .paperless-f-doctype').val('');
        $root.find('.paperless-f-from, .paperless-f-to').val('');
        // Deselecting options programmatically does not fire 'change'.
        paperlessUpdateTagsCount($root);
    });

    // Footer buttons.
    $root.find('.paperless-attach-btn').on('click', paperlessOnAttachClick);
    $root.find('.paperless-cancel-btn').on('click', function () {
        $root.closest('.ui-dialog-content').dialog('close');
    });
}

// -------------------------------------------------------------------------
// Lazy + cached filter dropdowns (UI-SPEC §4.2; CONTEXT decision).
// -------------------------------------------------------------------------
function paperlessLoadFilters() {
    if (paperlessState.filtersLoaded) {
        return; // cached for the dialog session — do not refetch.
    }
    paperlessState.filtersLoaded = true;

    paperlessLoadOneFilter('tags', 'plugin.paperless.tags', '.paperless-f-tags');
    paperlessLoadOneFilter('correspondents', 'plugin.paperless.correspondents', '.paperless-f-corr');
    paperlessLoadOneFilter('doctypes', 'plugin.paperless.doctypes', '.paperless-f-doctype');
}

function paperlessLoadOneFilter(cacheKey, action, selector) {
    var $sel = paperlessState.$dialog.find(selector);

    // Serve from cache if a prior expand already populated it.
    if (paperlessState.filterCache[cacheKey]) {
        paperlessFillSelect($sel, paperlessState.filterCache[cacheKey]);
        return;
    }

    $sel.prop('disabled', true).addClass('paperless-loading');

    // Each lookup is its own round-trip; the response handler routes by action.
    rcmail.http_post(action, {}, false);
}

function paperlessFillSelect($sel, items) {
    var multiple = $sel.prop('multiple');
    if (!multiple) {
        // keep the leading empty option
        $sel.find('option:not(:first-child)').remove();
    } else {
        $sel.empty();
    }
    $.each(items, function (i, it) {
        $sel.append($('<option>').val(it.id).text(it.name));
    });
    $sel.prop('disabled', false).removeClass('paperless-loading');

    // (Re)populating the tags select clears any prior selection — sync the
    // counter so it reads zero (label "Tags") rather than a stale value.
    if ($sel.hasClass('paperless-f-tags')) {
        paperlessUpdateTagsCount();
    }
}

// -------------------------------------------------------------------------
// Search (explicit trigger; query + all filters in ONE request).
// -------------------------------------------------------------------------
function paperlessCollectCriteria(page) {
    var $r = paperlessState.$dialog;
    var tags = $r.find('.paperless-f-tags').val() || [];
    return {
        query:         $.trim($r.find('.paperless-search-input').val() || ''),
        tags:          tags,
        correspondent: $r.find('.paperless-f-corr').val() || '',
        document_type: $r.find('.paperless-f-doctype').val() || '',
        date_from:     $r.find('.paperless-f-from').val() || '',
        date_to:       $r.find('.paperless-f-to').val() || '',
        page:          page || 1
    };
}

function paperlessRunSearch() {
    // Ignore a second submit while a page-1 search is in flight.
    if (paperlessState.currentAction === 'search') {
        return;
    }
    var criteria = paperlessCollectCriteria(1);
    paperlessState.lastCriteria = criteria;
    paperlessState.page = 1;
    paperlessState.currentAction = 'search';
    paperlessRenderState('loading');
    rcmail.http_post('plugin.paperless.search', criteria, false);
}

function paperlessLoadMore() {
    if (!paperlessState.hasMore || paperlessState.currentAction) {
        return;
    }
    var nextPage = paperlessState.page + 1;
    var criteria = paperlessCollectCriteria(nextPage);
    // Reuse the committed criteria; only the page advances.
    criteria.query = paperlessState.lastCriteria.query;
    criteria.tags = paperlessState.lastCriteria.tags;
    criteria.correspondent = paperlessState.lastCriteria.correspondent;
    criteria.document_type = paperlessState.lastCriteria.document_type;
    criteria.date_from = paperlessState.lastCriteria.date_from;
    criteria.date_to = paperlessState.lastCriteria.date_to;
    criteria.page = nextPage;
    paperlessState.currentAction = 'load_more';

    var $more = paperlessState.$dialog.find('.paperless-load-more');
    $more.prop('disabled', true).addClass('paperless-loading');

    rcmail.http_post('plugin.paperless.search', criteria, false);
}

// -------------------------------------------------------------------------
// Unified response handler for all proxy actions.
// -------------------------------------------------------------------------
function paperlessOnResponse(data) {
    data = data || {};

    // Backend-driven no-token / error states.
    if (data.ok === false) {
        // A filter-lookup failure should not blow away results; only the
        // search/list flow drives the Region-B state machine.
        if (paperlessState.currentAction === 'search') {
            paperlessRenderState(data.error === 'no_token' ? 'no_token' : 'error');
        } else if (paperlessState.currentAction === 'load_more') {
            paperlessState.$dialog.find('.paperless-load-more')
                .prop('disabled', false).removeClass('paperless-loading');
        }
        paperlessState.currentAction = null;
        return;
    }

    // Filter lookup payloads (have `items`, no `results`). The server tags each
    // with its `lookup` kind so we route deterministically — never by guessing
    // which select is "still loading" (that raced when 3 lookups overlapped).
    if (data.items && typeof data.results === 'undefined') {
        paperlessRouteLookup(data.lookup, data.items);
        return;
    }

    // Search / load-more payload.
    paperlessRenderResults(data);
    paperlessState.currentAction = null;
}

// Route a lookup payload to the matching dropdown, keyed by the server-provided
// `lookup` kind. No more "first still-loading select" guessing: the 3 lookups
// share the plugin.paperless.response channel and can complete out of order, so
// correlating by kind is what keeps tags/correspondents/doctypes from crossing.
function paperlessRouteLookup(kind, items) {
    var map = {
        tags:           '.paperless-f-tags',
        correspondents: '.paperless-f-corr',
        doctypes:       '.paperless-f-doctype'
    };
    var selector = map[kind];
    if (!selector) {
        return; // unknown/absent kind — ignore rather than mis-route.
    }
    paperlessState.filterCache[kind] = items;
    paperlessFillSelect(paperlessState.$dialog.find(selector), items);
}

// -------------------------------------------------------------------------
// Region B — state machine. Exactly one state renders at a time.
// -------------------------------------------------------------------------
function paperlessRenderState(state) {
    var t = function (k) { return rcmail.gettext(k, 'paperless_attach'); };
    var $b = paperlessState.$dialog.find('.paperless-region-b');
    $b.empty().attr('data-state', state);

    if (state === 'idle') {
        $b.append($('<div class="paperless-placeholder">')
            .append($('<span class="paperless-placeholder-icon" aria-hidden="true">'))
            .append($('<p>').text(t('state_idle'))));
    }
    else if (state === 'loading') {
        $b.append($('<div class="paperless-placeholder paperless-loading-state">')
            .append($('<span class="paperless-spinner" aria-hidden="true">'))
            .append($('<p>').text(t('state_loading'))));
    }
    else if (state === 'no_results') {
        $b.append($('<div class="alert alert-info boxinformation" role="status">')
            .text(t('state_no_results')));
    }
    else if (state === 'no_token') {
        var $warn = $('<div class="alert alert-warning" role="alert">');
        $warn.append($('<p>').text(t('state_no_token')));
        var $link = $('<a href="#" class="paperless-settings-link">').text(t('state_no_token_settings_link'));
        $link.on('click', function (e) {
            e.preventDefault();
            // Navigate to Settings → Paperless section (Phase 1).
            rcmail.goto_url('settings/edit-prefs', '_section=paperless', false);
        });
        $warn.append($link);
        $b.append($warn);
        // Disable the search controls while no token is configured.
        paperlessState.$dialog.find('.paperless-search-input, .paperless-search-btn').prop('disabled', true);
    }
    else if (state === 'error') {
        var $err = $('<div class="alert alert-danger" role="alert">');
        $err.append($('<p>').text(t('state_error')));
        var $retry = $('<button type="button" class="button paperless-retry-btn">').text(t('btn_retry'));
        $retry.on('click', function () {
            // Re-issue the last action.
            if (paperlessState.lastCriteria) {
                paperlessRunSearch();
            }
        });
        $err.append($retry);
        $b.append($err);
    }
}

// -------------------------------------------------------------------------
// Render result rows (append on load-more; replace on a fresh search).
// -------------------------------------------------------------------------
function paperlessRenderResults(data) {
    var t = function (k) { return rcmail.gettext(k, 'paperless_attach'); };
    var $b = paperlessState.$dialog.find('.paperless-region-b');
    var append = data.page > 1;

    paperlessState.page    = data.page;
    paperlessState.hasMore = !!data.has_more;
    paperlessState.count   = data.count || 0;

    if (!append) {
        paperlessState.loadedCount = 0;
        if (!data.results || !data.results.length) {
            paperlessRenderState('no_results');
            return;
        }
        $b.empty().attr('data-state', 'results');

        // Result-count hint.
        $b.append($('<div class="paperless-result-count text-muted">')
            .text(paperlessResultCountLabel()));

        $b.append($('<ul class="paperless-result-list">'));
    }

    var $list = $b.find('.paperless-result-list');

    $.each(data.results, function (i, doc) {
        $list.append(paperlessBuildRow(doc));
        paperlessState.loadedCount++;
    });

    // Update count hint and Mehr-laden affordance.
    $b.find('.paperless-result-count').text(paperlessResultCountLabel());

    $b.find('.paperless-load-more-wrap').remove();
    if (paperlessState.hasMore) {
        var $wrap = $('<div class="paperless-load-more-wrap">');
        var $more = $('<button type="button" class="button paperless-load-more">').text(t('load_more'));
        $more.on('click', paperlessLoadMore);
        $wrap.append($more);
        $b.append($wrap);
    }
}

function paperlessBuildRow(doc) {
    var t = function (k) { return rcmail.gettext(k, 'paperless_attach'); };
    var rid = 'paperless-row-' + doc.id;
    var $row = $('<li class="paperless-result">').attr('data-id', doc.id);

    // First-line-of-defense disabling: already-attached (client duplicate guard).
    // The Paperless API exposes no document size, so there is NO client-side
    // oversize pre-check — oversize is enforced authoritatively server-side after
    // download (Plan 04-01) and reported per item in the attach summary. A
    // disabled row is not selectable.
    var already  = !!paperlessState.attachedIds[doc.id];
    var disabled = already;

    var checked = !disabled && !!paperlessState.selected[doc.id];
    if (checked) {
        $row.addClass('paperless-selected');
    }
    if (already) {
        $row.addClass('paperless-attached-already');
    }

    var $cb = $('<input type="checkbox" class="paperless-row-cb">')
        .attr('id', rid)
        .prop('checked', checked)
        .prop('disabled', disabled);

    // Proxied, lazy thumbnail — never a direct browser->Paperless <img src>.
    var thumbUrl = rcmail.url('plugin.paperless.thumb', { id: doc.id, _id: paperlessState.composeId });
    var $thumb = $('<img class="paperless-thumb" loading="lazy">')
        .attr('src', thumbUrl)
        .attr('alt', doc.title || '')
        .on('error', function () {
            // Graceful fallback — never a broken image.
            $(this).replaceWith($('<span class="paperless-thumb paperless-thumb-fallback" aria-hidden="true">'));
        });

    var $meta = $('<div class="paperless-rowtext">');
    $meta.append($('<label class="paperless-title">').attr('for', rid)
        .attr('title', doc.title || '').text(doc.title || ''));
    $meta.append($('<div class="paperless-rowmeta text-muted">').text(paperlessMetaLine(doc)));

    // Per-row disabled marker (escaped via .text()).
    if (already) {
        $meta.append($('<span class="paperless-row-marker paperless-row-marker-dup">')
            .text(t('attach_duplicate_short')));
    }

    $row.append($cb).append($thumb).append($meta);

    // Disabled rows (oversize / already attached) are not selectable — no handlers.
    if (!disabled) {
        // Checkbox is the visible state; whole-row click also toggles.
        $cb.on('click', function (e) {
            e.stopPropagation();
            paperlessToggleSelect(doc, $cb.prop('checked'), $row);
        });
        $row.on('click', function (e) {
            if (e.target === $cb[0]) {
                return;
            }
            var now = !$cb.prop('checked');
            $cb.prop('checked', now);
            paperlessToggleSelect(doc, now, $row);
        });
    }

    return $row;
}

function paperlessMetaLine(doc) {
    var parts = [];
    if (doc.created) {
        parts.push(paperlessFormatDate(doc.created));
    }
    if (doc.correspondent_name) {
        parts.push(doc.correspondent_name);
    }
    return parts.join(' · ');
}

function paperlessFormatDate(iso) {
    // iso may be a full timestamp or YYYY-MM-DD; take the date part.
    var d = new Date(iso);
    if (isNaN(d.getTime())) {
        return String(iso).substring(0, 10);
    }
    try {
        return d.toLocaleDateString();
    } catch (e) {
        return String(iso).substring(0, 10);
    }
}

// -------------------------------------------------------------------------
// Selection model — accumulates across pages, keyed by document id.
// -------------------------------------------------------------------------
function paperlessToggleSelect(doc, on, $row) {
    if (on) {
        paperlessState.selected[doc.id] = { id: doc.id, title: doc.title || '' };
        $row.addClass('paperless-selected');
    } else {
        delete paperlessState.selected[doc.id];
        $row.removeClass('paperless-selected');
    }
    setSelectionCount(paperlessSelectionCount());
}

function paperlessSelectionCount() {
    return Object.keys(paperlessState.selected).length;
}

// Footer count + "N anhängen" enabled state (wired from 02-01; called live).
function setSelectionCount(n) {
    if (!paperlessState || !paperlessState.$dialog) {
        return;
    }
    paperlessState.$dialog.find('.paperless-count').text(paperlessSelectedLabel(n));
    var $btn = paperlessState.$dialog.find('.paperless-attach-btn');
    $btn.text(paperlessAttachLabel(n)).prop('disabled', n < 1);
}

// Assemble the Phase-3 hand-off payload from current dialog state.
function paperlessHandoff() {
    return {
        compose_id:   paperlessState.composeId,
        selected_ids: Object.keys(paperlessState.selected).map(function (k) { return parseInt(k, 10); })
    };
}

// -------------------------------------------------------------------------
// Footer primary button — attach the selected documents (Phase 3).
//
// Posts ONLY { _id: compose_id, selected_ids:[int] } to the server action; the
// token + base URL never leave the browser. A double-submit in-flight guard +
// disabled button + spinner make a rapid second click a no-op, so a double-click
// can never duplicate attachments. The guard clears on the response (success or
// failure) via paperlessOnAttachResponse.
// -------------------------------------------------------------------------
function paperlessOnAttachClick() {
    if (!paperlessState) {
        return;
    }

    // Double-submit guard: ignore re-entry while a request is in flight.
    if (paperlessState.attaching) {
        return;
    }

    var payload = paperlessHandoff();   // { compose_id, selected_ids:[int] }

    // Nothing to attach (no active compose group or empty selection) — no-op.
    if (!payload.compose_id || !payload.selected_ids.length) {
        return;
    }

    paperlessState.attaching = true;

    var $btn = paperlessState.$dialog
        ? paperlessState.$dialog.find('.paperless-attach-btn')
        : $();
    $btn.prop('disabled', true)
        .addClass('paperless-attaching')
        .text(rcmail.gettext('attaching', 'paperless_attach'));

    // `true` 3rd arg sets the busy lock; CSRF token + _remote are auto-attached.
    var attachData = { _id: payload.compose_id, selected_ids: payload.selected_ids };
    rcmail.http_post('plugin.paperless.attach', attachData, true);

    // Failure watchdog: Roundcube fires responseafter<action> only on a SUCCESSFUL
    // AJAX response. A genuine HTTP error (500 / timeout / dropped connection) routes
    // through http_error, which emits no per-action event — so without this the
    // in-flight guard + disabled button would stay stuck forever, blocking retry.
    // Re-enable the button + clear the guard if no response settles in time. The
    // normal response handler cancels this timer, so the two paths are idempotent.
    if (paperlessState.attachWatchdog) {
        clearTimeout(paperlessState.attachWatchdog);
    }
    paperlessState.attachWatchdog = setTimeout(function () {
        if (!paperlessState || !paperlessState.attaching) {
            return;
        }
        paperlessState.attaching = false;
        paperlessState.attachWatchdog = null;
        if (paperlessState.$dialog) {
            paperlessState.$dialog.find('.paperless-attach-btn')
                .prop('disabled', false)
                .removeClass('paperless-attaching')
                .text(paperlessAttachLabel(paperlessSelectionCount()));
        }
    }, 60000);
}

// -------------------------------------------------------------------------
// Localized label helpers (%d substitution).
// -------------------------------------------------------------------------
function paperlessSelectedLabel(n) {
    return paperlessFormat(rcmail.gettext('selected_count', 'paperless_attach'), [n]);
}
function paperlessAttachLabel(n) {
    return paperlessFormat(rcmail.gettext('btn_attach_selected', 'paperless_attach'), [n]);
}
// Live counter beside the Tags label: empty at zero (label stays "Tags"),
// otherwise the localized "N selected" string. Recomputed from the DOM so it
// stays correct regardless of which selection path (toggle, native, reset,
// refill) triggered the update.
function paperlessUpdateTagsCount($root) {
    $root = $root || (paperlessState && paperlessState.$dialog);
    if (!$root || !$root.length) {
        return;
    }
    var n = $root.find('.paperless-f-tags option:selected').length;
    $root.find('.paperless-f-tags-count').text(
        n > 0 ? paperlessFormat(rcmail.gettext('tags_selected_count', 'paperless_attach'), [n]) : ''
    );
}
function paperlessResultCountLabel() {
    return paperlessFormat(
        rcmail.gettext('result_count', 'paperless_attach'),
        [paperlessState.loadedCount, paperlessState.count]
    );
}
// Substitutes BOTH %d (counts) and %s (titles/sizes) positionally, in order, so
// the same helper serves the Phase-2 count labels and the Phase-4 batch messages.
function paperlessFormat(tmpl, args) {
    var i = 0;
    return String(tmpl).replace(/%[ds]/g, function () {
        var v = args[i++];
        return (v === undefined || v === null) ? '' : String(v);
    });
}
