<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\ActivityFit\Mapper\FitFusedRecordMessageProcessor;
use Youmad\Endurance\Fit\Decoder\FitDecodingSession;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\FitInput;
use Youmad\Endurance\Fit\Profile\FitProfileRegistry;
use Youmad\Endurance\Fit\Profile\FitTypeRegistry;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;

/**
 * Production FIT file decoder specialized for Activity import.
 */
final readonly class FitActivityFileDecoder
{
    public static function standard(
        GeneratedFitProfileSet $profileSet,
    ): self {
        return new self(
            profiles: $profileSet->profiles,
            types: $profileSet->types,
        );
    }

    public function __construct(
        private FitProfileRegistry $profiles,
        private FitTypeRegistry $types,
        private FitFileReader $files = new FitFileReader(),
    ) {
    }

    public function open(FitInput $input): FitActivityFileStream
    {
        return new FitActivityFileStream(
            source: $this->files->open($input),
            processor: $this->newMessageProcessor(),
        );
    }

    private function newMessageProcessor(): FitActivityMessageProcessor
    {
        $session = new FitDecodingSession(
            profiles: $this->profiles,
            types: $this->types,
        );

        return new FitActivityMessageProcessor(
            messages: $session->messages,
            records: new FitFusedRecordMessageProcessor(
                decoder: $session->decoder,
                profiles: $session->profiles,
                components: $session->components,
                componentValues: $session->componentValues,
                developerFields: $session->developerFields,
            ),
        );
    }
}
