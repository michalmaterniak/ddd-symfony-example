<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\EventListener;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use App\Shared\Infrastructure\Http\Exception\UrlParametersValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Throwable;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
readonly class ExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $domainException = $this->findDomainException($exception);

        switch (true) {
            case $exception instanceof RequestValidationException:
                $event->setResponse($this->validationResponse(
                    $exception->getViolations(),
                    'request-validation',
                    'Request validation failed.',
                    Response::HTTP_BAD_REQUEST,
                ));
                break;
            case $exception instanceof UrlParametersValidationException:
                $event->setResponse($this->validationResponse(
                    $exception->getViolations(),
                    'url-parameters-validation',
                    'URL parameters validation failed.',
                    Response::HTTP_NOT_FOUND,
                ));
                break;
            case $exception instanceof NotFoundHttpException: // after UrlParametersValidationException (subclass)
                $event->setResponse($this->notFoundResponse($exception));
                break;
            case null !== $domainException:
                $event->setResponse($this->domainResponse($domainException));
                break;
        }
    }

    private function domainResponse(DomainException $exception): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'domain-validation',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => $exception->getMessage(),
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function notFoundResponse(NotFoundHttpException $exception): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'not-found',
                'status' => Response::HTTP_NOT_FOUND,
                'message' => '' !== $exception->getMessage() ? $exception->getMessage() : 'Not Found.',
            ],
            Response::HTTP_NOT_FOUND,
            $exception->getHeaders(),
        );
    }

    private function validationResponse(
        ConstraintViolationListInterface $violationList,
        string $type,
        string $message,
        int $status,
    ): JsonResponse {
        $violations = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($violationList as $violation) {
            $violations[] = [
                'propertyPath' => $this->propertyPath($violation->getPropertyPath()),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return new JsonResponse(
            [
                'type' => $type,
                'status' => $status,
                'message' => $message,
                'violations' => $violations,
            ],
            $status,
        );
    }

    /**
     * Domain exceptions thrown by a handler dispatched synchronously arrive wrapped in HandlerFailedException.
     */
    private function findDomainException(Throwable $exception): ?DomainException
    {
        if ($exception instanceof DomainException) {
            return $exception;
        }

        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions(DomainException::class, true) as $wrapped) {
                return $wrapped;
            }
        }

        return null;
    }

    /**
     * "[amount][currency]" (Collection constraint) -> "amount.currency".
     */
    private function propertyPath(string $propertyPath): string
    {
        return str_replace('][', '.', trim($propertyPath, '[]'));
    }
}
