<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect;

use rex_addon;
use rex_socket;
use rex_socket_exception;

final class MailjetClient
{
    private const API_HOST = 'api.mailjet.com';

    public function getConfig(): array
    {
        $addon = rex_addon::get('mailjet_connect');

        return [
            'api_key' => $this->normalizeSecret((string) $addon->getConfig('api_key', '')),
            'api_secret' => $this->normalizeSecret((string) $addon->getConfig('api_secret', '')),
        ];
    }

    public function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $config = $this->getConfig();
        if ('' === $config['api_key'] || '' === $config['api_secret']) {
            throw new \RuntimeException('Mailjet API-Zugangsdaten sind unvollständig.');
        }

        $urlPath = '/v3/REST/' . ltrim($path, '/');
        if ([] !== $query) {
            $urlPath .= '?' . http_build_query($query);
        }

        $socket = rex_socket::factory(self::API_HOST, 443, true)
            ->setPath($urlPath)
            ->setTimeout(20)
            ->addBasicAuthorization($config['api_key'], $config['api_secret'])
            ->addHeader('Accept', 'application/json');

        if (null !== $payload) {
            $socket->addHeader('Content-Type', 'application/json');
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $socket->doGet(),
                'POST' => $socket->doPost((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
            };
        } catch (rex_socket_exception $exception) {
            throw new \RuntimeException('Mailjet API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $body = trim((string) $response->getBody());
        if ('' === $body) {
            return ['status' => $response->getStatusCode(), 'body' => null, 'raw' => ''];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Mailjet API returned invalid JSON: ' . $body);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
            'raw' => $body,
        ];
    }

    public function testConnection(): array
    {
        $result = $this->request('GET', 'eventcallbackurl', ['Limit' => 1]);

        return [
            'success' => 200 === $result['status'],
            'status' => $result['status'],
            'data' => $result['body'],
        ];
    }

    private function normalizeSecret(string $value): string
    {
        $value = trim($value);

        if (2 <= strlen($value)) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value);
    }
}
