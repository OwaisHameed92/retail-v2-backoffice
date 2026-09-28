<?php

/*
|--------------------------------------------------------------------------
| Till entity store: hand-written overrides for `php artisan till:entities:generate`
|--------------------------------------------------------------------------
|
| The generator reads every <contract>/schemas/entities/*.schema.json, the ownership map in
| samples/ownership.json and this file, then writes the migrations, models, enums and EntityRegistry.
| Everything not listed here is inferred from the schema (see docs/till-data.md, "Overrides").
|
| Field names are the till's camelCase names. Column lists in `indexes` use the portal's snake_case columns.
| Custom behaviour belongs in hand-written traits listed under `traits`, never in generated files.
|
*/

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Concerns\SaleLineQueries;
use App\Domain\TillData\Concerns\SaleQueries;
use App\Domain\TillData\Concerns\StockMovementQueries;

return [
    'contract' => 'docs/contracts/portal-api-v1.3.1/docs/web-portal-api',

    /*
     * The contract release this run writes migrations for. Migrations are additive: <prefix>00_create_till_<release>
     * _tables.php creates the tables earlier releases lack, <prefix>01_add_till_<release>_columns.php adds their
     * missing columns and indexes. database/till-schema.json records what each release's migrations made (v1.1:
     * the ten 2026_09_27_1100NN group migrations). For the next contract: new name and a later prefix.
     */
    'release' => ['name' => 'v1.3.1', 'migrationPrefix' => '2026_10_02_1000'],

    /* Entity groups: the order tables are created in. Every schema entity must be in exactly one group. */
    'groups' => [
        'catalogue' => [
            'Department', 'Category', 'Unit', 'VatRate', 'TaxRule', 'Product', 'ProductBarcode', 'ProductAlias',
            'ProductUnit', 'ProductSupplier', 'ProductRecall', 'PriceHistory', 'ExchangeRate', 'BranchProduct',
            'PriceChangeBatch', 'PriceChangeLine', 'ScalePluItem', 'ShelfLabel', 'TopSellerTile', 'HighValueCountItem',
            'MedicineClassification', 'ProductAllergenMatrix',
        ],
        'promotions' => [
            'PromotionRule', 'PromotionItem', 'PromotionCoupon', 'PromotionRedemption', 'CouponRedemption',
            'SeasonalEvent', 'SeasonalEventUplift',
        ],
        'customers' => [
            'Customer', 'Consent', 'CustomerTransaction', 'CustomerCorrection', 'CustomerOrder', 'CustomerOrderPayment',
            'StoreCreditVoucher', 'VoucherTransaction', 'EReceiptLog',
        ],
        'sales' => [
            'Sale', 'SaleLine', 'SalePayment', 'SaleVat', 'PaymentAttempt', 'OfflineCardReconciliation', 'HeldOrder',
            'AgeRefusal', 'SalesDaily', 'HourlySales', 'TenderDaily',
        ],
        'stock' => [
            'StockMovement', 'StockLayer', 'FifoStockLayer', 'StockReservation', 'StockTake', 'StockTakeSection',
            'StockTakeLine', 'DateCheck', 'StockTransfer', 'StockTransferLine', 'StockTransferReceipt',
            'StockTransferReceiptLine',
        ],
        'purchasing' => [
            'Supplier', 'PurchaseOrder', 'PurchaseOrderLine', 'GoodsReceipt', 'GoodsReceiptLine', 'SupplierInvoice',
            'SupplierInvoiceLine', 'SupplierCreditNote', 'SupplierCreditNoteLine', 'SupplierPayment',
            'SupplierPaymentAllocation', 'StandingOrder', 'StandingOrderLine', 'RebateAgreement', 'RebateAccrual',
            'NewsTitle', 'NewsDelivery', 'NewsDeliveryLine', 'NewsVoucherRedemption',
        ],
        'cash' => [
            'PaymentType', 'Reason', 'Shift', 'ShiftTender', 'CashCount', 'CashMovement', 'CashOfficeBanking',
            'CashOfficeReconciliation', 'ChangeOrder', 'CardSettlement', 'ZReport', 'DayLock', 'ShopRoutineRun',
        ],
        'accounts' => [
            'Account', 'JournalEntry', 'JournalLine', 'FinancialYear', 'FinancialPeriod', 'VatReturn', 'Expense',
            'RecurringBill', 'FixedAssetCategory', 'FixedAsset',
        ],
        'staff' => ['User', 'Role', 'ClockEvent', 'RotaShift', 'TimesheetApproval', 'WageRate', 'TrainingRecord'],
        'system' => [
            'AuditLog', 'ExceptionLog', 'AlertSubscription', 'BackupRun', 'BranchHoursOverride', 'ComplianceLicence',
            'EventSubscription', 'HardwareCheck', 'ImportColumnMap', 'ImportJob', 'Licence', 'LicenceAddOnTrial',
            'PrintJob', 'PrinterProfile', 'ScaleCalibrationLog', 'SyncConflict', 'SyncState', 'UpdateRun',
            'LayoutProfile', 'ParcelCarrier', 'Parcel',
        ],
        'compliance' => [
            'DiaryCheckDefinition', 'DiaryCheckRecord', 'TemperatureUnit', 'IncidentReport', 'DispensingRecord',
            'DispensingItem',
        ],
    ],

    /*
     * Till rows stored on the portal's own tables (module 1.2). Only `tillFields` are ever written by sync;
     * ids, codes, status, is_active and is_main_till stay portal-owned. `field => column`.
     */
    'tenancy' => [
        'Company' => [
            'table' => 'companies',
            'model' => Company::class,
            'tillFields' => [
                'name' => 'name', 'legalName' => 'legal_name', 'vatNumber' => 'vat_number',
                'companyNumber' => 'company_number', 'address' => 'address', 'phone' => 'phone', 'email' => 'email',
            ],
        ],
        'Branch' => [
            'table' => 'branches',
            'model' => Branch::class,
            'tillFields' => [
                'name' => 'name', 'address' => 'address', 'phone' => 'phone', 'vatNumber' => 'vat_number',
                'nation' => 'nation', 'licensedHoursJson' => 'licensed_hours_json',
                'isDrsReturnPoint' => 'is_drs_return_point', 'areaM2' => 'area_m2', 'nextPoNo' => 'next_po_no',
            ],
        ],
        'Register' => [
            'table' => 'registers',
            'model' => Register::class,
            'tillFields' => ['name' => 'name', 'nextSaleNo' => 'next_sale_no', 'nextRefundNo' => 'next_refund_no'],
        ],
    ],

    /* Enum class names where the value set is not in samples/enums.json or is shared by several fields. */
    'enumNames' => [
        'Expense.paymentType' => 'ExpensePaymentType',
        'RecurringBill.paymentType' => 'ExpensePaymentType',
        'ImportColumnMap.target' => 'ImportTarget',
        'ImportJob.target' => 'ImportTarget',
        'PriceChangeLine.level' => 'PriceLevel',
        'PriceHistory.level' => 'PriceLevel',
        'PromotionItem.scope' => 'PromotionScope',
        'PromotionRedemption.scope' => 'PromotionScope',
        'PromotionRule.scope' => 'PromotionScope',
        'PromotionRedemption.type' => 'PromotionType',
        'PromotionRule.type' => 'PromotionType',
        'Account.type' => 'AccountType',
    ],

    /*
     * Decimal kinds. Default is `money` (decimal(12,2), MoneyCast). Matched by field name or "Entity.field";
     * "Entity.field" wins over a bare name.
     */
    'decimals' => [
        'cost' => [
            'avgCost', 'caseCost', 'consignmentCost', 'cost', 'costAtSale', 'costPrice', 'expectedUnitCost',
            'lastCost', 'lineCost', 'newCost', 'oldCost', 'supplierPrice', 'unitCost', 'unitCostSnapshot',
            'varianceCost', 'RebateAgreement.rate', 'dispatchedCost', 'receivedCost',
        ],
        'quantity' => [
            'conversionFactor', 'countedQty', 'expectedQty', 'maxQty', 'maxShiftHours', 'maxStockQty', 'mileageMiles',
            'minQty', 'minStockQty', 'netMassKg', 'orderedUnits', 'overtimeHours', 'qty', 'qtyAfter',
            'qtyAvailable', 'qtyBefore', 'qtyDelta', 'qtyOnHand', 'qtyRemaining', 'qtyReserved', 'receivedQty',
            'refundQty', 'reorderPoint', 'reorderQty', 'returnedQty', 'snapshotQty', 'totalHours', 'unitsSold',
            'varianceQty', 'volumeMl', 'baseQty', 'unitFactor', 'quantity', 'qtyRequested', 'qtyDispatched',
            'qtyReceived', 'qtyVariance',
            // Temperatures (°C) need no more than 4 dp either.
            'safeMinC', 'safeMaxC',
        ],
        'percent' => [
            'abvPercent', 'changePercent', 'defaultDepreciationRatePercent', 'depreciationRatePercent',
            'flatRatePercent', 'markdownPercent', 'percent', 'percentage', 'recountThresholdPercent', 'upliftPercent',
            'vatPercentage',
        ],
        'rate' => ['exchangeRate', 'ExchangeRate.rate'],
    ],

    /* Strings stored verbatim in longText (embedded JSON documents and print payloads). *Json fields are automatic. */
    'longText' => ['payload', 'priceTiers'],

    /* Free-text strings stored in `text` (no length limit worth enforcing). Other strings are varchar. */
    'text' => [
        'address', 'allergenText', 'attachmentPath', 'cancelReason', 'channels', 'closeNotes', 'deliveryDays',
        'description', 'detail', 'discountReason', 'disputeReason', 'endedReason', 'error', 'errorReportPath',
        'evidence', 'failureReason', 'filePath', 'imagePath', 'instruction', 'lastError', 'lastPushError',
        'logoPath', 'maxQtyReason', 'memo', 'message', 'note', 'notes', 'overrideReason', 'reason', 'reasonText',
        'receiptFile', 'text', 'unlockReason', 'varianceFlags', 'verificationDetail', 'voidReason', 'weekdays',
        'days', 'afterValue', 'beforeValue', 'directions', 'allergensCsv', 'handOverIdCheckNote', 'declineReason',
        'resolutionReason', 'attributeFilter',
    ],

    /* Integers that may exceed 2^31. */
    'bigIntegers' => [
        'sizeBytes', 'elapsedMs', 'lastSeq', 'lastPushedSeq', 'lastPulledVersion', 'hubVersion', 'branchVersion',
    ],

    /*
     * Members every payload may carry that the till computes rather than stores. Never stored, never put in
     * `extra`. Object/array members (Money objects, navigation collections) are derived automatically unless the
     * entity lists them under `json`.
     */
    'derived' => ['isDeleted', 'domainEvents', 'key'],

    /*
     * Per entity. Keys (all optional):
     *   table, class      Table / model class name (defaults: snake plural / entity name).
     *   parent            [ParentEntity, parentKeyField]: child rows get branch_id (and register_id when the
     *                     parent is register-scoped) from the parent, else from the push header.
     *   scope             Force a scope (company|sender|branch|register|child). A `parent` with a non-child
     *                     scope only adds the relations.
     *   columns           field => column name overrides.
     *   derived           Extra derived members (computed from the same row; not stored).
     *   json              Object/array members that ARE stored (json column).
     *   secret            String fields stored as HMAC hash + last 4 only (<column>_hash, <column>_last4).
     *   drop              Secret members never stored at all: no column, not in `extra`, not in a conflict payload.
     *   hidden            Fields hidden from toArray()/JSON.
     *   indexes           Extra indexes (lists of columns). (company_id, updated_at), (company_id, branch_id)
     *                     and the parent key are indexed automatically.
     *   immutable         Historic rows (contract §11): once frozen only `mutable` fields, deleted_at and
     *                     the sync columns change. `when` = the stored row matches; `whenParent` = the parent
     *                     matched at the change's seq; `always` = frozen once stored.
     *   traits            Hand-written traits added to the model.
     */
    'entities' => [
        // Sales (register scope; children inherit branch/register from the sale).
        'Sale' => [
            'derived' => ['isCompleted'],
            'indexes' => [
                ['company_id', 'branch_id', 'completed_at'],
                ['company_id', 'register_id', 'number'],
                ['company_id', 'receipt_number'],
                ['company_id', 'customer_id'],
                ['shift_id'],
            ],
            'immutable' => [
                'when' => ['status' => ['completed', 'voided']],
                'mutable' => ['status', 'voidReasonId', 'voidedBy'],
            ],
            'traits' => [SaleQueries::class],
        ],
        'SaleLine' => [
            'parent' => ['Sale', 'saleId'],
            'indexes' => [['company_id', 'product_id']],
            'immutable' => ['whenParent' => ['status' => ['completed', 'voided']], 'mutable' => []],
            'traits' => [SaleLineQueries::class],
        ],
        'SalePayment' => [
            'parent' => ['Sale', 'saleId'],
            'indexes' => [['company_id', 'payment_type_id']],
            'immutable' => ['whenParent' => ['status' => ['completed', 'voided']], 'mutable' => ['status']],
        ],
        'SaleVat' => [
            'parent' => ['Sale', 'saleId'],
            'indexes' => [['company_id', 'vat_rate_id']],
            'immutable' => ['whenParent' => ['status' => ['completed', 'voided']], 'mutable' => []],
        ],
        'SalesDaily' => [
            'table' => 'sales_daily',
            'indexes' => [['company_id', 'branch_id', 'date'], ['company_id', 'product_id', 'date']],
        ],
        'HourlySales' => ['indexes' => [['company_id', 'branch_id', 'date']]],
        'TenderDaily' => ['table' => 'tender_daily', 'indexes' => [['company_id', 'branch_id', 'date']]],
        'PaymentAttempt' => ['indexes' => [['sale_id']]],

        // Customers and orders.
        'Customer' => ['derived' => ['isAnonymised'], 'indexes' => [['company_id', 'card_no']]],
        'Consent' => ['parent' => ['Customer', 'customerId'], 'scope' => 'sender'],
        'CustomerOrder' => [
            'derived' => ['balanceDue', 'isOpen'],
            'indexes' => [['company_id', 'branch_id', 'status'], ['company_id', 'reference']],
        ],
        'CustomerOrderPayment' => [
            'parent' => ['CustomerOrder', 'orderId'],
            'indexes' => [['sale_id']],
            'immutable' => ['always' => true, 'mutable' => []],
        ],
        'CustomerTransaction' => ['indexes' => [['company_id', 'customer_id', 'at']]],
        'StoreCreditVoucher' => ['derived' => ['isActivated', 'hasBalance'], 'indexes' => [['company_id', 'barcode']]],

        // Catalogue (hub-owned company-wide rows; parents only add relations).
        'Product' => ['indexes' => [['company_id', 'sku'], ['company_id', 'department_id'], ['company_id', 'category_id']]],
        'ProductBarcode' => ['parent' => ['Product', 'productId'], 'scope' => 'company', 'indexes' => [['company_id', 'barcode']]],
        'ProductAlias' => ['parent' => ['Product', 'productId'], 'scope' => 'company'],
        'ProductUnit' => ['parent' => ['Product', 'productId'], 'scope' => 'company'],
        'ProductSupplier' => ['parent' => ['Product', 'productId'], 'scope' => 'company', 'indexes' => [['company_id', 'supplier_id']]],
        'ProductRecall' => ['derived' => ['isOpen']],
        'PriceHistory' => ['indexes' => [['company_id', 'product_id', 'at']]],
        'BranchProduct' => ['indexes' => [['company_id', 'branch_id', 'product_id']]],
        'PriceChangeBatch' => ['derived' => ['isEditable', 'canGoLive', 'canCancel', 'isClosed']],
        // The line's own branchId is the price's target branch (null = all), not the owning branch.
        'PriceChangeLine' => ['parent' => ['PriceChangeBatch', 'batchId'], 'columns' => ['branchId' => 'price_branch_id']],
        'PromotionItem' => ['parent' => ['PromotionRule', 'promotionRuleId'], 'scope' => 'company'],
        'PromotionCoupon' => ['parent' => ['PromotionRule', 'promotionRuleId'], 'scope' => 'company'],
        'MedicineClassification' => ['parent' => ['Product', 'productId'], 'scope' => 'company'],
        'ProductAllergenMatrix' => ['indexes' => [['company_id', 'product_id']]],
        'PromotionRedemption' => ['indexes' => [['company_id', 'branch_id', 'trading_date'], ['sale_id']]],
        'CouponRedemption' => ['indexes' => [['sale_id']]],

        // Stock.
        'StockMovement' => [
            'indexes' => [['company_id', 'branch_id', 'product_id', 'at'], ['ref_id']],
            'traits' => [StockMovementQueries::class],
        ],
        'StockLayer' => ['indexes' => [['company_id', 'branch_id', 'product_id']]],
        'FifoStockLayer' => ['indexes' => [['company_id', 'branch_id', 'product_id']]],
        'StockReservation' => ['derived' => ['isHeld']],
        'StockTake' => ['derived' => ['isClosed']],
        'StockTakeSection' => ['parent' => ['StockTake', 'stockTakeId'], 'scope' => 'branch'],
        'StockTakeLine' => ['parent' => ['StockTake', 'stockTakeId'], 'scope' => 'branch', 'derived' => ['isCounted']],
        // Branch-to-branch transfers (v1.3; relayed to the receiving branch in v1.4). Each row has its own branchId.
        'StockTransfer' => ['indexes' => [['company_id', 'from_branch_id'], ['company_id', 'to_branch_id'], ['company_id', 'reference']]],
        'StockTransferLine' => ['parent' => ['StockTransfer', 'transferId'], 'scope' => 'branch', 'indexes' => [['company_id', 'product_id']]],
        'StockTransferReceipt' => ['parent' => ['StockTransfer', 'transferId'], 'scope' => 'branch'],
        'StockTransferReceiptLine' => [
            'parent' => ['StockTransferReceipt', 'receiptId'],
            'scope' => 'branch',
            'indexes' => [['transfer_id'], ['transfer_line_id']],
        ],

        // Purchasing.
        'PurchaseOrder' => ['derived' => ['isEditable', 'isOpen'], 'indexes' => [['company_id', 'supplier_id']]],
        'PurchaseOrderLine' => ['parent' => ['PurchaseOrder', 'purchaseOrderId'], 'derived' => ['isFullyReceived']],
        'GoodsReceipt' => ['indexes' => [['company_id', 'supplier_id']]],
        'GoodsReceiptLine' => ['parent' => ['GoodsReceipt', 'goodsReceiptId']],
        'SupplierInvoice' => ['indexes' => [['company_id', 'supplier_id']]],
        'SupplierInvoiceLine' => ['parent' => ['SupplierInvoice', 'supplierInvoiceId']],
        'SupplierCreditNote' => ['indexes' => [['company_id', 'supplier_id']]],
        'SupplierCreditNoteLine' => ['parent' => ['SupplierCreditNote', 'supplierCreditNoteId']],
        'SupplierPayment' => ['indexes' => [['company_id', 'supplier_id']]],
        'SupplierPaymentAllocation' => ['parent' => ['SupplierPayment', 'supplierPaymentId']],
        'StandingOrderLine' => ['parent' => ['StandingOrder', 'standingOrderId']],
        'RebateAccrual' => ['derived' => ['isOutstanding']],
        'NewsDeliveryLine' => ['parent' => ['NewsDelivery', 'deliveryId']],
        'NewsVoucherRedemption' => ['indexes' => [['company_id', 'branch_id', 'redeemed_at']]],

        // Cash and end of day.
        'Shift' => ['derived' => ['isOpen'], 'indexes' => [['company_id', 'branch_id', 'opened_at']]],
        'ShiftTender' => ['parent' => ['Shift', 'shiftId'], 'scope' => 'register'],
        'CashCount' => ['indexes' => [['shift_id']]],
        'CashMovement' => ['indexes' => [['company_id', 'branch_id', 'at'], ['shift_id']]],
        'CardSettlement' => ['derived' => ['isMatched']],
        'ZReport' => ['indexes' => [['company_id', 'branch_id', 'period_end']]],

        // Accounts.
        'Account' => ['table' => 'ledger_accounts'],
        'JournalEntry' => [
            'indexes' => [['company_id', 'date']],
            'immutable' => ['always' => true, 'mutable' => ['isReversed', 'reversedByEntryId']],
        ],
        'JournalLine' => [
            'parent' => ['JournalEntry', 'journalEntryId'],
            'indexes' => [['company_id', 'account_id']],
            'immutable' => ['always' => true, 'mutable' => []],
        ],
        'FinancialPeriod' => ['parent' => ['FinancialYear', 'financialYearId'], 'scope' => 'sender'],
        'FixedAsset' => ['derived' => ['isDisposed']],
        'Expense' => ['indexes' => [['company_id', 'branch_id', 'expense_date']]],

        // Staff. `pinHash` and `rfid` sign a person in at the till: never shown.
        // `remoteApprovalSecret` (v1.3) approves actions from another device: never stored, never sent down.
        'User' => ['table' => 'till_users', 'class' => 'TillUser', 'hidden' => ['pinHash', 'rfid'], 'drop' => ['remoteApprovalSecret']],
        'TrainingRecord' => ['indexes' => [['company_id', 'user_id']]],
        'Role' => ['table' => 'till_roles', 'class' => 'TillRole', 'json' => ['permissions']],
        'ClockEvent' => ['indexes' => [['company_id', 'user_id', 'at']]],

        // System. Names that clash with portal tables get a till_ prefix.
        'AuditLog' => [
            'table' => 'till_audit_logs',
            'class' => 'TillAuditLog',
            'indexes' => [['company_id', 'branch_id', 'at'], ['company_id', 'entity_name', 'entity_id']],
            'immutable' => ['always' => true, 'mutable' => []],
        ],
        // The real licences are module 1.3's; this is the till's copy. The key is a secret: hash + last 4 only.
        'Licence' => ['table' => 'till_licences', 'class' => 'TillLicence', 'secret' => ['licenceKey']],
        'LicenceAddOnTrial' => ['table' => 'till_licence_add_on_trials', 'class' => 'TillLicenceAddOnTrial', 'derived' => ['isOverUsageCap']],
        'SyncConflict' => ['table' => 'till_sync_conflicts', 'class' => 'TillSyncConflict', 'derived' => ['isOpen']],
        'SyncState' => ['table' => 'till_sync_states', 'class' => 'TillSyncState', 'derived' => ['isHealthy']],
        'PrintJob' => ['derived' => ['isPending']],
        'Parcel' => ['parent' => ['ParcelCarrier', 'carrierId'], 'scope' => 'branch', 'indexes' => [['company_id', 'tracking_code']]],

        // Compliance diary, incidents, pharmacy (v1.3). Children carry their own branchId: parents add relations.
        'DiaryCheckRecord' => [
            'parent' => ['DiaryCheckDefinition', 'diaryCheckDefinitionId'],
            'scope' => 'branch',
            'indexes' => [['company_id', 'branch_id', 'recorded_at']],
        ],
        'IncidentReport' => ['indexes' => [['company_id', 'branch_id', 'occurred_at']]],
        'DispensingRecord' => ['indexes' => [['company_id', 'branch_id', 'dispensed_at'], ['company_id', 'patient_customer_id'], ['sale_id']]],
        'DispensingItem' => ['parent' => ['DispensingRecord', 'dispensingRecordId'], 'scope' => 'branch'],
    ],
];
