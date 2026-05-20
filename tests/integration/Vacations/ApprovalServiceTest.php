<?php
/**
 * Integration tests para ApprovalService.
 *
 * @package Welow\RRHH\Tests\Integration\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Integration\Vacations;

use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\VacationYear;
use Welow\RRHH\Tests\Integration\IntegrationTestCase;

/**
 * ApprovalServiceTest.
 *
 * @covers \Welow\RRHH\Modules\Vacations\Service\ApprovalService
 */
final class ApprovalServiceTest extends IntegrationTestCase {

	/**
	 * Aprobar una solicitud actualiza saldo materializado.
	 */
	public function test_approve_recalculates_balance(): void {
		$this->container->get( 'vacations.years_config' )->save(
			new VacationYear( 2026, true, null, null, true, 5, new \DateTimeImmutable( '2027-03-31', wp_timezone() ) )
		);
		$user_id = $this->make_admin();
		wp_set_current_user( $user_id );

		$req = $this->container->get( 'vacations.request_service' )->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-09-21', wp_timezone() ),
			new \DateTimeImmutable( '2026-09-25', wp_timezone() )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $req );

		$approve = $this->container->get( 'vacations.approval_service' );
		$decided = $approve->approve( $req->id, $user_id, 'OK' );
		$this->assertNotInstanceOf( \WP_Error::class, $decided );
		$this->assertSame( RequestStatus::APPROVED, $decided->status );

		$bal = $this->container->get( 'vacations.balance_repository' )->find_for_user_year( $user_id, 2026 );
		$this->assertNotNull( $bal );
		$this->assertSame( 5.0, $bal->used );
	}

	/**
	 * No se puede decidir una solicitud que no esté PENDING.
	 */
	public function test_reject_then_approve_fails_with_not_pending(): void {
		$this->container->get( 'vacations.years_config' )->save(
			new VacationYear( 2026, true, null, null, false, null, null )
		);
		$user_id = $this->make_admin();
		wp_set_current_user( $user_id );

		$req = $this->container->get( 'vacations.request_service' )->create_request(
			$user_id,
			new \DateTimeImmutable( '2026-10-12', wp_timezone() ),
			new \DateTimeImmutable( '2026-10-16', wp_timezone() )
		);
		$approve = $this->container->get( 'vacations.approval_service' );

		$approve->reject( $req->id, $user_id, 'Pico de carga' );
		$err = $approve->approve( $req->id, $user_id, '' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'welow_vacation_not_pending', $err->get_error_code() );
	}

	/**
	 * Usuario sin caps → not_authorized.
	 */
	public function test_unauthorized_user_cannot_decide(): void {
		$this->container->get( 'vacations.years_config' )->save(
			new VacationYear( 2026, true, null, null, false, null, null )
		);
		$requester  = $this->make_admin();
		$bystander  = $this->make_user(); // welow_employee, sin APPROVE_TEAM/MANAGE_ANY.

		wp_set_current_user( $requester );
		$req = $this->container->get( 'vacations.request_service' )->create_request(
			$requester,
			new \DateTimeImmutable( '2026-11-09', wp_timezone() ),
			new \DateTimeImmutable( '2026-11-13', wp_timezone() )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $req );

		wp_set_current_user( $bystander );
		$err = $this->container->get( 'vacations.approval_service' )->approve( $req->id, $bystander, '' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'welow_vacation_not_authorized', $err->get_error_code() );
	}
}
