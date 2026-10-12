<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Repository;

use App\Account\Domain\Entity\AccountEntry;
use App\Account\Domain\Repository\AccountEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAccountEntryRepository implements AccountEntryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function new(AccountEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
