<?php

namespace App\Enums;

/**
 * Why a received notification was stored without being processed.
 *
 * An ignored notification is accepted and kept, so it can be looked up and
 * processed later, for example after a zaaktype mapping has been activated.
 */
enum NotificationIgnoreReason: string
{
    /** The notification is on a kanaal this application does not process. */
    case UnsupportedChannel = 'unsupported_channel';

    /** The actie and resource combination is not one this application processes. */
    case UnsupportedAction = 'unsupported_action';

    /** The zaak has a zaaktype without a mapping. */
    case UnknownZaaktype = 'unknown_zaaktype';

    /** The zaak has a zaaktype whose mapping is not active. */
    case InactiveZaaktype = 'inactive_zaaktype';
}
