<?php

declare(strict_types=1);

namespace App;

use Doctrine\Deprecations\Deprecation;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    #[Override]
    public function boot(): void
    {
        parent::boot();

        // doctrineencryptbundle registers its encrypt types through DBAL's static type API, which DBAL 4.6 deprecated
        // with one report per connection creation. In dev and test that report is a log record whose token processor
        // can boot the security firewall while the entity manager that opened the connection is still being
        // constructed; refreshing the session user from that half-built manager throws a "repositoryFactory must not
        // be accessed before initialization" error. The report is silenced until the bundle migrates off the API.
        Deprecation::ignoreDeprecations('https://github.com/doctrine/dbal/pull/7342');
    }
}
