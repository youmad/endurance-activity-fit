<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\ActivityFit\Mapper\FitFusedRecordMessageProcessor;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\Fit\Decoder\FitDataMessageProcessor;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\Fit\Raw\RawFitMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

/**
 * File-scoped FIT-to-Activity message processor.
 *
 * Record messages take the fused Activity path. Every other data message keeps
 * the existing UnifiedDataMessage pipeline. Both paths share component and
 * developer resolver state through their injected processors.
 */
final readonly class FitActivityMessageProcessor
{
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;

    public function __construct(
        private FitDataMessageProcessor $messages,
        private FitFusedRecordMessageProcessor $records,
    ) {
    }

    /**
     * @param iterable<RawFitMessage> $messages
     *
     * @return \Generator<int, UnifiedDataMessage|FitRecordImportProjection>
     */
    public function processStream(iterable $messages): \Generator
    {
        $this->messages->reset();

        foreach ($messages as $sequence => $message) {
            if (!$message instanceof RawDataRecord) {
                continue;
            }

            if (self::RECORD_GLOBAL_MESSAGE_NUMBER === $message->globalMessageNumber()) {
                yield $sequence => $this->records->process($message);

                continue;
            }

            yield $sequence => $this->messages->process($message);
        }
    }
}
