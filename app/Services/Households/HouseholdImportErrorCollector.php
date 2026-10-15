<?php

namespace App\Services\Households;

use Generator;
use RuntimeException;

final class HouseholdImportErrorCollector
{
    /** @var resource */
    private $stream;

    private string $path;

    private string $rowBitmap;

    private int $errorCount = 0;

    private int $errorRowCount = 0;

    private bool $hasFileLevelError = false;

    /** @var list<string> */
    private array $summaryMessages = [];

    public function __construct(int $highestRow)
    {
        $path = tempnam(sys_get_temp_dir(), 'pcgd-import-errors-');
        if ($path === false) {
            throw new RuntimeException('Không thể tạo vùng lưu tạm cho lỗi nhập file.');
        }

        $stream = fopen($path, 'wb+');
        if ($stream === false) {
            @unlink($path);
            throw new RuntimeException('Không thể mở vùng lưu tạm cho lỗi nhập file.');
        }

        $this->path = $path;
        $this->stream = $stream;
        $this->rowBitmap = str_repeat("\0", intdiv(max(0, $highestRow) + 8, 8));
    }

    public function add(?int $row, string $message): void
    {
        $encoded = json_encode(['row' => $row, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $offset = 0;
        while ($offset < strlen($encoded)) {
            $written = fwrite($this->stream, substr($encoded, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Không thể ghi vùng lưu tạm cho lỗi nhập file.');
            }
            $offset += $written;
        }

        $this->errorCount++;
        if ($row === null) {
            $this->hasFileLevelError = true;
        } else {
            $byteIndex = intdiv($row, 8);
            $bit = 1 << ($row % 8);
            $byte = ord($this->rowBitmap[$byteIndex]);
            if (($byte & $bit) === 0) {
                $this->rowBitmap[$byteIndex] = chr($byte | $bit);
                $this->errorRowCount++;
            }
        }

        if (count($this->summaryMessages) < 10 && ! in_array($message, $this->summaryMessages, true)) {
            $this->summaryMessages[] = $message;
        }
    }

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function errorRowCount(): int
    {
        return $this->errorRowCount + (int) $this->hasFileLevelError;
    }

    public function summary(): string
    {
        return implode(' ', $this->summaryMessages);
    }

    /** @return Generator<array{row: int|null, message: string, first_for_row: bool}> */
    public function errors(): Generator
    {
        fflush($this->stream);
        rewind($this->stream);
        $writtenRows = str_repeat("\0", strlen($this->rowBitmap));
        $fileLevelErrorWritten = false;

        while (($line = fgets($this->stream)) !== false) {
            $error = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $row = $error['row'];
            if ($row === null) {
                $firstForRow = ! $fileLevelErrorWritten;
                $fileLevelErrorWritten = true;
            } else {
                $byteIndex = intdiv($row, 8);
                $bit = 1 << ($row % 8);
                $byte = ord($writtenRows[$byteIndex]);
                $firstForRow = ($byte & $bit) === 0;
                $writtenRows[$byteIndex] = chr($byte | $bit);
            }

            yield [...$error, 'first_for_row' => $firstForRow];
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }
}
