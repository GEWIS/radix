<?php

declare(strict_types=1);

namespace App\Controller\Frontpage;

use App\Controller\Application\HandlesFormFlowTrait;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\Languages;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Entity\User\Enums\UserRoles;
use App\Form\Frontpage\Page\PageData;
use App\Form\Frontpage\Page\PageFlowType;
use App\Repository\Frontpage\PageRepository;
use App\Service\Application\FileStorageException;
use App\Service\Application\ImageUrlBuilder;
use App\Service\Frontpage\PageAdminService;
use App\Service\Frontpage\PageImageStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function strval;
use function usort;

/**
 * The pages the association writes itself: everything that is neither news nor a body's own page.
 *
 * A page is addressed by its own words rather than by an id, so the overview is grouped the way the address reads.
 * Saving one goes through {@see PageAdminService}, which is where the content is sanitized.
 */
#[Route(
    path: '/admin/pages',
    name: 'admin/frontpage/pages/',
)]
#[IsGranted(UserRoles::Board->value)]
class AdminPageController extends AbstractController
{
    use HandlesFormFlowTrait;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageAdminService $pageAdminService,
        private readonly PageImageStore $pageImageStore,
        private readonly ImageUrlBuilder $imageUrlBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        return $this->render(
            'frontpage/admin/pages/index.html.twig',
            ['pages' => $this->pagesByAddress()],
        );
    }

    #[Route(
        path: '/create',
        name: 'create',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function create(Request $request): Response
    {
        $page = new Page();
        $page->setCategory(new FrontpageLocalisedText());
        $page->setSubCategory(new FrontpageLocalisedText());
        $page->setName(new FrontpageLocalisedText());
        $page->setTitle(new FrontpageLocalisedText());
        $page->setContent(new FrontpageLocalisedText());
        $page->setRequiredRole(UserRoles::Guest);

        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            PageFlowType::class,
            new PageData(),
            ['flow_key' => $run],
        );
        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $data = $flow->getData();
            assert($data instanceof PageData);

            $data->applyTo($page);
            $this->pageAdminService->save(
                $page,
                $run,
            );
            $flow->reset();

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('The page was created.'),
            );

            return $this->redirectToRoute('admin/frontpage/pages/index');
        }

        $this->flashRejectedStep(
            $flow,
            $this->translator,
        );

        return $this->render(
            'frontpage/admin/pages/create.html.twig',
            [
                'form' => $flow->getStepForm(),
                'imageTopic' => $this->imageTopic(
                    null,
                    $run,
                ),
            ],
        );
    }

    #[Route(
        path: '/{page}/edit',
        name: 'edit',
        requirements: ['page' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        Page $page,
    ): Response {
        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            PageFlowType::class,
            PageData::fromEntity($page),
            [
                'flow_key' => $run,
                'role_editable' => UserRoles::ApiUser !== $page->getRequiredRole(),
            ],
        );
        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $data = $flow->getData();
            assert($data instanceof PageData);

            $data->applyTo($page);
            $this->pageAdminService->save(
                $page,
                $run,
            );
            $flow->reset();

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('The page was saved.'),
            );

            return $this->redirectToRoute('admin/frontpage/pages/index');
        }

        $this->flashRejectedStep(
            $flow,
            $this->translator,
        );

        return $this->render(
            'frontpage/admin/pages/edit.html.twig',
            [
                'form' => $flow->getStepForm(),
                'customPage' => $page,
                'imageTopic' => $this->imageTopic(
                    $page,
                    null,
                ),
            ],
        );
    }

    #[Route(
        path: '/{page}/delete',
        name: 'delete',
        requirements: ['page' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"page_delete-" ~ args["page"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function delete(Page $page): Response
    {
        $this->pageAdminService->delete($page);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The page was removed.'),
        );

        return $this->redirectToRoute('admin/frontpage/pages/index');
    }

    #[Route(
        path: '/upload',
        name: 'upload',
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: 'page_image_upload',
        tokenKey: '_csrf_token',
    )]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('image');

        if (!$file instanceof UploadedFile) {
            return new JsonResponse(
                ['error' => $this->translator->trans('No image was uploaded.')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $scope = $this->pageImageStore->scope(
            $this->uploadingPage($request),
            $request->request->getString('flow'),
        );

        if (null === $scope) {
            return new JsonResponse(
                ['error' => $this->translator->trans('It is not clear which page this image belongs to.')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $stored = $this->pageImageStore->store(
                $file->getPathname(),
                $scope,
            );
        } catch (FileStorageException) {
            return new JsonResponse(
                ['error' => $this->translator->trans('That file cannot be used as an image on a page.')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse([
            // The widest a page column ever is, which is what an image dropped into one should be served at.
            'url' => $this->imageUrlBuilder->url(
                $stored->path,
                ImageVariant::W1280,
            ),
        ]);
    }

    /** Null when there is nowhere for an image to go, so the browser listens to nothing rather than to everything. */
    private function imageTopic(
        ?Page $page,
        ?string $flowRun,
    ): ?string {
        $scope = $this->pageImageStore->scope(
            $page,
            $flowRun,
        );

        if (null === $scope) {
            return null;
        }

        return $this->pageImageStore->topic($scope);
    }

    /** A page being created names none, and an id answering to nothing is treated the same way rather than trusted. */
    private function uploadingPage(Request $request): ?Page
    {
        $id = $request->request->getInt('page');

        if (0 === $id) {
            return null;
        }

        return $this->pageRepository->find($id);
    }

    /**
     * Every page in the order its address reads, so the overview groups a category with what sits under it rather
     * than listing rows in whatever order they were written.
     *
     * @return list<Page>
     */
    private function pagesByAddress(): array
    {
        $pages = $this->pageRepository->findAll();
        $language = Languages::current();

        usort(
            $pages,
            static function (
                Page $a,
                Page $b,
            ) use ($language): int {
                return [
                    strval($a->getCategory()->getText($language)),
                    strval($a->getSubCategory()->getText($language)),
                    strval($a->getName()->getText($language)),
                ] <=> [
                    strval($b->getCategory()->getText($language)),
                    strval($b->getSubCategory()->getText($language)),
                    strval($b->getName()->getText($language)),
                ];
            },
        );

        return $pages;
    }
}
