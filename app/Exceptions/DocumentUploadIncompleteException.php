<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The content of a changed document is still being uploaded in parts (bestandsdelen).
 *
 * This is temporary: the job is released and checks again once the upload may be complete.
 */
class DocumentUploadIncompleteException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The upload of the document is not complete');
    }
}
