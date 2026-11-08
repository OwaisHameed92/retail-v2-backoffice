<?php

namespace App\Domain\Operations\Support;

/**
 * Reads a failed job's queue payload without ever unserialising it: the display name, the job class, attempts and
 * the models it was queued with (Laravel serialises them as ModelIdentifier class + id). Odd or broken payloads give
 * empty values, never an error.
 */
final class FailedJobPayload
{
    /** @var array<string, mixed> */
    private array $data;

    private string $command;

    public function __construct(?string $payload)
    {
        $decoded = json_decode((string) $payload, true);
        $this->data = is_array($decoded) ? $decoded : [];
        $command = $this->data['data']['command'] ?? '';
        $this->command = is_string($command) ? $command : '';
    }

    public function displayName(): string
    {
        $name = $this->data['displayName'] ?? $this->data['job'] ?? null;

        return is_string($name) && $name !== '' ? mb_strimwidth($name, 0, 191, '…') : 'Unknown job';
    }

    /** Basename of the display name ("App\Domain\Billing\Jobs\SendInvoice" → "SendInvoice"). */
    public function shortName(): string
    {
        return class_basename($this->displayName());
    }

    public function attempts(): ?int
    {
        $attempts = $this->data['attempts'] ?? null;

        return is_int($attempts) ? $attempts : null;
    }

    public function maxTries(): ?int
    {
        $tries = $this->data['maxTries'] ?? null;

        return is_int($tries) ? $tries : null;
    }

    public function timeout(): ?int
    {
        $timeout = $this->data['timeout'] ?? null;

        return is_int($timeout) ? $timeout : null;
    }

    /**
     * Models the job was queued with, in order, at most `$limit`: [class, id].
     *
     * @return list<array{class: string, id: string}>
     */
    public function models(int $limit = 10): array
    {
        if ($this->command === '') {
            return [];
        }

        preg_match_all(
            '/s:5:"class";s:\d+:"(App\\\\[A-Za-z0-9_\\\\]+)";s:2:"id";(?:s:\d+:"([^"]{1,64})"|i:(\d+))/',
            $this->command,
            $matches,
            PREG_SET_ORDER,
        );

        $models = [];

        foreach ($matches as $match) {
            $id = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
            $model = ['class' => $match[1], 'id' => $id];

            if ($id !== '' && ! in_array($model, $models, true)) {
                $models[] = $model;
            }

            if (count($models) >= $limit) {
                break;
            }
        }

        return $models;
    }

    /** A business id written straight into the job (`companyId` / `company_id` property), when there is one. */
    public function companyIdProperty(): ?string
    {
        if (preg_match('/(?:companyId|company_id)";s:26:"([0-9A-HJKMNP-TV-Z]{26})"/', $this->command, $m)) {
            return $m[1];
        }

        return null;
    }
}
