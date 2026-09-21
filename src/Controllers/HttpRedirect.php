<?php
declare(strict_types=1);

namespace QRoute\Controllers;

/**
 * Thrown to unwind out of a controller into a redirect, so guard clauses
 * such as requireUser() read as one line at the top of an action.
 */
final class HttpRedirect extends \Exception
{
    public function __construct(public readonly string $to, int $status = 302)
    {
        parent::__construct('Redirect to ' . $to, $status);
    }
}
