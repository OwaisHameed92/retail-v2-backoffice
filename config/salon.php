<?php

/*
| Salon / Barber on the portal (docs/salon.md). The till's salon part is not released yet, so everything the portal
| serves for it from W3 on sits behind one switch, `SALON_ONLINE`, default off and off in production until the salon
| release: the staff calendar feeds (W3b), the tenant "Salon" page and the admin salon summary. Off: the iCal address
| answers 404, the page and its menu item are hidden and its routes answer 404.
*/

return [
    'online' => (bool) env('SALON_ONLINE', false),

    // Staff calendar feed (iCal): requests per minute per address (IP), on top of the unguessable token.
    'ical_per_minute' => (int) env('SALON_ICAL_PER_MINUTE', 60),
];
