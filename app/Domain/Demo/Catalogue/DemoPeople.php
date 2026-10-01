<?php

namespace App\Domain\Demo\Catalogue;

/**
 * Names for the demo (`demo:seed`): staff per shop, customers, suppliers. Phone numbers are Ofcom's drama ranges and
 * e-mail addresses use example domains, so nothing reaches a real person.
 */
final class DemoPeople
{
    /** Per shop (in code order across the demo businesses): manager, three cashiers, a weekend part-timer. */
    public const STAFF = [
        ['Aisha Khan', 'Bilal Ahmed', 'Sophie Turner', 'Daniel Hughes', 'Zara Hussain'],
        ['Tariq Mahmood', 'Emma Walsh', 'Ryan Patel', 'Hannah Clarke', 'Adam Iqbal'],
        ['Gurpreet Kaur', 'Jaspreet Singh', 'Liam O\'Connor', 'Chloe Evans', 'Simran Gill'],
        ['Mohammed Ali', 'Lucy Brown', 'Josh Wright', 'Fatima Begum', 'Kieran Doyle'],
    ];

    public const FIRST = [
        'Oliver', 'Amelia', 'Muhammad', 'Olivia', 'George', 'Isla', 'Noah', 'Ava', 'Arthur', 'Mia', 'Harry', 'Ivy', 'Leo', 'Freya',
        'Jack', 'Lily', 'Charlie', 'Florence', 'Oscar', 'Grace', 'Jacob', 'Sophia', 'Thomas', 'Emily', 'Ali', 'Ayesha', 'Hassan',
        'Maryam', 'Harjit', 'Manpreet', 'Ravi', 'Priya', 'Sean', 'Siobhan', 'David', 'Margaret', 'Peter', 'Susan', 'Michael',
        'Patricia', 'Stephen', 'Janet', 'Graham', 'Pauline', 'Kevin', 'Karen', 'Wayne', 'Tracey', 'Dean', 'Joanne', 'Ibrahim',
        'Khadija', 'Usman', 'Nadia', 'Kwame', 'Abena', 'Tomasz', 'Agnieszka', 'Andrei', 'Elena',
    ];

    public const LAST = [
        'Smith', 'Jones', 'Taylor', 'Brown', 'Williams', 'Wilson', 'Johnson', 'Davies', 'Patel', 'Robinson', 'Wright', 'Thompson',
        'Evans', 'Walker', 'White', 'Roberts', 'Green', 'Hall', 'Wood', 'Jackson', 'Clarke', 'Khan', 'Hussain', 'Ahmed', 'Ali',
        'Begum', 'Singh', 'Kaur', 'Sharma', 'Shah', 'Mistry', 'O\'Brien', 'Murphy', 'Kelly', 'Hughes', 'Edwards', 'Lewis',
        'Harrison', 'Martin', 'Cooper', 'Ward', 'Morris', 'Turner', 'Baker', 'Moore', 'Mensah', 'Nowak', 'Kowalski', 'Popescu',
    ];

    public const STREETS = [
        'Station Road', 'Church Street', 'High Street', 'Park Avenue', 'Victoria Road', 'Queen Street', 'Manor Road', 'Mill Lane',
        'Green Lane', 'Albert Road', 'Windsor Drive', 'Grange Road', 'Springfield Road', 'Oakwood Avenue', 'Chapel Lane',
    ];

    /**
     * Supplier key => [name, code, contact, phone, town, postcode, terms, days, lead days, min order (pence), method, delivery days, notes].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: int, 8: int, 9: int, 10: string, 11: string, 12: string}>
     */
    public const SUPPLIERS = [
        'booker' => ['Booker Wholesale', 'BOOK', 'Account manager', '0113 496 0101', 'Leeds', 'LS11 5DR', 'netDays', 7, 2, 25000, 'portal', 'tuesday', 'Delivered service. Tobacco, alcohol, chilled and frozen.'],
        'bestway' => ['Bestway Wholesale', 'BEST', 'Telesales', '0113 496 0202', 'Bradford', 'BD4 7SY', 'netDays', 14, 2, 15000, 'portal', 'thursday', 'Soft drinks, confectionery and household.'],
        'parfetts' => ['Parfetts', 'PARF', 'Rep: Sam Holt', '0161 496 0303', 'Stockport', 'SK5 7BS', 'endOfMonth', 30, 3, 20000, 'rep', 'friday', 'Grocery and fresh produce. Go Local symbol group.'],
        'dairy' => ['Meadow Fresh Dairy', 'DAIRY', 'Pete (driver)', '0113 496 0404', 'Wetherby', 'LS22 6RT', 'netDays', 7, 1, 0, 'phone', 'monday, wednesday, friday', 'Milk, eggs, butter and bread. Weekly direct debit.'],
        'news' => ['Smiths News', 'NEWS', 'Customer services', '0121 496 0505', 'Birmingham', 'B7 4AX', 'netDays', 7, 1, 0, 'edi', 'monday, tuesday, wednesday, thursday, friday, saturday, sunday', 'Newspapers and magazines. Weekly bill by direct debit.'],
    ];

    /** Category => supplier that delivers it (DemoRange category keys). */
    public const SUPPLIER_OF = [
        'bread' => 'dairy', 'milk' => 'dairy', 'dairy' => 'dairy', 'eggs' => 'dairy',
        'papers' => 'news', 'sundays' => 'news', 'magazines' => 'news',
        'softdrinks' => 'bestway', 'energy' => 'bestway', 'water' => 'bestway', 'chocolate' => 'bestway', 'sweets' => 'bestway',
        'crisps' => 'bestway', 'biscuits' => 'bestway', 'cleaning' => 'bestway', 'toiletries' => 'bestway', 'paper' => 'bestway',
        'tins' => 'parfetts', 'dry' => 'parfetts', 'sauces' => 'parfetts', 'hotdrinks' => 'parfetts', 'fruit' => 'parfetts', 'veg' => 'parfetts',
        'cooking' => 'parfetts', 'general' => 'parfetts', 'health' => 'parfetts',
    ];

    public static function supplierOf(string $category): string
    {
        return self::SUPPLIER_OF[$category] ?? 'booker';
    }

    /** The owner's name for a demo business. */
    public static function owner(string $company): string
    {
        return match (true) {
            str_contains($company, 'Khan') => 'Imran Khan',
            str_contains($company, 'Singh') => 'Harpreet Singh',
            str_contains($company, 'Patel') => 'Imran Patel',
            default => 'Alex Morgan',
        };
    }
}
