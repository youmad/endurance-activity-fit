<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Import;

use Endurance\ActivityFit\Import\FitActivityMessageProcessor;
use Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Endurance\ActivityFit\Mapper\FitFusedRecordMessageProcessor;
use Endurance\ActivityFit\Tests\Fixture\FitDeveloperFieldFixture;
use Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\Decoder\FitDecodingSession;
use PHPUnit\Framework\TestCase;

final class FitActivityMessageProcessorTest extends TestCase
{
    public function testFusedRecordPathPreservesResolvedDeveloperMeasurements(): void
    {
        $messages = (new FitDeveloperFieldFixture())
            ->completeRecordRawMessages();

        $referenceMapper = FitActivityImportItemMapper::standard();
        $referenceItems = array_values(
            iterator_to_array(
                $referenceMapper->mapStream(
                    FitDecoder::standard(TestFitProfile::load())->decodeStream($messages),
                ),
            ),
        );

        $optimizedMapper = FitActivityImportItemMapper::standard();
        $optimizedItems = array_values(
            iterator_to_array(
                $optimizedMapper->mapStream(
                    $this->optimizedProcessor()->processStream($messages),
                ),
            ),
        );

        self::assertSame(
            serialize([
                $referenceItems,
                $referenceMapper->warnings(),
            ]),
            serialize([
                $optimizedItems,
                $optimizedMapper->warnings(),
            ]),
        );
    }

    public function testDeveloperProfilesStayInsideTheirSessionAndResetForNextStream(): void
    {
        $profileSet = TestFitProfile::load();
        $decoder = FitDecoder::standard($profileSet);
        $first = $decoder->newMessageProcessor();
        $second = $decoder->newMessageProcessor();
        $messages = (new FitDeveloperFieldFixture())->completeRecordRawMessages();
        $record = array_pop($messages);
        self::assertNotNull($record);
        foreach ($messages as $message) {
            $first->process($message);
        }

        $resolved = $first->process($record)->developerFields();
        $unresolved = $second->process($record)->developerFields();
        self::assertCount(3, $resolved);
        self::assertCount(3, $unresolved);
        foreach ($resolved as $field) {
            self::assertNotNull($field->profile);
            self::assertNull($field->unavailableReason);
        }
        foreach ($unresolved as $field) {
            self::assertNull($field->profile);
            self::assertNotNull($field->unavailableReason);
        }

        $first->reset();
        foreach ($first->process($record)->developerFields() as $field) {
            self::assertNull($field->profile);
        }
    }

    public function testFusedStreamResetDoesNotRetainDeveloperProfiles(): void
    {
        $messages = (new FitDeveloperFieldFixture())->completeRecordRawMessages();
        $record = $messages[array_key_last($messages)];
        $processor = $this->optimizedProcessor();
        iterator_to_array($processor->processStream($messages));

        self::assertSame(
            serialize(iterator_to_array($this->optimizedProcessor()->processStream([$record]))),
            serialize(iterator_to_array($processor->processStream([$record]))),
        );
    }

    private function optimizedProcessor(): FitActivityMessageProcessor
    {
        $profileSet = TestFitProfile::load();
        $session = new FitDecodingSession($profileSet->profiles, $profileSet->types);

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
