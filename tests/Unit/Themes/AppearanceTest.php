<?php

declare(strict_types=1);

namespace Tests\Unit\Themes;

use App\Modules\Branding\Branding;
use App\Modules\Themes\Appearance;
use PHPUnit\Framework\TestCase;

final class AppearanceTest extends TestCase
{
    /** @return array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> */
    private function schema(): array
    {
        return Appearance::schema([
            ['key' => 'rounded', 'type' => 'toggle', 'label' => 'Rounded', 'default' => true],
            ['key' => 'stripe', 'type' => 'color', 'label' => 'Stripe', 'default' => '#F80'],
            ['key' => 'density', 'type' => 'select', 'label' => 'Density', 'options' => [['value' => 'cosy', 'label' => 'Cosy'], 'compact', 'Not A Slug!'], 'default' => 'cosy'],
            ['key' => 'heroImage', 'type' => 'image', 'label' => 'Hero'],
            ['key' => 'brand', 'type' => 'colour', 'label' => 'Brand'],
            ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['key' => 'bad key', 'type' => 'text', 'label' => 'dropped'],
            ['key' => 'script', 'type' => 'html', 'label' => 'dropped'],
            ['key' => 'empty', 'type' => 'select', 'label' => 'no options, dropped', 'options' => []],
        ]);
    }

    public function test_schema_drops_malformed_keys_unknown_types_and_empty_selects(): void
    {
        $schema = $this->schema();
        $this->assertSame(['rounded', 'stripe', 'density', 'heroImage', 'brand', 'name'], array_keys($schema));
        $this->assertSame('colour', $schema['stripe']['type'], 'color is accepted as colour');
        $this->assertSame('#ff8800', $schema['stripe']['default'], 'defaults are cleaned too');
        $this->assertSame(['cosy', 'compact'], array_column($schema['density']['options'], 'value'));
    }

    public function test_a_later_declaration_of_the_same_key_wins_so_a_child_overrides_its_parent(): void
    {
        $schema = Appearance::schema([
            ['key' => 'rounded', 'type' => 'toggle', 'label' => 'Parent', 'default' => true],
            ['key' => 'rounded', 'type' => 'toggle', 'label' => 'Child', 'default' => false],
        ]);
        $this->assertSame('Child', $schema['rounded']['label']);
        $this->assertFalse($schema['rounded']['default']);
    }

    public function test_clean_refuses_values_that_could_escape_css_or_run_script(): void
    {
        $schema = $this->schema();
        $this->assertNull(Appearance::clean($schema['stripe'], 'red;}body{display:none'));
        $this->assertNull(Appearance::clean($schema['heroImage'], 'javascript:alert(1)'));
        $this->assertNull(Appearance::clean($schema['heroImage'], 'https://x.test/a.png") ;x:url("'));
        $this->assertNull(Appearance::clean($schema['heroImage'], '//evil.test/a.png'));
        $this->assertNull(Appearance::clean($schema['density'], 'spacious'));
        $this->assertSame('/media/theme/abc.png', Appearance::clean($schema['heroImage'], '/media/theme/abc.png'));
        $this->assertSame('#aabbcc', Appearance::clean($schema['stripe'], '#ABC'));
    }

    public function test_values_layer_stored_over_defaults_and_ignore_what_no_longer_fits(): void
    {
        $values = Appearance::values($this->schema(), ['rounded' => false, 'density' => 'gone', 'unknown' => 'x', 'name' => '  Harbour  ']);
        $this->assertSame(['rounded' => false, 'stripe' => '#ff8800', 'density' => 'cosy', 'name' => 'Harbour'], $values);
    }

    public function test_branding_fields_are_replaced_and_everything_else_is_left_as_the_base(): void
    {
        $base = Branding::normalizeBranding(['name' => 'Grace', 'brand' => '#112233']);
        $merged = Appearance::mergeBranding($base, ['brand' => '#aa0000', 'stripe' => '#ff8800']);
        $this->assertSame('#aa0000', $merged['brand']);
        $this->assertSame('Grace', $merged['name']);
        $this->assertArrayNotHasKey('stripe', $merged);
    }

    public function test_css_and_classes(): void
    {
        $schema = $this->schema();
        $values = ['rounded' => true, 'stripe' => '#ff8800', 'density' => 'compact', 'heroImage' => '/media/theme/a.png', 'brand' => '#aa0000', 'name' => 'X'];
        $this->assertSame(':root{--theme-stripe:#ff8800;--theme-hero-image:url("/media/theme/a.png")}', Appearance::css($schema, $values));
        $this->assertSame('theme-rounded theme-density-compact', Appearance::classes($schema, $values));
        $this->assertSame('', Appearance::classes($schema, ['rounded' => false]));
        $this->assertSame('', Appearance::css($schema, []));
    }
}
