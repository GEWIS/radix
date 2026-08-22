<?php

declare(strict_types=1);

namespace App\State\Api;

final readonly class ApiVersion
{
    public const string CURRENT = 'v5.0.0';

    /** What a consumer actually puts on the wire: the `v` above is internal to the semantic-version parser. */
    public const string CURRENT_WIRE = '5.0.0';

    public const string MINIMUM = 'gewis_minimum_version';
}
