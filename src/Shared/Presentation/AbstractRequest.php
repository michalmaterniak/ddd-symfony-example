<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use App\Shared\Infrastructure\Http\Exception\RequestValidationException;
use App\Shared\Infrastructure\Http\Exception\UrlParametersValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @template TPayload of array<string, mixed>
 * @template TParameters of array<string, mixed>
 */
abstract class AbstractRequest implements RequestValidation
{
    protected Request $request;

    /** @var TPayload */
    protected array $payload;

    /** @var TParameters */
    protected array $parameters;

    public function __construct(
        private ValidatorInterface $validator,
        RequestStack $requestStack,
    ) {
        $request = $requestStack->getCurrentRequest();
        if (false === $request instanceof Request) {
            throw new RuntimeException('Request is not set.');
        }

        $this->request = $request;

        /** @var TPayload $payload */
        $payload = $this->request->getPayload()->all();
        $this->payload = $payload;

        $parameters = [];
        foreach (array_keys($this->getParametersConstraints()->fields) as $name) {
            $parameters[$name] = $this->request->attributes->get((string) $name);
        }
        /** @var TParameters $parameters */
        $this->parameters = $parameters;

        $validationList = $this->validate();
        if (0 < $validationList->count()) {
            throw new RequestValidationException($validationList);
        }
    }

    public function validate(): ConstraintViolationListInterface
    {
        $this->validateParameters();

        return $this->validator->validate($this->payload, $this->getPayloadConstraints());
    }

    /**
     * Constraints for route attributes; only the attributes named in the collection are read.
     */
    abstract protected function getParametersConstraints(): Collection;

    abstract protected function getPayloadConstraints(): Collection;

    private function validateParameters(): void
    {
        $constraintViolationList = $this->validator->validate($this->parameters, $this->getParametersConstraints());
        if (0 < $constraintViolationList->count()) {
            throw new UrlParametersValidationException($constraintViolationList);
        }
    }
}
