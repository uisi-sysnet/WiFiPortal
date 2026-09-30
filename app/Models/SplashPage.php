<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The editable captive-portal page: HTML shell, Terms and Conditions,
 * and the rules for the registration form. One row is used.
 */
class SplashPage extends Model
{
    public const PLACEHOLDERS = [
        '[[form]]' => 'The login form and Terms pop-up (required)',
        '[[site_name]]' => 'Site name set below',
        '[[router_name]]' => 'Name of the router the user is on',
        '[[location]]' => 'Location of that router',
    ];

    public const AD_PLACEHOLDERS = [
        '[[connect]]' => 'The Connect button (required)',
        '[[site_name]]' => 'Site name',
        '[[router_name]]' => 'Name of the router the user is on',
        '[[location]]' => 'Location of that router',
    ];

    protected $fillable = [
        'ad_html', 'ad_button_label', 'ad_min_seconds',
        'site_name', 'html', 'terms',
        'citizen_label', 'citizen_hint', 'citizen_pattern',
        'blocked_words', 'success_url',
    ];

    public static function current(): self
    {
        return static::query()->oldest('id')->first() ?? static::create(static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'site_name' => 'Free Public WiFi',
            'html' => static::defaultHtml(),
            'terms' => file_get_contents(resource_path('portal/default-terms.md')),
            'citizen_label' => 'Citizen ID number',
            'citizen_hint' => 'As printed on your city ID card.',
            'citizen_pattern' => '[A-Za-z0-9-]{6,20}',
            'blocked_words' => implode("\n", [
                'test', 'testing', 'tester', 'asdf', 'qwerty', 'sample', 'admin', 'administrator',
                'user', 'guest', 'unknown', 'anonymous', 'anon', 'noname', 'no name', 'none', 'null',
                'dummy', 'fake', 'abc', 'abcd', 'xyz', 'hello', 'name', 'firstname', 'lastname',
                'surname', 'fullname', 'john doe', 'jane doe', 'wifi', 'free wifi',
            ]),
            'success_url' => null,
            'ad_html' => static::defaultAdHtml(),
            'ad_button_label' => 'Connect',
            'ad_min_seconds' => 0,
        ];
    }

    public static function defaultHtml(): string
    {
        return file_get_contents(resource_path('portal/default-template.html'));
    }

    public static function defaultAdHtml(): string
    {
        return file_get_contents(resource_path('portal/default-ad.html'));
    }

    /** @return string[] lowercase words/phrases, one per line */
    public function blockedWords(): array
    {
        return array_values(array_filter(array_map(
            fn ($w) => strtolower(trim(preg_replace('/\s+/', ' ', $w))),
            preg_split('/\R/', (string) $this->blocked_words)
        )));
    }

    /** Full-match regex for the citizen number, built from the admin's pattern. */
    public function citizenRegex(): string
    {
        return '~^(?:'.str_replace('~', '\~', $this->citizen_pattern).')$~u';
    }

    public function termsHtml(): string
    {
        return (string) Str::markdown(
            str_replace('[[site_name]]', $this->site_name, $this->terms),
            ['html_input' => 'strip', 'allow_unsafe_links' => false]
        );
    }

    public function termsHash(): string
    {
        return hash('sha256', $this->terms);
    }
}
