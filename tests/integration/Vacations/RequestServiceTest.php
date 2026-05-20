<?php
/**
 * Integration tests para RequestService.
 *
 * @package Welow\RRHH\Tests\Integration\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Integration\Vacations;

use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\VacationYear;
use Welow\RRHH\Tests\Integration\IntegrationTestCase;

/**
 * RequestServiceTest.
 *
 * @covers \Welow\RRHH\Modules\Vacations\Service\RequestService
 */
final class RequestServiceTest extends IntegrationTestCase {

	/**
	 * Helper: deja el año 2026 abierto sin restricciones.
	 */
	private function open_year_2026(): void {
		$this->container->get( 'vacations.years_config' )->save(
			new VacationYear( 2026, true, null, null, true, 5, new \DateTimeImmutable( '2027-03-31', wp_timezone() ) )
		);
	}

	/**
	 * Año no abierto → rechazo welow_vacation_year_closed.
	 */
	public function test_rejects_when_year_closed(): void {
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );

		$res = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2030-06-01', wp_timezone() ),
			new \DateTimeImmutable( '2030-06-05', wp_timezone() )
		);
		$this->assertWPError( $res );
		$this->assertSame( 'welow_vacation_year_closed', $res->get_error_code() );
	}

	/**
	 * Cross-year → rechazo welow_vacation_cross_year.
	 */
	public function test_rejects_cross_year(): void {
		$this->open_year_2026();
		// Necesitamos también abrir 2027 para que la validación de año abierto no se dispare antes.
		$this->container->get( 'vacations.years_config' )->save(
			new VacationYear( 2027, true, null, null, false, null, null )
		);
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );
		$res     = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-12-28', wp_timezone() ),
			new \DateTimeImmutable( '2027-01-05', wp_timezone() )
		);
		$this->assertWPError( $res );
		$this->assertSame( 'welow_vacation_cross_year', $res->get_error_code() );
	}

	/**
	 * Solapamiento → rechazo welow_vacation_overlap.
	 */
	public function test_rejects_overlap(): void {
		$this->open_year_2026();
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );

		$first = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-07-13', wp_timezone() ),
			new \DateTimeImmutable( '2026-07-17', wp_timezone() )
		);
		$this->assertNotWPError( $first );

		$second = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-07-15', wp_timezone() ),
			new \DateTimeImmutable( '2026-07-20', wp_timezone() )
		);
		$this->assertWPError( $second );
		$this->assertSame( 'welow_vacation_overlap', $second->get_error_code() );
	}

	/**
	 * Sin saldo → rechazo welow_vacation_no_balance.
	 */
	public function test_rejects_no_balance(): void {
		$this->open_year_2026();
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );

		// Consume 18 días útiles (de 22): primero un bloque y luego intenta otro grande.
		$service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-07-13', wp_timezone() ),
			new \DateTimeImmutable( '2026-07-31', wp_timezone() ) // 15 working days.
		);

		$too_big = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-09-01', wp_timezone() ),
			new \DateTimeImmutable( '2026-09-25', wp_timezone() ) // 18+ working days.
		);
		$this->assertWPError( $too_big );
		$this->assertSame( 'welow_vacation_no_balance', $too_big->get_error_code() );
	}

	/**
	 * Creación válida + cancel.
	 */
	public function test_create_and_cancel(): void {
		$this->open_year_2026();
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );

		$req = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-08-10', wp_timezone() ),
			new \DateTimeImmutable( '2026-08-14', wp_timezone() ),
			array( 'reason' => 'Verano' )
		);
		$this->assertNotWPError( $req );
		$this->assertSame( 5.0, $req->requested_days );
		$this->assertSame( RequestStatus::PENDING, $req->status );

		$cancelled = $service->cancel_request( $req->id, $user_id, 'Cambio de planes' );
		$this->assertNotWPError( $cancelled );
		$this->assertSame( RequestStatus::CANCELLED, $cancelled->status );
	}

	/**
	 * El filtro can_request puede vetar.
	 */
	public function test_can_request_filter_blocks(): void {
		$this->open_year_2026();
		$user_id = $this->make_admin();
		$service = $this->container->get( 'vacations.request_service' );

		add_filter(
			'welow_rrhh/vacations/can_request',
			static fn(): \WP_Error => new \WP_Error( 'mi_plugin_veto', 'No se puede.' )
		);

		$res = $service->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-08-10', wp_timezone() ),
			new \DateTimeImmutable( '2026-08-14', wp_timezone() )
		);
		$this->assertWPError( $res );
		$this->assertSame( 'mi_plugin_veto', $res->get_error_code() );

		remove_all_filters( 'welow_rrhh/vacations/can_request' );
	}

	/**
	 * Asserts que el valor es WP_Error.
	 *
	 * @param mixed $val Valor.
	 */
	protected function assertWPError( $val ): void {
		$this->assertInstanceOf( \WP_Error::class, $val, 'Se esperaba WP_Error.' );
	}

	/**
	 * Asserts que el valor NO es WP_Error.
	 *
	 * @param mixed $val Valor.
	 */
	protected function assertNotWPError( $val ): void {
		if ( $val instanceof \WP_Error ) {
			$this->fail( 'WP_Error inesperado: ' . $val->get_error_code() . ' — ' . $val->get_error_message() );
		}
		$this->assertNotInstanceOf( \WP_Error::class, $val );
	}
}
