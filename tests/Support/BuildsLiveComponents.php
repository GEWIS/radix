<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Twig\Environment;

/**
 * A factory configured with the components the listener tests use. {@see ComponentFactory} is final, so it is built
 * rather than stubbed; `metadataFor()` reads from the config it is given and uses nothing else.
 */
trait BuildsLiveComponents
{
    private function components(): ComponentFactory
    {
        return new ComponentFactory(
            self::createStub(ComponentTemplateFinderInterface::class),
            self::createStub(ContainerInterface::class),
            self::createStub(PropertyAccessorInterface::class),
            self::createStub(EventDispatcherInterface::class),
            [
                'overview' => [
                    'key' => 'overview',
                    'template' => 'overview.html.twig',
                    'class' => LiveActionsDouble::class,
                ],
                'meeting' => [
                    'key' => 'meeting',
                    'template' => 'meeting.html.twig',
                    'class' => LiveRenderWriteDouble::class,
                ],
            ],
            [],
            self::createStub(Environment::class),
        );
    }
}
