<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Conversion\FitCoordinateConverter;
use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Record\FitRecordMeasurementRegistry;
use Youmad\Endurance\Fit\Component\ComponentResolvedDataMessage;
use Youmad\Endurance\Fit\Component\ResolvedComponentField;
use Youmad\Endurance\Fit\Decoded\DecodedFieldElements;
use Youmad\Endurance\Fit\Decoded\ValidFieldElement;
use Youmad\Endurance\Fit\Decoder\FitComponentExtractor;
use Youmad\Endurance\Fit\Decoder\FitComponentValueResolver;
use Youmad\Endurance\Fit\Decoder\FitDataMessageDecoder;
use Youmad\Endurance\Fit\Decoder\FitDeveloperFieldResolver;
use Youmad\Endurance\Fit\Decoder\FitProfileNormalizer;
use Youmad\Endurance\Fit\Profiled\ProfiledDataMessage;
use Youmad\Endurance\Fit\Profiled\ProfiledStandardField;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\Fit\Unified\FieldValueOrigin;

/**
 * Production Record hot path.
 *
 * FIT decoding, Profile/subfield normalization and component value resolution
 * remain authoritative. Record-specific Activity projection then executes
 * directly from those values, avoiding standard type-resolution objects,
 * UnifiedDataMessage assembly and the generic name-indexing Record mapper.
 * Developer fields use the shared production resolver and mapper.
 */
final class FitFusedRecordMessageProcessor
{
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;
    /** @var \WeakMap<MessageDefinition, list<FitFusedRecordPlan>> */
    private \WeakMap $plans;

    public function __construct(
        private readonly FitDataMessageDecoder $decoder,
        private readonly FitProfileNormalizer $profiles,
        private readonly FitComponentExtractor $components,
        private readonly FitComponentValueResolver $componentValues,
        private readonly FitDeveloperFieldResolver $developerFields,
        private readonly FitRecordMeasurementRegistry $measurements =
            new FitRecordMeasurementRegistry(),
        private readonly FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private readonly FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
        private readonly FitCoordinateConverter $coordinates =
            new FitCoordinateConverter(),
        private readonly FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
        $this->plans = new \WeakMap();
    }

    public function process(RawDataRecord $record): FitRecordImportProjection
    {
        if (self::RECORD_GLOBAL_MESSAGE_NUMBER !== $record->globalMessageNumber()) {
            throw new \LogicException(sprintf('Fused FIT Record processor does not support global message %d.', $record->globalMessageNumber()));
        }

        $decoded = $this->decoder->decode($record);
        [$profiled, $componentContainers] =
            $this->profiles->normalizeForRecordPipeline($decoded);
        $resolvedComponents = $this->componentValues->resolve(
            $this->components->extractForPipeline(
                message: $profiled,
                containers: $componentContainers,
            ),
        );
        $developerFields = $this->developerFields->resolveRecord($profiled);
        $plan = $this->plan(
            message: $profiled,
            components: $resolvedComponents,
        );

        return $this->mapWithPlan(
            message: $profiled,
            components: $resolvedComponents,
            developerFields: $developerFields,
            plan: $plan,
        );
    }

    private function plan(
        ProfiledDataMessage $message,
        ComponentResolvedDataMessage $components,
    ): FitFusedRecordPlan {
        $definition = $message->source->source->definition();
        $physicalFields = $message->standardFields();
        $componentFields = $components->components();
        $cachedPlans = $this->plans[$definition] ?? [];

        foreach ($cachedPlans as $cached) {
            if ($cached->matches($physicalFields, $componentFields)) {
                return $cached;
            }
        }

        $compiled = $this->compilePlan(
            physicalFields: $physicalFields,
            componentFields: $componentFields,
        );
        $cachedPlans[] = $compiled;
        $this->plans[$definition] = $cachedPlans;

        return $compiled;
    }

    /**
     * @param list<ProfiledStandardField>  $physicalFields
     * @param list<ResolvedComponentField> $componentFields
     */
    private function compilePlan(
        array $physicalFields,
        array $componentFields,
    ): FitFusedRecordPlan {
        /** @var list<FitFusedRecordFieldSlotBuilder> $slots */
        $slots = [];
        /** @var array<int, int> $slotByFieldNumber */
        $slotByFieldNumber = [];

        foreach ($physicalFields as $physicalIndex => $field) {
            $fieldNumber = $field->fieldNumber();
            $slotIndex = $slotByFieldNumber[$fieldNumber] ?? null;

            if (null === $slotIndex) {
                $slotIndex = count($slots);
                $slotByFieldNumber[$fieldNumber] = $slotIndex;
                $slots[] = new FitFusedRecordFieldSlotBuilder(
                    fieldNumber: $fieldNumber,
                    physicalIndex: $physicalIndex,
                );

                continue;
            }

            // Decoded messages normally contain one canonical physical field per
            // field number. Preserve the Unified assembler's last-physical-value
            // behavior if a hand-built message violates that normal shape.
            $slots[$slotIndex]->physicalIndex = $physicalIndex;
        }

        foreach ($componentFields as $componentIndex => $component) {
            $fieldNumber = $component->targetFieldNumber();
            $slotIndex = $slotByFieldNumber[$fieldNumber] ?? null;

            if (null === $slotIndex) {
                $slotIndex = count($slots);
                $slotByFieldNumber[$fieldNumber] = $slotIndex;
                $slots[] = new FitFusedRecordFieldSlotBuilder(
                    fieldNumber: $fieldNumber,
                    physicalIndex: null,
                );
            }

            $slots[$slotIndex]->componentIndexes[] = $componentIndex;
        }

        /** @var array<string, int> $fieldIndexByName */
        $fieldIndexByName = [];
        $compiledSlots = [];

        foreach ($slots as $slotIndex => $slot) {
            $physical = null === $slot->physicalIndex
                ? null
                : $physicalFields[$slot->physicalIndex];
            $selectedName = $physical?->name();

            if (null === $selectedName && [] !== $slot->componentIndexes) {
                $selectedName = $componentFields[
                    $slot->componentIndexes[0]
                ]->name();
            }

            if (null !== $selectedName) {
                $this->indexFieldName(
                    fieldIndexByName: $fieldIndexByName,
                    fieldName: $selectedName,
                    fieldIndex: $slotIndex,
                );
            }

            $mainName = $physical?->mainName();

            if (null !== $mainName && $mainName !== $selectedName) {
                $this->indexFieldName(
                    fieldIndexByName: $fieldIndexByName,
                    fieldName: $mainName,
                    fieldIndex: $slotIndex,
                );
            }

            $compiledSlots[] = new FitFusedRecordFieldSlot(
                fieldNumber: $slot->fieldNumber,
                physicalIndex: $slot->physicalIndex,
                componentIndexes: $slot->componentIndexes,
            );
        }

        $operations = [];

        foreach ($this->measurements->scalarDefinitions() as $definition) {
            $candidates = [];

            foreach ($definition->fieldNames() as $fieldName) {
                if (!array_key_exists($fieldName, $fieldIndexByName)) {
                    continue;
                }

                $additiveFieldName = $definition
                    ->fallbackAdditiveFieldName($fieldName);
                $additiveFieldIndex = null === $additiveFieldName
                    || !array_key_exists($additiveFieldName, $fieldIndexByName)
                    ? null
                    : $fieldIndexByName[$additiveFieldName];

                $candidates[] = new FitFusedRecordCandidate(
                    fieldIndex: $fieldIndexByName[$fieldName],
                    fieldName: $fieldName,
                    additiveFieldIndex: $additiveFieldIndex,
                    additiveFieldName: $additiveFieldName,
                );
            }

            if ([] !== $candidates) {
                $operations[] = new FitFusedRecordScalarOperation(
                    definition: $definition,
                    candidates: $candidates,
                );
            }
        }

        return new FitFusedRecordPlan(
            slots: $compiledSlots,
            timestampFieldIndex: $fieldIndexByName['timestamp'] ?? null,
            latitudeFieldIndex: $fieldIndexByName['position_lat'] ?? null,
            longitudeFieldIndex: $fieldIndexByName['position_long'] ?? null,
            scalarOperations: $operations,
            physicalSignature: array_map(
                static fn (ProfiledStandardField $field): array => [
                    $field->fieldNumber(),
                    $field->name(),
                    $field->mainName(),
                ],
                $physicalFields,
            ),
            componentSignature: array_map(
                static fn (ResolvedComponentField $field): array => [
                    $field->targetFieldNumber(),
                    $field->name(),
                ],
                $componentFields,
            ),
        );
    }

    /** @param array<string, int> $fieldIndexByName */
    private function indexFieldName(
        array &$fieldIndexByName,
        string $fieldName,
        int $fieldIndex,
    ): void {
        if (!array_key_exists($fieldName, $fieldIndexByName)) {
            $fieldIndexByName[$fieldName] = $fieldIndex;

            return;
        }

        if ($fieldIndexByName[$fieldName] !== $fieldIndex) {
            $fieldIndexByName[$fieldName] = -1;
        }
    }

    /**
     * @param list<\Youmad\Endurance\Fit\Unified\UnifiedDeveloperField> $developerFields
     */
    private function mapWithPlan(
        ProfiledDataMessage $message,
        ComponentResolvedDataMessage $components,
        array $developerFields,
        FitFusedRecordPlan $plan,
    ): FitRecordImportProjection {
        $physicalFields = $message->standardFields();
        $componentFields = $components->components();
        $warnings = [];

        $timestampOrigin = null;
        $timestampValue = $this->selectedScalarByIndex(
            fieldName: 'timestamp',
            fieldIndex: $plan->timestampFieldIndex,
            slots: $plan->slots,
            physicalFields: $physicalFields,
            componentFields: $componentFields,
            origin: $timestampOrigin,
        );

        $missingTimestamp =
            null === $timestampValue
            || null === $timestampOrigin;

        if ($missingTimestamp) {
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::RecordWithoutTimestampSkipped,
                message: 'FIT record without a usable timestamp was skipped.',
                context: [
                    'sequence' => $message->sequence(),
                    'byteOffset' => $message->byteOffset(),
                ],
            );
        }

        $latitudeOrigin = null;
        $longitudeOrigin = null;
        $latitude = null;
        $longitude = null;
        $coordinateSelectionAmbiguous = false;

        try {
            $latitude = $this->selectedScalarByIndex(
                fieldName: 'position_lat',
                fieldIndex: $plan->latitudeFieldIndex,
                slots: $plan->slots,
                physicalFields: $physicalFields,
                componentFields: $componentFields,
                origin: $latitudeOrigin,
            );
            $longitude = $this->selectedScalarByIndex(
                fieldName: 'position_long',
                fieldIndex: $plan->longitudeFieldIndex,
                slots: $plan->slots,
                physicalFields: $physicalFields,
                componentFields: $componentFields,
                origin: $longitudeOrigin,
            );
        } catch (InvalidFitActivityMessage $exception) {
            if (!$missingTimestamp) {
                throw $exception;
            }

            // The reference warning detector suppresses coordinate ambiguity,
            // and the Record mapper returns before coordinate mapping when the
            // timestamp is unusable.
            $coordinateSelectionAmbiguous = true;
        }

        if (
            !$coordinateSelectionAmbiguous
            && (null === $latitude) !== (null === $longitude)
        ) {
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::RecordCoordinatePairSkipped,
                message: 'Optional FIT record coordinate pair was ignored because only one coordinate field resolved to a scalar value.',
                context: [
                    'sequence' => $message->sequence(),
                    'byteOffset' => $message->byteOffset(),
                    'latitudeField' => 'position_lat',
                    'longitudeField' => 'position_long',
                    'resolvedField' => null === $latitude
                        ? 'position_long'
                        : 'position_lat',
                    'unresolvedField' => null === $latitude
                        ? 'position_lat'
                        : 'position_long',
                ],
            );
        }

        if ($missingTimestamp) {
            return new FitRecordImportProjection(
                items: [],
                warnings: $warnings,
            );
        }

        $observedAt = $this->dateTimes->convertScalar($timestampValue);
        $readings = [];

        $this->appendPosition(
            latitude: $latitude,
            latitudeOrigin: $latitudeOrigin,
            longitude: $longitude,
            longitudeOrigin: $longitudeOrigin,
            readings: $readings,
        );

        foreach ($plan->scalarOperations as $operation) {
            $selectedCandidate = null;
            $selectedOrigin = null;
            $scalarValue = null;

            foreach ($operation->candidates as $candidate) {
                $candidateOrigin = null;
                $candidateValue = $this->selectedScalarByIndex(
                    fieldName: $candidate->fieldName,
                    fieldIndex: $candidate->fieldIndex,
                    slots: $plan->slots,
                    physicalFields: $physicalFields,
                    componentFields: $componentFields,
                    origin: $candidateOrigin,
                );

                if (null === $candidateValue || null === $candidateOrigin) {
                    continue;
                }

                $selectedCandidate = $candidate;
                $selectedOrigin = $candidateOrigin;
                $scalarValue = $candidateValue;
                break;
            }

            if (
                null === $selectedCandidate
                || null === $selectedOrigin
                || null === $scalarValue
            ) {
                continue;
            }

            if (!is_int($scalarValue) && !is_float($scalarValue)) {
                throw InvalidFitActivityMessage::invalidScalar(fieldName: $selectedCandidate->fieldName, value: $scalarValue);
            }

            $origins = [$selectedOrigin];
            $additiveFieldIndex = $selectedCandidate->additiveFieldIndex;

            if (null !== $additiveFieldIndex) {
                $additiveOrigin = null;
                $additiveValue = $this->selectedScalarByIndex(
                    fieldName: $selectedCandidate->additiveFieldName
                        ?? $selectedCandidate->fieldName,
                    fieldIndex: $additiveFieldIndex,
                    slots: $plan->slots,
                    physicalFields: $physicalFields,
                    componentFields: $componentFields,
                    origin: $additiveOrigin,
                );

                if (null !== $additiveValue && null !== $additiveOrigin) {
                    if (!is_int($additiveValue) && !is_float($additiveValue)) {
                        throw InvalidFitActivityMessage::invalidScalar(fieldName: $selectedCandidate->additiveFieldName ?? $selectedCandidate->fieldName, value: $additiveValue);
                    }

                    $scalarValue += $additiveValue;
                    $origins[] = $additiveOrigin;
                }
            }

            $readings[] = $this->readings->create(
                new ScalarMeasurement(
                    measurementType: $operation->definition->measurementType,
                    value: $scalarValue,
                    unit: $operation->definition->unit,
                ),
                ...$origins,
            );
        }

        $readings = [
            ...$readings,
            ...$this->developerMeasurements->mapFields($developerFields),
        ];

        if ([] === $readings) {
            return new FitRecordImportProjection(
                items: [],
                warnings: $warnings,
                timestamp: (int) $timestampValue,
            );
        }

        return new FitRecordImportProjection(
            items: [new ObservationItem(
                ActivityObservation::fromReadings(
                    $observedAt,
                    ...$readings,
                ),
            )],
            warnings: $warnings,
            timestamp: (int) $timestampValue,
        );
    }

    /**
     * @param list<FitFusedRecordFieldSlot> $slots
     * @param list<ProfiledStandardField>   $physicalFields
     * @param list<ResolvedComponentField>  $componentFields
     */
    private function selectedScalarByIndex(
        string $fieldName,
        ?int $fieldIndex,
        array $slots,
        array $physicalFields,
        array $componentFields,
        ?FieldValueOrigin &$origin,
    ): int|float|string|null {
        if (-1 === $fieldIndex) {
            throw InvalidFitActivityMessage::duplicateFieldName($fieldName);
        }

        if (null === $fieldIndex) {
            $origin = null;

            return null;
        }

        return $this->selectedScalar(
            slot: $slots[$fieldIndex],
            physicalFields: $physicalFields,
            componentFields: $componentFields,
            origin: $origin,
        );
    }

    /**
     * @param list<ProfiledStandardField>  $physicalFields
     * @param list<ResolvedComponentField> $componentFields
     */
    private function selectedScalar(
        FitFusedRecordFieldSlot $slot,
        array $physicalFields,
        array $componentFields,
        ?FieldValueOrigin &$origin,
    ): int|float|string|null {
        $origin = null;
        $physical = null === $slot->physicalIndex
            ? null
            : $physicalFields[$slot->physicalIndex];

        if (null !== $physical && $this->physicalHasUsableValue($physical)) {
            $value = $this->physicalScalar($physical);

            if (null !== $value) {
                $origin = FieldValueOrigin::Physical;
            }

            return $value;
        }

        foreach ($slot->componentIndexes as $componentIndex) {
            $origin = FieldValueOrigin::Component;

            return $componentFields[$componentIndex]->physicalValue;
        }

        if (null === $physical) {
            return null;
        }

        $value = $this->physicalScalar($physical);

        if (null !== $value) {
            $origin = FieldValueOrigin::Physical;
        }

        return $value;
    }

    private function physicalHasUsableValue(ProfiledStandardField $field): bool
    {
        if (!$field->value instanceof DecodedFieldElements) {
            return false;
        }

        foreach ($field->value->elements() as $element) {
            if ($element instanceof ValidFieldElement) {
                return true;
            }
        }

        return false;
    }

    private function physicalScalar(
        ProfiledStandardField $field,
    ): int|float|string|null {
        if (!$field->value instanceof DecodedFieldElements) {
            return null;
        }

        $elements = $field->value->elements();

        if (1 !== count($elements)) {
            return null;
        }

        $element = $elements[0];

        return $element instanceof ValidFieldElement
            ? $element->value
            : null;
    }

    /** @param list<MeasurementReading> $readings */
    private function appendPosition(
        int|float|string|null $latitude,
        ?FieldValueOrigin $latitudeOrigin,
        int|float|string|null $longitude,
        ?FieldValueOrigin $longitudeOrigin,
        array &$readings,
    ): void {
        if (
            null === $latitude
            || null === $longitude
            || null === $latitudeOrigin
            || null === $longitudeOrigin
        ) {
            return;
        }

        $readings[] = $this->readings->create(
            new PositionMeasurement(
                $this->coordinates->convertScalars(
                    latitude: $latitude,
                    longitude: $longitude,
                ),
            ),
            $latitudeOrigin,
            $longitudeOrigin,
        );
    }
}
