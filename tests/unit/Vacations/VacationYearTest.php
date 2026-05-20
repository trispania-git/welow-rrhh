<?php
/**
 * Unit tests del DTO VacationYear (round-trip array ↔ DTO).
 *
 * @package Welow\RRHH\Tests\Unit\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Unit\Vacations;

use PHPUnit\Framework\TestCase;
use Welow\RRHH\Modules\Vacations\Data\VacationYear;

/**
 * VacationYearTest.
 *
 * @covers \Welow\RRHH\Modules\Vacations\Data\VacationYear
 */
final class VacationYearTest extends TestCase {

	/**
	 * Round-trip serialización completa.
	 */
	public function test_to_array_and_from_array_round_trip(): void {
		$tz       = new \DateTimeZone( 'UTC' );
		$deadline = new \DateTimeImmutable( '2026-12-31', $tz );
		$carry    = new \DateTimeImmutable( '2027-03-31', $tz );

		$y = new VacationYear( 2026, true, $deadline, 25, true, 5, $carry );

		$arr     = $y->to_array();
		$revived = VacationYear::from_array( $arr );

		$this->assertNotNull( $revived );
		$this->assertSame( 2026, $revived->year );
		$this->assertTrue( $revived->is_open );
		$this->assertSame( 25, $revived->accrual_days );
		$this->assertSame( 5, $revived->carry_over_max_days );
		$this->assertSame( '2026-12-31', $revived->request_deadline->format( 'Y-m-d' ) );
		$this->assertSame( '2027-03-31', $revived->carry_over_deadline->format( 'Y-m-d' ) );
	}

	/**
	 * Año inválido → from_array devuelve null.
	 */
	public function test_from_array_returns_null_for_invalid_year(): void {
		$this->assertNull( VacationYear::from_array( array() ) );
		$this->assertNull( VacationYear::from_array( array( 'year' => 1800 ) ) );
		$this->assertNull( VacationYear::from_array( array( 'year' => 10000 ) ) );
	}

	/**
	 * Valores nulos opcionales se preservan.
	 */
	public function test_nullable_fields_preserved(): void {
		$y = new VacationYear( 2026, false, null, null, false, null, null );
		$r = VacationYear::from_array( $y->to_array() );
		$this->assertNotNull( $r );
		$this->assertFalse( $r->is_open );
		$this->assertNull( $r->request_deadline );
		$this->assertNull( $r->accrual_days );
		$this->assertNull( $r->carry_over_max_days );
		$this->assertNull( $r->carry_over_deadline );
	}
}
