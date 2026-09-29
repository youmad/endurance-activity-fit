<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Import\FitActivityFileDecoder;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;
use Youmad\Endurance\Fit\Typed\TypedEnumFieldElement;
use Youmad\Endurance\Fit\Typed\TypedFieldElements;

final class FitActivityFileDecoderProfileTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testBothFactoriesUseExplicitMessagesTypesAndRecordScale(): void
    {
        // Deliberately different from the device Profile: a private message
        // and a Record heart-rate scale of 2 instead of the usual raw value.
        $profileSet = GeneratedFitProfileSet::load(
            messagesFile: $this->temporaryPhpFile([
                'source_sha256' => str_repeat('a', 64),
                'messages' => [
                    60_000 => [
                        'name' => 'test_status',
                        'fields' => [
                            $this->field(0, 'status', 'test_state'),
                        ],
                    ],
                    20 => [
                        'name' => 'record',
                        'fields' => [
                            $this->field(253, 'timestamp', 'uint32'),
                            $this->field(3, 'heart_rate', 'uint8', 2.0, 'bpm'),
                        ],
                    ],
                ],
            ]),
            typesFile: $this->temporaryPhpFile([
                'source_sha256' => str_repeat('a', 64),
                'types' => [
                    'test_state' => [
                        'base_type' => 'enum',
                        'values' => [
                            ['value' => 7, 'name' => 'selected', 'aliases' => []],
                        ],
                    ],
                ],
            ]),
        );

        $reference = FitDecoder::standard($profileSet)->open($this->input());
        $optimized = FitActivityFileDecoder::standard($profileSet)->open($this->input());
        $referenceMessages = array_values(iterator_to_array($reference->messages()));
        $optimizedMessages = array_values(iterator_to_array($optimized->messages()));

        self::assertCount(2, $referenceMessages);
        self::assertCount(2, $optimizedMessages);

        foreach ([$referenceMessages[0], $optimizedMessages[0]] as $message) {
            self::assertSame('test_status', $message->name());
            $value = $message->standardField(0)?->physical()?->source->value;
            self::assertInstanceOf(TypedFieldElements::class, $value);
            $element = $value->elements()[0];
            self::assertInstanceOf(TypedEnumFieldElement::class, $element);
            self::assertSame('selected', $element->name());
        }

        $referenceRecord = $referenceMessages[1];
        $optimizedRecord = $optimizedMessages[1];
        self::assertInstanceOf(FitRecordImportProjection::class, $optimizedRecord);
        $referenceItems = (new FitRecordMessageMapper())->map($referenceRecord);

        self::assertSame(serialize($referenceItems), serialize($optimizedRecord->items));
        self::assertCount(1, $referenceItems);
        $item = $referenceItems[0];
        self::assertInstanceOf(ObservationItem::class, $item);
        $readings = $item->observation->readings();
        self::assertCount(1, $readings);
        $measurement = $readings[0]->measurement;
        self::assertInstanceOf(ScalarMeasurement::class, $measurement);
        self::assertSame(63.5, $measurement->value);
        self::assertSame([], $optimizedRecord->warnings);

        foreach ([$reference, $optimized] as $file) {
            self::assertTrue($file->isCompleted());
            self::assertSame(
                $file->trailer()->declaredCrc,
                $file->trailer()->calculatedCrc,
            );
        }
    }

    /** @return array<string, mixed> */
    private function field(
        int $number,
        string $name,
        string $type,
        ?float $scale = null,
        ?string $units = null,
    ): array {
        return [
            'field_number' => $number,
            'name' => $name,
            'type' => $type,
            'scale' => $scale,
            'offset' => null,
            'units' => $units,
            'accumulated' => false,
            'components' => [],
            'subfields' => [],
        ];
    }

    private function input(): ResourceFitInput
    {
        $data = "\x40\x00\x00".pack('v', 60_000)
            ."\x01\x00\x01\x00\x00\x07"
            ."\x41\x00\x00\x14\x00\x02"
            ."\xFD\x04\x86\x03\x01\x02"
            ."\x01".pack('V', 1_000_000_000)."\x7F";
        $header = pack('CCvVa4', 12, 0x20, 21_214, strlen($data), '.FIT');
        $bytes = $header.$data;
        $bytes .= pack('v', FitCrc16::calculate($bytes));
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        self::assertSame(strlen($bytes), fwrite($stream, $bytes));
        rewind($stream);

        return new ResourceFitInput($stream);
    }

    /** @param array<string, mixed> $data */
    private function temporaryPhpFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'explicit-fit-profile-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        self::assertNotFalse(file_put_contents(
            $path,
            '<?php return '.var_export($data, true).';',
        ));

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }

        $this->temporaryFiles = [];
    }
}
