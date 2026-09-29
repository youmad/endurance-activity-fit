# youmad/endurance-activity-fit

Streaming FIT-to-activity mapping and import coordination.

The package connects decoded FIT protocol messages to the format-independent
activity model. It translates messages into domain import items, validates
complete Activity files, reports recoverable ambiguities as warnings, and
coordinates one-pass imports.

## Features

- streaming FIT decoding specialized for Activity imports;
- mapping of records, laps, sessions, timer events, devices, pool lengths, and
  segment efforts;
- standard and developer-field measurements with source metadata;
- deterministic handling of Summary First and Summary Last message order;
- missing-summary recovery where the validated input permits it;
- contextual exceptions containing message sequence and byte offset;
- an optimized Record path with the same domain output as the reference path.

## Installation

```bash
composer require youmad/endurance-activity-fit
```

## Main entry points

| Class | Responsibility |
| --- | --- |
| `FitActivityFileDecoder` | Opens a FIT input and produces a validated message stream. |
| `FitActivityImportItemMapper` | Maps messages to domain import items and collects warnings. |
| `ImportFitActivity` | Connects decoding and mapping to `ImportActivityStream`. |

The standard high-level importer is created from an application-configured
`ImportActivityStream` and an explicitly loaded Profile set:

```php
<?php

declare(strict_types=1);

use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityFit\Import\ImportFitActivity;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;

require __DIR__.'/vendor/autoload.php';

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
    idempotencyKey: ActivityImportIdempotencyKey::fromString(
        'fit:activities/example.fit',
    ),
    input: $fitInput,
);
```

The application supplies the activity identifier, the caller-owned `FitInput`,
and implementations of the activity persistence ports used by
`$activityStreamImporter`.

The application selects both generated files. They must come from the same
workbook; the loader checks that their source SHA-256 values match. The files
are executable PHP, so load only trusted generated artifacts. Decoder and
import factories do not discover Profile files or download them implicitly.

## Validation policy

Complete Activity imports follow confirmed REQUIRED behavior from the official
Garmin FIT SDK. A condition accepted by the SDK is not promoted to a hard
failure without corresponding evidence. Tolerated ambiguity and recoverable
timing differences are surfaced as structured warnings where applicable.

Every confirmed parity correction is expected to have a regression test.

## Licensing boundary

This package contains the FIT-to-activity integration written for this project.
It does not contain `Profile.xlsx` or the generated FIT profile definitions
(`messages.php` and `types.php`). The application supplies these artifacts
separately; this package's license does not grant rights to them.

## Development

Tests that decode FIT messages use separately generated `messages.php` and
`types.php` registries. Place them in `var/fit-profile/` inside the package
checkout, or set `ENDURANCE_FIT_PROFILE_DIR` to the absolute directory
containing both files. These files are excluded from the package archive.

```bash
composer install
composer check
```

`composer check` validates the package metadata, runs PHPUnit and PHPStan
(level 6), and checks the code style with PHP CS Fixer (`@Symfony`).

Run individual checks or apply code-style fixes with:

```bash
composer test
composer analyse
composer cs:check
composer cs:fix
```

## License

The project-authored source code, tests, and documentation in this package
are licensed under the Mozilla Public License 2.0 (`MPL-2.0`).

> This Source Code Form is subject to the terms of the Mozilla Public
> License, v. 2.0. If a copy of the MPL was not distributed with this
> file, You can obtain one at https://mozilla.org/MPL/2.0/.

See [LICENSE](LICENSE). Dependencies retain their own licenses.

This license does not grant rights to Garmin SDK or Profile materials,
including locally generated registries loaded through `youmad/endurance-fit`.

## Developer measurement metadata contract

`FitDeveloperMeasurementMetadata` exposes type `fit.developer_field`, version
`1`. Its data contains `application` (`kind`, `identifier`,
`developer_data_index`, `manufacturer_id`, `application_version`),
`field_definition_number`, `field_name`, `units`, `native_message_number`,
`native_field_number`, `component_index`, `component_name`, `component_bits`,
and `component_accumulated`.

Application kind is `application_id` or `developer_id`; the identifier is
lowercase hexadecimal. Unknown optional values remain null, including component
fields for an unexpanded field; `component_accumulated` is a boolean. Field and
component names and units preserve the decoded values. This schema is explicit
and does not contain PHP class names. Its serialization contract is independent
of the class's public properties and constructor.

## Shared decoding session

Each `FitActivityFileDecoder::open()` creates a fresh `FitDecodingSession` from
the FIT package. The Activity adapter connects its fused Record projection to
that session's decoder, normalizer, component extractor, accumulator, and
developer resolver. Other messages use the session's unified processor.
Pipeline assembly is owned by FIT; Record projection is owned by Activity FIT.
The optimized path still avoids unified Record assembly. Stream reset and
chained-file behavior are unchanged; independent files never reuse a session.

## Explicit summary adjacency

FIT laps and sessions explicitly select `AbutWithinTwoWholeSeconds`. Missing
session recovery uses the same policy, and a lap recovered from a session inherits
that session's policy. Session overlap warnings consult the selected policy.
The Garmin REQUIRED validator, timestamp precision, and source durations remain
unchanged. The selected policy is persisted with each summary by the PostgreSQL
adapter.

## Terminal timer boundary resolution

`FitTerminalFinishResolver` owns the fallback `stop_all` boundary adjustment
when no Activity summary is available. It is stateless and returns a
`FitTerminalFinishResolution` containing the finish item and an optional warning.
The import mapper adds that warning to its existing collector immediately before
yielding the finish, preserving lazy stream behavior and warning order.

The resolver still selects the latest observed/detail/lap/session/event boundary,
keeps the existing priority for ties, and adjusts only for positive drift strictly
below one second. A later boundary at least one second away prevents adjustment;
it does not fall back to another, nearer boundary. Summary recovery and failed
segment filtering remain in their original positions in stream finalization.
