<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Doctrine\Repository;

use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function new(Account $account): void
    {
        $this->entityManager->persist($account);
        $this->update($account);
    }

    public function update(Account $account): void
    {
        $this->entityManager->flush();
    }

    public function findById(AccountId $id): ?Account
    {
        return $this->entityManager->find(Account::class, $id);
    }

    public function findByAccountName(AccountName $name): ?Account
    {
        return $this->entityManager->getRepository(Account::class)->findOneBy(['name' => $name]);
    }
}
