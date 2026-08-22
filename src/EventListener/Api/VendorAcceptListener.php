<?php

declare(strict_types=1);

namespace App\EventListener\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function explode;
use function is_string;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

#[AsEventListener(
    event: KernelEvents::REQUEST,
    priority: 16,
)]
final readonly class VendorAcceptListener
{
    public const string NEGOTIATED_VERSION = '_gewis_api_accept';

    public const string VERSION_HEADER = 'X-Api-Version';

    private const string VENDOR_MEDIA_TYPE = 'application/vnd.gewis.gewisdb+json';

    private const string API_PREFIX = '/api';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (
            !str_starts_with(
                $request->getPathInfo(),
                self::API_PREFIX,
            )
        ) {
            return;
        }

        $accept = $request->headers->get('Accept');
        $vendor = is_string($accept)
            ? $this->vendorRange($accept)
            : null;

        if (null !== $vendor) {
            $request->attributes->set(
                self::NEGOTIATED_VERSION,
                $vendor,
            );

            if ($this->servedByApiPlatform($request)) {
                $request->headers->set(
                    'Accept',
                    'application/json',
                );
            }

            return;
        }

        $version = $request->headers->get(self::VERSION_HEADER);

        if (null === $version) {
            return;
        }

        $request->attributes->set(
            self::NEGOTIATED_VERSION,
            self::VENDOR_MEDIA_TYPE . ';version=' . $version,
        );
    }

    /**
     * The vendor range on its own, because an `Accept` naming it alongside anything else is spec-legal and the
     * version parser only understands a header that is nothing but the vendor type.
     */
    private function vendorRange(string $accept): ?string
    {
        foreach (
            explode(
                ',',
                $accept,
            ) as $range
        ) {
            $range = trim($range);
            $type = strtolower(
                substr(
                    $range,
                    0,
                    strlen(self::VENDOR_MEDIA_TYPE),
                ),
            );

            if (self::VENDOR_MEDIA_TYPE !== $type) {
                continue;
            }

            $next = substr(
                $range,
                strlen(self::VENDOR_MEDIA_TYPE),
                1,
            );

            if (
                '' !== $next
                && ';' !== $next
            ) {
                continue;
            }

            return $range;
        }

        return null;
    }

    private function servedByApiPlatform(Request $request): bool
    {
        return $request->attributes->has('_api_resource_class')
            || $request->attributes->getBoolean('_api_respond');
    }
}
