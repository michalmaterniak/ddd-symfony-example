<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http\EventListener;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Http\EventListener\ExceptionListener;
use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use App\Shared\Infrastructure\Http\Exception\UrlParametersValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Throwable;

final class ExceptionListenerTest extends TestCase
{
    #[Test]
    public function rendersRequestValidationExceptionAsBadRequest(): void
    {
        // given
        $event = $this->event(new RequestValidationException(new ConstraintViolationList([
            new ConstraintViolation(
                'This field is missing.',
                null,
                [],
                null,
                '[side]',
                null,
            ),
            new ConstraintViolation(
                'The value you selected is not a valid choice.',
                null,
                [],
                null,
                '[amount][currency]',
                'EUR',
            ),
        ])));

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            Response::HTTP_BAD_REQUEST,
            [
                'type' => 'request-validation',
                'status' => 400,
                'message' => 'Request validation failed.',
                'violations' => [
                    ['propertyPath' => 'side', 'message' => 'This field is missing.'],
                    ['propertyPath' => 'amount.currency', 'message' => 'The value you selected is not a valid choice.'],
                ],
            ],
            $event,
        );
    }

    #[Test]
    public function rendersUrlParametersValidationExceptionAsNotFound(): void
    {
        // given
        $event = $this->event(new UrlParametersValidationException(new ConstraintViolationList([
            new ConstraintViolation('This is not a valid UUID.', null, [], null, 'accountId', 'abc'),
        ])));

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            Response::HTTP_NOT_FOUND,
            [
                'type' => 'url-parameters-validation',
                'status' => 404,
                'message' => 'URL parameters validation failed.',
                'violations' => [
                    ['propertyPath' => 'accountId', 'message' => 'This is not a valid UUID.'],
                ],
            ],
            $event,
        );
    }

    #[Test]
    public function rendersNotFoundHttpExceptionAsJson(): void
    {
        // given
        $event = $this->event(new NotFoundHttpException('Account not found.'));

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            Response::HTTP_NOT_FOUND,
            ['type' => 'not-found', 'status' => 404, 'message' => 'Account not found.'],
            $event,
        );
    }

    #[Test]
    public function rendersNotFoundHttpExceptionWithoutMessageAsJson(): void
    {
        // given
        $event = $this->event(new NotFoundHttpException());

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            Response::HTTP_NOT_FOUND,
            ['type' => 'not-found', 'status' => 404, 'message' => 'Not Found.'],
            $event,
        );
    }

    #[Test]
    public function rendersDomainExceptionAsUnprocessableEntity(): void
    {
        // given
        $event = $this->event($this->domainException('Account creation failed'));

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            422,
            ['type' => 'domain-validation', 'status' => 422, 'message' => 'Account creation failed'],
            $event,
        );
    }

    #[Test]
    public function unwrapsDomainExceptionFromHandlerFailedException(): void
    {
        // given
        $event = $this->event(new HandlerFailedException(
            new Envelope(new stdClass()),
            [new RuntimeException('other'), $this->domainException('Account creation failed')],
        ));

        // when
        new ExceptionListener()($event);

        // then
        self::assertResponse(
            422,
            ['type' => 'domain-validation', 'status' => 422, 'message' => 'Account creation failed'],
            $event,
        );
    }

    #[Test]
    public function leavesOtherExceptionsToFramework(): void
    {
        // given
        $event = $this->event(new RuntimeException('boom'));

        // when
        new ExceptionListener()($event);

        // then
        self::assertNull($event->getResponse());
    }

    /**
     * @param array<string, mixed> $expectedBody
     */
    private static function assertResponse(int $expectedStatus, array $expectedBody, ExceptionEvent $event): void
    {
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            $expectedBody,
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private function event(Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function domainException(string $message): DomainException
    {
        return new class($message) extends DomainException {
            public function getDomainData(): array
            {
                return [];
            }
        };
    }
}
