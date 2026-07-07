<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect;

use rex;
use rex_addon;
use rex_logger;

final class YFormSync
{
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $event
     */
    public function syncWebhookEvent(array $event, int $eventId): void
    {
        $result = $this->processEvent($event, false);
        (new EventStore())->updateSyncState(
            $eventId,
            $result['status'],
            $result['message'],
            $result['increment_attempts'],
            $result['touch_processed_at']
        );
    }

    public function processStoredEvents(int $limit = 100): int
    {
        $store = new EventStore();
        $events = $store->fetchSyncCandidates([self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_UNKNOWN, self::STATUS_IGNORED], $limit);
        $processedCount = 0;

        foreach ($events as $row) {
            $eventId = (int) ($row['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }

            $result = $this->processEvent($row, true);
            $store->updateSyncState(
                $eventId,
                $result['status'],
                $result['message'],
                $result['increment_attempts'],
                $result['touch_processed_at']
            );
            ++$processedCount;
        }

        return $processedCount;
    }

    /**
     * @return array{status:string,message:string,increment_attempts:bool,touch_processed_at:bool,dry_run:bool,dry_run_detail:string}
     */
    public function processStoredEventById(int $eventId, bool $manualSpamOverride = false): array
    {
        $store = new EventStore();
        $row = $store->fetchById($eventId);

        if (!is_array($row)) {
            return $this->result(self::STATUS_FAILED, 'Ereignis nicht gefunden.', false, false);
        }

        $result = $this->processEvent($row, true, false, $manualSpamOverride);
        $store->updateSyncState(
            $eventId,
            $result['status'],
            $result['message'],
            $result['increment_attempts'],
            $result['touch_processed_at']
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status:string,message:string,increment_attempts:bool,touch_processed_at:bool,dry_run:bool,dry_run_detail:string}
     */
    public function dryRun(array $event): array
    {
        return $this->processEvent($event, true, true);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status:string,message:string,increment_attempts:bool,touch_processed_at:bool}
     */
    private function processEvent(array $event, bool $forceExecution, bool $dryRun = false, bool $manualSpamOverride = false): array
    {
        $addon = rex_addon::get('mailjet_connect');
        if (!(bool) $addon->getConfig('yform_sync_enabled', false)) {
            return $this->result(self::STATUS_IGNORED, \rex_i18n::msg('mailjet_connect_sync_status_disabled'));
        }

        if (!rex_addon::get('yform')->isAvailable() || !class_exists('rex_yform_manager_table')) {
            return $this->result(self::STATUS_FAILED, \rex_i18n::msg('mailjet_connect_sync_status_yform_missing'));
        }

        $event = $this->enrichEventFromPayload($event);

        $eventType = trim((string) ($event['event_type'] ?? ''));
        $email = trim((string) ($event['email'] ?? ''));
        $hardBounce = (bool) ($event['hard_bounce'] ?? false);
        if ('' === $eventType || '' === $email) {
            return $this->result(self::STATUS_IGNORED, $this->buildMissingFieldMessage($eventType, $email));
        }

        $allowedEventTypes = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) $addon->getConfig('yform_sync_event_types', ['blocked', 'spam', 'bounce'])
        ), static fn (string $value): bool => '' !== $value));

        if (!in_array($eventType, $allowedEventTypes, true)) {
            return $this->result(self::STATUS_IGNORED, \rex_i18n::msg('mailjet_connect_sync_status_not_selected'));
        }

        // For bounce events, only sync hard bounces to avoid acting on temporary soft bounces.
        if ('bounce' === $eventType && !$hardBounce) {
            return $this->result(self::STATUS_IGNORED, \rex_i18n::msg('mailjet_connect_sync_status_soft_bounce'));
        }

        // Spam: if spam_action = log_only, only record the event for later analysis.
        $spamAction = (string) $addon->getConfig('yform_sync_spam_action', 'sync');
        if ('spam' === $eventType && 'log_only' === $spamAction && !$manualSpamOverride) {
            return $this->result(self::STATUS_IGNORED, \rex_i18n::msg('mailjet_connect_sync_status_spam_log_only'));
        }

        $configuredTable = trim((string) $addon->getConfig('yform_sync_table', ''));
        $emailField = trim((string) $addon->getConfig('yform_sync_email_field', 'email'));
        $action = (string) $addon->getConfig('yform_sync_action', 'deactivate');
        $statusField = trim((string) $addon->getConfig('yform_sync_status_field', 'status'));
        $inactiveValue = (string) $addon->getConfig('yform_sync_inactive_value', '0');
        $reasonField = trim((string) $addon->getConfig('yform_sync_reason_field', ''));
        $reasonTemplate = trim((string) $addon->getConfig('yform_sync_reason_template', 'Mailjet: {event_type}'));
        $syncMode = (string) $addon->getConfig('yform_sync_mode', 'immediate');

        if ('' === $configuredTable || '' === $emailField) {
            return $this->result(self::STATUS_FAILED, \rex_i18n::msg('mailjet_connect_sync_status_not_configured'));
        }

        $tableName = self::normalizeTableName($configuredTable);
        if (null === \rex_yform_manager_table::get($tableName)) {
            return $this->result(self::STATUS_FAILED, \rex_i18n::msg('mailjet_connect_sync_status_table_missing'));
        }

        if (!$forceExecution && 'cron' === $syncMode) {
            return $this->result(self::STATUS_PENDING, \rex_i18n::msg('mailjet_connect_sync_status_pending'));
        }

        try {
            $datasets = \rex_yform_manager_dataset::query($tableName)
                ->where($emailField, $email)
                ->find();

            $foundCount = $datasets->count();

            if ($datasets->isEmpty()) {
                $detail = sprintf('Keine Datensätze mit %s = "%s" in %s gefunden.', $emailField, $email, $tableName);
                return $this->result(self::STATUS_SYNCED, \rex_i18n::msg('mailjet_connect_sync_status_synced'), true, true) + ['dry_run' => $dryRun, 'dry_run_detail' => $detail];
            }

            if ('delete' === $action) {
                $detail = sprintf('%d Datensatz/Datensätze mit %s = "%s" würde(n) gelöscht werden.', $foundCount, $emailField, $email);
                if (!$dryRun) {
                    foreach ($datasets as $dataset) {
                        $dataset->delete();
                    }
                }
                return $this->result(self::STATUS_SYNCED, \rex_i18n::msg('mailjet_connect_sync_status_deleted'), true, true) + ['dry_run' => $dryRun, 'dry_run_detail' => $detail];
            }

            if ('' === $statusField) {
                return $this->result(self::STATUS_FAILED, \rex_i18n::msg('mailjet_connect_sync_status_status_field_missing')) + ['dry_run' => $dryRun, 'dry_run_detail' => ''];
            }

            $detail = sprintf('%d Datensatz/Datensätze mit %s = "%s" würde(n) Feld "%s" auf "%s" gesetzt bekommen.', $foundCount, $emailField, $email, $statusField, $inactiveValue);
            $reasonValue = $this->buildReasonValue($reasonTemplate, $eventType, $email, $event);
            if ('' !== $reasonField) {
                $detail .= sprintf(' Zusätzlich würde Feld "%s" auf "%s" gesetzt.', $reasonField, $reasonValue);
            }

            if (!$dryRun) {
                foreach ($datasets as $dataset) {
                    $dataset->setValue($statusField, $inactiveValue);
                    if ('' !== $reasonField) {
                        $dataset->setValue($reasonField, $reasonValue);
                    }
                    $dataset->save();
                }
            }

            return $this->result(self::STATUS_SYNCED, \rex_i18n::msg('mailjet_connect_sync_status_synced'), true, true) + ['dry_run' => $dryRun, 'dry_run_detail' => $detail];
        } catch (\Throwable $exception) {
            rex_logger::factory()->logException($exception);
            return $this->result(self::STATUS_FAILED, $exception->getMessage(), true, true) + ['dry_run' => $dryRun, 'dry_run_detail' => ''];
        }
    }

    /**
     * @return array{status:string,message:string,increment_attempts:bool,touch_processed_at:bool,dry_run:bool,dry_run_detail:string}
     */
    private function result(string $status, string $message, bool $incrementAttempts = false, bool $touchProcessedAt = true): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'increment_attempts' => $incrementAttempts,
            'touch_processed_at' => $touchProcessedAt,
            'dry_run' => false,
            'dry_run_detail' => '',
        ];
    }

    private static function normalizeTableName(string $tableName): string
    {
        if (str_starts_with($tableName, rex::getTablePrefix())) {
            return $tableName;
        }

        return rex::getTable($tableName);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function enrichEventFromPayload(array $event): array
    {
        if ('' !== trim((string) ($event['event_type'] ?? '')) && '' !== trim((string) ($event['email'] ?? ''))) {
            return $event;
        }

        $payloadJson = trim((string) ($event['payload_json'] ?? ''));
        if ('' === $payloadJson) {
            return $event;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return $event;
        }

        if ('' === trim((string) ($event['event_type'] ?? ''))) {
            $eventType = $this->extractStringValue($payload, ['event_type', 'event', 'EventType']);
            if ('' !== $eventType) {
                $event['event_type'] = $eventType;
            }
        }

        if ('' === trim((string) ($event['email'] ?? ''))) {
            $email = $this->extractStringValue($payload, ['email', 'Email', 'recipient', 'Recipient', 'email_address', 'EmailAddress']);
            if ('' !== $email) {
                $event['email'] = $email;
            }
        }

        if (!isset($event['hard_bounce']) || (0 === (int) $event['hard_bounce'] && '' !== trim((string) ($event['event_type'] ?? '')))) {
            $hardBounce = $this->extractBoolValue($payload, ['hard_bounce', 'HardBounce', 'hardbounce']);
            if (null !== $hardBounce) {
                $event['hard_bounce'] = $hardBounce;
            }
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function extractStringValue(array $payload, array $keys): string
    {
        $value = $this->extractValueRecursive($payload, $keys, 0);
        if (null === $value) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function extractBoolValue(array $payload, array $keys): ?bool
    {
        $value = $this->extractValueRecursive($payload, $keys, 0);
        if (null === $value) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $stringValue = strtolower(trim((string) $value));
        if ('' === $stringValue) {
            return null;
        }

        return in_array($stringValue, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<mixed> $payload
     * @param array<int, string> $keys
     * @return mixed
     */
    private function extractValueRecursive(array $payload, array $keys, int $depth)
    {
        if ($depth > 4) {
            return null;
        }

        $normalizedKeys = array_map('strtolower', $keys);

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array(strtolower($key), $normalizedKeys, true)) {
                if (is_scalar($value) || null === $value) {
                    return $value;
                }
            }

            if (is_array($value)) {
                $nested = $this->extractValueRecursive($value, $keys, $depth + 1);
                if (null !== $nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function buildMissingFieldMessage(string $eventType, string $email): string
    {
        if ('' === $eventType && '' === $email) {
            return 'Event-Typ und E-Mail-Adresse fehlen im Event.';
        }

        if ('' === $eventType) {
            return 'Event-Typ fehlt im Event.';
        }

        return \rex_i18n::msg('mailjet_connect_sync_status_missing_email');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function buildReasonValue(string $template, string $eventType, string $email, array $event): string
    {
        $template = '' !== $template ? $template : 'Mailjet: {event_type}';
        $message = trim((string) ($event['error_message'] ?? ''));

        return strtr($template, [
            '{event_type}' => $eventType,
            '{email}' => $email,
            '{error_message}' => $message,
            '{date}' => date('Y-m-d H:i:s'),
        ]);
    }
}
