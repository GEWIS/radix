<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Attribute\User\Replayable;
use App\Service\Application\FileStorage;
use App\Util\Application\ControllerAttribute;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function fclose;
use function fopen;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function parse_url;
use function stream_copy_to_stream;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_URL_PATH;

/**
 * Re-runs a write that was refused for want of a sudo grant, once the grant has been given.
 *
 * Only for an action that declares {@see Replayable}. Everything else returns null, and the caller returns the user
 * to the page instead.
 *
 * The write is run as a request of its own, which is what allows the uploads to be part of it: a form returned to
 * the browser cannot include a file. It is handled as a main request rather than a sub-request, because the
 * listeners that refuse a write stand down for a sub-request: the read-only maintenance window and the firewall's
 * `access_control` both test `isMainRequest()`, and a re-run that skipped them would do what the user could not have
 * done by hand.
 *
 * The statement that the request came from this site is copied from the confirmation the user has just submitted,
 * which was checked like any other submission, and the stash was only kept for a request that made the same
 * statement itself ({@see SudoStash}). The CSRF token is the one that was submitted the first time, and is
 * validated on the way through as it would have been then.
 */
final readonly class SudoReplay
{
    /**
     * What states that a request was made from a page of this site. Copied onto the re-run because the CSRF token
     * manager reads them from the request being handled, which is the re-run and not the confirmation it was sent
     * from.
     */
    private const array ORIGIN_HEADERS = [
        'Origin',
        'Referer',
        'Sec-Fetch-Site',
        'Sec-Fetch-Mode',
        'Sec-Fetch-Dest',
    ];

    public function __construct(
        private SudoStash $stash,
        private RequestStack $requestStack,
        private RouterInterface $router,
        private FileStorage $fileStorage,
        private HttpKernelInterface $kernel,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The response of the re-run write, or null where there is nothing to re-run.
     */
    public function replay(string $id): ?Response
    {
        $write = $this->stash->read($id);
        if (null === $write) {
            return null;
        }

        if (!$this->isReplayable($write->uri)) {
            return null;
        }

        $current = $this->requestStack->getMainRequest();
        if (null === $current) {
            return null;
        }

        $temporary = [];

        try {
            $rerun = Request::create(
                $write->uri,
                $write->method,
                $write->parameters,
                $current->cookies->all(),
                $this->restoreUploads(
                    $write->files,
                    $temporary,
                ),
                $current->server->all(),
            );

            $rerun->setSession($current->getSession());

            foreach (self::ORIGIN_HEADERS as $header) {
                $value = $current->headers->get($header);

                if (null === $value) {
                    continue;
                }

                $rerun->headers->set(
                    $header,
                    $value,
                );
            }

            // Referer states the page the write was made from rather than the prompt, which is what it was.
            $rerun->headers->set(
                'Referer',
                $current->getSchemeAndHttpHost() . $write->uri,
            );

            return $this->kernel->handle(
                $rerun,
                HttpKernelInterface::MAIN_REQUEST,
                false,
            );
        } catch (Throwable $failure) {
            $this->logger->error(
                'Could not re-run a write that was refused for want of a sudo grant.',
                ['exception' => $failure],
            );

            return null;
        } finally {
            $this->stash->discard($id);

            foreach ($temporary as $path) {
                if (!is_file($path)) {
                    continue;
                }

                unlink($path);
            }
        }
    }

    private function isReplayable(string $uri): bool
    {
        $path = parse_url(
            $uri,
            PHP_URL_PATH,
        );
        if (!is_string($path)) {
            return false;
        }

        try {
            $route = $this->router->match($path);
        } catch (RoutingException) {
            return false;
        }

        return ControllerAttribute::isPresent(
            $route['_controller'] ?? null,
            Replayable::class,
        );
    }

    /**
     * Rebuilds the upload fields from what the stash wrote to storage. The bytes go back onto the local filesystem
     * because an UploadedFile is a path, and the action moves it from there as it would have moved the original.
     *
     * @param array<array-key, mixed> $files
     * @param list<string>            $temporary
     *
     * @return array<array-key, mixed>
     */
    private function restoreUploads(
        array $files,
        array &$temporary,
    ): array {
        $restored = [];

        /** @var mixed $file */
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (!is_string($file['path'] ?? null)) {
                $restored[$name] = $this->restoreUploads(
                    $file,
                    $temporary,
                );

                continue;
            }

            $local = $this->copyOut($file['path']);
            if (null === $local) {
                continue;
            }

            $temporary[] = $local;

            $restored[$name] = new UploadedFile(
                $local,
                is_string($file['name'] ?? null) ? $file['name'] : 'upload',
                is_string($file['type'] ?? null) ? $file['type'] : null,
                null,
                true,
            );
        }

        return $restored;
    }

    private function copyOut(string $path): ?string
    {
        if (!$this->fileStorage->exists($path)) {
            return null;
        }

        $local = tempnam(
            sys_get_temp_dir(),
            'gws_sudo_',
        );
        if (false === $local) {
            return null;
        }

        $source = $this->fileStorage->readStream($path);
        $target = fopen(
            $local,
            'wb',
        );

        if (
            !is_resource($source)
            || !is_resource($target)
        ) {
            unlink($local);

            return null;
        }

        stream_copy_to_stream(
            $source,
            $target,
        );

        fclose($source);
        fclose($target);

        return $local;
    }
}
