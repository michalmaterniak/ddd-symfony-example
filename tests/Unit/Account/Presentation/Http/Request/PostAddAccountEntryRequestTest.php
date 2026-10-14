<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account\Presentation\Http\Request;

use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Presentation\Http\Request\PostAddAccountEntryRequest;
use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use App\Shared\Infrastructure\Http\Exception\UrlParametersValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

final class PostAddAccountEntryRequestTest extends TestCase
{
    private const string ACCOUNT_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    #[Test]
    public function exposesValidPayload(): void
    {
        // given
        $requestStack = $this->requestStack(self::ACCOUNT_ID, self::validPayload());

        // when
        $request = $this->createRequest($requestStack);

        // then
        self::assertSame(self::ACCOUNT_ID, $request->getAccountId()->toRfc4122());
        self::assertSame(AccountEntrySide::CREDIT, $request->getSide());
        self::assertSame(1500, $request->getAmount());
        self::assertEquals(new DateTimeImmutable('2026-10-14T12:30:00+02:00'), $request->getEffectiveAt());
    }

    #[Test]
    public function acceptsDebitSide(): void
    {
        // given
        $requestStack = $this->requestStack(self::ACCOUNT_ID, ['side' => 'debit'] + self::validPayload());

        // when
        $request = $this->createRequest($requestStack);

        // then
        self::assertSame(AccountEntrySide::DEBIT, $request->getSide());
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $expectedViolations propertyPath => message
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function rejectsInvalidPayload(array $payload, array $expectedViolations): void
    {
        // given
        $requestStack = $this->requestStack(self::ACCOUNT_ID, $payload);

        // when
        try {
            $this->createRequest($requestStack);
            self::fail('RequestValidationException was not thrown.');
        } catch (RequestValidationException $exception) {
            // then
            self::assertSame($expectedViolations, $this->violations($exception));
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function invalidPayloads(): iterable
    {
        $valid = self::validPayload();

        yield 'empty payload' => [
            [],
            [
                '[side]' => 'This field is missing.',
                '[amount]' => 'This field is missing.',
                '[effectiveAt]' => 'This field is missing.',
            ],
        ];
        yield 'unknown side' => [
            ['side' => 'both'] + $valid,
            ['[side]' => 'The value you selected is not a valid choice.'],
        ];
        yield 'side not a string' => [
            ['side' => 1] + $valid,
            ['[side]' => 'This value should be of type string.'],
        ];
        yield 'amount not an object' => [
            ['amount' => 1500] + $valid,
            ['[amount]' => 'This value should be of type array.'],
        ];
        yield 'amount value missing' => [
            ['amount' => ['currency' => 'PLN']] + $valid,
            ['[amount][value]' => 'This field is missing.'],
        ];
        yield 'amount value zero' => [
            ['amount' => ['value' => 0, 'currency' => 'PLN']] + $valid,
            ['[amount][value]' => 'This value should be positive.'],
        ];
        yield 'amount value negative' => [
            ['amount' => ['value' => -1, 'currency' => 'PLN']] + $valid,
            ['[amount][value]' => 'This value should be positive.'],
        ];
        yield 'amount value not an integer' => [
            ['amount' => ['value' => 15.5, 'currency' => 'PLN']] + $valid,
            ['[amount][value]' => 'This value should be of type integer.'],
        ];
        yield 'unsupported currency' => [
            ['amount' => ['value' => 1500, 'currency' => 'EUR']] + $valid,
            ['[amount][currency]' => 'The value you selected is not a valid choice.'],
        ];
        yield 'extra field in amount' => [
            ['amount' => ['value' => 1500, 'currency' => 'PLN', 'scale' => 2]] + $valid,
            ['[amount][scale]' => 'This field was not expected.'],
        ];
        yield 'effectiveAt without timezone' => [
            ['effectiveAt' => '2026-10-14 12:30:00'] + $valid,
            ['[effectiveAt]' => 'This value is not a valid datetime.'],
        ];
        yield 'effectiveAt blank' => [
            ['effectiveAt' => ''] + $valid,
            ['[effectiveAt]' => 'This value should not be blank.'],
        ];
        yield 'extra field' => [
            ['note' => 'x'] + $valid,
            ['[note]' => 'This field was not expected.'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAccountIds')]
    public function rejectsInvalidAccountIdAsUrlParameterError(mixed $accountId, string $expectedMessage): void
    {
        // given
        $requestStack = $this->requestStack($accountId, self::validPayload());

        // when
        try {
            $this->createRequest($requestStack);
            self::fail('UrlParametersValidationException was not thrown.');
        } catch (UrlParametersValidationException $exception) {
            // then
            self::assertSame(['[accountId]' => $expectedMessage], $this->violations($exception));
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidAccountIds(): iterable
    {
        yield 'missing' => [null, 'This value should not be blank.'];
        yield 'not a uuid' => ['abc', 'This is not a valid UUID.'];
    }

    #[Test]
    public function validatesUrlParametersBeforePayload(): void
    {
        // given
        $requestStack = $this->requestStack('abc', []);

        // then
        $this->expectException(UrlParametersValidationException::class);

        // when
        $this->createRequest($requestStack);
    }

    #[Test]
    public function requiresCurrentRequest(): void
    {
        // then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Request is not set.');

        // when
        $this->createRequest(new RequestStack());
    }

    private function createRequest(RequestStack $requestStack): PostAddAccountEntryRequest
    {
        return new PostAddAccountEntryRequest(Validation::createValidator(), $requestStack);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestStack(mixed $accountId, array $payload): RequestStack
    {
        $request = Request::create(
            uri: '/api/v1/account/'.(\is_string($accountId) ? $accountId : '').'/account-entry',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('accountId', $accountId);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'side' => 'credit',
            'amount' => ['value' => 1500, 'currency' => 'PLN'],
            'effectiveAt' => '2026-10-14T12:30:00+02:00',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function violations(RequestValidationException|UrlParametersValidationException $exception): array
    {
        $violations = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($exception->getViolations() as $violation) {
            $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $violations;
    }
}
