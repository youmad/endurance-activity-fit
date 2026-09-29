<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Fit\Typed\TypedEnumFieldElement;
use Youmad\Endurance\Fit\Typed\TypedFieldElements;
use Youmad\Endurance\Fit\Unified\ComponentFieldValue;
use Youmad\Endurance\Fit\Unified\FieldValueOrigin;
use Youmad\Endurance\Fit\Unified\PhysicalFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedFieldValue;

final readonly class SelectedFitFieldValue
{
    public function __construct(
        public UnifiedFieldValue $source,
        public int|float|string $value,
    ) {
    }

    public function origin(): FieldValueOrigin
    {
        return $this->source->origin();
    }

    public function fieldName(): ?string
    {
        return $this->source->name();
    }

    public function symbolicName(): ?string
    {
        if ($this->source instanceof ComponentFieldValue) {
            return $this->source
                ->source
                ->symbolicName();
        }

        if (!$this->source instanceof PhysicalFieldValue) {
            return null;
        }

        $typed = $this->source
            ->source
            ->value;

        if (!$typed instanceof TypedFieldElements) {
            return null;
        }

        $elements = $typed->elements();

        if (1 !== count($elements)) {
            return null;
        }

        $element = $elements[0];

        return $element instanceof TypedEnumFieldElement
            ? $element->name()
            : null;
    }
}
