# Endurance Activity FIT

Streaming FIT-to-Activity mapping and import coordination. The package maps
records, timer events, laps, sessions, pool lengths, segment efforts, devices
and developer measurements into the Endurance Activity model.

## Installation

Requires PHP `^8.5`.

```bash
composer require youmad/endurance-activity-fit
```

## FIT Profile

Generate `messages.php` and `types.php` from the same Garmin FIT Profile workbook
using the instructions in
[`youmad/endurance-fit`](https://github.com/youmad/endurance-fit).

Load both files explicitly, as shown below. Generated files are executable PHP;
use artifacts from a trusted source.

## Importing an activity

```php
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityFit\Import\ImportFitActivity;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;

$profileSet = GeneratedFitProfileSet::load(
    messagesFile: '/path/to/profile/messages.php',
    typesFile: '/path/to/profile/types.php',
);

$importer = ImportFitActivity::standard(
    activities: $activityStreamImporter,
    profileSet: $profileSet,
);

$result = $importer->import(
    activityId: $activityId,
    idempotencyKey: ActivityImportIdempotencyKey::fromString('fit:example.fit'),
    input: $fitInput,
);
```

The application supplies a configured `ImportActivityStream`, an `ActivityId`
and a caller-owned `FitInput`. The activity must already exist; alternatively,
pass `onPrepared` to create it after its start time is resolved. The input is
consumed once, and the application owns its underlying resource.

For lower-level integration, `Import\FitActivityFileDecoder` opens a validated
message stream and `Mapper\FitActivityImportItemMapper` produces import items.

## Validation and warnings

Complete Activity imports validate structure, CRC and supported Garmin REQUIRED
checks. Both Summary First and Summary Last ordering are supported. Recoverable
ambiguities produce structured warnings; invalid input raises contextual
exceptions with message sequence and byte offset where available.

FIT laps and sessions use whole-second abutment with a two-second tolerance.
Original durations and measurement values remain represented in the domain model.
Developer measurements retain their field and application metadata through the
portable `fit.developer_field` metadata contract.

## Development

Tests need the generated Profile files. Set `ENDURANCE_FIT_PROFILE_DIR` to the
absolute directory containing both files, or place them in `var/fit-profile/`.

```bash
composer install
composer check
```

## License

[MPL-2.0](LICENSE) for this package's code and documentation. Garmin SDK and
Profile materials have their own terms, including generated registries.
