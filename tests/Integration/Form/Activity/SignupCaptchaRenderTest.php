<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form\Activity;

use App\Entity\Activity\SignupList;
use App\Form\Activity\SignupType;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

use function preg_match;

/**
 * The captcha on the guest sign-up form is rendered inside a live component that re-renders on every field change.
 * The widget is a custom element that keeps the solved proof of work in a DOM of its own, and the bundle gives it a
 * random id on every render, which is what the live morph reads before it reads the `data-live-ignore` the bundle
 * puts on the widget. Filling in a field therefore emptied the widget and left nothing to solve.
 *
 * The form theme wraps it in a container whose id is the field's, so these pin that the container is there and that
 * its id is the same on a re-render while the widget's own is not.
 */
final class SignupCaptchaRenderTest extends DatabaseTestCase
{
    public function testTheWidgetIsRenderedInAContainerTheLiveMorphLeavesAlone(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression(
            '/<div id="[^"]+_altcha" data-live-ignore>\s*<div data-controller="[^"]*altcha"/',
            $html,
        );
        self::assertStringContainsString(
            '<altcha-widget',
            $html,
        );
    }

    public function testTheContainerKeepsItsIdWhileTheWidgetDoesNot(): void
    {
        $first = $this->render();
        $second = $this->render();

        self::assertSame(
            $this->containerId($first),
            $this->containerId($second),
        );
        self::assertNotSame(
            $this->widgetId($first),
            $this->widgetId($second),
            'The widget id is random per render, which is the reason the container exists.',
        );
    }

    private function containerId(string $html): string
    {
        return $this->firstMatch(
            '/<div id="([^"]+_altcha)" data-live-ignore>/',
            $html,
        );
    }

    private function widgetId(string $html): string
    {
        return $this->firstMatch(
            '/<altcha-widget\s+id="([^"]+)"/',
            $html,
        );
    }

    private function firstMatch(
        string $pattern,
        string $html,
    ): string {
        $matches = [];
        self::assertSame(
            1,
            preg_match(
                $pattern,
                $html,
                $matches,
            ),
            $html,
        );

        return $matches[1];
    }

    /**
     * The captcha row of the guest form, rendered the way the live component renders it.
     */
    private function render(): string
    {
        $list = $this->entityManager->getRepository(SignupList::class)->findOneBy([]);
        self::assertInstanceOf(
            SignupList::class,
            $list,
            'The seed is expected to contain a sign-up list.',
        );

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            SignupType::class,
            null,
            [
                'signupList' => $list,
                'mode' => SignupType::MODE_EXTERNAL,
                'csrf_protection' => false,
            ],
        );

        return self::getContainer()->get(Environment::class)
            ->createTemplate('{{ form_row(form.security) }}')
            ->render(['form' => $form->createView()]);
    }
}
