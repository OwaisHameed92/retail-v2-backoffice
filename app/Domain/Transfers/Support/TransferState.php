<?php

namespace App\Domain\Transfers\Support;

use App\Domain\TillData\Models\StockTransfer;
use App\Domain\Transfers\Data\TransferFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a transfer is (module 5.3), worked out in SQL so the list, its filter, its counts and the detail page agree.
 *
 * The till's header `status` (requested, dispatched, received, closed, cancelled) belongs to the sending shop; the
 * receiving shop's own `StockTransferReceipt` (+ lines) says what arrived. Shown status, first match wins:
 *
 * | state            | when                                                                                     |
 * |------------------|------------------------------------------------------------------------------------------|
 * | `cancelled`      | the header is cancelled                                                                  |
 * | `partlyReceived` | a receipt line has less received than was sent                                          |
 * | `received`       | there is a receipt (or the sender's header already says received / closed)                |
 * | `requested`      | the header is requested (never relayed, §10.2)                                           |
 * | `inTransit`      | dispatched, and the receiving shop's till has pulled the header and every line           |
 * | `dispatched`     | dispatched, not pulled by the receiving shop's till yet                                  |
 *
 * Relay (contract §10.2, built in 2.9B): the header and its lines carry the pull version (`hub_version`, null until
 * stamped). The receiving shop's `sync_branch_status` holds `last_pull_version` (the highest version its last pull was
 * sent) and `last_pull_since` (what that pull said it already had). `waiting` = not sent yet; `sent` = sent in a pull
 * reply; `stored` = the till has asked for later rows since, so it holds them; `received` = the shop has received it;
 * `notRelayed` = requested or cancelled (never relayed).
 */
final class TransferState
{
    public const STATES = ['requested', 'dispatched', 'inTransit', 'received', 'partlyReceived', 'cancelled'];

    public const RELAY = ['notRelayed', 'waiting', 'sent', 'stored', 'received'];

    /** Shown status as a SQL expression over `stock_transfers`. */
    public static function stateSql(): string
    {
        return 'case'
            ." when stock_transfers.status = 'cancelled' then 'cancelled'"
            .' when '.self::shortExists()." then 'partlyReceived'"
            .' when '.self::receivedSql()." then 'received'"
            ." when stock_transfers.status = 'requested' then 'requested'"
            .' when '.self::reached('last_pull_version')." then 'inTransit'"
            ." else 'dispatched' end";
    }

    /** Relay to the receiving shop's till as a SQL expression over `stock_transfers`. */
    public static function relaySql(): string
    {
        return 'case'
            ." when stock_transfers.status in ('requested', 'cancelled') then 'notRelayed'"
            .' when '.self::receivedSql()." then 'received'"
            .' when '.self::reached('last_pull_since')." then 'stored'"
            .' when '.self::reached('last_pull_version')." then 'sent'"
            ." else 'waiting' end";
    }

    /**
     * Transfers with their shown status (`state`) and relay (`relay`) as extra columns.
     *
     * @return Builder<StockTransfer>
     */
    public static function query(): Builder
    {
        return StockTransfer::query()->select('stock_transfers.*')
            ->selectRaw(self::stateSql().' as state')
            ->selectRaw(self::relaySql().' as relay');
    }

    /**
     * The shop, flow (in / out) and day filters (not the status).
     *
     * @param  Builder<StockTransfer>  $query
     * @return Builder<StockTransfer>
     */
    public static function scope(Builder $query, TransferFilters $filters): Builder
    {
        if ($filters->shop !== null) {
            $shop = $filters->shop;
            match ($filters->flow) {
                'out' => $query->where('stock_transfers.from_branch_id', $shop),
                'in' => $query->where('stock_transfers.to_branch_id', $shop),
                default => $query->where(fn (Builder $q) => $q->where('stock_transfers.from_branch_id', $shop)->orWhere('stock_transfers.to_branch_id', $shop)),
            };
        }

        [$from, $to] = $filters->window();

        if ($from !== null) {
            $query->where('stock_transfers.requested_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('stock_transfers.requested_at', '<', $to);
        }

        return $query;
    }

    /**
     * @param  Builder<StockTransfer>  $query
     * @return Builder<StockTransfer>
     */
    public static function whereState(Builder $query, string $state): Builder
    {
        return $query->whereRaw('('.self::stateSql().') = ?', [$state]);
    }

    /** A receipt row is there for the transfer, or the sender's header already says it was received. */
    private static function receivedSql(): string
    {
        return "(stock_transfers.status in ('received', 'closed') or exists (select 1 from stock_transfer_receipts r"
            .' where r.transfer_id = stock_transfers.id and r.company_id = stock_transfers.company_id and r.deleted_at is null))';
    }

    private static function shortExists(): string
    {
        return 'exists (select 1 from stock_transfer_receipt_lines rl'
            .' join stock_transfer_receipts rr on rr.id = rl.receipt_id and rr.company_id = rl.company_id and rr.deleted_at is null'
            .' where rl.transfer_id = stock_transfers.id and rl.company_id = stock_transfers.company_id and rl.deleted_at is null'
            .' and rl.qty_received < rl.qty_dispatched)';
    }

    /** The header and every line have a pull version at or below the receiving shop's cursor. */
    private static function reached(string $cursor): string
    {
        $at = "coalesce((select s.{$cursor} from sync_branch_status s where s.branch_id = stock_transfers.to_branch_id"
            .' and s.company_id = stock_transfers.company_id), 0)';

        return "(stock_transfers.status = 'dispatched' and stock_transfers.hub_version > 0 and stock_transfers.hub_version <= {$at}"
            .' and not exists (select 1 from stock_transfer_lines l where l.transfer_id = stock_transfers.id'
            ." and l.company_id = stock_transfers.company_id and l.deleted_at is null and (l.hub_version is null or l.hub_version > {$at})))";
    }
}
