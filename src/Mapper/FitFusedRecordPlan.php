<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Fit\Component\ResolvedComponentField;
use Youmad\Endurance\Fit\Profiled\ProfiledStandardField;

/** @internal */
final readonly class FitFusedRecordPlan
{
    /**
     * @param list<FitFusedRecordFieldSlot>       $slots
     * @param list<FitFusedRecordScalarOperation> $scalarOperations
     * @param list<array{int, ?string, ?string}>  $physicalSignature
     * @param list<array{int, string}>            $componentSignature
     */
    public function __construct(
        public array $slots,
        public ?int $timestampFieldIndex,
        public ?int $latitudeFieldIndex,
        public ?int $longitudeFieldIndex,
        public array $scalarOperations,
        private array $physicalSignature,
        private array $componentSignature,
    ) {
    }

    /**
     * @param list<ProfiledStandardField>  $physicalFields
     * @param list<ResolvedComponentField> $componentFields
     */
    public function matches(array $physicalFields, array $componentFields): bool
    {
        if (
            count($physicalFields) !== count($this->physicalSignature)
            || count($componentFields) !== count($this->componentSignature)
        ) {
            return false;
        }

        foreach ($physicalFields as $index => $field) {
            if ([
                $field->fieldNumber(),
                $field->name(),
                $field->mainName(),
            ] !== $this->physicalSignature[$index]) {
                return false;
            }
        }

        foreach ($componentFields as $index => $field) {
            if ([
                $field->targetFieldNumber(),
                $field->name(),
            ] !== $this->componentSignature[$index]) {
                return false;
            }
        }

        return true;
    }
}
