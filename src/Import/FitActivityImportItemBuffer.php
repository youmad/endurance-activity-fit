<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;

final class FitActivityImportItemBuffer
{
    private const int ITEMS_PER_CHUNK = 10;

    /** @var resource|null */
    private $stream;

    /** @var list<ActivityImportItem> */
    private array $pending = [];

    public function __construct()
    {
        $stream = tmpfile();

        if (false === $stream) {
            throw new \RuntimeException('Failed to create a temporary FIT activity import buffer.');
        }

        $this->stream = $stream;
    }

    public function append(ActivityImportItem $item): void
    {
        $this->pending[] = $item;

        if (self::ITEMS_PER_CHUNK <= count($this->pending)) {
            $this->flushPending();
        }
    }

    /** @return \Generator<int, ActivityImportItem> */
    public function items(): \Generator
    {
        $this->flushPending();
        $stream = $this->stream();

        if (0 !== fseek($stream, 0)) {
            throw new \RuntimeException('Failed to rewind the FIT activity import buffer.');
        }

        $sequence = 0;

        while (true) {
            $lengthBytes = fread($stream, 4);

            if (false === $lengthBytes) {
                throw new \RuntimeException('Failed to read the FIT activity import buffer.');
            }

            if ('' === $lengthBytes) {
                break;
            }

            if (4 !== strlen($lengthBytes)) {
                throw new \RuntimeException('FIT activity import buffer contains a truncated chunk length.');
            }

            $decoded = unpack('Nlength', $lengthBytes);

            if (false === $decoded || !isset($decoded['length'])) {
                throw new \RuntimeException('Failed to decode a FIT activity import buffer chunk length.');
            }

            $payload = $this->readExact($decoded['length']);
            $items = unserialize(
                $payload,
                ['allowed_classes' => true],
            );

            if (!is_array($items)) {
                throw new \RuntimeException('FIT activity import buffer contains an invalid chunk.');
            }

            foreach ($items as $item) {
                if (!$item instanceof ActivityImportItem) {
                    throw new \RuntimeException('FIT activity import buffer contains an invalid item.');
                }

                yield $sequence++ => $item;
            }
        }
    }

    public function close(): void
    {
        if (null === $this->stream) {
            return;
        }

        fclose($this->stream);
        $this->stream = null;
        $this->pending = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    private function flushPending(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $payload = serialize($this->pending);
        $length = strlen($payload);

        if ($length > 0xFFFF_FFFF) {
            throw new \RuntimeException('Serialized FIT activity import chunk is too large.');
        }

        $this->writeExact(pack('N', $length));
        $this->writeExact($payload);
        $this->pending = [];
    }

    /** @return resource */
    private function stream()
    {
        if (null === $this->stream) {
            throw new \RuntimeException('FIT activity import buffer is already closed.');
        }

        return $this->stream;
    }

    private function writeExact(string $bytes): void
    {
        $stream = $this->stream();
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));

            if (false === $written || 0 === $written) {
                throw new \RuntimeException('Failed to write the FIT activity import buffer.');
            }

            $offset += $written;
        }
    }

    private function readExact(int $length): string
    {
        $stream = $this->stream();
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));

            if (false === $chunk || '' === $chunk) {
                throw new \RuntimeException('FIT activity import buffer contains a truncated chunk payload.');
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }
}
