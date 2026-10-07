<?php

namespace App\Console\Commands;

use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\EmailControl;
use Illuminate\Console\Command;

/**
 * P11: shows or changes which tenant emails go out by themselves (the same settings as Admin → Settings → Emails).
 *
 *   php artisan emails:auto                      # show
 *   php artisan emails:auto --off=all            # hold every category until an admin sends them
 *   php artisan emails:auto --off=welcome,invoices --on=reminders
 *
 * Categories: invoices, reminders, setPassword, welcome, ownerAlerts (or "all"). Audited (`email.settings_updated`).
 */
class EmailsAutoCommand extends Command
{
    protected $signature = 'emails:auto {--off= : Categories to hold (comma separated, or "all")} {--on= : Categories to send automatically again (comma separated, or "all")}';

    protected $description = 'Show or set which tenant emails are sent automatically (others are held for an admin)';

    public function handle(UpdateEmailSettings $update): int
    {
        $off = $this->categories((string) $this->option('off'));
        $on = $this->categories((string) $this->option('on'));

        if ($off === null || $on === null) {
            $this->error('Unknown category. Use: all, '.implode(', ', EmailCategory::values()).'.');

            return self::INVALID;
        }

        $changes = array_fill_keys($on, true) + array_fill_keys($off, false);

        if (array_intersect($on, $off) !== []) {
            $this->error('A category cannot be both on and off.');

            return self::INVALID;
        }

        $settings = $changes === [] ? EmailControl::all() : $update->handle($changes);

        $this->table(['Category', 'Key', 'Sent automatically'], array_map(
            fn (EmailCategory $category) => [$category->label(), $category->value, $settings[$category->value] ? 'Yes' : 'No: held for an admin'],
            EmailCategory::cases(),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null when one is unknown
     */
    private function categories(string $option): ?array
    {
        $option = trim($option);

        if ($option === '') {
            return [];
        }

        if (strtolower($option) === 'all') {
            return EmailCategory::values();
        }

        $values = array_values(array_filter(array_map('trim', explode(',', $option))));

        return array_diff($values, EmailCategory::values()) === [] ? $values : null;
    }
}
