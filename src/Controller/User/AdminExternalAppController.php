<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Application\HandlesFormFlowTrait;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\ExternalApp;
use App\Form\User\ExternalApp\ExternalAppData;
use App\Form\User\ExternalApp\ExternalAppFlowType;
use App\Repository\User\ExternalAppRepository;
use App\Service\User\ExternalAppService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * Manage the external applications that may authenticate members. An application is retired by disabling it or setting
 * an expiry rather than deleting it, so the authentication history it is tied to is kept.
 */
#[IsGranted(
    attribute: UserRoles::Admin->value,
    message: 'You are not allowed to administer external applications.',
)]
#[Route(
    path: '/admin/users/apps',
    name: 'admin/users/apps/',
)]
class AdminExternalAppController extends AbstractController
{
    use HandlesFormFlowTrait;

    public function __construct(
        private readonly ExternalAppService $externalAppService,
        private readonly TranslatorInterface $translator,
        private readonly ExternalAppRepository $externalAppRepository,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        return $this->render(
            'user/admin/external-app/index.html.twig',
            [
                'apps' => $this->externalAppRepository->findBy(
                    [],
                    ['appId' => 'ASC'],
                ),
            ],
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
        $flow = $this->createFlow(
            ExternalAppFlowType::class,
            new ExternalAppData(),
            ['flow_key' => 'create'],
        );
        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $data = $flow->getData();
            assert($data instanceof ExternalAppData);

            $externalApp = new ExternalApp();
            $data->applyTo($externalApp);
            $this->externalAppService->save($externalApp);
            $flow->reset();

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('The external application has been created.'),
            );

            return $this->redirectToRoute('admin/users/apps/index');
        }

        $this->flashRejectedStep(
            $flow,
            $this->translator,
        );

        return $this->render(
            'user/admin/external-app/form.html.twig',
            [
                'form' => $flow->getStepForm(),
                'externalApp' => null,
            ],
        );
    }

    #[Route(
        path: '/{externalApp}/edit',
        name: 'edit',
        requirements: ['externalApp' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        ExternalApp $externalApp,
    ): Response {
        $flow = $this->createFlow(
            ExternalAppFlowType::class,
            ExternalAppData::fromEntity($externalApp),
            ['flow_key' => (string) $externalApp->getId()],
        );
        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $data = $flow->getData();
            assert($data instanceof ExternalAppData);

            $data->applyTo($externalApp);
            $this->externalAppService->save($externalApp);
            $flow->reset();

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('The external application has been updated.'),
            );

            return $this->redirectToRoute('admin/users/apps/index');
        }

        $this->flashRejectedStep(
            $flow,
            $this->translator,
        );

        return $this->render(
            'user/admin/external-app/form.html.twig',
            [
                'form' => $flow->getStepForm(),
                'externalApp' => $externalApp,
            ],
        );
    }
}
