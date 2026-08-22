<?php

namespace App\Filament\Pages;

use App\Models\PaymentSetting;
use App\Support\LipaNamba;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Settings → Payment: the one till the whole marketplace is paid into.
 *
 * This is an administrator's screen on purpose. Sellers used to enter their
 * own number and a customer buying from three shops was handed three tills and
 * asked to make three transfers — which no customer does, and which cannot be
 * reconciled against a single order. One number, set here, settled with sellers
 * afterwards.
 */
class ManagePayment extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Payment';
    protected static ?string $title = 'Payment settings';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-payment';

    public ?array $data = [];

    public function mount(): void
    {
        $current = PaymentSetting::current();

        $this->form->fill([
            'lipa_namba'   => $current->lipa_namba,
            'account_name' => $current->payeeName(),
            'is_active'    => (bool) $current->is_active,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Direct2Kariakoo Lipa Namba')
                    ->description('Customers pay the whole of their order to this one number, whichever sellers the basket came from.')
                    ->schema([
                        Forms\Components\TextInput::make('lipa_namba')
                            ->label('Lipa Namba')
                            ->helperText('Digits only. This is the number every customer is shown at checkout.')
                            ->required()
                            ->maxLength(32)
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && ! LipaNamba::isValid($value)) {
                                    $fail('That does not look like a Tanzanian Lipa Namba.');
                                }
                            }),

                        Forms\Components\TextInput::make('account_name')
                            ->label('Account name')
                            ->helperText('Shown under the number so the customer can check it before sending.')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Accepting payments')
                            ->helperText('Turn off to stop checkout taking new orders — during a till change, for example. Existing orders keep the number they were placed with.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Stored normalised so checkout, the order snapshot and this screen all
        // show the same digits.
        $data['lipa_namba'] = LipaNamba::normalise($data['lipa_namba']);

        $existing = PaymentSetting::query()->orderBy('id')->first();

        // Deliberately updates the single row rather than inserting: a second
        // row would silently become dead configuration nobody is reading.
        if ($existing) {
            $existing->update($data);
        } else {
            PaymentSetting::create($data);
        }

        Notification::make()
            ->success()
            ->title('Payment settings saved')
            ->body('New orders will show this number. Orders already placed keep the number they were given.')
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }
}
