<?php

declare(strict_types=1);

/** @var rex_addon $this */

$store = new FriendsOfREDAXO\MailjetConnect\EventStore();
$clearEventsToken = rex_csrf_token::factory('mailjet_connect_clear_events');
$processSyncsToken = rex_csrf_token::factory('mailjet_connect_process_syncs');
$manualSpamToken = rex_csrf_token::factory('mailjet_connect_manual_spam_sync');

if (rex_post('process_syncs', 'bool', false)) {
    if ($processSyncsToken->isValid()) {
        $processedCount = (new FriendsOfREDAXO\MailjetConnect\YFormSync())->processStoredEvents(200);
        echo rex_view::success(str_replace('###count###', (string) $processedCount, $this->i18n('mailjet_connect_syncs_processed')));
    } else {
        echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
    }
}

if (rex_post('clear_events', 'bool', false)) {
    if ($clearEventsToken->isValid()) {
        rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('mailjet_connect_event'));
        echo rex_view::success($this->i18n('mailjet_connect_events_cleared'));
    } else {
        echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
    }
}

if (rex_post('manual_spam_sync', 'bool', false)) {
    if ($manualSpamToken->isValid()) {
        $eventId = rex_post('event_id', 'int', 0);
        if ($eventId > 0) {
            $result = (new FriendsOfREDAXO\MailjetConnect\YFormSync())->processStoredEventById($eventId, true);
            if (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_SYNCED === $result['status']) {
                echo rex_view::success($this->i18n('mailjet_connect_manual_spam_sync_success'));
            } else {
                $message = $this->i18n('mailjet_connect_manual_spam_sync_failed');
                if ('' !== trim((string) ($result['message'] ?? ''))) {
                    $message .= '<br><small>' . rex_escape((string) $result['message']) . '</small>';
                }
                echo rex_view::warning($message);
            }
        } else {
            echo rex_view::warning($this->i18n('mailjet_connect_manual_spam_sync_missing_id'));
        }
    } else {
        echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
    }
}

$eventTypeFilter = rex_request('filter_event_type', 'string', '');
$blockedFilter = rex_request('filter_blocked', 'string', '');
$hardBounceFilter = rex_request('filter_hard_bounce', 'string', '');
$syncStatusFilter = rex_request('filter_sync_status', 'string', '');
$showErrorColumn = 1 === rex_request('show_error_column', 'int', 0);
$showPayloadColumn = 1 === rex_request('show_payload_column', 'int', 0);
$searchFilter = rex_request('filter_search', 'string', '');
$clearFilter = rex_request('clear_filter', 'string', '');

$allowedSyncStatuses = [
    FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_UNKNOWN,
    FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED,
    FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_PENDING,
    FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_SYNCED,
    FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_FAILED,
];

if ('' !== $syncStatusFilter && !in_array($syncStatusFilter, $allowedSyncStatuses, true)) {
    $syncStatusFilter = '';
}

if ('' !== $clearFilter) {
    $eventTypeFilter = '';
    $blockedFilter = '';
    $hardBounceFilter = '';
    $syncStatusFilter = '';
    $showErrorColumn = false;
    $showPayloadColumn = false;
    $searchFilter = '';
}

$sql = rex_sql::factory();
$where = [];

if ('' !== $eventTypeFilter) {
    $where[] = 'event_type = ' . $sql->escape($eventTypeFilter);
}

if ('0' === $blockedFilter || '1' === $blockedFilter) {
    $where[] = 'blocked = ' . (int) $blockedFilter;
}

if ('0' === $hardBounceFilter || '1' === $hardBounceFilter) {
    $where[] = 'hard_bounce = ' . (int) $hardBounceFilter;
}

if ('' !== $syncStatusFilter) {
    $where[] = 'sync_status = ' . $sql->escape($syncStatusFilter);
}

if ('' !== $searchFilter) {
    $escapedSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $searchFilter);
    $searchSql = $sql->escape('%' . $escapedSearch . '%');
    $where[] = '('
        . 'email LIKE ' . $searchSql
        . ' OR message_id LIKE ' . $searchSql
        . ' OR message_guid LIKE ' . $searchSql
        . ' OR custom_id LIKE ' . $searchSql
        . ' OR error_message LIKE ' . $searchSql
        . ' OR smtp_reply LIKE ' . $searchSql
        . ' OR comment LIKE ' . $searchSql
        . ')';
}

$query = 'SELECT * FROM ' . rex::getTable('mailjet_connect_event');
if ([] !== $where) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}

$eventTypes = array_values(array_filter(
    array_keys($store->countByType()),
    static fn (string $value): bool => '' !== trim($value)
));

$content = '';
$summary = $store->countByType();
$listContent = '';
try {
    $list = rex_list::factory($query, 30, 'mailjet_connect_events', false, 1, ['event_time' => 'desc']);

    $filterContent = '<form action="' . rex_url::currentBackendPage() . '" method="get">';
    $filterContent .= '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '" />';
    $filterContent .= '<div class="row">';
    $filterContent .= '<div class="col-sm-3"><div class="form-group">';
    $filterContent .= '<select class="form-control selectpicker" data-live-search="true" name="filter_event_type" onchange="this.form.submit()">';
    $filterContent .= '<option value="">' . rex_escape($this->i18n('mailjet_connect_filter_all_types')) . '</option>';
    foreach ($eventTypes as $eventType) {
        $selected = $eventType === $eventTypeFilter ? ' selected' : '';
        $filterContent .= '<option value="' . rex_escape($eventType) . '"' . $selected . '>' . rex_escape($eventType) . '</option>';
    }
    $filterContent .= '</select>';
    $filterContent .= '</div></div>';
    $filterContent .= '<div class="col-sm-3"><div class="form-group">';
    $filterContent .= '<select class="form-control selectpicker" name="filter_blocked" onchange="this.form.submit()">';
    $filterContent .= '<option value="">' . rex_escape($this->i18n('mailjet_connect_filter_blocked_all')) . '</option>';
    $filterContent .= '<option value="1"' . ('1' === $blockedFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_filter_blocked_yes')) . '</option>';
    $filterContent .= '<option value="0"' . ('0' === $blockedFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_filter_blocked_no')) . '</option>';
    $filterContent .= '</select>';
    $filterContent .= '</div></div>';
    $filterContent .= '<div class="col-sm-2"><div class="form-group">';
    $filterContent .= '<select class="form-control selectpicker" name="filter_hard_bounce" onchange="this.form.submit()">';
    $filterContent .= '<option value="">' . rex_escape($this->i18n('mailjet_connect_filter_hard_bounce_all')) . '</option>';
    $filterContent .= '<option value="1"' . ('1' === $hardBounceFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_filter_hard_bounce_yes')) . '</option>';
    $filterContent .= '<option value="0"' . ('0' === $hardBounceFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_filter_hard_bounce_no')) . '</option>';
    $filterContent .= '</select>';
    $filterContent .= '</div></div>';
    $filterContent .= '<div class="col-sm-2"><div class="form-group">';
    $filterContent .= '<select class="form-control selectpicker" name="filter_sync_status" onchange="this.form.submit()">';
    $filterContent .= '<option value="">' . rex_escape($this->i18n('mailjet_connect_filter_sync_status_all')) . '</option>';
    $filterContent .= '<option value="' . rex_escape(FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_UNKNOWN) . '"' . (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_UNKNOWN === $syncStatusFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_sync_status_unknown_label')) . '</option>';
    $filterContent .= '<option value="' . rex_escape(FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED) . '"' . (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED === $syncStatusFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_sync_status_ignored_label')) . '</option>';
    $filterContent .= '<option value="' . rex_escape(FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_PENDING) . '"' . (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_PENDING === $syncStatusFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_sync_status_pending_label')) . '</option>';
    $filterContent .= '<option value="' . rex_escape(FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_SYNCED) . '"' . (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_SYNCED === $syncStatusFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_sync_status_synced_label')) . '</option>';
    $filterContent .= '<option value="' . rex_escape(FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_FAILED) . '"' . (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_FAILED === $syncStatusFilter ? ' selected' : '') . '>' . rex_escape($this->i18n('mailjet_connect_sync_status_failed_label')) . '</option>';
    $filterContent .= '</select>';
    $filterContent .= '</div></div>';
    $filterContent .= '<div class="col-sm-4"><div class="input-group">';
    $filterContent .= '<input class="form-control" type="text" name="filter_search" value="' . rex_escape($searchFilter) . '" placeholder="' . rex_escape($this->i18n('mailjet_connect_filter_search_placeholder')) . '" />';
    $filterContent .= '<span class="input-group-btn">';
    $filterContent .= '<button class="btn btn-primary" type="submit"><i class="rex-icon fa-search"></i> ' . rex_escape($this->i18n('mailjet_connect_filter_apply')) . '</button>';
    if ('' !== $searchFilter || '' !== $eventTypeFilter || '' !== $blockedFilter || '' !== $hardBounceFilter || '' !== $syncStatusFilter || $showErrorColumn || $showPayloadColumn) {
        $filterContent .= '<a class="btn btn-default" href="' . rex_url::currentBackendPage() . '"><i class="rex-icon fa-times"></i> ' . rex_escape($this->i18n('mailjet_connect_reset')) . '</a>';
    }
    $filterContent .= '</span></div></div>';
    $filterContent .= '</div>';

    $filterContent .= '<div class="row" style="margin-top:8px">';
    $filterContent .= '<div class="col-sm-12">';
    $filterContent .= '<label class="checkbox-inline">';
    $filterContent .= '<input type="checkbox" name="show_error_column" value="1"' . ($showErrorColumn ? ' checked' : '') . '> ' . rex_escape($this->i18n('mailjet_connect_show_error_column'));
    $filterContent .= '</label>';
    $filterContent .= '<label class="checkbox-inline">';
    $filterContent .= '<input type="checkbox" name="show_payload_column" value="1"' . ($showPayloadColumn ? ' checked' : '') . '> ' . rex_escape($this->i18n('mailjet_connect_show_payload_column'));
    $filterContent .= '</label>';
    $filterContent .= '</div>';
    $filterContent .= '</div>';

    if ('' !== $searchFilter || '' !== $eventTypeFilter || '' !== $blockedFilter || '' !== $hardBounceFilter || '' !== $syncStatusFilter || $showErrorColumn || $showPayloadColumn) {
        $filterContent .= '<div class="row"><div class="col-sm-12"><div class="alert alert-info">';
        $filterContent .= rex_escape($this->i18n('mailjet_connect_filter_results')) . ': ' . $list->getRows();
        $filterContent .= '</div></div></div>';
    }

    $filterContent .= '</form>';

    $filterFragment = new rex_fragment();
    $filterFragment->setVar('title', $this->i18n('mailjet_connect_filter'));
    $filterFragment->setVar('body', $filterContent, false);
    $content .= $filterFragment->parse('core/page/section.php');

    $actionsContent = '<div class="btn-toolbar">';
    $actionsContent .= '<form method="post" class="rex-form-inline" style="display:inline-block;margin-right:10px">'
        . $processSyncsToken->getHiddenField()
        . '<input type="hidden" name="process_syncs" value="1">'
        . '<button type="submit" class="btn btn-default">' . rex_escape($this->i18n('mailjet_connect_process_syncs')) . '</button>'
        . '</form>';
    $actionsContent .= '<form method="post" class="rex-form-inline" style="display:inline-block">'
        . $clearEventsToken->getHiddenField()
        . '<input type="hidden" name="clear_events" value="1">'
        . '<button type="submit" class="btn btn-danger" data-confirm="' . rex_escape($this->i18n('mailjet_connect_events_clear_confirm')) . '">' . rex_escape($this->i18n('mailjet_connect_events_clear')) . '</button>'
        . '</form>';
    $actionsContent .= '</div>';
    $actionsContent .= '<p class="help-block" style="margin-top:8px">' . rex_i18n::rawMsg('mailjet_connect_events_sync_hint') . '</p>';

    $actionsFragment = new rex_fragment();
    $actionsFragment->setVar('body', $actionsContent, false);
    $content .= $actionsFragment->parse('core/page/section.php');

    $listContent = '';

    $listTitle = $this->i18n('mailjet_connect_events');
    if ([] !== $summary) {
        $total = array_sum($summary);
        $listTitle .= ' <span class="badge">' . (int) $total . '</span>';
    }

    $list->addTableAttribute('class', 'table-striped');
    $list->setCaption('');
    $list->setNoRowsMessage($this->i18n('mailjet_connect_events_no_rows'));

    $list->removeColumn('event_hash');
    $list->removeColumn('message_guid');
    $list->removeColumn('custom_id');
    $list->removeColumn('error_related_to');
    $list->removeColumn('comment');
    $list->removeColumn('smtp_reply');
    $list->removeColumn('sync_message');
    $list->removeColumn('sync_attempts');
    $list->removeColumn('sync_processed_at');
    $list->removeColumn('createdate');
    $list->removeColumn('updatedate');

    $list->setColumnLabel('id', 'ID');
    $list->setColumnLabel('event_type', $this->i18n('mailjet_connect_event_type'));
    $list->setColumnLabel('event_time', $this->i18n('mailjet_connect_event_time'));
    $list->setColumnLabel('email', $this->i18n('mailjet_connect_email'));
    $list->setColumnLabel('message_id', $this->i18n('mailjet_connect_message_id'));
    $list->setColumnLabel('blocked', $this->i18n('mailjet_connect_blocked'));
    $list->setColumnLabel('hard_bounce', $this->i18n('mailjet_connect_hard_bounce'));
    $list->setColumnLabel('sync_status', $this->i18n('mailjet_connect_sync_status'));

    if ($showErrorColumn) {
        $list->setColumnLabel('error_message', $this->i18n('mailjet_connect_error'));
    } else {
        $list->removeColumn('error_message');
    }

    if ($showPayloadColumn) {
        $list->setColumnLabel('payload_json', $this->i18n('mailjet_connect_raw_payload'));
    } else {
        $list->removeColumn('payload_json');
    }

    $list->setColumnSortable('id');
    $list->setColumnSortable('event_type');
    $list->setColumnSortable('event_time');
    $list->setColumnSortable('email');
    $list->setColumnSortable('message_id');
    $list->setColumnSortable('blocked');
    $list->setColumnSortable('hard_bounce');
    $list->setColumnSortable('sync_status');

    $list->setColumnLayout('id', ['<th class="rex-small">###VALUE###</th>', '<td class="rex-small">###VALUE###</td>']);

    $list->setColumnFormat('event_type', 'custom', static function (array $params): string {
        return '<span class="label label-default">' . rex_escape((string) $params['value']) . '</span>';
    });

    $list->setColumnFormat('event_time', 'custom', static function (array $params): string {
        $timestamp = (int) $params['value'];
        if ($timestamp <= 0) {
            return '—';
        }

        return rex_escape(rex_formatter::intlDateTime($timestamp, [\IntlDateFormatter::SHORT, \IntlDateFormatter::MEDIUM]));
    });

    $list->setColumnFormat('message_id', 'custom', static function (array $params): string {
        $list = $params['list'];
        $messageId = trim((string) $params['value']);
        $customId = trim((string) $list->getValue('custom_id'));
        $messageGuid = trim((string) $list->getValue('message_guid'));

        $parts = [];
        if ('' !== $messageId) {
            $parts[] = '<strong>' . rex_escape($messageId) . '</strong>';
        }
        if ('' !== $customId) {
            $parts[] = '<small class="text-muted">Custom ID: ' . rex_escape($customId) . '</small>';
        }
        if ('' !== $messageGuid) {
            $parts[] = '<small class="text-muted">GUID: ' . rex_escape($messageGuid) . '</small>';
        }

        return implode('<br>', $parts);
    });

    $boolFormatter = static function (array $params): string {
        return 1 === (int) $params['value']
            ? '<i class="rex-icon fa-check text-success" title="Ja"></i>'
            : '<i class="rex-icon fa-times text-muted" title="Nein"></i>';
    };

    $list->setColumnFormat('blocked', 'custom', $boolFormatter);
    $list->setColumnFormat('hard_bounce', 'custom', $boolFormatter);

    if ($showErrorColumn) {
        $list->setColumnFormat('error_message', 'custom', static function (array $params): string {
            $list = $params['list'];
            $errorMessage = trim((string) $params['value']);
            $relatedTo = trim((string) $list->getValue('error_related_to'));
            $smtpReply = trim((string) $list->getValue('smtp_reply'));
            $comment = trim((string) $list->getValue('comment'));
            $parts = [];
            if ('' !== $errorMessage) {
                $parts[] = rex_escape($errorMessage);
            }
            if ('' !== $relatedTo) {
                $parts[] = '<small class="text-muted">Bezug: ' . rex_escape($relatedTo) . '</small>';
            }
            if ('' !== $smtpReply) {
                $parts[] = '<small class="text-muted">SMTP: ' . rex_escape($smtpReply) . '</small>';
            }
            if ('' !== $comment) {
                $parts[] = '<small class="text-muted">Kommentar: ' . rex_escape($comment) . '</small>';
            }

            if ([] === $parts) {
                return '—';
            }

            return implode('<br>', $parts);
        });
    }

    if ($showPayloadColumn) {
        $list->setColumnFormat('payload_json', 'custom', static function (array $params): string {
            $payloadJson = trim((string) $params['value']);
            if ('' === $payloadJson) {
                return '—';
            }

            $prettyPayload = $payloadJson;
            $decodedPayload = json_decode($payloadJson, true);
            if (is_array($decodedPayload)) {
                $encodedPayload = json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (false !== $encodedPayload) {
                    $prettyPayload = $encodedPayload;
                }
            }

            return '<details><summary>'
                . rex_escape(rex_addon::get('mailjet_connect')->i18n('mailjet_connect_show_raw_payload'))
                . '</summary><pre class="pre-scrollable">'
                . rex_escape($prettyPayload)
                . '</pre></details>';
        });
    }

    $list->setColumnFormat('sync_status', 'custom', static function (array $params): string {
        $list = $params['list'];
        $status = trim((string) $params['value']);
        $syncMessage = trim((string) $list->getValue('sync_message'));
        $email = trim((string) $list->getValue('email'));
        $eventType = trim((string) $list->getValue('event_type'));
        $attempts = (int) $list->getValue('sync_attempts');
        $processedAt = trim((string) $list->getValue('sync_processed_at'));
        $syncEnabled = (bool) rex_addon::get('mailjet_connect')->getConfig('yform_sync_enabled', false);
        $spamAction = (string) rex_addon::get('mailjet_connect')->getConfig('yform_sync_spam_action', 'sync');
        $manualActionToken = rex_csrf_token::factory('mailjet_connect_manual_spam_sync');

        // Legacy ignored rows can still carry old reason texts; when sync is disabled,
        // show the current effective reason consistently in the list.
        if (!$syncEnabled && FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED === $status) {
            $syncMessage = rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_disabled');
        }

        if (FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED === $status
            && '' !== $email
            && rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_missing_email') === $syncMessage
        ) {
            $syncMessage = '' === $eventType ? 'Event-Typ fehlt im Event.' : 'Legacy-Status: E-Mail ist vorhanden, bitte Eintrag erneut verarbeiten.';
        }

        $statusMap = [
            FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_SYNCED => ['label-success', rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_synced_label')],
            FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_PENDING => ['label-warning', rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_pending_label')],
            FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_FAILED => ['label-danger', rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_failed_label')],
            FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED => ['label-default', rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_ignored_label')],
            FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_UNKNOWN => ['label-default', rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_status_unknown_label')],
        ];

        [$labelClass, $labelText] = $statusMap[$status] ?? ['label-default', $status];

        $parts = ['<span class="label ' . $labelClass . '">' . rex_escape($labelText) . '</span>'];
        if ('' !== $syncMessage) {
            $parts[] = '<small class="text-muted">' . rex_escape($syncMessage) . '</small>';
        }
        if ($attempts > 0) {
            $parts[] = '<small class="text-muted">' . rex_escape(rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_attempts')) . ': ' . $attempts . '</small>';
        }
        if ('' !== $processedAt) {
            $timestamp = strtotime($processedAt);
            if (false !== $timestamp) {
                $parts[] = '<small class="text-muted">' . rex_escape(rex_addon::get('mailjet_connect')->i18n('mailjet_connect_sync_processed_at')) . ': ' . rex_formatter::intlDateTime($timestamp, [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT]) . '</small>';
            }
        }

        $isManualSpamCandidate = $syncEnabled
            && 'spam' === strtolower($eventType)
            && FriendsOfREDAXO\MailjetConnect\YFormSync::STATUS_IGNORED === $status
            && 'log_only' === $spamAction;

        if ($isManualSpamCandidate) {
            $actionHtml = '<form method="post" style="margin-top:6px">';
            $actionHtml .= $manualActionToken->getHiddenField();
            $actionHtml .= '<input type="hidden" name="manual_spam_sync" value="1">';
            $actionHtml .= '<input type="hidden" name="event_id" value="' . (int) $list->getValue('id') . '">';
            $actionHtml .= '<button type="submit" class="btn btn-xs btn-warning">' . rex_escape(rex_addon::get('mailjet_connect')->i18n('mailjet_connect_manual_spam_sync')) . '</button>';

            $persistParams = [
                'filter_event_type',
                'filter_blocked',
                'filter_hard_bounce',
                'filter_sync_status',
                'filter_search',
                'show_error_column',
                'show_payload_column',
                'list',
                'sort',
                'sorttype',
                'start',
            ];

            foreach ($persistParams as $paramName) {
                $paramValue = rex_request($paramName, 'string', '');
                if ('' === $paramValue) {
                    continue;
                }

                $actionHtml .= '<input type="hidden" name="' . rex_escape($paramName) . '" value="' . rex_escape($paramValue) . '">';
            }

            $actionHtml .= '</form>';
            $parts[] = $actionHtml;
        }

        return implode('<br>', $parts);
    });

    if ('' !== $eventTypeFilter) {
        $list->addParam('filter_event_type', $eventTypeFilter);
    }
    if ('' !== $blockedFilter) {
        $list->addParam('filter_blocked', $blockedFilter);
    }
    if ('' !== $hardBounceFilter) {
        $list->addParam('filter_hard_bounce', $hardBounceFilter);
    }
    if ('' !== $syncStatusFilter) {
        $list->addParam('filter_sync_status', $syncStatusFilter);
    }
    if ($showErrorColumn) {
        $list->addParam('show_error_column', '1');
    }
    if ($showPayloadColumn) {
        $list->addParam('show_payload_column', '1');
    }
    if ('' !== $searchFilter) {
        $list->addParam('filter_search', $searchFilter);
    }

    $listContent .= $list->get();
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    if (str_contains($message, rex::getTable('mailjet_connect_event')) && str_contains(strtolower($message), 'doesn\'t exist')) {
        $listContent .= rex_view::warning($this->i18n('mailjet_connect_events_missing_table'));
    } else {
        $listContent .= rex_view::error($this->i18n('mailjet_connect_events_error') . '<br><small>' . rex_escape($message) . '</small>');
    }
}

$listFragment = new rex_fragment();
$listFragment->setVar('title', $listTitle ?? $this->i18n('mailjet_connect_events'), false);
$listFragment->setVar('body', $listContent, false);
$content .= $listFragment->parse('core/page/section.php');

echo $content;

// Spam-Domain-Analyse
try {
    $spamSql = rex_sql::factory();
    $spamSql->setQuery(
        'SELECT email, COUNT(*) AS amount FROM ' . rex::getTable('mailjet_connect_event')
        . ' WHERE event_type = "spam" AND email != "" GROUP BY email ORDER BY amount DESC LIMIT 200'
    );

    $spamRows = $spamSql->getArray();

    if ([] !== $spamRows) {
        $domainCounts = [];
        foreach ($spamRows as $row) {
            $parts = explode('@', (string) $row['email'], 2);
            $domain = strtolower($parts[1] ?? 'unbekannt');
            if (!isset($domainCounts[$domain])) {
                $domainCounts[$domain] = 0;
            }
            $domainCounts[$domain] += (int) $row['amount'];
        }
        arsort($domainCounts);

        $total = array_sum($domainCounts);
        $spamContent = '<p class="help-block">' . rex_escape($this->i18n('mailjet_connect_spam_analysis_notice')) . '</p>';
        $spamContent .= '<table class="table table-striped table-condensed">';
        $spamContent .= '<thead><tr><th>' . rex_escape($this->i18n('mailjet_connect_spam_analysis_domain')) . '</th>';
        $spamContent .= '<th>' . rex_escape($this->i18n('mailjet_connect_spam_analysis_count')) . '</th>';
        $spamContent .= '<th>' . rex_escape($this->i18n('mailjet_connect_spam_analysis_share')) . '</th></tr></thead><tbody>';
        foreach ($domainCounts as $domain => $count) {
            $share = $total > 0 ? round($count / $total * 100, 1) : 0;
            $barWidth = min(100, (int) $share);
            $badgeClass = $share >= 30 ? 'danger' : ($share >= 10 ? 'warning' : 'info');
            $spamContent .= '<tr>';
            $spamContent .= '<td>' . rex_escape($domain) . '</td>';
            $spamContent .= '<td>' . (int) $count . '</td>';
            $spamContent .= '<td style="width:50%">';
            $spamContent .= '<div class="progress" style="margin:0"><div class="progress-bar progress-bar-' . $badgeClass . '" style="width:' . $barWidth . '%">' . $share . ' %</div></div>';
            $spamContent .= '</td>';
            $spamContent .= '</tr>';
        }
        $spamContent .= '</tbody></table>';

        $spamAnalysisFragment = new rex_fragment();
        $spamAnalysisTitle = $this->i18n('mailjet_connect_spam_analysis') . ' <span class="badge">' . (int) $total . '</span>';
        $spamAnalysisFragment->setVar('title', $spamAnalysisTitle, false);
        $spamAnalysisFragment->setVar('body', $spamContent, false);
        echo $spamAnalysisFragment->parse('core/page/section.php');
    }
} catch (Throwable $spamException) {
    // keine Spam-Einträge oder Tabelle nicht vorhanden
}
