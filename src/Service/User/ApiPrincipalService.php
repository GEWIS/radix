<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Database\User\ApiPrincipal;
use App\Repository\User\ApiPrincipalRepository;

final readonly class ApiPrincipalService
{
    public function __construct(private ApiPrincipalRepository $apiPrincipalRepository)
    {
    }

    /**
     * @return ApiPrincipal[]
     */
    public function findAll(): array
    {
        return $this->apiPrincipalRepository->findAll();
    }

    public function find(int $id): ?ApiPrincipal
    {
        return $this->apiPrincipalRepository->find($id);
    }

    public function create(ApiPrincipal $principal): string
    {
        $token = $principal->generateToken();

        $this->apiPrincipalRepository->persist($principal);

        return $token;
    }

    public function save(ApiPrincipal $principal): void
    {
        $this->apiPrincipalRepository->persist($principal);
    }

    public function revoke(ApiPrincipal $principal): void
    {
        $principal->revoke();

        $this->apiPrincipalRepository->persist($principal);
    }
}
