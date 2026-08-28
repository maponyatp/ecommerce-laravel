<?php

namespace App\Filament\Admin\Pages;

use App\Services\ThemeLibraryService;
use App\Settings\GeneralSettings;
use App\Support\StoreMoney;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class ManageGeneralSettings extends SettingsPage
{
    protected ?bool $hasDatabaseTransactions = true;

    #[Locked]
    public int $themeVersion = 0;

    #[Locked]
    public string $themeFingerprint = '';

    public function canEdit(): bool
    {
        return (bool) (auth()->user()?->hasAnyRole(['admin', 'super_admin']) && ! auth()->user()?->staff_access_disabled_at);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $service = app(ThemeLibraryService::class);
        $this->themeVersion = (int) $service->state()->version;
        $this->themeFingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }

    protected function beforeSave(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_theme_state')) {
            return;
        }
        $service = app(ThemeLibraryService::class);
        $service->lock($this->themeVersion, $this->themeFingerprint);
        $snapshot = $service->snapshot(auth()->user());
        DB::table('store_theme_state')->where('id', 1)->increment('version');
        DB::table('store_theme_events')->insert(['actor_id' => auth()->id(), 'theme_id' => $snapshot->id,
            'action' => 'settings_edited', 'version' => $this->themeVersion + 1, 'created_at' => now()]);
    }

    protected function afterSave(): void
    {
        $service = app(ThemeLibraryService::class);
        $this->themeVersion = (int) $service->state()->version;
        $this->themeFingerprint = $service->fingerprint();
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Store design & settings';

    protected static ?string $navigationLabel = 'General Settings';

    public function form(Schema $schema): Schema
    {
        $sections = [
            Section::make('Site Information')
                ->schema([
                    TextInput::make('site_name')
                        ->label('Site Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('site_email')
                        ->label('Site Email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('site_phone')
                        ->label('Site Phone')
                        ->tel()
                        ->maxLength(255),
                    TextInput::make('site_address')
                        ->label('Site Address')
                        ->maxLength(255),
                    TextInput::make('site_country')
                        ->label('Country')
                        ->maxLength(255),
                    TextInput::make('site_currency')
                        ->label('Checkout currency')
                        ->formatStateUsing(fn () => StoreMoney::currency())
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('All catalogue prices and new charges use ZAR. Display labels do not convert prices. Other currencies need a configured conversion and settlement workflow.'),
                    TextInput::make('site_default_language')
                        ->label('Default Language')
                        ->maxLength(10)
                        ->default('en'),
                ])
                ->columns(2),

            Section::make('Branding')
                ->description('One identity for your storefront, admin portal, emails and new invoices. Use a transparent PNG or JPEG for compatibility with email clients.')
                ->schema([
                    FileUpload::make('site_logo_path')
                        ->label('Store Logo')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['1:1', '3:1', '4:1'])
                        ->disk('public')
                        ->directory('cms/branding')
                        ->visibility('public')
                        ->maxSize(5120)
                        ->helperText('Use PNG or JPEG up to 2 MB for emails and invoice snapshots. SVG/WebP remain storefront-only; documents use the store name as a fallback. Existing invoices retain their original branding.'),
                ]),

            Section::make('Storefront theme')
                ->description('Control the visual identity used across the public store. Changes apply immediately after saving.')
                ->schema([
                    ColorPicker::make('store_primary_color')
                        ->rules(['regex:/^#[0-9a-fA-F]{6}$/'])
                        ->label('Primary colour')
                        ->required()
                        ->default('#18181b')
                        ->helperText('Used for key buttons, highlights, and the store footer.'),
                    ColorPicker::make('store_background_color')
                        ->rules(['regex:/^#[0-9a-fA-F]{6}$/'])
                        ->label('Page background')
                        ->required()
                        ->default('#fafafa'),
                    Select::make('store_font_style')
                        ->label('Typography style')
                        ->options([
                            'modern' => 'Modern sans serif',
                            'editorial' => 'Editorial serif',
                            'classic' => 'Classic system font',
                        ])
                        ->required()
                        ->default('modern'),
                ])
                ->columns(3),

            Section::make('Search and social sharing')
                ->description('Set the default metadata used when a storefront page is found on Google or shared on social media. CMS pages can still use their own title and description.')
                ->schema([
                    Textarea::make('seo_description')
                        ->label('Default search description')
                        ->rows(3)
                        ->maxLength(160)
                        ->helperText('Aim for 120–160 characters.'),
                    TextInput::make('seo_keywords')
                        ->label('Search keywords')
                        ->maxLength(500)
                        ->helperText('Optional, comma-separated keywords.'),
                    FileUpload::make('seo_share_image_path')
                        ->label('Default social share image')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('cms/branding')
                        ->visibility('public')
                        ->maxSize(5120)
                        ->helperText('Recommended: 1200 × 630 pixels, maximum 5 MB.'),
                    FileUpload::make('favicon_path')
                        ->label('Browser icon (favicon)')
                        ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/x-icon'])
                        ->disk('public')
                        ->directory('cms/branding')
                        ->visibility('public')
                        ->maxSize(1024)
                        ->helperText('PNG, SVG, or ICO, maximum 1 MB.'),
                ])
                ->columns(2),

            Section::make('Store availability')
                ->description('Temporarily hide the public storefront while you prepare content, prices, or design changes. Admin access remains available.')
                ->schema([
                    Toggle::make('storefront_enabled')
                        ->label('Storefront is open to customers')
                        ->default(true),
                    Textarea::make('storefront_unavailable_message')
                        ->label('Customer message while the storefront is unavailable')
                        ->required()
                        ->rows(3)
                        ->maxLength(500),
                ]),

            Section::make('Homepage announcement')
                ->schema([
                    TextInput::make('announcement_text')
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make('Homepage hero')
                ->schema([
                    TextInput::make('hero_eyebrow')->maxLength(100),
                    TextInput::make('hero_title')->required()->maxLength(255),
                    Textarea::make('hero_description')->rows(3)->maxLength(1000),
                    FileUpload::make('hero_image_path')->image()->disk('public')->directory('cms/home')->visibility('public'),
                    TextInput::make('hero_primary_label')->required()->maxLength(80),
                    TextInput::make('hero_primary_url')
                        ->required()
                        ->rules(['regex:/^(?:\/|#|https?:\/\/).+$/i'])
                        ->helperText('Use a local path (/products), page anchor (#featured-categories), or full https:// URL.')
                        ->default('/products'),
                    TextInput::make('hero_secondary_label')->maxLength(80),
                    TextInput::make('hero_secondary_url')
                        ->rules(['regex:/^(?:\/|#|https?:\/\/).+$/i'])
                        ->helperText('Use a local path, page anchor, or full https:// URL.'),
                ])->columns(2),

            Section::make('Homepage sections')
                ->schema([
                    TextInput::make('featured_categories_heading')->required()->maxLength(120),
                    TextInput::make('featured_categories_subheading')->maxLength(255),
                    TextInput::make('homepage_category_limit')->label('Categories to show')->numeric()->minValue(1)->maxValue(12)->required()->default(4),
                    TextInput::make('products_heading')->required()->maxLength(120),
                    TextInput::make('products_link_label')->required()->maxLength(80),
                    TextInput::make('homepage_product_limit')->label('Products to show')->numeric()->minValue(1)->maxValue(24)->required()->default(6),
                ])->columns(2),

            Section::make('Homepage layout')
                ->description('Use the arrow controls to reorder the storefront. Disable a section to hide it without deleting its content.')
                ->schema([
                    Toggle::make('show_announcement')->label('Show announcement bar')->default(true),
                    Repeater::make('homepage_sections')
                        ->label('Storefront sections')
                        ->schema([
                            Select::make('section')
                                ->label('Section')
                                ->options([
                                    'hero' => 'Hero banner',
                                    'categories' => 'Featured categories',
                                    'products' => 'Latest products',
                                    'promotion' => 'Promotion banner',
                                ])
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->required(),
                            Toggle::make('enabled')->label('Visible')->default(true),
                        ])
                        ->default([
                            ['section' => 'hero', 'enabled' => true],
                            ['section' => 'categories', 'enabled' => true],
                            ['section' => 'products', 'enabled' => true],
                            ['section' => 'promotion', 'enabled' => true],
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderableWithButtons()
                        ->columns(2),
                ]),

            Section::make('Homepage promotion')
                ->schema([
                    TextInput::make('promo_eyebrow')->maxLength(100),
                    TextInput::make('promo_title')->maxLength(255),
                    Textarea::make('promo_description')->rows(3)->maxLength(1000),
                    TextInput::make('promo_button_label')->maxLength(80),
                    TextInput::make('promo_button_url')
                        ->rules(['regex:/^(?:\/|#|https?:\/\/).+$/i'])
                        ->helperText('Use a local path, page anchor, or full https:// URL.'),
                ])->columns(2),

            Section::make('Social Media Links')
                ->description('Add your social media profile URLs')
                ->schema([
                    TextInput::make('facebook_url')
                        ->label('Facebook URL')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('twitter_url')
                        ->label('Twitter URL')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('github_url')
                        ->label('GitHub URL')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('youtube_url')
                        ->label('YouTube URL')
                        ->url()
                        ->maxLength(255),
                ])
                ->columns(2),

            Section::make('Footer')
                ->description('To manage footer links, create a menu named “Footer” in the Menus area. It will automatically replace the default links once it contains items.')
                ->schema([
                    Textarea::make('footer_copyright')
                        ->label('Copyright Text')
                        ->required()
                        ->maxLength(500)
                        ->rows(2),
                ]),
        ];

        return $schema->columns(1)->components([
            Tabs::make('Store settings')->persistTabInQueryString('tab')->tabs([
                Tab::make('Store details')->key('contact', isInheritable: false)->icon('heroicon-o-building-storefront')->schema([$sections[0], $sections[4]]),
                Tab::make('Business & invoices')->key('business', isInheritable: false)->icon('heroicon-o-document-text')->schema([
                    Section::make('Invoice seller details')
                        ->description('Use your actual business details. These are captured on newly issued invoices; changes never rewrite existing invoice snapshots. Confirm your invoice and tax requirements with your accountant.')
                        ->schema([
                            TextInput::make('invoice_seller_name')->label('Legal seller name')->maxLength(255)
                                ->helperText('Leave blank to use the store name for new invoices.'),
                            TextInput::make('invoice_registration_number')->label('Business registration number')->maxLength(100),
                            Select::make('invoice_vat_status')->label('South African VAT registration status')->required()->live()
                                ->options(['unconfirmed' => 'Not yet confirmed', 'not_registered' => 'Not VAT registered', 'registered' => 'SARS-confirmed VAT vendor'])
                                ->helperText('Select vendor only after SARS confirms registration. Review tax rates, delivery tax and advertised VAT-inclusive prices with your accountant; this setting does not change prices or calculate VAT.'),
                            TextInput::make('invoice_tax_number')->label('VAT registration number')->maxLength(100)
                                ->required(fn (Get $get) => $get('invoice_vat_status') === 'registered')
                                ->rules(fn (Get $get) => $get('invoice_vat_status') === 'registered' ? ['regex:/^\d{10}$/'] : [])
                                ->helperText('For a registered vendor, enter the 10-digit SARS VAT number, not an income-tax number. Existing unconfirmed references are not treated as VAT registration.'),
                            Textarea::make('invoice_seller_address')->label('Invoice business address')->maxLength(1000)->rows(3)
                                ->helperText('Leave blank to use the store address for new invoices.'),
                            Textarea::make('invoice_footer_note')->label('Invoice footer note')->maxLength(500)->rows(2)
                                ->helperText('Optional thank-you or account enquiry instructions. Captured on new orders only; do not enter passwords or payment-card details.'),
                        ])->columns(2),
                ]),
                Tab::make('Brand & theme')->key('branding', isInheritable: false)->icon('heroicon-o-swatch')->schema([$sections[1], $sections[2]]),
                Tab::make('Homepage')->key('homepage', isInheritable: false)->icon('heroicon-o-home')->schema([$sections[5], $sections[6], $sections[7], $sections[8], $sections[9]]),
                Tab::make('Search & social')->key('search', isInheritable: false)->icon('heroicon-o-magnifying-glass')->schema([$sections[3], $sections[10]]),
                Tab::make('Footer')->key('footer', isInheritable: false)->icon('heroicon-o-bars-3-bottom-left')->schema([$sections[11]]),
            ])->columnSpanFull(),
        ]);
    }
}
