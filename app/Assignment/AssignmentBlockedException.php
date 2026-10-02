<?php

declare(strict_types=1);

namespace App\Assignment;

use RuntimeException;

/**
 * Thrown by the strict `handle()` entry points when policy blocks an assignment.
 *
 * Callers that want to render an outcome use `attempt()` and get an
 * {@see AssignmentItemResult} instead. This exists so that code which still calls
 * `handle()` and expects a model can never silently carry on as if a blocked
 * assignment had happened — it fails loudly, with the result attached.
 */
final class AssignmentBlockedException extends RuntimeException
{
    public function __construct(public readonly AssignmentItemResult $result, string $subjectName)
    {
        parent::__construct($result->message($subjectName));
    }
}
