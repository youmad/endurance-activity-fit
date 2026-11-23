<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Import;

use Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\IO\FitInput;
use Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;
use Endurance\Foundation\ValueObject\Instant;

final readonly class DetectFitActivityStart
{
    public function __construct(
        private FitDecoder $decoder,
        private FitActivityImportItemMapper $mapper,
    ) {
    }

    public static function standard(
        GeneratedFitProfileSet $profileSet,
    ): self {
        return new self(
            decoder: FitDecoder::standard($profileSet),
            mapper: FitActivityImportItemMapper::standard(),
        );
    }

    public function detect(FitInput $input): Instant
    {
        $file = $this->decoder->open($input);
        $resolver = new FitActivityStartResolver();

        foreach ($this->mapper->mapStream($file->messages()) as $item) {
            $resolver->observe($item);
        }

        $file->trailer();

        return $resolver->resolve();
    }
}
