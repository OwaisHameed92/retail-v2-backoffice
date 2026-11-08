<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Workshop\Enums\WebhookEvent;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\ConnectorException;
use SensitiveParameter;

/**
 * BikeBook manages its webhook on us (WORKSHOP-BIKEBOOK.md §3.8 "Webhook CRUD"): `{id, url, secret, subscriptions[],
 * enabled, name}`, one per connection. A POST when one exists replaces it (idempotent by connection); PUT changes it
 * (the secret only when sent); DELETE removes it. The URL must be HTTPS on a public host name; the secret is stored
 * encrypted and never answered back. Subscriptions: order.statuschanged, inventory.updated, product.updated,
 * customer.updated.
 */
final class SaveWorkshopWebhook
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<mixed>  $body
     */
    public function save(WorkshopConnection $connection, array $body, bool $replace): WorkshopConnection
    {
        $url = trim((string) ($body['url'] ?? $connection->webhook_url ?? ''));
        $secret = array_key_exists('secret', $body) ? trim((string) $body['secret']) : null;
        $subscriptions = $body['subscriptions'] ?? $connection->webhook_subscriptions ?? [];

        if (! $replace && $connection->webhook_url === null) {
            throw ConnectorException::notFound('There is no webhook on this connection. Create it with POST.');
        }

        self::checkUrl($url);
        $secret = $replace || $secret !== null ? self::checkSecret($secret) : null;

        if (! is_array($subscriptions) || ! array_is_list($subscriptions) || array_diff($subscriptions, WebhookEvent::values()) !== []) {
            throw ConnectorException::invalid('subscriptions must be a list of: '.implode(', ', WebhookEvent::values()).'.');
        }

        $id = trim((string) ($body['id'] ?? ''));
        $connection->forceFill([
            'webhook_id' => $replace ? ($id !== '' ? mb_substr($id, 0, 100) : Ulid::new()) : $connection->webhook_id,
            'webhook_name' => mb_substr(trim((string) ($body['name'] ?? $connection->webhook_name ?? 'BikeBook')), 0, 120),
            'webhook_url' => $url,
            'webhook_subscriptions' => array_values(array_unique($subscriptions)),
            'webhook_enabled' => array_key_exists('enabled', $body) ? (bool) $body['enabled'] : ($replace || $connection->webhook_enabled),
            ...($secret !== null ? ['webhook_secret' => $secret] : []),
        ]);

        if ($connection->isDirty()) {
            $connection->save();
            $this->audit->handle('workshop.webhook_saved', $connection, null, ['url' => $url, 'subscriptions' => $connection->webhook_subscriptions, 'enabled' => $connection->webhook_enabled]);
        }

        return $connection;
    }

    public function delete(WorkshopConnection $connection): void
    {
        if ($connection->webhook_url === null) {
            return;
        }

        $connection->forceFill(['webhook_id' => null, 'webhook_name' => null, 'webhook_url' => null, 'webhook_secret' => null, 'webhook_subscriptions' => null, 'webhook_enabled' => false])->save();
        $this->audit->handle('workshop.webhook_deleted', $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public static function view(WorkshopConnection $connection): array
    {
        return [
            'id' => $connection->webhook_id,
            'name' => $connection->webhook_name,
            'url' => $connection->webhook_url,
            'subscriptions' => $connection->webhook_subscriptions ?? [],
            'enabled' => $connection->webhook_enabled,
        ];
    }

    private static function checkUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($url === '' || mb_strlen($url) > 500 || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user'])
            || filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost' || str_ends_with($host, '.local') || ! str_contains($host, '.')) {
            throw ConnectorException::invalid('url must be an https:// address on a public host name.');
        }
    }

    private static function checkSecret(#[SensitiveParameter] ?string $secret): string
    {
        if ($secret === null || mb_strlen($secret) < 16 || mb_strlen($secret) > 200) {
            throw ConnectorException::invalid('secret is required: 16 to 200 characters.');
        }

        return $secret;
    }
}
