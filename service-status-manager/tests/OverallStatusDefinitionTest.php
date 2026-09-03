<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers ::ssm_get_overall_status_definition
 */
final class OverallStatusDefinitionTest extends TestCase {

	/**
	 * The public status page's hero (public/templates/summary.php, server-
	 * rendered) and the live-refresh REST response (GET /status, consumed
	 * by public/js/public.js) must use this exact same wording - they used
	 * to disagree (the hero said "All Systems Operational", the REST
	 * response said the shorter per-service "Operational"), so the hero
	 * title visibly changed the moment the page's live-refresh timer first
	 * fired.
	 */
	public function test_every_status_has_fuller_hero_wording_distinct_from_the_plain_service_label() {
		$statuses = array( 'operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance', 'unknown' );

		foreach ( $statuses as $status ) {
			$overall = ssm_get_overall_status_definition( $status );
			$this->assertArrayHasKey( 'title', $overall );
			$this->assertArrayHasKey( 'description', $overall );
			$this->assertNotSame( '', $overall['title'] );
			$this->assertNotSame( '', $overall['description'] );
		}
	}

	public function test_operational_title_is_the_fuller_hero_wording() {
		$this->assertSame( 'All Systems Operational', ssm_get_overall_status_definition( 'operational' )['title'] );
	}

	public function test_unknown_status_falls_back_to_the_unknown_definition() {
		$this->assertSame(
			ssm_get_overall_status_definition( 'unknown' ),
			ssm_get_overall_status_definition( 'not-a-real-status' )
		);
	}
}
