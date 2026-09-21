<?php
declare(strict_types=1);

namespace QRoute\Controllers;

/** Thrown to unwind out of a controller into an error response. */
final class HttpError extends \Exception
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message, $status);
    }
}
