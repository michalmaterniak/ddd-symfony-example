<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Domain\Exception;

use App\Account\Domain\Exception\AccountCreationException;
use App\Account\Domain\ValueObject\AccountId;
use PHPUnit\Framework\TestCase;

final class AccountCreationExceptionTest extends TestCase
{
    public function testThrowBuildsExceptionWithAccountIdInDomainData(): void
    {
        $accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');

        $exception = AccountCreationException::throw($accountId);

        self::assertSame('Account creation failed', $exception->getMessage());
        self::assertSame(['accountId' => '9fc62b77-8dc6-4493-b245-6020d30573bd'], $exception->getDomainData());
    }

    public function testKeepsCustomMessage(): void
    {
        $exception = new AccountCreationException('custom', new AccountId());

        self::assertSame('custom', $exception->getMessage());
    }
}
