<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\EventListener;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use App\Shared\Infrastructure\Http\Exception\UrlParametersValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Throwable;

/**
 * Setting the response stops propagation, so Symfony's ErrorListener::logKernelException() does not run
 * for the exceptions handled here: they are logged by this listener instead.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

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
                $this->logger->warning('Request validation failed.', [
                    'exception' => $exception,
                    'status' => Response::HTTP_BAD_REQUEST,
                    'violations' => $this->violations($exception->getViolations()),
                ]);
                break;
            case $exception instanceof UrlParametersValidationException:
                $event->setResponse($this->validationResponse(
                    $exception->getViolations(),
                    'url-parameters-validation',
                    'URL parameters validation failed.',
                    Response::HTTP_NOT_FOUND,
                ));
                $this->logger->info('URL parameters validation failed.', [
                    'exception' => $exception,
                    'status' => Response::HTTP_NOT_FOUND,
                    'violations' => $this->violations($exception->getViolations()),
                ]);
                break;
            case $exception instanceof NotFoundHttpException: // after UrlParametersValidationException (subclass)
                $event->setResponse($this->notFoundResponse($exception));
                $this->logger->info('Not found.', [
                    'exception' => $exception,
                    'status' => Response::HTTP_NOT_FOUND,
                ]);
                break;
            case null !== $domainException:
                $event->setResponse($this->domainResponse($domainException));
                $this->logger->warning('Domain validation failed.', [
                    'exception' => $exception,
                    'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'domainException' => $domainException::class,
                ]);
                break;
            case $exception instanceof HttpExceptionInterface
                && Response::HTTP_INTERNAL_SERVER_ERROR > $exception->getStatusCode():
                // other client errors (e.g. 405) are rendered and logged by the framework
                break;
            default:
                $event->setResponse($this->internalErrorResponse());
                $this->logger->critical('Internal error.', [
                    'exception' => $exception,
                    'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                ]);
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

    private function internalErrorResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'internal-error',
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'message' => 'Internal error.',
            ],
            Response::HTTP_INTERNAL_SERVER_ERROR,
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
        return new JsonResponse(
            [
                'type' => $type,
                'status' => $status,
                'message' => $message,
                'violations' => $this->violations($violationList),
            ],
            $status,
        );
    }

    /**
     * @return list<array{propertyPath: string, message: string}>
     */
    private function violations(ConstraintViolationListInterface $violationList): array
    {
        $violations = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($violationList as $violation) {
            $violations[] = [
                'propertyPath' => $this->propertyPath($violation->getPropertyPath()),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
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
