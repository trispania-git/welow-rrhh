<?php
/**
 * Unit tests del RateLimiter.
 *
 * Es un unit test "ligeramente integración" porque depende de
 * set_transient/get_transient de WP. Lo mantenemos en /unit porque es muy
 * pequeño y los stubs de transients son triviales.
 *
 * @package Welow\RRHH\Tests\Unit\Support
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Welow\RRHH\Support\RateLimiter;

/**
 * RateLimiterTest.
 *
 * @covers \Welow\RRHH\Support\RateLimiter
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Bajo el límite consume devuelve true; tras agotar, false.
	 */
	public function test_consume_under_then_over_limit(): void {
		$rl = new RateLimiter( 'unit_test_' . wp_rand( 10000, 99999 ), 3, 60 );
		$user_id = 100;
		$rl->reset( $user_id );
		$this->assertTrue( $rl->consume( $user_id ) );
		$this->assertTrue( $rl->consume( $user_id ) );
		$this->assertTrue( $rl->consume( $user_id ) );
		$this->assertFalse( $rl->consume( $user_id ), 'La cuarta debería bloquearse (max=3).' );
	}

	/**
	 * reset() restablece el contador.
	 */
	public function test_reset_clears_counter(): void {
		$rl = new RateLimiter( 'unit_test_reset_' . wp_rand( 10000, 99999 ), 1, 60 );
		$user_id = 101;
		$rl->reset( $user_id );
		$this->assertTrue( $rl->consume( $user_id ) );
		$this->assertFalse( $rl->consume( $user_id ) );
		$rl->reset( $user_id );
		$this->assertTrue( $rl->consume( $user_id ) );
	}
}
