<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Decoded\DecodedFieldElements;
use Youmad\Endurance\Fit\Decoded\ValidFieldElement;
use Youmad\Endurance\Fit\Unified\ComponentFieldValue;
use Youmad\Endurance\Fit\Unified\FieldValueSelectionPolicy;
use Youmad\Endurance\Fit\Unified\PhysicalFieldValue;
use Youmad\Endurance\Fit\Unified\PreferPhysicalFieldValueSelectionPolicy;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Fit\Unified\UnifiedFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedStandardField;

final class FitFieldValueReader
{
    private ?UnifiedDataMessage $indexedMessage = null;

    /** @var array<string, list<UnifiedStandardField>> */
    private array $fieldsByName = [];

    /** @var array<string, list<UnifiedStandardField>> */
    private array $selectedFieldsByName = [];

    public function __construct(
        private readonly FieldValueSelectionPolicy $selection =
            new PreferPhysicalFieldValueSelectionPolicy(),
    ) {
    }

    /**
     * Return every field name that can be resolved through byName()/byNames().
     * Selected Profile subfield names and their physical main field names are
     * both included because the normal lookup path intentionally accepts both.
     *
     * @return list<string>
     */
    public function fieldNames(
        UnifiedDataMessage $message,
    ): array {
        $this->index($message);

        return array_keys($this->fieldsByName);
    }

    public function byName(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?SelectedFitFieldValue {
        return $this->byNames(
            message: $message,
            fieldNames: [$fieldName],
        );
    }

    /**
     * Field names are checked in priority order. An invalid or absent
     * higher-priority field does not block a valid fallback field.
     *
     * Main field names also match an active Profile subfield so callers that
     * intentionally request the physical field keep seeing its selected value.
     *
     * @param non-empty-list<string> $fieldNames
     */
    public function byNames(
        UnifiedDataMessage $message,
        array $fieldNames,
    ): ?SelectedFitFieldValue {
        return $this->byNamesUsing(
            message: $message,
            fieldNames: $fieldNames,
            matchMainName: true,
        );
    }

    /**
     * Select only fields whose active Profile name matches one of the supplied
     * names. This keeps semantic subfields distinct from their physical main
     * field when the caller needs the Profile-selected meaning and units.
     *
     * @param non-empty-list<string> $fieldNames
     */
    public function bySelectedNames(
        UnifiedDataMessage $message,
        array $fieldNames,
    ): ?SelectedFitFieldValue {
        return $this->byNamesUsing(
            message: $message,
            fieldNames: $fieldNames,
            matchMainName: false,
        );
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private function byNamesUsing(
        UnifiedDataMessage $message,
        array $fieldNames,
        bool $matchMainName,
    ): ?SelectedFitFieldValue {
        foreach ($fieldNames as $fieldName) {
            $field = $this->fieldByName(
                message: $message,
                fieldName: $fieldName,
                matchMainName: $matchMainName,
            );

            if (null === $field) {
                continue;
            }

            foreach ($this->selection->select($field) as $source) {
                $scalar = $this->scalar($source);

                if (null === $scalar) {
                    continue;
                }

                return new SelectedFitFieldValue(
                    source: $source,
                    value: $scalar,
                );
            }
        }

        return null;
    }

    private function fieldByName(
        UnifiedDataMessage $message,
        string $fieldName,
        bool $matchMainName,
    ): ?UnifiedStandardField {
        $this->index($message);

        $matched = ($matchMainName
            ? $this->fieldsByName
            : $this->selectedFieldsByName
        )[$fieldName] ?? [];

        if (count($matched) > 1) {
            throw InvalidFitActivityMessage::duplicateFieldName($fieldName);
        }

        return $matched[0] ?? null;
    }

    private function index(UnifiedDataMessage $message): void
    {
        if ($message === $this->indexedMessage) {
            return;
        }

        $fieldsByName = [];
        $selectedFieldsByName = [];

        foreach ($message->standardFields() as $field) {
            $selectedName = $field->name();

            if (null !== $selectedName) {
                $selectedFieldsByName[$selectedName][] = $field;
                $fieldsByName[$selectedName][] = $field;
            }

            $mainName = $field
                ->physical()
                ?->source
                ->source
                ->mainName();

            if (null === $mainName || $mainName === $selectedName) {
                continue;
            }

            $fieldsByName[$mainName][] = $field;
        }

        $this->indexedMessage = $message;
        $this->fieldsByName = $fieldsByName;
        $this->selectedFieldsByName = $selectedFieldsByName;
    }

    private function scalar(
        UnifiedFieldValue $value,
    ): int|float|string|null {
        if ($value instanceof ComponentFieldValue) {
            return $value->source->value();
        }

        if (!$value instanceof PhysicalFieldValue) {
            return null;
        }

        $decoded = $value
            ->source
            ->source
            ->value;

        if (!$decoded instanceof DecodedFieldElements) {
            return null;
        }

        $elements = $decoded->elements();

        if (1 !== count($elements)) {
            return null;
        }

        $element = $elements[0];

        if (!$element instanceof ValidFieldElement) {
            return null;
        }

        return $element->value;
    }
}
