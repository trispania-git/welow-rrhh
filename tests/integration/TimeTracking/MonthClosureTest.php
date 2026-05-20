<?php
/**
 * Integration tests para MonthClosure (cierre / reapertura de meses).
 *
 * @package Welow\RRHH\Tests\Integration\TimeTracking
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Integration\TimeTracking;

use Welow\RRHH\Tests\Integration\IntegrationTestCase;

/**
 * MonthClosureTest.
 *
 * @covers \Welow\RRHH\Modules\TimeTracking\Closure\MonthClosure
 */
final class MonthClosureTest extends IntegrationTestCase {

	/**
	 * Cerrar un mes lo marca y is_closed lo refleja.
	 */
	public function test_close_marks_period_closed(): void {
		$closure = $this->container->get( 'time_tracking.month_closure' );
		$user_id = $this->make_admin();

		$res = $closure->close( 2026, 4, $user_id );
		$this->assertTrue( true === $res, 'close() debería devolver true.' );

		$this->assertTrue( $closure->is_closed( new \DateTimeImmutable( '2026-04-15', wp_timezone() ) ) );
		$this->assertFalse( $closure->is_closed( new \DateTimeImmutable( '2026-05-15', wp_timezone() ) ) );
	}

	/**
	 * Cerrar dos veces el mismo mes → error.
	 */
	public function test_double_close_returns_error(): void {
		$closure = $this->container->get( 'time_tracking.month_closure' );
		$user_id = $this->make_admin();
		$closure->close( 2026, 4, $user_id );

		$err = $closure->close( 2026, 4, $user_id );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'welow_month_already_closed', $err->get_error_code() );
	}

	/**
	 * Reabrir sin motivo → error.
	 */
	public function test_reopen_without_reason_fails(): void {
		$closure = $this->container->get( 'time_tracking.month_closure' );
		$user_id = $this->make_admin();
		$closure->close( 2026, 4, $user_id );

		$err = $closure->reopen( 2026, 4, $user_id, '' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'welow_month_reopen_reason', $err->get_error_code() );
	}

	/**
	 * Reabrir con motivo elimina el periodo de la lista.
	 */
	public function test_reopen_with_reason_removes_period(): void {
		$closure = $this->container->get( 'time_tracking.month_closure' );
		$user_id = $this->make_admin();
		$closure->close( 2026, 4, $user_id );

		$res = $closure->reopen( 2026, 4, $user_id, 'Corrección administrativa autorizada por dirección' );
		$this->assertTrue( true === $res );
		$this->assertFalse( $closure->is_closed( new \DateTimeImmutable( '2026-04-15', wp_timezone() ) ) );
		$this->assertNotContains( '2026-04', $closure->closed_months() );
	}

	/**
	 * Año/mes fuera de rango → error.
	 */
	public function test_invalid_period_rejected(): void {
		$closure = $this->container->get( 'time_tracking.month_closure' );
		$user_id = $this->make_admin();

		$err = $closure->close( 2026, 13, $user_id );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'welow_month_closure_invalid', $err->get_error_code() );

		$err = $closure->close( 1800, 6, $user_id );
		$this->assertInstanceOf( \WP_Error::class, $err );
	}
}
