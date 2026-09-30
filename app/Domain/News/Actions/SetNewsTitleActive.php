<?php

namespace App\Domain\News\Actions;

use App\Domain\News\Support\NewsAccess;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\NewsTitle;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Archives (`isActive` false) or restores a news title (module 5.8), sent to the title's shop(s) as an update. The row
 * is never deleted: the shops' deliveries, returns and vouchers still name it. Doing it twice is a no-op.
 */
final class SetNewsTitleActive
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(NewsTitle $title, bool $active): NewsTitle
    {
        if (! NewsAccess::mayEdit($title)) {
            throw new AuthorizationException('This title is for every shop; only a user of every shop can change it.');
        }

        if ($title->is_active === $active) {
            return $title;
        }

        $title->forceFill(['is_active' => $active, 'row_version' => (int) $title->row_version + 1])->save();
        $this->audit->handle($active ? 'news_title.restored' : 'news_title.archived', $title, ['is_active' => ! $active], ['is_active' => $active], ['name' => $title->name]);

        return $title;
    }
}
