<?php

namespace App\Domain\Catalogue\Import;

use Generator;
use RuntimeException;
use SplFileObject;

/**
 * Reads an uploaded product CSV: UTF-8 (a byte-order mark is skipped; Windows-1252 cells are converted), comma,
 * semicolon or tab separated (from the header line), the first line is the header. Blank lines are skipped.
 * Rows are read from a byte offset, so a queued import never re-reads what it already applied.
 */
final class CsvReader
{
    private SplFileObject $file;

    private string $delimiter;

    private int $dataStart;

    public function __construct(string $path)
    {
        if (! is_readable($path)) {
            throw new RuntimeException('The import file is missing.');
        }

        $this->file = new SplFileObject($path, 'r');
        $first = (string) $this->file->fgets();
        $bom = str_starts_with($first, "\xEF\xBB\xBF") ? 3 : 0;
        $counts = ['' => 0, ',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $this->delimiter = (string) (array_key_first($counts) ?: ',');
        $this->file->fseek($bom);
        $this->file->fgetcsv($this->delimiter, '"', '');
        $this->dataStart = (int) $this->file->ftell();
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        $this->file->rewind();
        $line = (string) $this->file->fgets();
        $line = str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
        $cells = str_getcsv(rtrim($line, "\r\n"), $this->delimiter, '"', '');

        return array_map(fn ($cell) => self::clean((string) $cell), $cells);
    }

    /**
     * Data rows from a byte offset (0 = the first data row), each with its line number in the file (the header is
     * line 1) and the byte offset after it.
     *
     * @return Generator<int, array{line: int, cells: list<string>, next: int}>
     */
    public function rows(int $offset = 0, int $firstLine = 2): Generator
    {
        $this->file->fseek(max($offset, $this->dataStart));
        $line = $firstLine - 1;

        while (! $this->file->eof()) {
            $cells = $this->file->fgetcsv($this->delimiter, '"', '');
            $line++;

            if (! is_array($cells) || $cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            yield ['line' => $line, 'cells' => array_map(fn ($cell) => self::clean((string) $cell), $cells), 'next' => (int) $this->file->ftell()];
        }
    }

    private static function clean(string $cell): string
    {
        if (! mb_check_encoding($cell, 'UTF-8')) {
            $cell = (string) mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
        }

        return trim($cell);
    }
}
