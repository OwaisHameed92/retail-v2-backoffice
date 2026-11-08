<?php

namespace App\Domain\Salon\Support;

/**
 * Words for the salon till rights (salon pack 2026-11-08, README Part C "RolePermission keys"; W2). Only salon tills
 * send `salon.*` rights, so the role editor (TillPermissionCatalogue) labels them when a till sent them and never
 * offers them to any other shop; a non-salon shop's pull never carries one (SalonPull).
 */
final class SalonRights
{
    /** @var array<string, array{0: string, 1: string}> key => [label, help] */
    public const WORDS = [
        'salon.services_manage' => ['Set up services', 'Add and change services, options, add-ons and prices.'],
        'salon.team_manage' => ['Set up the team', 'Add stylists, levels, chairs and rooms, and who does what.'],
        'salon.commission_view' => ['See commission', 'See commission plans and what each person earned.'],
        'salon.tips_manage' => ['Pay out tips', 'Pay tips to the team and change how tips are shared.'],
        'salon.queue_manage' => ['Run the walk-in queue', 'Add, call and remove walk-in clients.'],
        'salon.queue_reorder' => ['Reorder the queue', 'Move a client up or down the walk-in queue.'],
        'salon.diary_manage' => ['Run the diary', 'Book, move and cancel appointments and answer online requests.'],
        'salon.clients_technical' => ['Client records', 'See and write technical records, photos and patch tests.'],
        'salon.client_medical' => ['Client health answers', 'See a client\'s health and allergy answers (kept on the till only).'],
        'salon.messages_manage' => ['Client messages', 'Set up reminders and marketing messages and their providers.'],
        'salon.settings' => ['Salon settings', 'Change the salon, diary, queue and online booking settings.'],
    ];
}
