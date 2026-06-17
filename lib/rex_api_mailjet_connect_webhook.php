<?php

declare(strict_types=1);

use FriendsOfREDAXO\MailjetConnect\EventStore;

final class rex_api_mailjet_connect_webhook extends rex_api_function
{
    protected $published = true;

    public function requiresCsrfProtection(): bool
    {
        return false;
    }

    public function execute(): void
    {
        rex_response::cleanOutputBuffers();

        $method = rex_request_method();

        // Mail providers sometimes validate webhook URLs with GET/HEAD before sending POST events.
        if ('get' === $method || 'head' === $method) {
            $this->sendJsonWithStatus(200, ['success' => true, 'status' => 'ok']);
            exit;
        }

        if ('post' !== $method) {
            $this->sendJsonWithStatus(405, ['success' => false, 'error' => 'method_not_allowed']);
            exit;
        }

        try {
            if (!$this->isAuthorized()) {
                $this->sendJsonWithStatus(401, ['success' => false, 'error' => 'unauthorized']);
                exit;
            }

            $input = file_get_contents('php://input');
            if (false === $input || '' === trim($input)) {
                $this->sendJsonWithStatus(400, ['success' => false, 'error' => 'empty_payload']);
                exit;
            }

            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                $this->sendJsonWithStatus(400, ['success' => false, 'error' => 'invalid_json']);
                exit;
            }

            $events = $this->normalizePayload($decoded);
            $store = new EventStore();
            $yformSync = class_exists(FriendsOfREDAXO\MailjetConnect\YFormSync::class)
                ? new FriendsOfREDAXO\MailjetConnect\YFormSync()
                : null;

            foreach ($events as $event) {
                $eventId = $store->upsert($event);
                if (null !== $yformSync) {
                    $yformSync->syncWebhookEvent($event, $eventId);
                }
            }

            $this->sendJsonWithStatus(200, [
                'success' => true,
                'stored' => count($events),
            ]);
            exit;
        } catch (\Throwable $exception) {
            rex_logger::factory()->logException($exception);
            $this->sendJsonWithStatus(500, ['success' => false, 'error' => 'server_error']);
            exit;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJsonWithStatus(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        rex_response::setStatus($statusCode);
        rex_response::sendJson($payload);
    }

    /**
     * @param array<mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function normalizePayload(array $payload): array
    {
        if ($this->isSingleEvent($payload)) {
            return [$this->mapEvent($payload)];
        }

        $events = [];
        foreach ($this->collectEventPayloads($payload, 0) as $eventPayload) {
            $events[] = $this->mapEvent($eventPayload);
        }

        return [] !== $events ? $events : [$this->mapEvent($payload)];
    }

    /**
     * @param array<mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function collectEventPayloads(array $payload, int $depth): array
    {
        if ($depth > 5) {
            return [];
        }

        $events = [];
        if ($this->isSingleEvent($payload)) {
            $events[] = $payload;
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        foreach ($this->collectEventPayloads($item, $depth + 1) as $nestedEvent) {
                            $events[] = $nestedEvent;
                        }
                    }
                    continue;
                }

                foreach ($this->collectEventPayloads($value, $depth + 1) as $nestedEvent) {
                    $events[] = $nestedEvent;
                }

                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $decoded = $this->decodeJsonArray($value);
            if (null === $decoded) {
                continue;
            }

            foreach ($this->collectEventPayloads($decoded, $depth + 1) as $nestedEvent) {
                $events[] = $nestedEvent;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isSingleEvent(array $payload): bool
    {
        return isset($payload['event']) || isset($payload['EventType']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapEvent(array $payload): array
    {
        $eventType = $this->normalizeString($this->firstScalarValue($payload, ['event', 'EventType', 'event_type']));
        $messageId = $this->firstScalarValue($payload, ['MessageID', 'mj_message_id', 'message_id', 'MessageId', 'messageid']);
        $messageId = $this->normalizeMessageId($messageId);
        $blocked = $this->normalizeBool($this->firstScalarValue($payload, ['blocked', 'Blocked']));
        $hardBounce = $this->normalizeBool($this->firstScalarValue($payload, ['hard_bounce', 'HardBounce', 'hardbounce']));

        $eventTypeLower = strtolower($eventType);
        if (!$blocked && 'blocked' === $eventTypeLower) {
            $blocked = true;
        }
        if (!$hardBounce && in_array($eventTypeLower, ['hard_bounce', 'hardbounce'], true)) {
            $hardBounce = true;
        }

        return [
            'event_type' => $eventType,
            'event_time' => $this->normalizeTimestamp($this->firstScalarValue($payload, ['time', 'Time', 'event_time', 'timestamp'])),
            'email' => $this->normalizeString($this->firstScalarValue($payload, ['email', 'Email', 'recipient', 'Recipient', 'email_address', 'EmailAddress'])),
            'message_id' => $messageId,
            'message_guid' => $this->normalizeString($this->firstScalarValue($payload, ['Message_GUID', 'message_guid', 'MessageGuid'])),
            'custom_id' => $this->normalizeString($this->firstScalarValue($payload, ['CustomID', 'custom_id', 'customId'])),
            'blocked' => $blocked,
            'hard_bounce' => $hardBounce,
            'error_related_to' => $this->normalizeString($this->firstScalarValue($payload, ['error_related_to', 'ErrorRelatedTo'])),
            'error_message' => $this->normalizeString($this->firstScalarValue($payload, ['error', 'Error', 'error_message'])),
            'comment' => $this->normalizeString($this->firstScalarValue($payload, ['comment', 'Comment'])),
            'smtp_reply' => $this->normalizeString($this->firstScalarValue($payload, ['smtp_reply', 'SMTPReply', 'smtpReply'])),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     * @return mixed
     */
    private function firstScalarValue(array $payload, array $keys)
    {
        $normalizedKeys = [];
        foreach ($keys as $key) {
            $normalizedKeys[] = strtolower($key);
        }

        return $this->findScalarValueRecursive($payload, $normalizedKeys, 0);
    }

    /**
     * @param array<mixed> $payload
     * @param array<int, string> $normalizedKeys
     * @return mixed
     */
    private function findScalarValueRecursive(array $payload, array $normalizedKeys, int $depth)
    {
        if ($depth > 4) {
            return null;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!in_array(strtolower($key), $normalizedKeys, true)) {
                continue;
            }

            if (is_scalar($value) || null === $value) {
                return $value;
            }

            if (is_array($value)) {
                $nestedValue = $this->findScalarValueRecursive($value, $normalizedKeys, $depth + 1);
                if (null !== $nestedValue) {
                    return $nestedValue;
                }
            }

            if (is_string($value)) {
                $decoded = $this->decodeJsonArray($value);
                if (null !== $decoded) {
                    $nestedValue = $this->findScalarValueRecursive($decoded, $normalizedKeys, $depth + 1);
                    if (null !== $nestedValue) {
                        return $nestedValue;
                    }
                }
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $nestedValue = $this->findScalarValueRecursive($value, $normalizedKeys, $depth + 1);
                if (null !== $nestedValue) {
                    return $nestedValue;
                }

                continue;
            }

            if (is_string($value)) {
                $decoded = $this->decodeJsonArray($value);
                if (null === $decoded) {
                    continue;
                }

                $nestedValue = $this->findScalarValueRecursive($decoded, $normalizedKeys, $depth + 1);
                if (null !== $nestedValue) {
                    return $nestedValue;
                }
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeJsonArray(string $value): ?array
    {
        $trimmed = trim($value);
        if ('' === $trimmed || ('{' !== $trimmed[0] && '[' !== $trimmed[0])) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeString($value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return trim((string) $value);
    }

    /**
     * @param mixed $value
     */
    private function normalizeTimestamp($value): int
    {
        if (null === $value || '' === $value) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * @param mixed $value
     */
    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (null === $value) {
            return false;
        }

        $stringValue = strtolower(trim((string) $value));
        return in_array($stringValue, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private function normalizeMessageId($value): string
    {
        $messageId = $this->normalizeString($value);
        if ('0' === $messageId) {
            return '';
        }

        return $messageId;
    }

    private function isAuthorized(): bool
    {
        $addon = rex_addon::get('mailjet_connect');
        $username = (string) $addon->getConfig('webhook_username', '');
        $password = (string) $addon->getConfig('webhook_password', '');
        $token = trim((string) $addon->getConfig('webhook_token', ''));

        if ('' === $username && '' === $password && '' === $token) {
            return true;
        }

        if ('' !== $token && $this->isValidToken($token)) {
            return true;
        }

        $authHeader = (string) rex_request::server('HTTP_AUTHORIZATION', 'string', '');
        if ('' === $authHeader) {
            $authHeader = (string) rex_request::server('REDIRECT_HTTP_AUTHORIZATION', 'string', '');
        }

        if ('' === $authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if (false === $decoded || !str_contains($decoded, ':')) {
            return false;
        }

        [$receivedUser, $receivedPassword] = explode(':', $decoded, 2);

        return hash_equals($username, $receivedUser) && hash_equals($password, $receivedPassword);
    }

    private function isValidToken(string $expectedToken): bool
    {
        $tokenFromQuery = trim((string) rex_request('token', 'string', ''));
        if ('' !== $tokenFromQuery && hash_equals($expectedToken, $tokenFromQuery)) {
            return true;
        }

        $tokenFromHeader = trim((string) rex_request::server('HTTP_X_WEBHOOK_TOKEN', 'string', ''));
        if ('' !== $tokenFromHeader && hash_equals($expectedToken, $tokenFromHeader)) {
            return true;
        }

        $authHeader = trim((string) rex_request::server('HTTP_AUTHORIZATION', 'string', ''));
        if ('' === $authHeader) {
            $authHeader = trim((string) rex_request::server('REDIRECT_HTTP_AUTHORIZATION', 'string', ''));
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = trim(substr($authHeader, 7));
            if ('' !== $bearerToken && hash_equals($expectedToken, $bearerToken)) {
                return true;
            }
        }

        return false;
    }
}
