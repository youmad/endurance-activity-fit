<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Developer;

use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Fit\Unified\UnifiedDeveloperComponent;
use Youmad\Endurance\Fit\Unified\UnifiedDeveloperField;

final readonly class FitDeveloperMeasurementTypeFactory
{
    public function create(
        UnifiedDeveloperField $field,
    ): ?MeasurementType {
        $prefix = $this->prefix($field);

        if (
            null === $prefix
            || null === $field->profile
        ) {
            return null;
        }

        return MeasurementType::fromString(
            sprintf(
                '%s_%s',
                $prefix,
                $this->normalizeName($field->profile->name),
            ),
        );
    }

    public function createComponent(
        UnifiedDeveloperField $field,
        UnifiedDeveloperComponent $component,
    ): ?MeasurementType {
        $prefix = $this->prefix($field);

        if (
            null === $prefix
            || null === $field->profile
        ) {
            return null;
        }

        return MeasurementType::fromString(
            sprintf(
                '%s_%s_component_%d_%s',
                $prefix,
                $this->normalizeName($field->profile->name),
                $component->componentIndex,
                $this->normalizeName($component->name()),
            ),
        );
    }

    private function prefix(
        UnifiedDeveloperField $field,
    ): ?string {
        if (
            null === $field->developerData
            || null === $field->profile
        ) {
            return null;
        }

        $identity = FitDeveloperApplicationIdentity::fromProfile(
            $field->developerData,
        );

        if (null === $identity) {
            return null;
        }

        return sprintf(
            'fit_developer_%s_%d',
            $identity->namespaceToken(),
            $field->fieldDefinitionNumber(),
        );
    }

    private function normalizeName(string $name): string
    {
        $normalized = strtolower($name);
        $normalized = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $normalized,
        );

        $normalized = trim(
            (string) $normalized,
            '_',
        );

        if ('' === $normalized) {
            return 'field';
        }

        if (1 === preg_match('/^[0-9]/', $normalized)) {
            return 'field_'.$normalized;
        }

        return $normalized;
    }
}
