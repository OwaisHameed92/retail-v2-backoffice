<?php

namespace App\Domain\TillData\Generator;

use RuntimeException;

/**
 * The contract folders the generator reads (definitions.php `contract` plus an optional `overlay`).
 *
 * An overlay is a pack that only adds to the released contract (W2: the salon pack from the till's
 * `feat/salon-barber` branch, not released). Its files win over the contract's: a schema or sample it carries replaces
 * the contract's copy (the shared files it changed are supersets), and every file it lacks is read from the contract.
 */
final class ContractFiles
{
    public function __construct(private readonly string $basePath, private readonly string $contract, private readonly ?string $overlay = null) {}

    /** The folder a file is read from (relative to the project): the overlay's copy when it has one. */
    public function sourceOf(string $relative): string
    {
        return $this->overlay !== null && is_file($this->basePath.'/'.$this->overlay.'/'.$relative) ? $this->overlay : $this->contract;
    }

    /** The folder an entity's schema is read from. */
    public function schemaSource(string $entity): string
    {
        return $this->sourceOf("schemas/entities/{$entity}.schema.json");
    }

    /** What the generated files say they came from: the contract, plus the overlay when there is one. */
    public function label(): string
    {
        return $this->overlay === null ? $this->contract : "{$this->contract}\n * + {$this->overlay}";
    }

    /**
     * Entity name => schema file path, sorted by name; the overlay's schema wins.
     *
     * @return array<string, string>
     */
    public function entitySchemas(): array
    {
        $files = [];

        foreach (array_filter([$this->contract, $this->overlay]) as $folder) {
            foreach (glob($this->basePath.'/'.$folder.'/schemas/entities/*.schema.json') ?: [] as $file) {
                $files[basename($file, '.schema.json')] = 'schemas/entities/'.basename($file);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(string $relative): array
    {
        $path = $this->basePath.'/'.$this->sourceOf($relative).'/'.$relative;
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
