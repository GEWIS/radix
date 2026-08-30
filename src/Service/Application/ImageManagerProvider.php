<?php

declare(strict_types=1);

namespace App\Service\Application;

use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\ImageManager;

/**
 * Provides an Intervention {@see ImageManager} on libvips, which streams tiles instead of decoding whole frames. It is
 * the only driver: GD accepts a narrower set of inputs and its memory behaviour on a large photo is what libvips was
 * brought in to avoid, so an environment without libvips should fail rather than quietly encode differently.
 *
 * A fresh manager is created per call rather than cached on the service, because under FrankenPHP's long-lived worker
 * the vips FFI handle must not be reused across requests and constructing a manager is cheap.
 */
final readonly class ImageManagerProvider
{
    public function create(): ImageManager
    {
        return new ImageManager(new VipsDriver());
    }
}
