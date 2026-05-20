<?php
/**
 * Integration tests para BalanceCalculator.
 *
 * @package Welow\RRHH\Tests\Integration\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Tests\Integration\Vacations;

use Welow\RRHH\Modules\Vacations\Data\VacationYear;
use Welow\RRHH\Tests\Integration\IntegrationTestCase;

/**
 * BalanceCalculatorTest.
 *
 * @covers \Welow\RRHH\Modules\Vacations\Service\BalanceCalculator
 */
final class BalanceCalculatorTest extends IntegrationTestCase {

	/**
	 * 5 días laborables L-V sin festivos.
	 */
	public function test_working_days_full_week(): void {
		$calc  = $this->container->get( 'vacations.balance_calculator' );
		$start = new \DateTimeImmutable( '2026-08-10', wp_timezone() ); // lunes.
		$end   = new \DateTimeImmutable( '2026-08-14', wp_timezone() ); // viernes.
		$this->assertSame( 5.0, $calc->compute_requested_days( $start, $end ) );
	}

	/**
	 * Cruzando un fin de semana excluye sábado y domingo.
	 */
	public function test_working_days_skip_weekend(): void {
		$calc  = $this->container->get( 'vacations.balance_calculator' );
		$start = new \DateTimeImmutable( '2026-08-10', wp_timezone() );
		$end   = new \DateTimeImmutable( '2026-08-17', wp_timezone() );
		$this->assertSame( 6.0, $calc->compute_requested_days( $start, $end ) );
	}

	/**
	 * end_half_day resta 0.5 si el último día es laborable.
	 */
	public function test_end_half_day_subtracts_half(): void {
		$calc  = $this->container->get( 'vacations.balance_calculator' );
		$start = new \DateTimeImmutable( '2026-08-10', wp_timezone() );
		$end   = new \DateTimeImmutable( '2026-08-14', wp_timezone() );
		$this->assertSame( 4.5, $calc->compute_requested_days( $start, $end, false, true ) );
	}

	/**
	 * Un solo día con medio día → 0.5.
	 */
	public function test_single_day_half(): void {
		$calc = $this->container->get( 'vacations.balance_calculator' );
		$day  = new \DateTimeImmutable( '2026-08-10', wp_timezone() );
		$this->assertSame( 0.5, $calc->compute_requested_days( $day, $day, true, false ) );
		$this->assertSame( 0.5, $calc->compute_requested_days( $day, $day, false, true ) );
	}

	/**
	 * Un solo día con ambos medio día → 0 (combinación inválida).
	 */
	public function test_single_day_both_halves_is_zero(): void {
		$calc = $this->container->get( 'vacations.balance_calculator' );
		$day  = new \DateTimeImmutable( '2026-08-10', wp_timezone() );
		$this->assertSame( 0.0, $calc->compute_requested_days( $day, $day, true, true ) );
	}

	/**
	 * Devengo por defecto = default_days_per_year (22).
	 */
	public function test_accrual_default_is_company_setting(): void {
		$user_id = $this->make_admin();
		$calc    = $this->container->get( 'vacations.balance_calculator' );
		$this->assertSame( 22.0, $calc->compute_accrual( $user_id, 2026 ) );
	}

	/**
	 * Override por año: VacationYear.accrual_days tiene prioridad sobre default.
	 */
	public function test_accrual_year_override(): void {
		$user_id = $this->make_admin();
		$years   = $this->container->get( 'vacations.years_config' );
		$years->save( new VacationYear( 2026, true, null, 30, false, null, null ) );
		$calc = $this->container->get( 'vacations.balance_calculator' );
		$this->assertSame( 30.0, $calc->compute_accrual( $user_id, 2026 ) );
	}

	/**
	 * Carry-over deshabilitado para el año actual → 0 arrastrado.
	 */
	public function test_carry_over_disabled_returns_zero(): void {
		$user_id = $this->make_admin();
		$years   = $this->container->get( 'vacations.years_config' );
		$years->save( new VacationYear( 2026, true, null, null, false, null, null ) );
		$calc = $this->container->get( 'vacations.balance_calculator' );
		$out  = $calc->compute_carry_over_from_prev( $user_id, 2026 );
		$this->assertSame( 0.0, $out['days'] );
	}
}
