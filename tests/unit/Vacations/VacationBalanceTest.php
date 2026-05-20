<?php
/**
 * Unit tests del DTO VacationBalance.available().
 *
 * @package Welow\RRHH\Tests\Unit\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Unit\Vacations;

use PHPUnit\Framework\TestCase;
use Welow\RRHH\Modules\Vacations\Data\VacationBalance;

/**
 * VacationBalanceTest.
 *
 * @covers \Welow\RRHH\Modules\Vacations\Data\VacationBalance
 */
final class VacationBalanceTest extends TestCase {

	private \DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->tz = new \DateTimeZone( 'UTC' );
	}

	/**
	 * Sin carry-over: available = accrued - used.
	 */
	public function test_available_without_carry_over(): void {
		$b = new VacationBalance( 1, 100, 2026, 22.0, 5.0, 0.0, null );
		$this->assertSame( 17.0, $b->available( new \DateTimeImmutable( '2026-06-01', $this->tz ) ) );
	}

	/**
	 * Carry-over vigente: se suma.
	 */
	public function test_available_with_active_carry_over(): void {
		$b = new VacationBalance(
			1, 100, 2026, 22.0, 5.0, 3.0,
			new \DateTimeImmutable( '2026-06-30', $this->tz )
		);
		$today = new \DateTimeImmutable( '2026-06-01', $this->tz );
		$this->assertSame( 20.0, $b->available( $today ) ); // 22 + 3 - 5.
	}

	/**
	 * Carry-over expirado: NO se suma.
	 */
	public function test_available_with_expired_carry_over(): void {
		$b = new VacationBalance(
			1, 100, 2026, 22.0, 5.0, 3.0,
			new \DateTimeImmutable( '2026-03-31', $this->tz )
		);
		$today = new \DateTimeImmutable( '2026-06-01', $this->tz );
		$this->assertSame( 17.0, $b->available( $today ) ); // sólo 22 - 5.
	}

	/**
	 * Carry-over con expires_at exactamente hoy: vigente (inclusive).
	 */
	public function test_carry_over_inclusive_on_expiry_day(): void {
		$expiry = new \DateTimeImmutable( '2026-03-31', $this->tz );
		$b      = new VacationBalance( 1, 100, 2026, 22.0, 5.0, 3.0, $expiry );
		$this->assertSame( 20.0, $b->available( $expiry ) );
	}

	/**
	 * Carry-over sin fecha de caducidad: siempre vigente.
	 */
	public function test_null_expiry_means_always_active(): void {
		$b = new VacationBalance( 1, 100, 2026, 22.0, 5.0, 3.0, null );
		// Cualquier fecha futura.
		$this->assertSame( 20.0, $b->available( new \DateTimeImmutable( '2099-01-01', $this->tz ) ) );
	}
}
