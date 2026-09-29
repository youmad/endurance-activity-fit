<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\UseCase\ImportActivityStream;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Decoder\UnifiedFitFileStream;
use Youmad\Endurance\Fit\IO\FitInput;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ImportFitActivity
{
    public function __construct(
        private FitDecoder|FitActivityFileDecoder $decoder,
        private FitActivityImportItemMapper $mapper,
        private ImportActivityStream $activities,
    ) {
    }

    public static function standard(
        ImportActivityStream $activities,
        GeneratedFitProfileSet $profileSet,
    ): self {
        return new self(
            decoder: FitActivityFileDecoder::standard($profileSet),
            mapper: FitActivityImportItemMapper::standard(),
            activities: $activities,
        );
    }

    /**
     * Decodes and maps one caller-owned FIT input exactly once.
     *
     * When the activity already exists, generation ownership is acquired before
     * decoding so a decode/CRC failure leaves a failed invisible generation.
     * When $onPrepared is supplied, mapped items are spooled until startedAt is
     * known and the caller has created the activity.
     *
     * @param \Closure(Instant): void|null                     $onPrepared
     * @param \Closure(list<ActivityImportWarning>): void|null $onImported
     * @param \Closure(): void|null                            $onProgress
     */
    public function import(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
        FitInput $input,
        ?\Closure $onPrepared = null,
        ?\Closure $onImported = null,
        ?\Closure $onProgress = null,
    ): FitActivityImportResult {
        if (null === $onPrepared) {
            return $this->importExistingActivity(
                activityId: $activityId,
                idempotencyKey: $idempotencyKey,
                input: $input,
                onImported: $onImported,
                onProgress: $onProgress,
            );
        }

        return $this->importPreparedActivity(
            activityId: $activityId,
            idempotencyKey: $idempotencyKey,
            input: $input,
            onPrepared: $onPrepared,
            onImported: $onImported,
            onProgress: $onProgress,
        );
    }

    /**
     * @param \Closure(list<ActivityImportWarning>): void|null $onImported
     * @param \Closure(): void|null                            $onProgress
     */
    private function importExistingActivity(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
        FitInput $input,
        ?\Closure $onImported,
        ?\Closure $onProgress,
    ): FitActivityImportResult {
        $progress = new FitActivityImportProgress();
        $file = null;
        $items = $this->withProgressPulse(
            $this->items(
                input: $input,
                progress: $progress,
                onOpened: static function (UnifiedFitFileStream|FitActivityFileStream $opened) use (&$file): void {
                    $file = $opened;
                },
                onProgress: $onProgress,
            ),
            $onProgress,
        );

        try {
            $activityImport = $this->activities->handle(
                activityId: $activityId,
                idempotencyKey: $idempotencyKey,
                items: $items,
                finalizeImport: null === $onImported
                    ? null
                    : function () use ($onImported): void {
                        $onImported($this->mapper->warnings());
                    },
            );
        } catch (\Throwable $exception) {
            throw $this->failure($activityId, $input, $progress, $exception);
        }

        if (!$activityImport->isImported()) {
            return FitActivityImportResult::alreadyImported($activityImport);
        }

        return $this->result(
            activityId: $activityId,
            input: $input,
            progress: $progress,
            file: $file,
            activityImport: $activityImport,
            warnings: $this->mapper->warnings(),
        );
    }

    /**
     * @param \Closure(Instant): void                          $onPrepared
     * @param \Closure(list<ActivityImportWarning>): void|null $onImported
     * @param \Closure(): void|null                            $onProgress
     */
    private function importPreparedActivity(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
        FitInput $input,
        \Closure $onPrepared,
        ?\Closure $onImported,
        ?\Closure $onProgress,
    ): FitActivityImportResult {
        $progress = new FitActivityImportProgress();
        $buffer = new FitActivityImportItemBuffer();

        try {
            try {
                $file = $this->open($input);
                $resolver = new FitActivityStartResolver();
                $messages = $progress->trackMessages(
                    $file->messages(),
                    $onProgress,
                );
                $items = $this->withProgressPulse(
                    $progress->trackItems($this->mapper->mapStream(
                        messages: $messages,
                        requireGarminSummaryMessages: true,
                    )),
                    $onProgress,
                );

                foreach ($items as $item) {
                    $resolver->observe($item);
                    $buffer->append($item);
                }

                $file->trailer();
                $startedAt = $resolver->resolve();
                $preStartObservations = new FitPreStartObservationFilter(
                    $startedAt,
                );
                $onPrepared($startedAt);

                $activityImport = $this->activities->handle(
                    activityId: $activityId,
                    idempotencyKey: $idempotencyKey,
                    items: $this->withProgressPulse(
                        $preStartObservations->filter($buffer->items()),
                        $onProgress,
                    ),
                    finalizeImport: null === $onImported
                        ? null
                        : function () use (
                            $onImported,
                            $preStartObservations,
                        ): void {
                            $this->reportPreStartObservationWarning(
                                $preStartObservations,
                            );
                            $onImported($this->mapper->warnings());
                        },
                );
                $this->reportPreStartObservationWarning(
                    $preStartObservations,
                );
                $warnings = $this->mapper->warnings();
            } catch (\Throwable $exception) {
                throw $this->failure($activityId, $input, $progress, $exception);
            }

            if (!$activityImport->isImported()) {
                return FitActivityImportResult::alreadyImported($activityImport);
            }

            return $this->result(
                activityId: $activityId,
                input: $input,
                progress: $progress,
                file: $file,
                activityImport: $activityImport,
                warnings: $warnings,
            );
        } finally {
            $buffer->close();
        }
    }

    /**
     * @param \Closure(UnifiedFitFileStream|FitActivityFileStream): void $onOpened
     * @param \Closure(): void|null                                      $onProgress
     *
     * @return \Generator<int, \Youmad\Endurance\Activity\Application\Import\ActivityImportItem>
     */
    private function items(
        FitInput $input,
        FitActivityImportProgress $progress,
        \Closure $onOpened,
        ?\Closure $onProgress,
    ): \Generator {
        $file = $this->open($input);
        $onOpened($file);

        yield from $progress->trackItems(
            $this->mapper->mapStream(
                messages: $progress->trackMessages(
                    $file->messages(),
                    $onProgress,
                ),
                requireGarminSummaryMessages: true,
            ),
        );
    }

    private function open(
        FitInput $input,
    ): UnifiedFitFileStream|FitActivityFileStream {
        return $this->decoder->open($input);
    }

    /**
     * @param iterable<\Youmad\Endurance\Activity\Application\Import\ActivityImportItem> $items
     * @param \Closure(): void|null                                                      $onProgress
     *
     * @return \Generator<int, \Youmad\Endurance\Activity\Application\Import\ActivityImportItem>
     */
    private function withProgressPulse(
        iterable $items,
        ?\Closure $onProgress,
    ): \Generator {
        foreach ($items as $sequence => $item) {
            if (null !== $onProgress) {
                $onProgress();
            }

            yield $sequence => $item;
        }
    }

    private function reportPreStartObservationWarning(
        FitPreStartObservationFilter $filter,
    ): void {
        $warning = $filter->warning();

        if (null !== $warning) {
            $this->mapper->reportWarning($warning);
        }
    }

    /** @param list<ActivityImportWarning> $warnings */
    private function result(
        ActivityId $activityId,
        FitInput $input,
        FitActivityImportProgress $progress,
        UnifiedFitFileStream|FitActivityFileStream|null $file,
        \Youmad\Endurance\Activity\Application\Import\ActivityImportResult $activityImport,
        array $warnings,
    ): FitActivityImportResult {
        if (null === $file) {
            throw new \LogicException('Imported FIT stream was not opened.');
        }

        $trailer = $file->trailer();

        return FitActivityImportResult::imported(
            activityImport: $activityImport,
            report: new FitActivityImportReport(
                activityId: $activityId,
                protocolVersion: $file->header->protocolVersion,
                profileVersion: $file->header->profileVersion,
                declaredDataSize: $file->header->dataSize,
                decodedDataMessages: $progress->decodedDataMessages(),
                items: $progress->itemCounts(),
                finalBytePosition: $input->position(),
                fileCrc: $trailer->calculatedCrc,
                warnings: $warnings,
            ),
        );
    }

    private function failure(
        ActivityId $activityId,
        FitInput $input,
        FitActivityImportProgress $progress,
        \Throwable $exception,
    ): FitActivityImportFailed {
        return FitActivityImportFailed::because(
            activityId: $activityId,
            bytePosition: $input->position(),
            decodedDataMessages: $progress->decodedDataMessages(),
            mappedItems: $progress->mappedItems(),
            previous: $exception,
        );
    }
}
