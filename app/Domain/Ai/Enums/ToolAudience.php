<?php

namespace App\Domain\Ai\Enums;

/**
 * Who a tool is for. Tenant tools run inside CurrentCompany::runAs() and check a CompanyRole ability (Ability enum);
 * admin tools check an admin ability (AdminRole constants).
 */
enum ToolAudience: string
{
    case Tenant = 'tenant';
    case Admin = 'admin';
}
