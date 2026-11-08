/**
 * Salon / Barber till audit actions in plain words (salon pack 2026-11-08, README Part C "New AuditLog actions"; W2).
 * Only salon shops send them. A test keeps this list equal to the pack's actions (SalonLabelsTest).
 */
export const SALON_AUDIT_ACTIONS: Record<string, string> = {
    // Services and team
    SalonLevelRetired: 'Stylist level retired',
    SalonResourceRetired: 'Chair or room retired',
    // Clients
    SalonPhotoRemoved: 'Client photo removed',
    SalonClientErased: 'Client salon records erased',
    SalonClientsImported: 'Clients imported',
    SalonAppointmentsImported: 'Appointments imported',
    // Diary
    SalonAppointmentCancelled: 'Appointment cancelled',
    SalonAppointmentNoShow: 'Appointment marked no-show',
    SalonBlockDeleted: 'Diary block deleted',
    SalonDepositTaken: 'Deposit taken',
    SalonDepositKept: 'Deposit kept',
    SalonDepositRefunded: 'Deposit refunded',
    SalonFeeCharged: 'Missed appointment fee charged',
    SalonFeeWaived: 'Missed appointment fee waived',
    // Walk-in queue
    SalonQueueNoShow: 'Queue client marked no-show',
    SalonQueueEntryRemoved: 'Queue client removed',
    SalonQueueReordered: 'Queue reordered',
    SalonQueueStepUndone: 'Queue step undone',
    // Till
    'Salon.LineStaffChange': 'Stylist on a line changed',
    // Money
    SalonTipsPaidOut: 'Tips paid out',
    SalonTipsSettingsSaved: 'Tip settings saved',
    SalonCommissionPlanSaved: 'Commission plan saved',
    SalonCommissionPlanAssigned: 'Commission plan assigned',
    SalonRenterAgreementSaved: 'Chair renter agreement saved',
    SalonRenterStatementMade: 'Chair renter statement made',
    SalonRenterStatementIssue: 'Chair renter statement issued',
    SalonRenterStatementSettle: 'Chair renter statement settled',
    SalonRenterStatementCancel: 'Chair renter statement cancelled',
    SalonPackageExpired: 'Package expired',
    // Messages
    SalonMessageSent: 'Client message sent',
    SalonMessageFailed: 'Client message failed',
    SalonMessageSkipped: 'Client message skipped',
    SalonMessageRetried: 'Client message retried',
    SalonMessageTest: 'Test message sent',
    SalonAutomationSaved: 'Automatic message saved',
    SalonMessageSettingsSaved: 'Message settings saved',
    SalonMarketingOptOut: 'Client opted out of marketing',
    SalonMessageProvidersSaved: 'Message providers saved',
    SalonMarketingClientsExported: 'Marketing list exported',
};
