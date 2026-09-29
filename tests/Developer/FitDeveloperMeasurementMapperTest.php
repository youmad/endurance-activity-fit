<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Developer;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Telemetry\ArrayMeasurement;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperIdentityKind;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMetadata;
use Youmad\Endurance\ActivityFit\Tests\Fixture\FitDeveloperFieldFixture;

final class FitDeveloperMeasurementMapperTest extends TestCase
{
    public function testMapsScalarTextAndArrayDeveloperValues(): void
    {
        $readings = (new FitDeveloperMeasurementMapper())->map(
            (new FitDeveloperFieldFixture())->completeRecord(),
        );

        self::assertCount(3, $readings);

        $scalar = $this->reading(
            readings: $readings,
            fieldDefinitionNumber: 0,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $scalar->measurement,
        );

        self::assertEqualsWithDelta(
            123.4,
            $scalar->measurement->value,
            0.000000001,
        );

        self::assertSame(
            'mm',
            $scalar->measurement->unit->toString(),
        );

        $text = $this->reading(
            readings: $readings,
            fieldDefinitionNumber: 1,
        );

        self::assertInstanceOf(
            TextMeasurement::class,
            $text->measurement,
        );

        self::assertSame(
            'great',
            $text->measurement->value,
        );

        $array = $this->reading(
            readings: $readings,
            fieldDefinitionNumber: 2,
        );

        self::assertInstanceOf(
            ArrayMeasurement::class,
            $array->measurement,
        );

        self::assertSame(
            [1, null, 3],
            $array->measurement->values(),
        );

        self::assertSame(
            'W',
            $array->measurement->unit->toString(),
        );
    }

    public function testBuildsCollisionSafeTypesFromApplicationAndFieldIdentity(): void
    {
        $fixture = new FitDeveloperFieldFixture();
        $mapper = new FitDeveloperMeasurementMapper();

        $first = $mapper->map(
            $fixture->completeRecord(
                applicationId: range(0xA0, 0xAF),
            ),
        );

        $second = $mapper->map(
            $fixture->completeRecord(
                applicationId: range(0xB0, 0xBF),
            ),
        );

        $firstType = $first[0]
            ->measurement
            ->type()
            ->toString();

        $secondType = $second[0]
            ->measurement
            ->type()
            ->toString();

        self::assertSame(
            'fit_developer_application_a0a1a2a3a4a5a6a7a8a9aaabacadaeaf_0_vertical_oscillation',
            $firstType,
        );

        self::assertSame(
            'fit_developer_application_b0b1b2b3b4b5b6b7b8b9babbbcbdbebf_0_vertical_oscillation',
            $secondType,
        );
    }

    public function testCachedDescriptorsStayIsolatedAcrossSequentialProfiles(): void
    {
        $fixture = new FitDeveloperFieldFixture();
        $mapper = new FitDeveloperMeasurementMapper();
        $firstMessage = $fixture->completeRecord(
            applicationId: range(0xA0, 0xAF),
        );

        $first = $mapper->map($firstMessage);
        $cached = $mapper->map($firstMessage);
        $second = $mapper->map(
            $fixture->completeRecord(
                applicationId: range(0xB0, 0xBF),
            ),
        );

        self::assertEquals($first, $cached);

        self::assertSame(
            'fit_developer_application_a0a1a2a3a4a5a6a7a8a9aaabacadaeaf_0_vertical_oscillation',
            $cached[0]
                ->measurement
                ->type()
                ->toString(),
        );

        self::assertSame(
            'fit_developer_application_b0b1b2b3b4b5b6b7b8b9babbbcbdbebf_0_vertical_oscillation',
            $second[0]
                ->measurement
                ->type()
                ->toString(),
        );
    }

    public function testPreservesApplicationAndFieldMetadata(): void
    {
        $readings = (new FitDeveloperMeasurementMapper())->map(
            (new FitDeveloperFieldFixture())->completeRecord(),
        );

        $reading = $this->reading(
            readings: $readings,
            fieldDefinitionNumber: 0,
        );

        $metadata = $reading->metadata;

        self::assertInstanceOf(
            FitDeveloperMeasurementMetadata::class,
            $metadata,
        );

        self::assertSame(
            FitDeveloperIdentityKind::ApplicationId,
            $metadata->application->kind,
        );

        self::assertSame(
            'a0a1a2a3a4a5a6a7a8a9aaabacadaeaf',
            $metadata->application->identifier,
        );

        self::assertSame(
            1,
            $metadata->application->manufacturerId,
        );

        self::assertSame(
            110,
            $metadata->application->applicationVersion,
        );

        self::assertSame(
            'Vertical Oscillation',
            $metadata->fieldName,
        );

        self::assertSame(20, $metadata->nativeMessageNumber);
        self::assertSame(39, $metadata->nativeFieldNumber);
    }

    public function testMapsBlankUnitsAsDimensionlessWhilePreservingMetadata(): void
    {
        $fixture = new FitDeveloperFieldFixture();

        foreach ([['', null], [' ', ' ']] as [$units, $decodedUnits]) {
            $readings = (new FitDeveloperMeasurementMapper())->map(
                $fixture->completeRecord(
                    scalarUnits: $units,
                ),
            );

            $reading = $this->reading(
                readings: $readings,
                fieldDefinitionNumber: 0,
            );

            self::assertInstanceOf(ScalarMeasurement::class, $reading->measurement);
            self::assertSame(
                '1',
                $reading->measurement->unit->toString(),
            );

            $metadata = $reading->metadata;

            self::assertInstanceOf(
                FitDeveloperMeasurementMetadata::class,
                $metadata,
            );

            self::assertSame($decodedUnits, $metadata->units);
        }
    }

    public function testFallsBackToDeveloperIdWhenApplicationIdIsUnavailable(): void
    {
        $readings = (new FitDeveloperMeasurementMapper())->map(
            (new FitDeveloperFieldFixture())->recordUsingDeveloperId(),
        );

        $metadata = $readings[0]->metadata;

        self::assertInstanceOf(
            FitDeveloperMeasurementMetadata::class,
            $metadata,
        );

        self::assertSame(
            FitDeveloperIdentityKind::DeveloperId,
            $metadata->application->kind,
        );
    }

    public function testSkipsDeveloperFieldsWithoutStableApplicationIdentity(): void
    {
        $readings = (new FitDeveloperMeasurementMapper())->map(
            (new FitDeveloperFieldFixture())
                ->recordWithoutStableIdentity(),
        );

        self::assertSame([], $readings);
    }

    public function testSkipsUnresolvedDeveloperFields(): void
    {
        $readings = (new FitDeveloperMeasurementMapper())->map(
            (new FitDeveloperFieldFixture())->unresolvedRecord(),
        );

        self::assertSame([], $readings);
    }

    /**
     * @param list<MeasurementReading> $readings
     */
    private function reading(
        array $readings,
        int $fieldDefinitionNumber,
    ): MeasurementReading {
        foreach ($readings as $reading) {
            $metadata = $reading->metadata;

            if (
                $metadata instanceof FitDeveloperMeasurementMetadata
                && $fieldDefinitionNumber
                    === $metadata->fieldDefinitionNumber
            ) {
                return $reading;
            }
        }

        self::fail(
            sprintf(
                'Developer field %d was not mapped.',
                $fieldDefinitionNumber,
            ),
        );
    }
}
