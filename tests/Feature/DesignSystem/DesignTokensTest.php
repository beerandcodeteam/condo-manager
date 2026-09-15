<?php

use App\Models\User;
use Database\Seeders\LookupSeeder;

test('theme uses the design font stack and base typography', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->not->toContain('Instrument Sans')
        ->toContain("--font-sans: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', Helvetica, sans-serif;")
        ->toContain('font-size: 13px;')
        ->toContain('line-height: 1.45;')
        ->toContain('-webkit-font-smoothing: antialiased;');
});

test('theme defines the design color, tag, dot and radius tokens', function (string $token) {
    expect(file_get_contents(resource_path('css/app.css')))->toContain($token);
})->with([
    '--color-canvas: #f5f5f7;',
    '--color-sidebar: #ebebef;',
    '--color-ink: #1d1d1f;',
    '--color-ink-secondary: #6e6e73;',
    '--color-ink-tertiary: #86868b;',
    '--color-ink-body: #3a3a3f;',
    '--color-accent: #5a5bd9;',
    '--color-accent-hover: #4547b8;',
    '--color-surface-soft: #f7f7fa;',
    '--color-surface-input: #fafafc;',
    '--color-line: rgba(0, 0, 0, 0.06);',
    '--color-tag-ia-bg: #eeeef8;',
    '--color-tag-ia-fg: #4547b8;',
    '--color-tag-ok-bg: #e8f8ee;',
    '--color-tag-ok-fg: #1f7a3e;',
    '--color-tag-warn-bg: #fff4e0;',
    '--color-tag-warn-fg: #a05a00;',
    '--color-tag-esc-bg: #fdecec;',
    '--color-tag-esc-fg: #b3261e;',
    '--color-tag-grey-bg: #f0f0f4;',
    '--color-tag-grey-fg: #3a3a3f;',
    '--color-dot-red: #e5484d;',
    '--color-dot-orange: #f5a623;',
    '--color-dot-green: #2fb35b;',
    '--color-dot-cyan: #30a4c9;',
    '--color-dot-grey: #8e8e93;',
    '--radius-card: 14px;',
    '--radius-control-sm: 8px;',
    '--radius-control: 9px;',
    '--radius-control-lg: 10px;',
    '--radius-drawer: 18px;',
    '--radius-pill: 99px;',
]);

test('welcome view is removed', function () {
    expect(view()->exists('welcome'))->toBeFalse();
});

test('home redirects authenticated users to the dashboard', function () {
    $this->seed(LookupSeeder::class);

    $this->actingAs(User::factory()->sindico()->create())
        ->get('/')
        ->assertRedirect('/dashboard');
});
