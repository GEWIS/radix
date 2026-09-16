<?php

declare(strict_types=1);

namespace App\Validator\Frontpage;

use App\Entity\Application\Enums\Languages;
use App\Form\Frontpage\Page\PageData;
use App\Repository\Frontpage\PageRepository;
use Override;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function implode;
use function in_array;

class UnclaimedPageAddressValidator extends ConstraintValidator
{
    /** The routes that serve a page rather than replace one, so an address matched by either is free. */
    private const array PAGE_ROUTES = [
        'page_route',
        'catch_all',
    ];

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly RouterInterface $router,
    ) {
    }

    #[Override]
    public function validate(
        mixed $value,
        Constraint $constraint,
    ): void {
        if (!$constraint instanceof UnclaimedPageAddress) {
            throw new UnexpectedTypeException(
                $constraint,
                UnclaimedPageAddress::class,
            );
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof PageData) {
            throw new UnexpectedValueException(
                $value,
                PageData::class,
            );
        }

        foreach (
            [
                [
                    Languages::Dutch,
                    'categoryNL',
                ],
                [
                    Languages::English,
                    'categoryEN',
                ],
            ] as [$language, $path]
        ) {
            $category = $value->slug(
                $language,
                'category',
            );

            if (null === $category) {
                continue;
            }

            $subCategory = $value->slug(
                $language,
                'subCategory',
            );
            $name = $value->slug(
                $language,
                'name',
            );

            if (
                $this->isAnsweredByTheApplication(
                    $language,
                    $category,
                    $subCategory,
                    $name,
                )
            ) {
                $this->context->buildViolation($constraint->reservedMessage)
                    ->atPath($path)
                    ->addViolation();

                continue;
            }

            $existing = $this->pageRepository->findPage(
                $language,
                $category,
                $subCategory,
                $name,
            );

            if (
                null === $existing
                || $existing->id === $value->pageId
            ) {
                continue;
            }

            $this->context->buildViolation($constraint->takenMessage)
                ->atPath($path)
                ->addViolation();
        }
    }

    /**
     * A real route is matched before the page route ever sees the request, so a page written at the same address
     * would be unreachable. The whole address is checked rather than its first segment: every page of the association
     * is under `association`, which is itself matched by a route further down.
     */
    private function isAnsweredByTheApplication(
        Languages $language,
        string $category,
        ?string $subCategory,
        ?string $name,
    ): bool {
        $segments = [];

        foreach (
            [
                $category,
                $subCategory,
                $name,
            ] as $segment
        ) {
            if (null === $segment) {
                continue;
            }

            $segments[] = $segment;
        }

        $path = '/' . $language->getLangParam() . '/' . implode(
            '/',
            $segments,
        );

        try {
            $match = $this->router->match($path);
        } catch (ResourceNotFoundException) {
            return false;
        } catch (MethodNotAllowedException) {
            // A route matches this address, but not the method of this request.
            return true;
        }

        return !in_array(
            $match['_route'] ?? '',
            self::PAGE_ROUTES,
            true,
        );
    }
}
