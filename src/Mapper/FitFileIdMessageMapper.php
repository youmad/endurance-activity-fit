<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitFileIdMessageMapper implements ResettableFitMessageMapper
{
    private const int FILE_ID_GLOBAL_MESSAGE_NUMBER = 0;
    private const int CREATOR_DEVICE_INDEX = 0;
    private const int ACTIVITY_FILE_TYPE = 4;

    private bool $firstFileIdValidated = false;

    public function __construct(
        private readonly FitDeviceIndexRegistry $devices,
        private readonly FitFieldValueReader $values =
            new FitFieldValueReader(),
        private readonly FitDeviceDescriptorFactory $descriptors =
            new FitDeviceDescriptorFactory(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::FILE_ID_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT file_id mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        // SDK ActivityFileValidationPlugin validates getFileIdMesgs().get(0).
        // Later members belong to the same import and may identify other types.
        if (!$this->firstFileIdValidated) {
            $this->validateFileType($message);
        } else {
            // Our projection gives each FileId its own device-index context.
            // Retain emitted devices and statuses under their original IDs;
            // a later creator (including Settings) must not overwrite them.
            // Continuations without another FileId keep the current context.
            $this->devices->reset();
        }

        $device = $this->devices->register(
            deviceIndex: self::CREATOR_DEVICE_INDEX,
            descriptor: $this->descriptors->create($message),
        );

        $this->firstFileIdValidated = true;

        return null === $device
            ? []
            : [new DeviceItem($device)];
    }

    public function reset(): void
    {
        $this->firstFileIdValidated = false;
        $this->devices->reset();
    }

    private function validateFileType(UnifiedDataMessage $message): void
    {
        $type = $this->values->byName(
            message: $message,
            fieldName: 'type',
        );

        if (null === $type) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'file_id', fieldName: 'type');
        }

        if (
            'activity' !== $type->symbolicName()
            && self::ACTIVITY_FILE_TYPE !== $type->value
        ) {
            throw InvalidFitActivityMessage::unsupportedFileType(symbolicType: $type->symbolicName(), rawType: $type->value);
        }
    }
}
