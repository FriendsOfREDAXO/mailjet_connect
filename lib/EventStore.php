<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect;

use rex;
use rex_sql;

final class EventStore
{
    public function __construct()
    {
        Schema::ensureEventTable();
    }

    /**
     * @param array<string, mixed> $event
     */
    public function upsert(array $event): int
    {
        $payloadJson = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventHash = $this->buildHash($event);

        $sql = rex_sql::factory();
        $sql->setQuery(
            'INSERT INTO ' . rex::getTable('mailjet_connect_event') . ' (
                event_hash, event_type, event_time, email, message_id, message_guid, custom_id,
                blocked, hard_bounce, error_related_to, error_message, comment, smtp_reply,
                payload_json, sync_status, sync_message, sync_attempts, sync_processed_at, createdate, updatedate
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                event_type = VALUES(event_type),
                event_time = VALUES(event_time),
                email = VALUES(email),
                message_id = VALUES(message_id),
                message_guid = VALUES(message_guid),
                custom_id = VALUES(custom_id),
                blocked = VALUES(blocked),
                hard_bounce = VALUES(hard_bounce),
                error_related_to = VALUES(error_related_to),
                error_message = VALUES(error_message),
                comment = VALUES(comment),
                smtp_reply = VALUES(smtp_reply),
                payload_json = VALUES(payload_json),
                sync_status = IF(sync_status = "synced", sync_status, VALUES(sync_status)),
                sync_message = IF(sync_status = "synced", sync_message, VALUES(sync_message)),
                updatedate = NOW()',
            [
                $eventHash,
                (string) ($event['event_type'] ?? ''),
                (int) ($event['event_time'] ?? 0),
                (string) ($event['email'] ?? ''),
                (string) ($event['message_id'] ?? ''),
                (string) ($event['message_guid'] ?? ''),
                (string) ($event['custom_id'] ?? ''),
                !empty($event['blocked']) ? 1 : 0,
                !empty($event['hard_bounce']) ? 1 : 0,
                (string) ($event['error_related_to'] ?? ''),
                (string) ($event['error_message'] ?? ''),
                (string) ($event['comment'] ?? ''),
                (string) ($event['smtp_reply'] ?? ''),
                $payloadJson ?: '',
                'unknown',
                '',
                0,
                null,
            ]
        );

        return $this->findIdByHash($eventHash);
    }

    public function fetchLatest(int $limit = 50): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('mailjet_connect_event') . ' ORDER BY event_time DESC, id DESC LIMIT ' . max(1, $limit)
        );

        return $sql->getArray();
    }

    public function countByType(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT event_type, COUNT(*) AS amount FROM ' . rex::getTable('mailjet_connect_event') . ' GROUP BY event_type ORDER BY amount DESC');

        $result = [];
        foreach ($sql->getArray() as $row) {
            $result[(string) $row['event_type']] = (int) $row['amount'];
        }

        return $result;
    }

    /**
     * @param array<int, string> $statuses
     * @return array<int, array<string, mixed>>
     */
    public function fetchSyncCandidates(array $statuses, int $limit = 100): array
    {
        if ([] === $statuses) {
            return [];
        }

        $sql = rex_sql::factory();
        $escapedStatuses = array_map([$sql, 'escape'], $statuses);
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('mailjet_connect_event')
            . ' WHERE sync_status IN (' . implode(', ', $escapedStatuses) . ')'
            . ' ORDER BY event_time ASC, id ASC LIMIT ' . max(1, $limit)
        );

        return $sql->getArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchById(int $id): ?array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('mailjet_connect_event') . ' WHERE id = ?', [$id]);
        $row = $sql->getArray();

        return isset($row[0]) && is_array($row[0]) ? $row[0] : null;
    }

    public function updateSyncState(int $id, string $status, string $message = '', bool $incrementAttempts = false, bool $touchProcessedAt = false): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('mailjet_connect_event'));
        $sql->setWhere('id = :id', ['id' => $id]);
        $sql->setValue('sync_status', $status);
        $sql->setValue('sync_message', $message);

        if ($incrementAttempts) {
            $currentRow = $this->fetchById($id);
            $currentAttempts = is_array($currentRow) ? (int) ($currentRow['sync_attempts'] ?? 0) : 0;
            $sql->setValue('sync_attempts', $currentAttempts + 1);
        }

        if ($touchProcessedAt) {
            $sql->setValue('sync_processed_at', date('Y-m-d H:i:s'));
        }

        $sql->update();
    }

    private function findIdByHash(string $eventHash): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id FROM ' . rex::getTable('mailjet_connect_event') . ' WHERE event_hash = ?', [$eventHash]);

        return (int) $sql->getValue('id');
    }

    private function buildHash(array $event): string
    {
        $parts = [
            (string) ($event['event_type'] ?? ''),
            (string) ($event['event_time'] ?? ''),
            (string) ($event['email'] ?? ''),
            (string) ($event['message_id'] ?? ''),
            (string) ($event['message_guid'] ?? ''),
            (string) ($event['custom_id'] ?? ''),
            (string) ($event['error_message'] ?? ''),
        ];

        return sha1(implode('|', $parts));
    }
}
