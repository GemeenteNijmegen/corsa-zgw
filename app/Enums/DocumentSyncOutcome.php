<?php

namespace App\Enums;

/**
 * The result of adding a document to its zaak, or of updating it, in the document management system.
 */
enum DocumentSyncOutcome
{
    /** The document was sent to the document management system. */
    case Added;

    /** The document was already sent for this zaak, so nothing was sent again. */
    case AlreadySynced;

    /** The zaak is not in the document management system yet, so nothing was sent. */
    case ZaakNotInCorsa;

    /** A changed document was sent to the document management system. */
    case Updated;

    /** The document management system already has this version of the document, or a newer one. */
    case AlreadyUpToDate;

    /** The document is still being edited and has no content yet, so the placeholder stays. */
    case PlaceholderKept;

    /** The document is not in the document management system yet, so nothing was sent. */
    case DocumentNotInCorsa;

    /** The content of the document is still being uploaded in parts, so nothing was sent. */
    case UploadIncomplete;
}
