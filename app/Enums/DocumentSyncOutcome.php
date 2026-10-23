<?php

namespace App\Enums;

/**
 * The result of adding a document to its zaak in the document management system.
 */
enum DocumentSyncOutcome
{
    /** The document was sent to the document management system. */
    case Added;

    /** The document was already sent for this zaak, so nothing was sent again. */
    case AlreadySynced;

    /** The zaak is not in the document management system yet, so nothing was sent. */
    case ZaakNotInCorsa;
}
