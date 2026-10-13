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
    private const int ADDITIONAL_ACCOUNTS = 49;

    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= self::ADDITIONAL_ACCOUNTS + 1; ++$i) {
            $manager->persist(
                Account::create(
                    new CreateAccountDto(
                        AccountId::fromString(\sprintf('00000000-0000-4000-8000-%012d', $i)),
                        new AccountName(\sprintf('account-%02d', $i)),
                        new AccountDescription(\sprintf('Test account %d for dev testing', $i)),
                        new DateTimeImmutable('2026-09-30 21:37:00'),
                    ),
                ),
            );
        }

        $manager->flush();
    }
}
