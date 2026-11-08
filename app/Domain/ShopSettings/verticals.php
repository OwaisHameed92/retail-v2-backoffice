<?php

/**
 * Till 0.1.85 (W1): the shop-type settings the Till settings page shows read-only, by section. Shown only for the
 * matching shop (VerticalSettings): pharmacy for a pharmacy, the bike workshop for a shop linked to BikeBook, the shop
 * type wherever a till has sent it. Every key is a shared key of the 0.1.85 `samples/settings-local-only.json` or a
 * key only a till writes (SettingSyncPolicy::TILL_WRITES_ONLY); none is local only (a test keeps it that way). The
 * portal never writes them in this release: the pharmacy's own keys are set on its till, the shop type only in the
 * till's setup wizard, and `workshop.connection_status` belongs to the main till.
 *
 * Left out on purpose: the local-only keys (`pharmacy.dmd_*` secrets and status, `workshop.bikebook_api_key`: never
 * pushed, never stored), the workshop keys that hold till ids (`workshop.scope_category_ids`, `labour_product_id`,
 * `misc_product_id`, `category_id`), and `workshop.connector_status` (the portal's own, once the connector exists).
 */
return [
    'pharmacy' => [
        'title' => 'Pharmacy',
        'description' => 'Set on the pharmacy\'s tills. Shown here as the tills sent them.',
        'settings' => [
            'pharmacy.ods_code' => ['label' => 'ODS code', 'type' => 'text', 'help' => 'The pharmacy\'s NHS organisation code.'],
            'pharmacy.gphc_premises_number' => ['label' => 'GPhC premises number', 'type' => 'text', 'help' => 'The premises registration with the General Pharmaceutical Council.'],
            'pharmacy.prescription_charge' => ['label' => 'NHS prescription charge', 'type' => 'money', 'help' => 'Charged per item when the patient pays.'],
            'pharmacy.ppc_3_month_price' => ['label' => '3-month prepayment certificate', 'type' => 'money', 'help' => 'Price of a 3-month PPC sold at the till. The money is owed to NHSBSA.'],
            'pharmacy.ppc_12_month_price' => ['label' => '12-month prepayment certificate', 'type' => 'money', 'help' => 'Price of a 12-month PPC sold at the till. The money is owed to NHSBSA.'],
            'pharmacy.ppc_hrt_price' => ['label' => 'HRT prepayment certificate', 'type' => 'money', 'help' => 'Price of an HRT PPC sold at the till. The money is owed to NHSBSA.'],
            'pharmacy.private_dispensing_fee' => ['label' => 'Private dispensing fee', 'type' => 'money', 'help' => 'Added to each private prescription.'],
            'pharmacy.private_markup_percent' => ['label' => 'Private prescription mark-up', 'type' => 'percent', 'help' => 'Added to the medicine cost on a private prescription.'],
            'pharmacy.ready_message' => ['label' => 'Prescription ready message', 'type' => 'multiline', 'help' => 'Sent to the patient when their prescription is ready to collect.'],
            'pharmacy.two_pack_guidance' => ['label' => 'Two-pack guidance', 'type' => 'bool', 'help' => 'The till reminds staff of the pack limits on painkillers.'],
            'pharmacy.codeine_guidance' => ['label' => 'Codeine guidance', 'type' => 'bool', 'help' => 'The till shows the codeine questions before a codeine sale.'],
            'pharmacy.wwham_card' => ['label' => 'WWHAM questions', 'type' => 'bool', 'help' => 'The till shows Who, What, How long, Action, Medication for pharmacy medicines.'],
            'pharmacy.dmd_library_fallback' => ['label' => 'Medicines list fallback', 'type' => 'bool', 'help' => 'The till uses its own medicines list when the weekly NHS dictionary (dm+d) is not there.'],
        ],
    ],
    'workshop' => [
        'title' => 'Bike workshop (BikeBook)',
        'description' => 'Set on the shop\'s main till when it connects BikeBook. Shown here as the till sent them.',
        'settings' => [
            'integration.workshop' => ['label' => 'Workshop link', 'type' => 'bool', 'help' => 'On when the shop runs its repairs in BikeBook.'],
            'workshop.provider' => ['label' => 'Workshop system', 'type' => 'text', 'help' => 'The repair system the till is linked to.'],
            'workshop.bikebook_business_name' => ['label' => 'BikeBook business', 'type' => 'text', 'help' => 'The BikeBook account the till is linked to.'],
            'workshop.bikebook_business_id' => ['label' => 'BikeBook business number', 'type' => 'text', 'help' => 'BikeBook\'s own number for that account.'],
            'workshop.bikebook_base_url' => ['label' => 'BikeBook address', 'type' => 'text', 'help' => 'Blank means BikeBook\'s standard address.'],
            'workshop.connection_status' => ['label' => 'Connection', 'type' => 'text', 'help' => 'What the main till last found when it talked to BikeBook.'],
            'workshop.product_scope' => ['label' => 'Products BikeBook sees', 'type' => 'text', 'default' => 'categories', 'help' => 'Which products the till offers to BikeBook.'],
            'workshop.new_categories_included' => ['label' => 'Include new categories', 'type' => 'bool', 'help' => 'Products in a category made later are offered too.'],
            'workshop.orders_show_from' => ['label' => 'Show repair orders from', 'type' => 'text', 'help' => 'Older BikeBook jobs are not listed on the till.'],
            'workshop.notify_ready' => ['label' => 'Tell the customer when ready', 'type' => 'bool', 'help' => 'BikeBook lets the customer know the bike is ready.'],
            'workshop.send_ready_message' => ['label' => 'Send the ready message', 'type' => 'bool', 'help' => 'The till sends its own ready message too.'],
            'workshop.complete_job_on_payment' => ['label' => 'Complete the job when paid', 'type' => 'bool', 'help' => 'Taking the money marks the BikeBook job complete.'],
            'workshop.refunds_to_bikebook' => ['label' => 'Send refunds to BikeBook', 'type' => 'bool', 'help' => 'A refund at the till is reported to BikeBook.'],
            'workshop.supplier_orders_by' => ['label' => 'Parts ordered by', 'type' => 'text', 'help' => 'Who orders the parts a repair needs.'],
        ],
    ],
    'shopType' => [
        'title' => 'Shop type',
        'description' => 'Chosen in the till\'s setup wizard. Only the till can change it.',
        'settings' => [
            'setup.business_type' => ['label' => 'Shop type', 'type' => 'text', 'help' => 'The kind of shop the till was set up as.'],
            'vertical.pharmacy' => ['label' => 'Pharmacy features', 'type' => 'bool', 'help' => 'On for a shop set up as a pharmacy.'],
            'vertical.salon' => ['label' => 'Salon / Barber features', 'type' => 'bool', 'help' => 'On for a shop set up as a salon or barber.'],
        ],
    ],
];
