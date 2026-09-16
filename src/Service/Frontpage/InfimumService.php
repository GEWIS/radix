<?php

declare(strict_types=1);

namespace App\Service\Frontpage;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_string;
use function trim;

/**
 * The infimum the website shows, fetched from the Supremum's own API and cached for a few minutes. The cron fills the
 * cache and pushes what it found; nothing here is fetched while a page is being rendered.
 *
 * A failed fetch is cached as well, so an API that is down costs one request in a while, not one per page request.
 */
final readonly class InfimumService
{
    private const string CACHE_KEY = 'infimum';

    /**
     * As long as the rotation the cron drives, so a fetch and a rotation never disagree about what is current.
     */
    private const int TTL_SECONDS = 300;

    /**
     * How long a failed fetch is cached for: short enough that the infimum comes back on its own once the API does,
     * long enough that not every page request has to wait for it.
     */
    private const int FAILURE_TTL_SECONDS = 60;

    public function __construct(
        #[Autowire(service: 'supremum.client')]
        private HttpClientInterface $supremumClient,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * The infimum as it stands, fetching a new one only when there is nothing kept.
     */
    public function getInfimum(): ?string
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            $cached = $item->get();

            return is_string($cached)
                ? $cached
                : null;
        }

        $infimum = $this->fetch();
        $item->set($infimum);
        $item->expiresAfter(null === $infimum
            ? self::FAILURE_TTL_SECONDS
            : self::TTL_SECONDS);
        $this->cache->save($item);

        return $infimum;
    }

    /**
     * A new infimum regardless of what is cached, which is what the rotation requires. A failed fetch changes nothing.
     */
    public function refresh(): ?string
    {
        $infimum = $this->fetch();
        if (null === $infimum) {
            return null;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($infimum);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $infimum;
    }

    private function fetch(): ?string
    {
        try {
            $body = $this->supremumClient->request(
                'GET',
                '/api/random_infimum',
            )->toArray();
        } catch (ExceptionInterface) {
            return null;
        }

        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            return null;
        }

        $content = trim($content);

        return '' === $content
            ? null
            : $content;
    }
}
