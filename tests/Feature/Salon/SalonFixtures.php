<?php

namespace Tests\Feature\Salon;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Salon W3b test rows, written straight into the tables the till's push fills (salon_staff_profiles,
 * salon_appointments, salon_appointment_services, salon_online_bookings, till_users, products). "Now" in these tests
 * is Sunday 8 Nov 2026 10:00 UTC (GMT, so London = UTC).
 */
final class SalonFixtures
{
    public const NOW = '2026-11-08 10:00:00';

    /** @return array{0: Company, 1: Branch, 2: Branch} a salon business with two shops */
    public static function business(string $name = 'Snip & Co', BusinessType $type = BusinessType::Salon): array
    {
        $company = Company::factory()->create(['name' => $name, 'business_type' => $type]);
        $leeds = Branch::factory()->forCompany($company)->create(['name' => 'Leeds', 'code' => strtoupper(substr(md5($name.'L'), 0, 3))]);
        $york = Branch::factory()->forCompany($company)->create(['name' => 'York', 'code' => strtoupper(substr(md5($name.'Y'), 0, 3))]);

        return [$company, $leeds, $york];
    }

    public static function member(Company $company, CompanyRole $role, ?Branch $shop = null): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $shop?->id]);

        return $user;
    }

    /** A till user who is a stylist of the shop (till_users + salon_staff_profiles). */
    public static function stylist(Company $company, Branch $shop, string $name, array $profile = []): string
    {
        $userId = $profile['user_id'] ?? Ulid::new();
        DB::table('till_users')->insertOrIgnore(['id' => $userId, 'company_id' => $company->id, 'name' => $name, 'is_active' => true, 'row_version' => 1]);
        DB::table('salon_staff_profiles')->insert([
            'id' => Ulid::new(), 'company_id' => $company->id, 'branch_id' => $shop->id, 'user_id' => $userId, 'display_name' => $name,
            'is_bookable' => true, 'is_online_bookable' => false, 'is_chair_renter' => false, 'sort_order' => 1, 'row_version' => 1,
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00', ...$profile,
        ]);

        return $userId;
    }

    public static function service(Company $company, string $name): string
    {
        $id = Ulid::new();
        DB::table('products')->insert(['id' => $id, 'company_id' => $company->id, 'name' => $name, 'row_version' => 1]);

        return $id;
    }

    /**
     * An appointment with one service row; returns the service row id (the feed's UID).
     *
     * @param  array<string, mixed>  $appointment
     * @param  array<string, mixed>  $service
     */
    public static function booked(Company $company, Branch $shop, string $stylist, string $productId, string $startsAt, array $appointment = [], array $service = []): string
    {
        $appointmentId = Ulid::new();
        $serviceId = Ulid::new();
        $ends = date('Y-m-d H:i:s', strtotime($startsAt) + 3600);

        DB::table('salon_appointments')->insert([
            'id' => $appointmentId, 'company_id' => $company->id, 'branch_id' => $shop->id, 'client_name' => 'Sarah Jones',
            'client_phone' => '07700 900123', 'client_email' => 'sarah@example.com', 'notes' => 'Allergic to latex; prefers quiet',
            'starts_at' => $startsAt, 'ends_at' => $ends, 'status' => 'booked', 'source' => 'till', 'deposit_amount' => '10.00',
            'row_version' => 1, 'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-02 09:00:00', ...$appointment,
        ]);
        DB::table('salon_appointment_services')->insert([
            'id' => $serviceId, 'company_id' => $company->id, 'branch_id' => $shop->id, 'appointment_id' => $appointmentId,
            'product_id' => $productId, 'stylist_user_id' => $stylist, 'assistant_user_id' => null, 'starts_at' => $startsAt, 'ends_at' => $ends,
            'price' => '45.00', 'row_version' => 1, 'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00', ...$service,
        ]);

        return $serviceId;
    }

    /** @param array<string, mixed> $columns */
    public static function request(Company $company, Branch $shop, array $columns = []): string
    {
        $id = Ulid::new();
        DB::table('salon_online_bookings')->insert([
            'id' => $id, 'company_id' => $company->id, 'branch_id' => $shop->id, 'reference' => 'BK-'.substr($id, -5), 'kind' => 'new',
            'requested_starts_at' => '2026-11-10 14:00:00', 'client_first_name' => 'Amira', 'client_last_name' => 'Khan',
            'client_phone' => '07700 900456', 'client_email' => 'amira@example.com', 'notes' => 'Long thick hair', 'status' => 'pending',
            'row_version' => 1, 'created_at' => '2026-11-07 18:00:00', 'updated_at' => '2026-11-07 18:00:00', ...$columns,
        ]);

        return $id;
    }

    /** The plain token in a feed address. */
    public static function tokenOf(string $url): string
    {
        return (string) preg_replace('#^.*/([^/]+)\.ics$#', '$1', $url);
    }

    /** The path of a feed address. */
    public static function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
