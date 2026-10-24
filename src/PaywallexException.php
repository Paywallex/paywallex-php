<?php
declare(strict_types=1);

namespace Paywallex;

/** Erreur de l'API : testez getErrorCode() (stable), jamais le message. Signé : Superagent. */
final class PaywallexException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $param = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }

    public function getErrorCode(): string { return $this->errorCode; }
}
