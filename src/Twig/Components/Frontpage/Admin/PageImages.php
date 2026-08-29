<?php

declare(strict_types=1);

namespace App\Twig\Components\Frontpage\Admin;

use App\Entity\User\Enums\UserRoles;
use App\Repository\Frontpage\PageRepository;
use App\Service\Frontpage\PageImageStore;
use App\ViewModel\Frontpage\PageImage;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Everything uploaded for one page, listed for the browser in the editor. Live, because the form around it is usually
 * half filled in and must survive a thumbnail being pressed.
 */
#[AsLiveComponent(
    name: 'Frontpage:Admin:PageImages',
    template: 'components/Frontpage/Admin/PageImages.html.twig',
)]
#[IsGranted(UserRoles::Board->value)]
final class PageImages
{
    use DefaultActionTrait;

    /** Settled on mount and checksummed from there, so it cannot be pointed at another page's images. */
    #[LiveProp]
    public ?string $scope = null;

    public ?string $feedback = null;

    public function __construct(
        private readonly PageImageStore $pageImageStore,
        private readonly PageRepository $pageRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(
        ?int $page = null,
        ?string $flowRun = null,
    ): void {
        $this->scope = $this->pageImageStore->scope(
            null === $page
                ? null
                : $this->pageRepository->find($page),
            $flowRun,
        );
    }

    public function getTopic(): ?string
    {
        if (null === $this->scope) {
            return null;
        }

        return $this->pageImageStore->topic($this->scope);
    }

    /**
     * @return list<PageImage>
     */
    public function getImages(): array
    {
        if (null === $this->scope) {
            return [];
        }

        return $this->pageImageStore->list($this->scope);
    }

    #[LiveAction]
    public function remove(
        #[LiveArg]
        string $path,
    ): void {
        if (null === $this->scope) {
            return;
        }

        if (
            $this->pageImageStore->remove(
                $this->scope,
                $path,
            )
        ) {
            return;
        }

        $this->feedback = $this->translator->trans('That image is not one of this page\'s own and was left alone.');
    }
}
