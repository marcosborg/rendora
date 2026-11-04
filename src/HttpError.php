<?php
declare(strict_types=1);
namespace HomeAlone;
final class HttpError extends \RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
