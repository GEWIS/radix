<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Service\User\CompanyUserAccessPolicy;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\SignsInThroughTheKernel;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

use function html_entity_decode;
use function in_array;
use function json_encode;
use function ksort;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Requests every page the application serves, once each.
 *
 * PHPStan does not analyse templates and `lint:twig` only parses them, so a template attribute that resolves to
 * nothing fails when the template is rendered and not before. A page no other test covers is therefore unchecked,
 * which is how a removed accessor reaches the user rather than the developer who removed it.
 *
 * The only assertion is that a page does not raise an exception. A 404 or a 403 is a valid response, and which one a
 * route returns is the subject of the test written for that route.
 *
 * Only routes whose sole parameter is a locale are requested. The rest require a meaningful identifier, which is the
 * responsibility of the fixtures rather than of this test.
 */
final class RouteSmokeTest extends DatabaseTestCase
{
    use SignsInThroughTheKernel;

    /**
     * The member the sweep signs in as. The fixtures grant this member ROLE_ADMIN and ROLE_DATABASE_ADMIN, so the
     * administration is included in the sweep. Those roles only survive {@see User::getRoles()} while MFA
     * enforcement is disabled, because enforcement removes them from a member in scope who has not enrolled and the
     * fixtures enrol no member. {@see DatabaseTestCase::setUp()} assigns the switch from the parameter this
     * environment configures, for every test, so what the sweep reads does not depend on what ran before it. The
     * test asserts ROLE_ADMIN is present before it starts, so that this cannot regress without being noticed.
     *
     * ROLE_BOARD and the register's own roles are derived from a board installation and from being the serving
     * secretary, neither of which the fixtures create for this member. Routes requiring them respond 403 here and
     * are covered by their own tests.
     */
    private const int MEMBER = 8000;

    /**
     * A page only the administration reaches, which is asserted to have rendered.
     *
     * Every route the member may not open returns 302 to the login page, and a redirect is a valid response here, so
     * without this a sweep that is signed out altogether (a renamed firewall, a remember-me cookie the guard no
     * longer accepts) still renders the public pages and passes. `user_api_principal_index` is behind
     * `ROLE_ADMIN`/`ROLE_DATABASE_ADMIN` in `access_control`, which is exactly what the fixtures grant this member.
     */
    private const string ADMIN_PAGE = 'user_api_principal_index';

    /**
     * The prefix of the company pages, which are behind a firewall of their own.
     */
    private const string COMPANY = '/en/company';

    /**
     * Not pages: the bearer-authenticated API, the profiler, and the health check.
     */
    private const array NOT_PAGES = [
        '/api',
        '/_wdt',
        '/_profiler',
        '/_error',
        '/health',
    ];

    public function testNoPageRaises(): void
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        $user = $this->entityManager->getRepository(User::class)->find(self::MEMBER);
        self::assertInstanceOf(
            User::class,
            $user,
            'The fixtures are expected to contain this member.',
        );
        self::assertContains(
            UserRoles::Admin->value,
            $user->getRoles(),
            'MFA enforcement must be disabled for the administration to be covered by this sweep.',
        );

        $representative = $this->aRepresentative();
        $asked = 0;
        $raised = [];
        $outcomes = [];
        $statuses = [];

        foreach ($this->pagePaths() as $route => $path) {
            ++$asked;

            // Signed in again for every page. The application rotates the session and remember-me values as they
            // are used, so a single sign-in reused across the sweep is invalidated partway through and the result
            // differs between runs.
            //
            // The representative only for the company pages: that sign-in writes a session row and a remember-me
            // cookie of its own, and outside the company firewall nothing reads either.
            $cookies = $this->signedInCookies(
                $user,
                str_starts_with(
                    $path,
                    self::COMPANY,
                ) ? $representative : null,
            );

            $request = Request::create($path);
            foreach ($cookies as $name => $value) {
                $request->cookies->set(
                    $name,
                    $value,
                );
            }

            try {
                $response = $kernel->handle(
                    $request,
                    HttpKernelInterface::MAIN_REQUEST,
                    true,
                );

                $status = $response->getStatusCode();
                $outcomes[$status] = ($outcomes[$status] ?? 0) + 1;
                $statuses[$route] = $status;
                if ($status < 500) {
                    continue;
                }

                $title = 1 === preg_match(
                    '{<title>(.*?)</title>}s',
                    (string) $response->getContent(),
                    $matches,
                )
                    ? trim(html_entity_decode($matches[1]))
                    : '(no message in the response)';

                $raised[] = sprintf(
                    '%s (%s): %d %s',
                    $route,
                    $path,
                    $response->getStatusCode(),
                    $title,
                );
            } catch (AccessDeniedException) {
                // A denial is a valid response. Which pages this member may open depends on the roles the register
                // grants, and is the subject of the tests written for those pages.
                $outcomes['denied'] = ($outcomes['denied'] ?? 0) + 1;

                continue;
            } catch (HttpExceptionInterface $exception) {
                // A valid response, including a denial. Only a status of 500 or above is a failure here, and it is
                // counted as well: the summary printed on failure reports what the run did.
                $thrown = 'thrown ' . $exception->getStatusCode();
                $outcomes[$thrown] = ($outcomes[$thrown] ?? 0) + 1;
                $statuses[$route] = $exception->getStatusCode();

                if ($exception->getStatusCode() < 500) {
                    continue;
                }

                $raised[] = sprintf(
                    '%s (%s): %s',
                    $route,
                    $path,
                    $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                $raised[] = sprintf(
                    '%s (%s): %s: %s',
                    $route,
                    $path,
                    $exception::class,
                    $exception->getMessage(),
                );
            }
        }

        self::assertGreaterThan(
            100,
            $asked,
            'Too few pages were requested, so the route filter is wrong.',
        );
        ksort($outcomes);
        self::assertSame(
            200,
            $statuses[self::ADMIN_PAGE] ?? null,
            'The administration did not render, so this sweep covers the public pages only. The member is signed in '
            . 'for every page, and a page they may not open answers 302, so this is what a sweep that is signed out '
            . 'looks like.',
        );
        self::assertGreaterThan(
            40,
            $outcomes[200] ?? 0,
            'Too few pages rendered for this to be meaningful: ' . (string) json_encode($outcomes),
        );
        self::assertSame(
            [],
            $raised,
            sprintf(
                '%d pages requested, with responses %s.',
                $asked,
                (string) json_encode($outcomes),
            ),
        );
    }

    /**
     * A company representative the access policy still admits. The company pages are behind a firewall of their own,
     * with its own provider, remember-me cookie and sudo area, so a member's session does not authenticate there.
     *
     * Selected by querying the policy rather than by naming one, because which representative is disabled and which
     * company still has a package are defined by the fixtures rather than by this test.
     */
    private function aRepresentative(): ?CompanyUser
    {
        $policy = self::getContainer()->get(CompanyUserAccessPolicy::class);
        self::assertInstanceOf(
            CompanyUserAccessPolicy::class,
            $policy,
        );

        $now = new DateTimeImmutable();
        foreach ($this->entityManager->getRepository(CompanyUser::class)->findAll() as $companyUser) {
            if (
                !$policy->isAllowed(
                    $companyUser,
                    $now,
                )
            ) {
                continue;
            }

            return $companyUser;
        }

        return null;
    }

    /**
     * Every route accepting GET whose only parameter is a locale, mapped to the path to request.
     *
     * @return iterable<string, string>
     */
    private function pagePaths(): iterable
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(
            RouterInterface::class,
            $router,
        );

        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = $route->getMethods();
            if (
                [] !== $methods
                && !in_array(
                    'GET',
                    $methods,
                    true,
                )
            ) {
                continue;
            }

            $path = $route->getPath();

            if (
                1 === preg_match(
                    '/\{(?!_locale\})/',
                    $path,
                )
            ) {
                continue;
            }

            foreach (self::NOT_PAGES as $prefix) {
                if (
                    str_starts_with(
                        $path,
                        $prefix,
                    )
                ) {
                    continue 2;
                }
            }

            yield $name => str_replace(
                '{_locale}',
                'en',
                $path,
            );
        }
    }
}
