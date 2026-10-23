<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another job is sending the same document to the document management system right now.
 *
 * This is temporary: the job is released and tries again once the other job has registered
 * the document, and then finds it in the register.
 */
class DocumentLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The document is being sent by another job');
    }
}
