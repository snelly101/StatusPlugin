<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\AppearanceSettings;
use ServiceStatusManager\AppearanceRenderer;

/**
 * @covers \ServiceStatusManager\AppearanceSettings
 * @covers \ServiceStatusManager\AppearanceRenderer
 */
final class AppearanceSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ssm_test_options'] = array();
	}

	public function test_defaults_match_current_hardcoded_public_css_values() {
		$defaults = AppearanceSettings::defaults();

		$this->assertSame( '#2563eb', $defaults['primary_color'] );
		$this->assertSame( '#f7f9fc', $defaults['bg_color'] );
		$this->assertSame( '#ffffff', $defaults['surface_color'] );
		$this->assertSame( 920, $defaults['content_max_width'] );
		$this->assertSame( '', $defaults['custom_css'] );
	}

	public function test_get_returns_defaults_when_nothing_stored() {
		$this->assertSame( AppearanceSettings::defaults(), AppearanceSettings::get() );
	}

	public function test_sanitize_rejects_invalid_hex_colors_and_falls_back_to_default() {
		$sanitized = AppearanceSettings::sanitize( array( 'primary_color' => 'not-a-color' ) );
		$this->assertSame( '#2563eb', $sanitized['primary_color'] );
	}

	public function test_sanitize_accepts_valid_hex_colors() {
		$sanitized = AppearanceSettings::sanitize( array( 'primary_color' => '#ff0000' ) );
		$this->assertSame( '#ff0000', $sanitized['primary_color'] );
	}

	public function test_sanitize_clamps_content_max_width_to_allowed_range() {
		$this->assertSame( 480, AppearanceSettings::sanitize( array( 'content_max_width' => 100 ) )['content_max_width'] );
		$this->assertSame( 1600, AppearanceSettings::sanitize( array( 'content_max_width' => 9999 ) )['content_max_width'] );
		$this->assertSame( 1000, AppearanceSettings::sanitize( array( 'content_max_width' => 1000 ) )['content_max_width'] );
	}

	public function test_sanitize_rejects_unknown_radius_scale_and_shadow_preset() {
		$sanitized = AppearanceSettings::sanitize( array( 'card_radius_scale' => 'huge', 'card_shadow' => 'extreme' ) );
		$this->assertSame( 'standard', $sanitized['card_radius_scale'] );
		$this->assertSame( 'subtle', $sanitized['card_shadow'] );
	}

	public function test_sanitize_strips_tags_from_custom_css() {
		$sanitized = AppearanceSettings::sanitize( array( 'custom_css' => '.ssm-card{color:red}<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>', $sanitized['custom_css'] );
	}

	public function test_sanitize_drops_unknown_keys() {
		$sanitized = AppearanceSettings::sanitize( array( 'sms_credentials_encrypted' => 'smuggled', 'primary_color' => '#111111' ) );
		$this->assertArrayNotHasKey( 'sms_credentials_encrypted', $sanitized );
		$this->assertSame( '#111111', $sanitized['primary_color'] );
	}

	public function test_update_persists_and_reset_restores_defaults() {
		AppearanceSettings::update( array( 'primary_color' => '#123456' ) );
		$this->assertSame( '#123456', AppearanceSettings::get()['primary_color'] );

		AppearanceSettings::reset();
		$this->assertSame( AppearanceSettings::defaults(), AppearanceSettings::get() );
	}

	public function test_import_json_rejects_malformed_payload() {
		$result = AppearanceSettings::import_json( 'not json at all' );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_import_json_sanitizes_and_applies_valid_payload() {
		$result = AppearanceSettings::import_json( wp_json_encode( array( 'primary_color' => '#00ff00' ) ) );
		$this->assertTrue( $result );
		$this->assertSame( '#00ff00', AppearanceSettings::get()['primary_color'] );
	}

	public function test_renderer_emits_scoped_css_variable_block() {
		AppearanceSettings::update( array( 'primary_color' => '#abcdef' ) );
		$css = AppearanceRenderer::build_css();

		$this->assertStringStartsWith( '.ssm-status-page{', $css );
		$this->assertStringContainsString( '--ssm-primary:#abcdef;', $css );
	}

	public function test_renderer_appends_custom_css_after_the_variable_block() {
		AppearanceSettings::update( array( 'custom_css' => '.ssm-card{border-color:red}' ) );
		$css = AppearanceRenderer::build_css();

		$this->assertStringContainsString( '.ssm-card{border-color:red}', $css );
		$this->assertGreaterThan( strpos( $css, '}' ), strpos( $css, '.ssm-card{border-color:red}' ) );
	}

	public function test_renderer_maps_radius_scale_and_shadow_preset_to_all_four_and_three_variables() {
		AppearanceSettings::update( array( 'card_radius_scale' => 'square', 'card_shadow' => 'none' ) );
		$css = AppearanceRenderer::build_css();

		$this->assertStringContainsString( '--ssm-r-sm:0px;', $css );
		$this->assertStringContainsString( '--ssm-r-xl:0px;', $css );
		$this->assertStringContainsString( '--ssm-shadow-sm:none;', $css );
	}

	public function test_accent_color_default_matches_text_color_default_for_zero_visual_change() {
		// accent_color drives the header nav hover colour, which was
		// previously hardcoded to the same value as text_color. Defaults
		// must match exactly, or installing this feature would silently
		// recolour every existing status page's header on upgrade.
		$this->assertSame( AppearanceSettings::defaults()['text_color'], AppearanceSettings::defaults()['accent_color'] );
	}

	public function test_header_colors_default_to_empty_meaning_inherit() {
		$defaults = AppearanceSettings::defaults();
		$this->assertSame( '', $defaults['header_bg_color'] );
		$this->assertSame( '', $defaults['header_text_color'] );
	}

	public function test_sanitize_allows_blank_header_colors_but_rejects_invalid_ones() {
		$sanitized = AppearanceSettings::sanitize( array( 'header_bg_color' => '' ) );
		$this->assertSame( '', $sanitized['header_bg_color'] );

		$sanitized = AppearanceSettings::sanitize( array( 'header_bg_color' => 'not-a-color' ) );
		$this->assertSame( '', $sanitized['header_bg_color'] );

		$sanitized = AppearanceSettings::sanitize( array( 'header_bg_color' => '#123456' ) );
		$this->assertSame( '#123456', $sanitized['header_bg_color'] );
	}

	public function test_sanitize_rejects_unknown_background_style_and_gradient_direction() {
		$sanitized = AppearanceSettings::sanitize( array( 'background_style' => 'plaid', 'gradient_direction' => 'sideways' ) );
		$this->assertSame( 'solid', $sanitized['background_style'] );
		$this->assertSame( 'to-bottom', $sanitized['gradient_direction'] );
	}

	public function test_renderer_omits_header_vars_when_blank_so_css_fallback_applies() {
		$css = AppearanceRenderer::build_css();
		$this->assertStringNotContainsString( '--ssm-header-bg:', $css );
		$this->assertStringNotContainsString( '--ssm-header-text:', $css );
	}

	public function test_renderer_emits_header_vars_when_set() {
		AppearanceSettings::update( array( 'header_bg_color' => '#111111', 'header_text_color' => '#eeeeee' ) );
		$css = AppearanceRenderer::build_css();
		$this->assertStringContainsString( '--ssm-header-bg:#111111;', $css );
		$this->assertStringContainsString( '--ssm-header-text:#eeeeee;', $css );
	}

	public function test_renderer_header_position_reflects_sticky_toggle() {
		$css = AppearanceRenderer::build_css( array( 'header_sticky' => true ) );
		$this->assertStringContainsString( '--ssm-header-position:sticky;', $css );

		$css = AppearanceRenderer::build_css( array( 'header_sticky' => false ) );
		$this->assertStringContainsString( '--ssm-header-position:static;', $css );
	}

	public function test_renderer_default_background_fill_is_the_plain_bg_variable() {
		$css = AppearanceRenderer::build_css( array( 'background_style' => 'solid' ) );
		$this->assertStringContainsString( '--ssm-bg-final:var(--ssm-bg);', $css );
	}

	public function test_renderer_gradient_background_builds_linear_gradient_from_direction() {
		$css = AppearanceRenderer::build_css( array(
			'background_style'     => 'gradient',
			'gradient_start_color' => '#111111',
			'gradient_end_color'   => '#222222',
			'gradient_direction'   => 'to-right',
		) );
		$this->assertStringContainsString( '--ssm-bg-final:linear-gradient(to right, #111111, #222222);', $css );
	}

	public function test_renderer_radial_gradient_direction_builds_radial_gradient() {
		$css = AppearanceRenderer::build_css( array(
			'background_style'     => 'gradient',
			'gradient_start_color' => '#111111',
			'gradient_end_color'   => '#222222',
			'gradient_direction'   => 'radial',
		) );
		$this->assertStringContainsString( 'radial-gradient(circle at 30% 0%, #111111, #222222)', $css );
	}

	public function test_renderer_background_pattern_presets_map_to_expected_layers() {
		$css = AppearanceRenderer::build_css( array( 'background_pattern' => 'none' ) );
		$this->assertStringContainsString( '--ssm-bg-decoration:none;', $css );

		$css = AppearanceRenderer::build_css( array( 'background_pattern' => 'grid' ) );
		$this->assertStringContainsString( '--ssm-bg-decoration:linear-gradient(var(--ssm-bg-alt)', $css );
		$this->assertStringNotContainsString( 'radial-gradient(ellipse', $css );
	}

	public function test_renderer_omits_dark_mode_block_by_default() {
		$css = AppearanceRenderer::build_css( array( 'dark_mode_custom' => false ) );
		$this->assertStringNotContainsString( 'prefers-color-scheme', $css );
		$this->assertStringNotContainsString( 'data-ssm-theme="dark"', $css );
	}

	public function test_renderer_emits_dark_mode_block_when_enabled_with_both_selectors() {
		$css = AppearanceRenderer::build_css( array(
			'dark_mode_custom' => true,
			'dark_bg_color'    => '#010101',
			'dark_text_color'  => '#fefefe',
		) );

		$this->assertStringContainsString( '@media (prefers-color-scheme: dark){.ssm-status-page:not([data-ssm-theme="light"]){', $css );
		$this->assertStringContainsString( '.ssm-status-page[data-ssm-theme="dark"]{', $css );
		$this->assertStringContainsString( '--ssm-bg:#010101;', $css );
		$this->assertStringContainsString( '--ssm-text:#fefefe;', $css );
	}
}
