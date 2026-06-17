<?php

declare(strict_types=1);

/** @var rex_addon $this */

$addon = rex_addon::get('mailjet_connect');
$client = new FriendsOfREDAXO\MailjetConnect\MailjetClient();
$csrfToken = rex_csrf_token::factory('mailjet_connect_diagnostics');

$webhookPath = rex_url::frontendController(['rex-api-call' => 'mailjet_connect_webhook'], false);
$webhookUrl = rtrim(rex::getServer(), '/') . '/' . ltrim($webhookPath, './');
$username = (string) $addon->getConfig('webhook_username', '');
$password = (string) $addon->getConfig('webhook_password', '');
$token = (string) $addon->getConfig('webhook_token', '');
if ('' !== $username || '' !== $password) {
    $webhookUrl = preg_replace('#^https?://#', 'https://' . rawurlencode($username) . ':' . rawurlencode($password) . '@', $webhookUrl);
}
if ('' !== $token) {
    $separator = str_contains($webhookUrl, '?') ? '&' : '?';
    $webhookUrl .= $separator . 'token=' . rawurlencode($token);
}

$buildSamplePayload = static function (string $type): array {
    return match ($type) {
        'blocked' => [
            'event' => 'blocked',
            'time' => time(),
            'Recipient' => 'blocked-test@example.invalid',
            'MessageID' => 'blocked-' . time(),
            'error' => 'Mailbox rejected message',
            'comment' => 'Diagnose-Test: blocked',
        ],
        'spam' => [
            'event' => 'spam',
            'time' => time(),
            'Email' => 'spam-test@example.invalid',
            'MessageID' => 'spam-' . time(),
            'comment' => 'Diagnose-Test: spam complaint',
        ],
        default => [
            'event' => 'bounce',
            'time' => time(),
            'email' => 'bounce-test@example.invalid',
            'mj_message_id' => 'bounce-' . time(),
            'hard_bounce' => true,
            'error' => 'Mailbox does not exist',
            'smtp_reply' => '550 5.1.1 User unknown',
            'comment' => 'Diagnose-Test: hard bounce',
        ],
    };
};

$buildTransportPayload = static function (array $samplePayload, string $wrapper): array {
    return match ($wrapper) {
        'batch' => ['events' => [$samplePayload]],
        default => [$samplePayload],
    };
};

$extractSampleEvents = static function (array $payload): array {
    $events = [];

    $walk = static function (array $node) use (&$walk, &$events): void {
        if (isset($node['event']) || isset($node['EventType']) || isset($node['event_type'])) {
            $events[] = $node;
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $walk($value);
            }
        }
    };

    $walk($payload);

    return $events;
};

$normalizeSampleEvent = static function (array $event): array {
    $eventType = trim((string) ($event['event_type'] ?? $event['event'] ?? $event['EventType'] ?? ''));
    $eventTypeLower = strtolower($eventType);

    $blockedRaw = $event['blocked'] ?? $event['Blocked'] ?? null;
    $hardBounceRaw = $event['hard_bounce'] ?? $event['HardBounce'] ?? $event['hardbounce'] ?? null;

    $blocked = false;
    if (is_bool($blockedRaw)) {
        $blocked = $blockedRaw;
    } elseif (null !== $blockedRaw) {
        $blocked = in_array(strtolower(trim((string) $blockedRaw)), ['1', 'true', 'yes', 'on'], true);
    }

    $hardBounce = false;
    if (is_bool($hardBounceRaw)) {
        $hardBounce = $hardBounceRaw;
    } elseif (null !== $hardBounceRaw) {
        $hardBounce = in_array(strtolower(trim((string) $hardBounceRaw)), ['1', 'true', 'yes', 'on'], true);
    }

    if (!$blocked && 'blocked' === $eventTypeLower) {
        $blocked = true;
    }
    if (!$hardBounce && in_array($eventTypeLower, ['hard_bounce', 'hardbounce'], true)) {
        $hardBounce = true;
    }

    $messageId = trim((string) ($event['message_id'] ?? $event['MessageID'] ?? $event['mj_message_id'] ?? $event['MessageId'] ?? $event['messageid'] ?? ''));
    if ('0' === $messageId) {
        $messageId = '';
    }

    return [
        'event_type' => $eventType,
        'event_time' => (int) ($event['event_time'] ?? $event['time'] ?? $event['Time'] ?? $event['timestamp'] ?? 0),
        'email' => trim((string) ($event['email'] ?? $event['Email'] ?? $event['recipient'] ?? $event['Recipient'] ?? $event['email_address'] ?? $event['EmailAddress'] ?? '')),
        'message_id' => $messageId,
        'message_guid' => trim((string) ($event['message_guid'] ?? $event['Message_GUID'] ?? $event['MessageGuid'] ?? '')),
        'custom_id' => trim((string) ($event['custom_id'] ?? $event['CustomID'] ?? $event['customId'] ?? '')),
        'blocked' => $blocked,
        'hard_bounce' => $hardBounce,
        'error_related_to' => trim((string) ($event['error_related_to'] ?? $event['ErrorRelatedTo'] ?? '')),
        'error_message' => trim((string) ($event['error_message'] ?? $event['error'] ?? $event['Error'] ?? '')),
        'comment' => trim((string) ($event['comment'] ?? $event['Comment'] ?? '')),
        'smtp_reply' => trim((string) ($event['smtp_reply'] ?? $event['SMTPReply'] ?? $event['smtpReply'] ?? '')),
    ];
};

if (rex_post('run_test', 'bool', false) && $csrfToken->isValid()) {
    try {
        $result = $client->testConnection();
        echo $result['success'] ? rex_view::success($this->i18n('mailjet_connect_test_ok')) : rex_view::warning($this->i18n('mailjet_connect_test_failed'));
    } catch (Throwable $exception) {
        echo rex_view::error($exception->getMessage());
    }
} elseif (rex_post('run_test', 'bool', false)) {
    echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
}

if (rex_post('send_sample_event', 'bool', false) && $csrfToken->isValid()) {
    $sampleType = rex_post('sample_event_type', 'string', 'bounce');
    if (!in_array($sampleType, ['bounce', 'blocked', 'spam'], true)) {
        $sampleType = 'bounce';
    }

    $wrapper = rex_post('sample_event_wrapper', 'string', 'single');
    if (!in_array($wrapper, ['single', 'batch'], true)) {
        $wrapper = 'single';
    }

    $samplePayload = $buildSamplePayload($sampleType);
    $transportPayload = $buildTransportPayload($samplePayload, $wrapper);

    try {
        $events = $extractSampleEvents($transportPayload);
        if ([] === $events) {
            $events = [$samplePayload];
        }

        $store = new FriendsOfREDAXO\MailjetConnect\EventStore();
        $yformSync = class_exists(FriendsOfREDAXO\MailjetConnect\YFormSync::class)
            ? new FriendsOfREDAXO\MailjetConnect\YFormSync()
            : null;

        $stored = 0;
        foreach ($events as $event) {
            $normalizedEvent = $normalizeSampleEvent($event);
            $eventId = $store->upsert($normalizedEvent);
            if (null !== $yformSync) {
                $yformSync->syncWebhookEvent($normalizedEvent, $eventId);
            }
            ++$stored;
        }

        $message = str_replace('###count###', (string) $stored, $this->i18n('mailjet_connect_sample_stored'));
        echo rex_view::success($message);
    } catch (Throwable $exception) {
        echo rex_view::error($this->i18n('mailjet_connect_sample_failed') . '<br><small>' . rex_escape($exception->getMessage()) . '</small>');
    }
} elseif (rex_post('send_sample_event', 'bool', false)) {
    echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
}

if (rex_post('run_dry_run', 'bool', false) && $csrfToken->isValid()) {
    $dryRunEmail = trim((string) rex_post('dry_run_email', 'string', ''));
    $dryRunType = rex_post('dry_run_event_type', 'string', 'bounce');
    if (!in_array($dryRunType, ['bounce', 'blocked', 'spam'], true)) {
        $dryRunType = 'bounce';
    }

    if ('' === $dryRunEmail) {
        echo rex_view::warning($this->i18n('mailjet_connect_dry_run_email_missing'));
    } elseif (!class_exists(FriendsOfREDAXO\MailjetConnect\YFormSync::class)) {
        echo rex_view::warning('YFormSync nicht verfügbar.');
    } else {
        $dryRunEvent = $buildSamplePayload($dryRunType);
        $dryRunEvent['email'] = $dryRunEmail;
        $dryRunEvent['Recipient'] = $dryRunEmail;
        $dryRunEvent['event_type'] = (string) ($dryRunEvent['event'] ?? $dryRunType);
        if ('bounce' === $dryRunType) {
            $dryRunEvent['hard_bounce'] = true;
        }

        try {
            $result = (new FriendsOfREDAXO\MailjetConnect\YFormSync())->dryRun($normalizeSampleEvent($dryRunEvent));
            $label = $result['status'];
            $detail = $result['dry_run_detail'];
            $message = '<strong>' . rex_escape($label) . '</strong>';
            if ('' !== $detail) {
                $message .= '<br>' . rex_escape($detail);
            }
            if ('' !== $result['message']) {
                $message .= '<br><small class="text-muted">' . rex_escape($result['message']) . '</small>';
            }
            echo rex_view::info($message);
        } catch (Throwable $exception) {
            echo rex_view::error(rex_escape($exception->getMessage()));
        }
    }
} elseif (rex_post('run_dry_run', 'bool', false)) {
    echo rex_view::warning($this->i18n('mailjet_connect_csrf_failed'));
}

$connectionContent = '<form method="post">'
    . $csrfToken->getHiddenField()
    . '<input type="hidden" name="run_test" value="1">'
    . '<button class="btn btn-primary" type="submit">' . rex_escape($this->i18n('mailjet_connect_run_test')) . '</button>'
    . '</form>';
$connectionFragment = new rex_fragment();
$connectionFragment->setVar('title', $this->i18n('mailjet_connect_diagnostics_api_panel'));
$connectionFragment->setVar('body', $connectionContent, false);
echo $connectionFragment->parse('core/page/section.php');

$webhookContent = '<input class="form-control" type="text" readonly value="' . rex_escape($webhookUrl) . '">';
$webhookContent .= '<p class="help-block">' . rex_escape($this->i18n('mailjet_connect_diagnostics_notice')) . '</p>';
$webhookFragment = new rex_fragment();
$webhookFragment->setVar('title', $this->i18n('mailjet_connect_webhook_url'));
$webhookFragment->setVar('body', $webhookContent, false);
echo $webhookFragment->parse('core/page/section.php');

$sampleContent = '<p class="help-block">' . rex_escape($this->i18n('mailjet_connect_sample_notice')) . '</p>';
$sampleContent .= '<form method="post">';
$sampleContent .= $csrfToken->getHiddenField();
$sampleContent .= '<input type="hidden" name="send_sample_event" value="1">';
$sampleContent .= '<div class="row">';
$sampleContent .= '<div class="col-sm-6"><div class="form-group">';
$sampleContent .= '<label for="mailjet-connect-sample-event-type">' . rex_escape($this->i18n('mailjet_connect_sample_event_type')) . '</label>';
$sampleContent .= '<select class="form-control" id="mailjet-connect-sample-event-type" name="sample_event_type">';
$sampleContent .= '<option value="bounce">' . rex_escape($this->i18n('mailjet_connect_sample_type_bounce')) . '</option>';
$sampleContent .= '<option value="blocked">' . rex_escape($this->i18n('mailjet_connect_sample_type_blocked')) . '</option>';
$sampleContent .= '<option value="spam">' . rex_escape($this->i18n('mailjet_connect_sample_type_spam')) . '</option>';
$sampleContent .= '</select>';
$sampleContent .= '</div></div>';
$sampleContent .= '<div class="col-sm-6"><div class="form-group">';
$sampleContent .= '<label for="mailjet-connect-sample-event-wrapper">' . rex_escape($this->i18n('mailjet_connect_sample_event_wrapper')) . '</label>';
$sampleContent .= '<select class="form-control" id="mailjet-connect-sample-event-wrapper" name="sample_event_wrapper">';
$sampleContent .= '<option value="single">' . rex_escape($this->i18n('mailjet_connect_sample_wrapper_single')) . '</option>';
$sampleContent .= '<option value="batch">' . rex_escape($this->i18n('mailjet_connect_sample_wrapper_batch')) . '</option>';
$sampleContent .= '</select>';
$sampleContent .= '</div></div>';
$sampleContent .= '</div>';
$sampleContent .= '<button class="btn btn-default" type="submit">' . rex_escape($this->i18n('mailjet_connect_send_sample_event')) . '</button>';
$sampleContent .= '</form>';
$sampleFragment = new rex_fragment();
$sampleFragment->setVar('title', $this->i18n('mailjet_connect_diagnostics_sample_panel'));
$sampleFragment->setVar('body', $sampleContent, false);
echo $sampleFragment->parse('core/page/section.php');

$dryRunContent = '<p class="help-block">' . rex_escape($this->i18n('mailjet_connect_dry_run_notice')) . '</p>';
$dryRunContent .= '<form method="post">';
$dryRunContent .= $csrfToken->getHiddenField();
$dryRunContent .= '<input type="hidden" name="run_dry_run" value="1">';
$dryRunContent .= '<div class="row">';
$dryRunContent .= '<div class="col-sm-6"><div class="form-group">';
$dryRunContent .= '<label for="mailjet-connect-dry-run-email">' . rex_escape($this->i18n('mailjet_connect_dry_run_email')) . '</label>';
$dryRunContent .= '<input class="form-control" type="email" id="mailjet-connect-dry-run-email" name="dry_run_email" placeholder="test@example.com">';
$dryRunContent .= '</div></div>';
$dryRunContent .= '<div class="col-sm-6"><div class="form-group">';
$dryRunContent .= '<label for="mailjet-connect-dry-run-type">' . rex_escape($this->i18n('mailjet_connect_sample_event_type')) . '</label>';
$dryRunContent .= '<select class="form-control" id="mailjet-connect-dry-run-type" name="dry_run_event_type">';
$dryRunContent .= '<option value="bounce">' . rex_escape($this->i18n('mailjet_connect_sample_type_bounce')) . '</option>';
$dryRunContent .= '<option value="blocked">' . rex_escape($this->i18n('mailjet_connect_sample_type_blocked')) . '</option>';
$dryRunContent .= '<option value="spam">' . rex_escape($this->i18n('mailjet_connect_sample_type_spam')) . '</option>';
$dryRunContent .= '</select>';
$dryRunContent .= '</div></div>';
$dryRunContent .= '</div>';
$dryRunContent .= '<button class="btn btn-default" type="submit">' . rex_escape($this->i18n('mailjet_connect_dry_run_run')) . '</button>';
$dryRunContent .= '</form>';
$dryRunFragment = new rex_fragment();
$dryRunFragment->setVar('title', $this->i18n('mailjet_connect_diagnostics_dry_run_panel'));
$dryRunFragment->setVar('body', $dryRunContent, false);
echo $dryRunFragment->parse('core/page/section.php');
