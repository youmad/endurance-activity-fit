<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Import;

use Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Endurance\Fit\Decoder\RawFitFileStream;
use Endurance\Fit\Raw\FitFileHeader;
use Endurance\Fit\Raw\FitFileTrailer;
use Endurance\Fit\Unified\UnifiedDataMessage;

final class FitActivityFileStream
{
    public readonly FitFileHeader $header;

    public function __construct(
        private readonly RawFitFileStream $source,
        private readonly FitActivityMessageProcessor $processor,
    ) {
        $this->header = $source->header;
    }

    /** @return \Generator<int, UnifiedDataMessage|FitRecordImportProjection> */
    public function messages(): \Generator
    {
        yield from $this->processor->processStream(
            $this->source->messages(),
        );
    }

    public function isCompleted(): bool
    {
        return $this->source->isCompleted();
    }

    public function trailer(): FitFileTrailer
    {
        return $this->source->trailer();
    }
}
