<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http\Exception;

use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class RequestValidationExceptionTest extends TestCase
{
    #[Test]
    public function carriesViolationsAsBadRequest(): void
    {
        // given
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This field is missing.', null, [], null, '[side]', null),
            new ConstraintViolation('This value should be positive.', null, [], null, '[amount][amount]', 0),
        ]);

        // when
        $exception = new RequestValidationException($violations);

        // then
        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame($violations, $exception->getViolations());
        self::assertSame(
            "[side]: This field is missing.\n[amount][amount]: This value should be positive.",
            $exception->getMessage(),
        );
    }
}
