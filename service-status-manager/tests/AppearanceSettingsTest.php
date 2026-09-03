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
}
