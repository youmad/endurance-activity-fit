<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Developer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperApplicationIdentity;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMetadata;
use Youmad\Endurance\Fit\Developer\DeveloperDataProfile;

final class FitDeveloperMeasurementMetadataTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function identityKinds(): iterable
    {
        yield 'application id' => [true];
        yield 'developer id' => [false];
    }

    #[DataProvider('identityKinds')]
    public function testExplicitVersionedRepresentationPreservesAllFields(bool $applicationId): void
    {
        $application = FitDeveloperApplicationIdentity::fromProfile(DeveloperDataProfile::create(
            developerDataIndex: 2,
            developerId: [1, 2],
            applicationId: $applicationId ? [10, 11] : null,
            manufacturerId: 1,
            applicationVersion: 42,
        ));
        self::assertNotNull($application);
        $metadata = new FitDeveloperMeasurementMetadata(
            application: $application,
            fieldDefinitionNumber: 3,
            fieldName: 'pressure',
            units: 'psi',
            nativeMessageNumber: 20,
            nativeFieldNumber: 39,
            componentIndex: 0,
            componentName: 'front_pressure',
            componentBits: 12,
            componentAccumulated: true,
        );
        self::assertSame('fit.developer_field', $metadata->metadataType());
        self::assertSame(1, $metadata->metadataVersion());
        self::assertSame([
            'application' => [
                'kind' => $applicationId ? 'application_id' : 'developer_id',
                'identifier' => $applicationId ? '0a0b' : '0102',
                'developer_data_index' => 2,
                'manufacturer_id' => 1,
                'application_version' => 42,
            ],
            'field_definition_number' => 3,
            'field_name' => 'pressure',
            'units' => 'psi',
            'native_message_number' => 20,
            'native_field_number' => 39,
            'component_index' => 0,
            'component_name' => 'front_pressure',
            'component_bits' => 12,
            'component_accumulated' => true,
        ], $metadata->metadataData());
    }

    public function testAbsentDetailsRemainExplicitlyNull(): void
    {
        $application = FitDeveloperApplicationIdentity::fromProfile(DeveloperDataProfile::create(
            developerDataIndex: 0,
            developerId: [1],
        ));
        self::assertNotNull($application);
        $metadata = new FitDeveloperMeasurementMetadata($application, 0, 'value', null, null, null);
        $data = $metadata->metadataData();
        self::assertSame([
            'application' => [
                'kind' => 'developer_id',
                'identifier' => '01',
                'developer_data_index' => 0,
                'manufacturer_id' => null,
                'application_version' => null,
            ],
            'field_definition_number' => 0,
            'field_name' => 'value',
            'units' => null,
            'native_message_number' => null,
            'native_field_number' => null,
            'component_index' => null,
            'component_name' => null,
            'component_bits' => null,
            'component_accumulated' => false,
        ], $data);
    }
}
