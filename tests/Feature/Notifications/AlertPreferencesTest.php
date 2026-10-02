<?php

use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Notifications\Support\AlertInbox;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures as T;

/* Module 7.8: Settings → Notifications, the bell and the signed unsubscribe link. */

beforeEach(function () {
    [$this->company] = T::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->other = Company::factory()->create(['name' => 'Patel News']);
    $this->otherOwner = C::member($this->other, CompanyRole::Owner);
});

test('guests are sent to sign in from the settings page and the bell', function () {
    $this->get('/app/settings/notifications')->assertRedirect('/login');
    $this->put('/app/settings/notifications', [])->assertRedirect('/login');
    $this->getJson('/app/notifications')->assertUnauthorized();
    $this->postJson('/app/notifications/read')->assertUnauthorized();
});

test('the settings page shows the role\'s alert types with their defaults and the shops', function () {
    $this->actingAs($this->owner)->get('/app/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/settings/notifications')
        ->where('business', 'Kirkgate Convenience')
        ->has('types', 8) // module 6.6 added "Unusual activity"
        ->where('types.0.value', 'tillOffline')->where('types.0.delivery', 'immediate')
        ->where('types.2.value', 'lowStock')->where('types.2.delivery', 'digest')
        ->where('types.2.options', [['value' => 'off', 'label' => 'Off'], ['value' => 'digest', 'label' => 'Daily digest']])
        ->has('shops', 2)->where('allShops', true)->where('lockedShop', null));
});

test('staff see only what their role can see, all off; a one-shop user sees their shop locked', function () {
    $staff = C::member($this->company, CompanyRole::Staff, T::LEEDS);

    $this->actingAs($staff)->get('/app/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('types', 1)->where('types.0.value', 'lowStock')->where('types.0.delivery', 'off')
        ->where('shops', [])->where('lockedShop', 'Leeds'));
});

test('saving keeps the user\'s choices and shops, drops what the role cannot get, and is audited', function () {
    $this->actingAs($this->owner)->put('/app/settings/notifications', [
        'deliveries' => ['tillOffline' => 'digest', 'lowStock' => 'off', 'cashVariance' => 'digest'],
        'allShops' => false,
        'shops' => [T::BRADFORD, 'NOTASHOP00000000000000000X'],
    ])->assertRedirect()->assertSessionHas('success');

    $saved = AlertPreference::withoutCompanyScope()->where('user_id', $this->owner->id)->sole();
    expect($saved->company_id)->toBe($this->company->id)
        ->and($saved->deliveries)->toBeIgnoringKeyOrder(['tillOffline' => 'digest', 'lowStock' => 'off', 'cashVariance' => 'digest'])
        ->and($saved->branch_ids)->toBe([T::BRADFORD])
        ->and(AuditLog::query()->where('action', 'alerts.preferences_updated')->exists())->toBeTrue();

    // A manager cannot sneak in a type they cannot see (accountant: sync conflicts).
    $accountant = C::member($this->company, CompanyRole::Accountant);
    $this->actingAs($accountant)->put('/app/settings/notifications', ['deliveries' => ['syncConflicts' => 'digest'], 'allShops' => true])->assertRedirect();
    expect(AlertPreference::withoutCompanyScope()->where('user_id', $accountant->id)->sole()->deliveries)->toBe([]);
});

test('digest-only types cannot be set to straight away, and an empty shop pick is refused', function () {
    $this->actingAs($this->owner)->from('/app/settings/notifications')->put('/app/settings/notifications', [
        'deliveries' => ['lowStock' => 'immediate', 'tillOffline' => 'never'],
        'allShops' => false,
        'shops' => [],
    ])->assertSessionHasErrors(['deliveries.lowStock', 'deliveries.tillOffline', 'shops']);

    expect(AlertPreference::withoutCompanyScope()->count())->toBe(0);
});

test('a user\'s choices in one business never touch another business', function () {
    $this->otherOwner->companies()->attach($this->company->id, ['role' => 'manager', 'is_active' => true]);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->other->id, 'user_id' => $this->otherOwner->id, 'deliveries' => ['lowStock' => 'digest']]);

    // Signed in to Kirkgate (their current business is the first active membership); saving there leaves Patel alone.
    $this->actingAs($this->otherOwner)->withSession(['current_company_id' => $this->company->id])
        ->put('/app/settings/notifications', ['deliveries' => ['lowStock' => 'off'], 'allShops' => true])->assertRedirect();

    expect(AlertPreference::withoutCompanyScope()->where('company_id', $this->other->id)->sole()->deliveries)->toBe(['lowStock' => 'digest'])
        ->and(AlertPreference::withoutCompanyScope()->where('company_id', $this->company->id)->sole()->deliveries)->toBe(['lowStock' => 'off']);
});

test('the bell lists only the user\'s own entries in the current business and marks them read', function () {
    $mine = AlertInbox::add($this->company->id, $this->owner->id, 'tillOffline', 'danger', 'Till 1 at Leeds is offline', null, config('sspos.portal_url').'/app/shops');
    AlertInbox::add($this->company->id, $this->owner->id, 'digest', 'info', 'Daily summary: 2 things to check', 'Low stock', null);
    AlertInbox::add($this->other->id, $this->otherOwner->id, 'tillOffline', 'danger', 'Their till is offline', null, null);
    $theirs = AlertNotification::withoutCompanyScope()->where('company_id', $this->other->id)->sole();

    $feed = $this->actingAs($this->owner)->getJson('/app/notifications')->assertOk()->json();
    expect($feed['unread'])->toBe(2)
        ->and(array_column($feed['items'], 'title'))->not->toContain('Their till is offline')
        ->and(collect($feed['items'])->firstWhere('id', $mine->id)['url'])->toBe('/app/shops');

    // Another business's entry id changes nothing.
    $this->actingAs($this->owner)->postJson('/app/notifications/read', ['id' => $theirs->id])->assertOk()->assertJsonPath('unread', 2);
    expect($theirs->fresh()->read_at)->toBeNull();

    $this->actingAs($this->owner)->postJson('/app/notifications/read', ['id' => $mine->id])->assertOk()->assertJsonPath('unread', 1);
    $this->actingAs($this->owner)->postJson('/app/notifications/read')->assertOk()->assertJsonPath('unread', 0);
});

test('the signed unsubscribe link asks first, then turns that alert off without signing in', function () {
    $url = AlertLinks::unsubscribe($this->company->id, $this->owner->id, 'lowStock');

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('alerts/unsubscribe')->where('state', 'confirm')->where('what', 'Low and negative stock'));
    expect(AlertPreference::withoutCompanyScope()->count())->toBe(0);

    $this->post($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'done'));
    $saved = AlertPreference::withoutCompanyScope()->where('user_id', $this->owner->id)->sole();
    expect($saved->deliveries['lowStock'])->toBe('off')
        ->and($saved->deliveries['tillOffline'])->toBe('immediate')
        ->and($saved->company_id)->toBe($this->company->id);
});

test('the digest unsubscribe link turns off every digest type and leaves urgent emails on', function () {
    $this->post(AlertLinks::unsubscribe($this->company->id, $this->owner->id, AlertLinks::DIGEST))->assertOk();

    $saved = AlertPreference::withoutCompanyScope()->sole()->deliveries;
    expect($saved)->toMatchArray(['lowStock' => 'off', 'cashVariance' => 'off', 'compliance' => 'off', 'syncConflicts' => 'off', 'tillOffline' => 'immediate', 'syncFailing' => 'immediate']);
});

test('an unsigned or tampered unsubscribe link is refused, and a link for another business changes nothing there', function () {
    $url = AlertLinks::unsubscribe($this->company->id, $this->owner->id, 'lowStock');

    $this->get('/app/alerts/unsubscribe/'.$this->company->id.'/'.$this->owner->id.'/lowStock')->assertForbidden();
    $this->post(str_replace('/lowStock?', '/cashVariance?', $url))->assertForbidden();
    $this->post(str_replace((string) $this->owner->id.'/', (string) $this->otherOwner->id.'/', $url))->assertForbidden();

    // A real link for a user who is not a member of that business: nothing to change.
    $this->post(AlertLinks::unsubscribe($this->company->id, $this->otherOwner->id, 'lowStock'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
    expect(AlertPreference::withoutCompanyScope()->count())->toBe(0);
});
