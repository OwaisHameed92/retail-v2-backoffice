<?php

/**
 * The till settings the portal edits (module 4.9, contract §10.3, §18.4 item 7), grouped for the Till settings page.
 * Every key is a shared key of `samples/settings-local-only.json` (`sharedKeys`) and never a deny-listed one; a test
 * keeps it that way. Values are stored as text exactly as the till keeps them: `true`/`false`, `20`, `2.50`.
 *
 * Per setting: label, help, type (bool, int, money, percent, decimal, text, multiline), optional min/max (numbers),
 * max (characters for text), unit ("minutes", "days"…) and default (only the till's defaults the contract states).
 */

return [
    'receipt' => [
        'title' => 'Receipts',
        'description' => 'What every printed and emailed receipt says.',
        'settings' => [
            'receipt.header_lines' => ['label' => 'Receipt header', 'type' => 'multiline', 'max' => 1000, 'help' => 'Printed at the top of every receipt, one line per line: your shop name, address and phone number.'],
            'receipt.footer_text' => ['label' => 'Receipt footer', 'type' => 'multiline', 'max' => 1000, 'help' => 'Printed at the bottom, for example "Thank you for shopping with us" or your returns policy.'],
            'shop.vat_number' => ['label' => 'VAT number on receipts', 'type' => 'text', 'max' => 20, 'help' => 'Printed on receipts and VAT invoices, for example GB123456789.'],
            'shop.vat_registered' => ['label' => 'VAT registered', 'type' => 'bool', 'help' => 'On: receipts show VAT and your VAT number.'],
            'receipt.show_vat_summary' => ['label' => 'VAT summary', 'type' => 'bool', 'help' => 'A VAT breakdown by rate at the end of the receipt.'],
            'receipt.show_vat_code_per_line' => ['label' => 'VAT code on each line', 'type' => 'bool', 'help' => 'A letter beside each item showing its VAT rate.'],
            'receipt.show_cashier_name' => ['label' => 'Cashier name', 'type' => 'bool', 'help' => 'Who served the customer.'],
            'receipt.show_receipt_number' => ['label' => 'Receipt number', 'type' => 'bool', 'help' => 'Helps you find the sale for a refund.'],
            'receipt.show_barcode' => ['label' => 'Receipt barcode', 'type' => 'bool', 'help' => 'Scan it at the till to find the sale for a refund.'],
            'receipt.show_logo' => ['label' => 'Logo', 'type' => 'bool', 'help' => 'Your logo at the top, when the till has one.'],
            'receipt.show_loyalty_balance' => ['label' => 'Loyalty balance', 'type' => 'bool', 'help' => 'The customer\'s points after this sale.'],
            'receipt.show_discount_detail' => ['label' => 'Discount details', 'type' => 'bool', 'help' => 'Each discount under the item it applies to.'],
            'receipt.show_offer_detail' => ['label' => 'Offer details', 'type' => 'bool', 'help' => 'The name of each offer the customer got.'],
            'receipt.show_drs_lines' => ['label' => 'Bottle deposit lines', 'type' => 'bool', 'help' => 'Deposit return scheme charges as their own lines.'],
            'receipt.large_font_totals' => ['label' => 'Large totals', 'type' => 'bool', 'help' => 'The total printed in large letters.'],
            'receipt.copies' => ['label' => 'Copies printed', 'type' => 'int', 'min' => 0, 'max' => 5, 'help' => 'How many receipts print after each sale.'],
        ],
    ],
    'shop' => [
        'title' => 'Shop details and opening hours',
        'description' => 'Printed on receipts and labels, and used by the till\'s routines. Name and address are on the Shops page.',
        'settings' => [
            'shop.trading_hours' => ['label' => 'Opening hours', 'type' => 'multiline', 'max' => 1000, 'help' => 'As your till shows them. Keep the format your till uses (see the current value); a day-by-day editor is coming.'],
            'shop.phone' => ['label' => 'Phone', 'type' => 'text', 'max' => 30, 'help' => 'The number customers call.'],
            'shop.email' => ['label' => 'Email', 'type' => 'text', 'max' => 120, 'help' => 'Shown on receipts and emails to customers.'],
            'shop.website' => ['label' => 'Website', 'type' => 'text', 'max' => 120, 'help' => 'For example www.yourshop.co.uk.'],
            'shop.company_number' => ['label' => 'Company number', 'type' => 'text', 'max' => 20, 'help' => 'Your Companies House number, if you are a limited company.'],
        ],
    ],
    'cash' => [
        'title' => 'Cash and cash-up',
        'description' => 'How the drawer is opened, counted and closed.',
        'settings' => [
            'cash.require_shift_open' => ['label' => 'Open a shift before selling', 'type' => 'bool', 'help' => 'Staff count the float in before the first sale.'],
            'cash.default_float' => ['label' => 'Usual float', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'The cash left in the drawer at the start of the day.'],
            'cash.count_on_close' => ['label' => 'Count the drawer when closing', 'type' => 'bool', 'help' => 'Staff count the cash before the Z report.'],
            'cash.blind_count' => ['label' => 'Blind count', 'type' => 'bool', 'help' => 'Staff count without seeing how much the till expects.'],
            'cash.variance_alert_over' => ['label' => 'Alert when over or short by more than', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'A cash-up difference above this is flagged.'],
            'cash.high_value_variance_threshold' => ['label' => 'Large difference', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'A difference above this needs a manager.'],
            'cash.safe_drop_prompt_over' => ['label' => 'Ask for a safe drop above', 'type' => 'money', 'min' => 0, 'max' => 100000, 'help' => 'The till reminds staff to take cash to the safe when the drawer holds more than this.'],
            'cash.auto_print_z_on_close' => ['label' => 'Print the Z report on close', 'type' => 'bool', 'help' => 'Prints as soon as the day is closed.'],
            'cash.email_z_on_close' => ['label' => 'Email the Z report on close', 'type' => 'bool', 'help' => 'Sent to the shop owner\'s email.'],
        ],
    ],
    'till' => [
        'title' => 'Till and checkout',
        'description' => 'How the sales screen behaves.',
        'settings' => [
            'till.keypad_price_in_pence' => ['label' => 'Type prices in pence', 'type' => 'bool', 'help' => 'On: typing 150 on the keypad means £1.50. Off: type 1.50.'],
            'till.show_prices_on_tiles' => ['label' => 'Prices on product buttons', 'type' => 'bool', 'help' => 'Shows each price on its button.'],
            'till.lock_after_idle_minutes' => ['label' => 'Lock the till after', 'type' => 'int', 'unit' => 'minutes', 'min' => 0, 'max' => 240, 'help' => 'Minutes without use before staff must enter their PIN again.'],
            'till.hold_requires_customer_name' => ['label' => 'Name on held sales', 'type' => 'bool', 'help' => 'Ask for the customer\'s name when a sale is put on hold.'],
            'till.ask_reason_void' => ['label' => 'Reason for voids', 'type' => 'bool', 'help' => 'Staff choose a reason when they remove an item.'],
            'till.ask_reason_no_sale' => ['label' => 'Reason for no sale', 'type' => 'bool', 'help' => 'Staff choose a reason when they open the drawer without a sale.'],
            'till.allow_negative_stock_sale' => ['label' => 'Sell when stock shows none', 'type' => 'bool', 'help' => 'Off: the till stops a sale when the item shows no stock.'],
            'till.bag_charge_enabled' => ['label' => 'Bag charge', 'type' => 'bool', 'help' => 'A quick button for carrier bags.'],
            'till.bag_charge_amount' => ['label' => 'Bag charge amount', 'type' => 'money', 'min' => 0, 'max' => 5, 'help' => 'For example 0.10.'],
            'till.charity_round_up_enabled' => ['label' => 'Charity round-up', 'type' => 'bool', 'help' => 'Customers can round up their total for a charity. Kept apart from your sales.'],
            'till.charity_name' => ['label' => 'Charity name', 'type' => 'text', 'max' => 80, 'help' => 'Shown on the till and the receipt.'],
        ],
    ],
    'refunds' => [
        'title' => 'Refunds and manager approval',
        'description' => 'When staff need a manager\'s PIN.',
        'settings' => [
            'till.refund_needs_manager' => ['label' => 'Ask a manager for refunds', 'type' => 'bool', 'default' => 'false', 'help' => 'Every refund needs a manager or admin PIN before it completes.'],
            'till.manager_pin_refund_over' => ['label' => 'Manager PIN for refunds over', 'type' => 'money', 'min' => 0, 'max' => 10000, 'default' => '0.00', 'help' => 'Refunds above this need a manager. 0 never asks.'],
            'till.refund_return_window_days' => ['label' => 'Refunds allowed for', 'type' => 'int', 'unit' => 'days', 'min' => 0, 'max' => 365, 'help' => 'How many days after the sale a refund is allowed.'],
            'till.refund_without_receipt' => ['label' => 'Refunds without a receipt', 'type' => 'bool', 'help' => 'Allow refunds when the customer has no receipt.'],
            'till.refund_without_receipt_pin' => ['label' => 'Manager PIN without a receipt', 'type' => 'bool', 'default' => 'false', 'help' => 'A refund without a receipt needs a manager.'],
            'till.refund_without_receipt_max' => ['label' => 'Most refunded without a receipt', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'The largest refund allowed without a receipt.'],
            'till.manager_pin_price_override' => ['label' => 'Manager PIN to change a price', 'type' => 'bool', 'help' => 'Staff need a manager to change a price at the till.'],
            'till.open_drawer_requires_pin' => ['label' => 'Manager PIN to open the drawer', 'type' => 'bool', 'help' => 'For a no sale.'],
            'till.clear_cart_requires_pin_over' => ['label' => 'Manager PIN to clear a sale over', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'Clearing a basket worth more than this needs a manager.'],
        ],
    ],
    'age' => [
        'title' => 'Age-restricted sales',
        'description' => 'The prompts staff see for alcohol, tobacco, vapes and energy drinks.',
        'settings' => [
            'compliance.challenge25_age' => ['label' => 'Ask for ID if they look under', 'type' => 'int', 'unit' => 'years', 'min' => 18, 'max' => 30, 'help' => 'Challenge 25: usually 25.'],
            'compliance.challenge25_wording' => ['label' => 'Prompt wording', 'type' => 'text', 'max' => 200, 'help' => 'What the till asks staff, for example "Does the customer look under 25?"'],
            'compliance.energy_drink_age_gate' => ['label' => 'Age check for energy drinks', 'type' => 'bool', 'help' => 'Ask for proof of age for high-caffeine drinks.'],
            'compliance.nicotine_products_gated' => ['label' => 'Age check for vapes and nicotine', 'type' => 'bool', 'help' => 'Ask for proof of age for nicotine products.'],
            'compliance.refusal_register_enabled' => ['label' => 'Refusals register', 'type' => 'bool', 'help' => 'Staff record each refused sale, as Trading Standards expect.'],
        ],
    ],
    'payments' => [
        'title' => 'Payments',
        'description' => 'How customers can pay. The payment buttons themselves are on the Payment types page.',
        'settings' => [
            'payments.allow_split' => ['label' => 'Split payments', 'type' => 'bool', 'help' => 'Pay part by cash and part by card, for example.'],
            'payments.round_cash_to_5p' => ['label' => 'Round cash to 5p', 'type' => 'bool', 'help' => 'Cash totals are rounded to the nearest 5p.'],
            'payments.cashback_enabled' => ['label' => 'Cashback', 'type' => 'bool', 'help' => 'Customers can take cash out with a card payment.'],
            'payments.cashback_max' => ['label' => 'Most cashback', 'type' => 'money', 'min' => 0, 'max' => 500, 'help' => 'The largest cashback per sale.'],
            'payments.allow_account' => ['label' => 'Pay on account', 'type' => 'bool', 'help' => 'Customers with an account can pay later.'],
            'payments.change_screen_seconds' => ['label' => 'Show the change for', 'type' => 'int', 'unit' => 'seconds', 'min' => 0, 'max' => 60, 'help' => 'How long the change due stays on screen.'],
        ],
    ],
    'loyalty' => [
        'title' => 'Loyalty and customers',
        'description' => 'How customers earn and spend points.',
        'settings' => [
            'customers.loyalty_enabled' => ['label' => 'Loyalty points', 'type' => 'bool', 'help' => 'Customers earn points on what they spend.'],
            'customers.loyalty_points_per_pound' => ['label' => 'Points per £1 spent', 'type' => 'decimal', 'min' => 0, 'max' => 1000, 'help' => 'For example 1.'],
            'customers.loyalty_pounds_per_point' => ['label' => 'Value of one point', 'type' => 'decimal', 'unit' => '£', 'min' => 0, 'max' => 100, 'help' => 'In pounds, for example 0.01 for 1p a point.'],
            'customers.loyalty_min_spend_to_earn' => ['label' => 'Least spend to earn points', 'type' => 'money', 'min' => 0, 'max' => 1000, 'help' => 'Sales below this earn no points.'],
            'customers.loyalty_min_redeem_points' => ['label' => 'Least points to spend', 'type' => 'int', 'unit' => 'points', 'min' => 0, 'max' => 1000000, 'help' => 'A customer needs at least this many points to pay with them.'],
            'customers.loyalty_expiry_months' => ['label' => 'Points expire after', 'type' => 'int', 'unit' => 'months', 'min' => 0, 'max' => 120, 'help' => 'How long points last.'],
            'customers.default_credit_limit' => ['label' => 'Usual account credit limit', 'type' => 'money', 'min' => 0, 'max' => 100000, 'help' => 'For new customer accounts.'],
        ],
    ],
    'promotions' => [
        'title' => 'Offers and discounts',
        'description' => 'Your offers are on the Promotions page; these are the till\'s rules for them.',
        'settings' => [
            'promotions.enabled' => ['label' => 'Offers', 'type' => 'bool', 'help' => 'Off: the tills apply no offers.'],
            'promotions.show_savings' => ['label' => 'Show savings', 'type' => 'bool', 'help' => 'The customer sees what they saved.'],
            'promotions.coupons_enabled' => ['label' => 'Coupons', 'type' => 'bool', 'help' => 'Staff can scan coupons.'],
            'promotions.manual_line_max_percent' => ['label' => 'Largest discount on an item', 'type' => 'percent', 'min' => 0, 'max' => 100, 'help' => 'Staff cannot take more off one item.'],
            'promotions.manual_basket_max_percent' => ['label' => 'Largest discount on a sale', 'type' => 'percent', 'min' => 0, 'max' => 100, 'help' => 'Staff cannot take more off the whole sale.'],
            'promotions.manual_requires_reason' => ['label' => 'Reason for a discount', 'type' => 'bool', 'help' => 'Staff choose a reason when they give one.'],
        ],
    ],
    'staff' => [
        'title' => 'Staff',
        'description' => 'Clocking in and staff discounts.',
        'settings' => [
            'staff.clock_in_enabled' => ['label' => 'Clock in and out', 'type' => 'bool', 'help' => 'Staff clock in at the till for timesheets.'],
            'staff.discount_percent' => ['label' => 'Staff discount', 'type' => 'percent', 'min' => 0, 'max' => 100, 'help' => 'Taken off what staff buy.'],
            'staff.discount_daily_cap' => ['label' => 'Most staff discount a day', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'The most discount one person gets in a day.'],
            'staff.discount_weekly_cap' => ['label' => 'Most staff discount a week', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'The most discount one person gets in a week.'],
        ],
    ],
];
