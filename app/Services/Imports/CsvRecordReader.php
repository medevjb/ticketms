<?php

namespace App\Services\Imports;

use UnexpectedValueException;

class CsvRecordReader
{
    /** @var resource */
    private $stream;

    private string $buffer = '';

    private int $bufferPosition = 0;

    private int $consumedBytes = 0;

    /** @param resource $stream */
    public function __construct(mixed $stream, int $offset)
    {
        if (! is_resource($stream) || fseek($stream, $offset) !== 0) {
            throw new UnexpectedValueException('The import source could not be opened at its saved checkpoint.');
        }

        $this->stream = $stream;
    }

    /** @return array{record: string, next_offset: int}|null */
    public function nextRecord(int $startOffset, int $maximumBytes): ?array
    {
        $record = '';
        $recordBytes = 0;
        $insideQuotes = false;
        $atFieldStart = true;
        $afterQuotedField = false;
        $hasBytes = false;

        while (($byte = $this->readByte()) !== null) {
            $hasBytes = true;
            $recordBytes++;
            if ($recordBytes > $maximumBytes) {
                throw new UnexpectedValueException('A CSV record exceeds the maximum permitted size.');
            }

            if ($byte === '"') {
                if ($insideQuotes && $this->peekByte() === '"') {
                    $this->readByte();
                    $recordBytes++;
                    if ($recordBytes > $maximumBytes) {
                        throw new UnexpectedValueException('A CSV record exceeds the maximum permitted size.');
                    }
                    $record .= '""';
                } else {
                    if ($insideQuotes) {
                        $insideQuotes = false;
                        $afterQuotedField = true;
                    } else {
                        if (! $atFieldStart) {
                            throw new UnexpectedValueException('The CSV contains an unexpected quote.');
                        }
                        $insideQuotes = true;
                        $afterQuotedField = false;
                    }
                    $record .= $byte;
                }
                $atFieldStart = false;

                continue;
            }

            if (! $insideQuotes && $byte === "\n") {
                $record = str_ends_with($record, "\r") ? substr($record, 0, -1) : $record;
                $nextOffset = $startOffset + $this->consumedBytes;
                $this->consumedBytes = 0;

                return ['record' => $record, 'next_offset' => $nextOffset];
            }

            if (! $insideQuotes && $byte === ',') {
                $atFieldStart = true;
                $afterQuotedField = false;
            } elseif ($byte !== "\r") {
                if (! $insideQuotes && $afterQuotedField) {
                    throw new UnexpectedValueException('The CSV contains text after a closing quote.');
                }
                $atFieldStart = false;
            }

            $record .= $byte;
        }

        if (! $hasBytes) {
            return null;
        }
        if ($insideQuotes) {
            throw new UnexpectedValueException('The CSV ends inside a quoted field.');
        }

        $nextOffset = $startOffset + $this->consumedBytes;
        $this->consumedBytes = 0;

        return ['record' => $record, 'next_offset' => $nextOffset];
    }

    private function readByte(): ?string
    {
        if ($this->bufferPosition >= strlen($this->buffer)) {
            $buffer = fread($this->stream, 8192);
            $this->bufferPosition = 0;
            if (! is_string($buffer) || $buffer === '') {
                return null;
            }
            $this->buffer = $buffer;
        }

        $this->consumedBytes++;

        return $this->buffer[$this->bufferPosition++];
    }

    private function peekByte(): ?string
    {
        $byte = $this->readByte();
        if ($byte !== null) {
            $this->bufferPosition--;
            $this->consumedBytes--;
        }

        return $byte;
    }
}
