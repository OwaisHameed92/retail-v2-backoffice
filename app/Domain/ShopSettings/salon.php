<?php

/**
 * Salon pack 2026-11-08 (W2; the till's unreleased `feat/salon-barber` branch): the `salon.*` settings a salon's till
 * pushes, shown read-only on the Till settings page in Salon sections, only for Salon / Barber shops
 * (VerticalSettings, SalonShops). Every key is a shared key of the salon pack's `samples/settings-local-only.json`
 * (a test keeps it that way). The portal never writes them: "the till never takes a pulled `salon.*` value"
 * (SettingSyncPolicy::TILL_WRITES_ONLY_PREFIXES).
 *
 * Left out on purpose: the local-only keys (`salon.queue.board_*` / `checkin_*` belong to one PC; the secrets
 * `salon.integrations.meta_access_token`, `salon.integrations.sms_secret`, `salon.online.manage_link_key` never leave
 * the till and are never stored).
 */
$choice = /** @param list<string> $options */ static fn (string $label, array $options, string $default, string $help): array => [
    'label' => $label, 'type' => 'choice', 'options' => $options, 'default' => $default, 'help' => $help,
];

return [
    'salon' => [
        'title' => 'Salon / Barber',
        'description' => 'Set on the salon\'s tills. Shown here as the tills sent them.',
        'settings' => [
            'salon.clients_we_serve' => $choice('Clients we serve', ['Both', 'Men', 'Women'], 'Both', 'Who the salon\'s menu and booking page are for.'),
            'salon.staff_word' => $choice('We call our team', ['Stylist', 'Barber'], 'Stylist', 'The word the till uses for the people who do the services.'),
            'salon.slot_interval_min' => $choice('Diary slots (minutes)', ['5', '10', '15', '20', '30', '60'], '15', 'How the diary is divided.'),
            'salon.clients.patch_test_valid_days' => ['label' => 'Patch test valid for (days)', 'type' => 'int', 'default' => '180', 'help' => 'After this a client needs a new patch test.'],
            'salon.till.rebook_prompt' => ['label' => 'Ask to book the next visit', 'type' => 'bool', 'default' => 'false', 'help' => 'The till asks to book the client\'s next visit at checkout.'],
            'salon.till.rebook_weeks' => ['label' => 'Next visit in (weeks)', 'type' => 'int', 'default' => '6', 'help' => 'The suggested gap before the next visit.'],
            'salon.tips.ask' => ['label' => 'Ask for a tip', 'type' => 'text', 'help' => 'Whether the till offers a tip at payment.'],
            'salon.tips.presets' => ['label' => 'Tip buttons', 'type' => 'text', 'help' => 'The tip amounts the till offers.'],
            'salon.tips.policy' => ['label' => 'How tips are shared', 'type' => 'text', 'help' => 'How the till splits a tip between the team.'],
        ],
    ],
    'salonDiary' => [
        'title' => 'Salon diary and queue',
        'description' => 'Set on the salon\'s tills from the Diary and Queue screens.',
        'settings' => [
            'salon.diary.day_start' => ['label' => 'Diary starts at', 'type' => 'time', 'help' => 'The first time shown in the diary.'],
            'salon.diary.day_end' => ['label' => 'Diary ends at', 'type' => 'time', 'help' => 'The last time shown in the diary.'],
            'salon.diary.auto_accept_online' => ['label' => 'Accept online bookings automatically', 'type' => 'bool', 'help' => 'The till books a request at once when the time is still free; a clash always waits for the desk.'],
            'salon.diary.show_cancelled' => ['label' => 'Show cancelled appointments', 'type' => 'bool', 'help' => 'Cancelled appointments stay visible in the diary.'],
            'salon.diary.colour_by' => $choice('Colour appointments by', ['status', 'service'], 'status', 'How the diary colours each appointment.'),
            'salon.diary.waitlist_offer_minutes' => ['label' => 'Waiting list offer lasts (minutes)', 'type' => 'int', 'help' => 'How long a client on the waiting list has to take a freed time.'],
            'salon.diary.late_cancel_hours' => ['label' => 'Late cancellation (hours before)', 'type' => 'int', 'default' => '24', 'help' => 'A cancellation inside this time counts as late.'],
            'salon.diary.deposit_late_cancel' => $choice('Deposit on a late cancellation', ['keep', 'refund'], 'keep', 'What happens to a deposit when the client cancels late.'),
            'salon.diary.fee_percent' => ['label' => 'Missed appointment fee', 'type' => 'percent', 'default' => '0', 'help' => 'Share of the booked price asked for a no-show or late cancellation.'],
            'salon.queue.enabled' => ['label' => 'Walk-in queue', 'type' => 'bool', 'default' => 'true', 'help' => 'The till keeps a queue of walk-in clients.'],
            'salon.queue.max_length' => ['label' => 'Longest queue', 'type' => 'int', 'default' => '0', 'help' => '0 means no limit.'],
            'salon.queue.no_show_after_min' => ['label' => 'No-show after (minutes)', 'type' => 'int', 'default' => '10', 'help' => 'A called client who does not come is marked a no-show.'],
            'salon.queue.fair_turns' => ['label' => 'Fair turns', 'type' => 'bool', 'help' => 'Walk-ins go to the team member whose turn it is.'],
            'salon.queue.print_ticket' => ['label' => 'Print queue tickets', 'type' => 'bool', 'help' => 'The till prints a ticket when a client joins the queue.'],
            'salon.queue.messages' => ['label' => 'Queue messages', 'type' => 'bool', 'default' => 'true', 'help' => 'The till texts "you\'re next" to clients who left a phone number.'],
            'salon.queue.paused' => ['label' => 'Queue paused', 'type' => 'bool', 'help' => 'Set by Pause on the till\'s Queue screen.'],
        ],
    ],
    'salonMessages' => [
        'title' => 'Salon messages',
        'description' => 'Set on the salon\'s main till. Provider passwords and tokens stay on the till and are never shown here.',
        'settings' => [
            'salon.messages.automations' => ['label' => 'Automatic messages', 'type' => 'multiline', 'help' => 'Which reminders and follow-ups the till sends.'],
            'salon.messages.send_from' => ['label' => 'Send messages from', 'type' => 'time', 'help' => 'No message goes out before this time.'],
            'salon.messages.send_until' => ['label' => 'Send messages until', 'type' => 'time', 'help' => 'No message goes out after this time.'],
            'salon.messages.no_sundays' => ['label' => 'No messages on Sundays', 'type' => 'bool', 'help' => 'Marketing waits until Monday.'],
            'salon.messages.cap_messages' => ['label' => 'Marketing messages at most', 'type' => 'int', 'help' => 'The most marketing messages one client gets in the period below.'],
            'salon.messages.cap_days' => ['label' => 'In (days)', 'type' => 'int', 'help' => 'The period for the limit above.'],
            'salon.messages.review_link' => ['label' => 'Review link', 'type' => 'text', 'help' => 'Where a review request sends the client.'],
            'salon.messages.review_every_months' => ['label' => 'Ask for a review every (months)', 'type' => 'int', 'help' => 'A client is asked for a review at most this often.'],
            'salon.messages.sender_notes' => ['label' => 'Sender notes', 'type' => 'multiline', 'help' => 'The salon\'s own notes about its message sender.'],
            'salon.integrations.whatsapp_provider' => $choice('WhatsApp sent by', ['Shop', 'MetaCloud'], 'Shop', 'The shop\'s own WhatsApp, or the WhatsApp Business platform.'),
            'salon.integrations.meta_phone_number_id' => ['label' => 'WhatsApp Business number id', 'type' => 'text', 'help' => 'The number the WhatsApp Business platform sends from.'],
            'salon.integrations.meta_template_language' => ['label' => 'WhatsApp template language', 'type' => 'text', 'default' => 'en_GB', 'help' => 'The language of the approved message templates.'],
            'salon.integrations.meta_templates' => ['label' => 'WhatsApp templates', 'type' => 'multiline', 'help' => 'Which approved template each automatic message uses.'],
            'salon.integrations.sms_provider' => $choice('Texts sent by', ['Shop', 'Twilio', 'Esendex'], 'Shop', 'The shop\'s own phone, or a text message provider.'),
            'salon.integrations.sms_account' => ['label' => 'Text provider account', 'type' => 'text', 'help' => 'The salon\'s account with its text provider.'],
            'salon.integrations.sms_login' => ['label' => 'Text provider login', 'type' => 'text', 'help' => 'The login name (the password stays on the till).'],
            'salon.integrations.sms_sender' => ['label' => 'Texts come from', 'type' => 'text', 'help' => 'The name or number clients see.'],
        ],
    ],
    'salonOnline' => [
        'title' => 'Salon online booking',
        'description' => 'Set on the salon\'s main till, Online booking tab. The booking addresses are the portal\'s, written back by the till.',
        'settings' => [
            'salon.online.enabled' => ['label' => 'Online booking', 'type' => 'bool', 'help' => 'Clients can ask for a time on the booking page.'],
            'salon.online.slot_interval_min' => ['label' => 'Start times every (minutes)', 'type' => 'int', 'help' => 'How far apart the offered start times are.'],
            'salon.online.min_notice_min' => ['label' => 'Shortest notice (minutes)', 'type' => 'int', 'help' => 'No time is offered sooner than this.'],
            'salon.online.days_ahead' => ['label' => 'Book up to (days ahead)', 'type' => 'int', 'help' => 'How far ahead clients can book.'],
            'salon.online.buffer_min' => ['label' => 'Gap after an online booking (minutes)', 'type' => 'int', 'help' => 'Kept free after each online booking.'],
            'salon.online.change_window_hours' => ['label' => 'Changes allowed until (hours before)', 'type' => 'int', 'help' => 'After this a client must ring to move or cancel.'],
            'salon.online.hold_min' => ['label' => 'Hold a requested time (minutes)', 'type' => 'int', 'help' => 'How long a request holds its time while the shop answers.'],
            'salon.online.offline_after_min' => ['label' => 'Request only after (minutes offline)', 'type' => 'int', 'default' => '10', 'help' => 'With no word from the till for this long, the page takes requests only.'],
            'salon.online.ask_patch_test' => ['label' => 'Ask about patch tests', 'type' => 'bool', 'help' => 'The page asks the patch-test question for services that need one.'],
            'salon.online.questions' => ['label' => 'Questions for the client', 'type' => 'multiline', 'help' => 'Asked on the booking page.'],
            'salon.online.terms' => ['label' => 'Booking terms', 'type' => 'multiline', 'help' => 'The client accepts these when booking.'],
            'salon.online.deposit_note' => ['label' => 'Deposit note', 'type' => 'multiline', 'help' => 'Shown on the booking page about deposits.'],
            'salon.online.queue_join' => ['label' => 'Join the queue from a phone', 'type' => 'bool', 'help' => 'The door QR code lets walk-ins join the queue.'],
            'salon.online.booking_url' => ['label' => 'Booking page address', 'type' => 'text', 'help' => 'The public booking page.'],
            'salon.online.queue_url' => ['label' => 'Queue join address', 'type' => 'text', 'help' => 'The page the door QR code opens.'],
            'salon.online.manage_url' => ['label' => 'Manage link address', 'type' => 'text', 'help' => 'Where a client moves or cancels a booking.'],
        ],
    ],
];
