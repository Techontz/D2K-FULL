<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManagePayment;
use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Payment, the screen where an administrator sets the one till the
 * whole marketplace is paid into.
 */
class PaymentSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@test.local',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'phone' => '0700000000',
        ]));
    }

    public function test_the_page_loads_and_shows_the_current_settings(): void
    {
        PaymentSetting::create([
            'lipa_namba' => '552354', 'account_name' => 'Direct2Kariakoo', 'is_active' => true,
        ]);

        Livewire::test(ManagePayment::class)
            ->assertOk()
            ->assertFormSet([
                'lipa_namba'   => '552354',
                'account_name' => 'Direct2Kariakoo',
                'is_active'    => true,
            ]);
    }

    public function test_an_admin_can_change_the_number(): void
    {
        PaymentSetting::create([
            'lipa_namba' => '552354', 'account_name' => 'Direct2Kariakoo', 'is_active' => true,
        ]);

        Livewire::test(ManagePayment::class)
            ->fillForm(['lipa_namba' => '99 88 77 66', 'account_name' => 'D2K Tanzania', 'is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored normalised, so checkout and this screen agree on the digits.
        $this->assertSame('99887766', PaymentSetting::current()->lipa_namba);
        $this->assertSame('D2K Tanzania', PaymentSetting::current()->payeeName());
    }

    public function test_saving_updates_the_single_row_rather_than_adding_another(): void
    {
        PaymentSetting::create(['lipa_namba' => '552354', 'account_name' => 'D2K', 'is_active' => true]);

        Livewire::test(ManagePayment::class)
            ->fillForm(['lipa_namba' => '111222', 'account_name' => 'D2K', 'is_active' => true])
            ->call('save');

        // A second row would become dead configuration nobody reads.
        $this->assertSame(1, PaymentSetting::query()->count());
    }

    public function test_a_nonsense_number_is_refused(): void
    {
        PaymentSetting::create(['lipa_namba' => '552354', 'account_name' => 'D2K', 'is_active' => true]);

        Livewire::test(ManagePayment::class)
            ->fillForm(['lipa_namba' => 'not-a-number', 'account_name' => 'D2K', 'is_active' => true])
            ->call('save')
            ->assertHasFormErrors(['lipa_namba']);

        $this->assertSame('552354', PaymentSetting::current()->lipa_namba, 'A rejected edit must not clear the live till.');
    }

    public function test_the_first_save_creates_the_row(): void
    {
        $this->assertSame(0, PaymentSetting::query()->count());

        Livewire::test(ManagePayment::class)
            ->fillForm(['lipa_namba' => '552354', 'account_name' => 'Direct2Kariakoo', 'is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('552354', PaymentSetting::current()->lipa_namba);
    }
}
