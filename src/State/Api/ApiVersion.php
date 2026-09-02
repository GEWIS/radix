<?php

declare(strict_types=1);

namespace App\State\Api;

/**
 * Every version the API has ever bounded an endpoint on, and the keys an operation declares its bounds under.
 *
 * A case is added when the contract moves, and never removed: an operation's bound is frozen at the release it
 * describes, so the cases outlive the releases that introduced them. There is no case for the release the
 * application currently is, on purpose. That is a fact about the build, it is the git tag, and a copy here would be
 * one more thing to keep honest that nothing reads.
 */
enum ApiVersion: string
{
    /** The release the two function lists first answered in, which is the oldest bound the API still enforces. */
    case V4_3_3 = '4.3.3';

    /** The release the API moved onto API Platform in, which is what everything added since then answers. */
    case V5_0_0 = '5.0.0';

    /**
     * The release an operation first answered in. Required on everything added since the contract was versioned; a
     * consumer stating an older version is refused rather than served a shape it cannot read.
     */
    public const string MINIMUM = 'gewis_minimum_version';

    /**
     * The last release an operation answers in, for an operation kept alive only until its consumers have moved.
     * Optional, and set only once the shape that replaces it exists.
     */
    public const string MAXIMUM = 'gewis_maximum_version';

    /**
     * The release an operation was deprecated in. Optional, and it enforces nothing: it marks the operation
     * deprecated in the document, which is where a consumer finds out, and states from when.
     */
    public const string DEPRECATED = 'gewis_deprecated_version';
}
