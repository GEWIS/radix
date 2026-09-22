<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision\Admin;

use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\User;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\Admin\ReferenceLibrary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_key_exists;
use function explode;
use function html_entity_decode;
use function is_array;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function sprintf;

/**
 * Exercises the reference library component as the framework does, the way the meeting management test does: the
 * real instance with its real services, after authenticating on the token storage.
 */
final class ReferenceLibraryTest extends DatabaseTestCase
{
    /**
     * The rename input binds to a path inside the pending renames, and the client rejects a model path whose every
     * level does not already exist among the props. They are dehydrated before the template runs, so this renders
     * the component rather than reading the array off it: seeding it during the render is what "Invalid model name"
     * was.
     */
    public function testEveryInlineInputHasAModelPathAmongTheProps(): void
    {
        $this->authenticate();

        $html = $this->renderLibrary();
        $props = $this->propsOf($html);
        $paths = $this->modelPathsOf($html);

        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            self::assertNotNull(
                $this->resolve(
                    $props,
                    $path,
                ),
                sprintf(
                    'The model path "%s" is not among the props.',
                    $path,
                ),
            );
        }
    }

    /**
     * The renames are cleared to the current names, so the inputs of the next render have a model path again.
     */
    public function testSyncEditsLeavesEveryDocumentSeeded(): void
    {
        $this->authenticate();
        $component = self::getContainer()->get(ReferenceLibrary::class);
        $documents = $this->entityManager->getRepository(ReferenceDocument::class)->findAll();
        self::assertNotEmpty($documents);

        $component->syncEdits();

        foreach ($documents as $document) {
            self::assertArrayHasKey(
                (string) $document->id,
                $component->nameEdits,
            );
        }
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [
                'ROLE_BOARD',
                'ROLE_DATABASE_ADMIN',
            ],
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $request->cookies->set(
            $session->getName(),
            $session->getId(),
        );
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(SudoMode::class)->grant();
    }

    /**
     * The component as the page renders it, which is the only way to read the props sent to the client.
     */
    private function renderLibrary(): string
    {
        return self::getContainer()->get('twig')->createTemplate(
            "{{ component('Decision:Admin:ReferenceLibrary') }}",
        )->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function propsOf(string $html): array
    {
        self::assertSame(
            1,
            preg_match(
                '/data-live-props-value="([^"]*)"/',
                $html,
                $matches,
            ),
        );

        $props = json_decode(
            html_entity_decode($matches[1]),
            true,
        );
        self::assertIsArray($props);

        return $props;
    }

    /**
     * The model of every input that binds to one, without the modifiers that may precede it.
     *
     * @return list<string>
     */
    private function modelPathsOf(string $html): array
    {
        preg_match_all(
            '/data-model="(?:[^"]*\|)?([^"]*)"/',
            $html,
            $matches,
        );

        return $matches[1];
    }

    /**
     * Resolves a model path the way the client does: walk the props level by level, and return null when one of
     * those levels is missing.
     *
     * @param array<string, mixed> $props
     */
    private function resolve(
        array $props,
        string $path,
    ): mixed {
        $current = $props;
        $parts = explode(
            '.',
            $path,
        );

        foreach ($parts as $part) {
            if (
                !is_array($current)
                || !array_key_exists(
                    $part,
                    $current,
                )
            ) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }
}
