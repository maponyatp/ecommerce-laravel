<?php

namespace App\Filament\App\Pages;

use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.app.pages.settings';

    protected static ?int $navigationSort = 12;

    public string $site_name = '';
    public string $site_email = '';
    public ?string $site_phone = '';
    public ?string $site_address = '';
    public ?string $site_country = '';
    public string $site_currency = '';
    public string $site_default_language = 'en';
    public ?string $facebook_url = '';
    public ?string $twitter_url = '';
    public ?string $youtube_url = '';
    public string $footer_copyright = '';

    public function mount(GeneralSettings $settings): void
    {
        $this->site_name = $settings->site_name;
        $this->site_email = $settings->site_email;
        $this->site_phone = $settings->site_phone;
        $this->site_address = $settings->site_address;
        $this->site_country = $settings->site_country;
        $this->site_currency = $settings->site_currency;
        $this->site_default_language = $settings->site_default_language;
        $this->facebook_url = $settings->facebook_url;
        $this->twitter_url = $settings->twitter_url;
        $this->youtube_url = $settings->youtube_url;
        $this->footer_copyright = $settings->footer_copyright;
    }

    public function save(GeneralSettings $settings): void
    {
        $settings->site_name = $this->site_name;
        $settings->site_email = $this->site_email;
        $settings->site_phone = $this->site_phone;
        $settings->site_address = $this->site_address;
        $settings->site_country = $this->site_country;
        $settings->site_currency = $this->site_currency;
        $settings->site_default_language = $this->site_default_language;
        $settings->facebook_url = $this->facebook_url;
        $settings->twitter_url = $this->twitter_url;
        $settings->youtube_url = $this->youtube_url;
        $settings->footer_copyright = $this->footer_copyright;

        $settings->save();

        Notification::make()
            ->title('Settings Saved')
            ->body('Store settings have been updated successfully.')
            ->success()
            ->send();
    }
}
