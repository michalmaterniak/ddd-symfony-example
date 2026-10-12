<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\DataFixtures;

use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AccountFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $accountId = AccountId::fromString('9fc62b77-8dc6-4493-b245-6020d30573bd');
        $account = Account::create(
            new CreateAccountDto(
                $accountId,
                new AccountName('account'),
                new AccountDescription('Account for dev testing'),
                new DateTimeImmutable('2026-09-30 21:37:00'),
            ),
        );
        $manager->persist($account);
        $manager->flush();
    }
}
