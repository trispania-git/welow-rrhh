<?php
/**
 * Cálculo de saldo y días laborables para Vacaciones (§8.4).
 *
 * Responsabilidades:
 *   - Calcular los días laborables entre dos fechas considerando fines
 *     de semana, festivos del scope configurado y banderas de medio día.
 *   - Calcular el devengo anual de un empleado según la política
 *     (full_year, prorated, override en ficha).
 *   - Reconciliar (recalculate) el saldo materializado a partir de las
 *     solicitudes APPROVED y del carry-over vigente del año anterior.
 *   - Exponer el saldo disponible considerando pendientes.
 *
 * @package Welow\RRHH\Modules\Vacations\Service
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Service;

use Welow\RRHH\Employees\EmployeeRepository;
use Welow\RRHH\Holidays\HolidayRepository;
use Welow\RRHH\Modules\Vacations\Config\VacationYearsConfig;
use Welow\RRHH\Modules\Vacations\Data\RequestStatus;
use Welow\RRHH\Modules\Vacations\Data\VacationBalance;
use Welow\RRHH\Modules\Vacations\Repository\VacationBalanceRepository;
use Welow\RRHH\Modules\Vacations\Repository\VacationRequestRepository;
use Welow\RRHH\Settings\CompanySettings;

defined( 'ABSPATH' ) || exit;

/**
 * BalanceCalculator.
 */
final class BalanceCalculator {

	/**
	 * Sábado (DateTime::format('N')).
	 */
	private const SATURDAY = 6;

	/**
	 * Domingo.
	 */
	private const SUNDAY = 7;

	/**
	 * Repo solicitudes.
	 *
	 * @var VacationRequestRepository
	 */
	private VacationRequestRepository $requests;

	/**
	 * Repo saldos.
	 *
	 * @var VacationBalanceRepository
	 */
	private VacationBalanceRepository $balances;

	/**
	 * Repo empleados.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Repo festivos.
	 *
	 * @var HolidayRepository
	 */
	private HolidayRepository $holidays;

	/**
	 * Config años.
	 *
	 * @var VacationYearsConfig
	 */
	private VacationYearsConfig $years;

	/**
	 * Settings empresa.
	 *
	 * @var CompanySettings
	 */
	private CompanySettings $settings;

	/**
	 * Constructor.
	 *
	 * @param VacationRequestRepository $requests  Repo solicitudes.
	 * @param VacationBalanceRepository $balances  Repo saldos.
	 * @param EmployeeRepository        $employees Repo empleados.
	 * @param HolidayRepository         $holidays  Repo festivos.
	 * @param VacationYearsConfig       $years     Config años.
	 * @param CompanySettings           $settings  Settings.
	 */
	public function __construct(
		VacationRequestRepository $requests,
		VacationBalanceRepository $balances,
		EmployeeRepository $employees,
		HolidayRepository $holidays,
		VacationYearsConfig $years,
		CompanySettings $settings
	) {
		$this->requests  = $requests;
		$this->balances  = $balances;
		$this->employees = $employees;
		$this->holidays  = $holidays;
		$this->years     = $years;
		$this->settings  = $settings;
	}

	/**
	 * Calcula días contables (decimal) entre dos fechas inclusive,
	 * aplicando la política `computation` y los flags de medio día.
	 *
	 * Si `computation` = working_days: descuenta sábados, domingos y
	 * festivos del scope configurado. Si = natural_days: cuenta todos
	 * los días naturales.
	 *
	 * @param \DateTimeImmutable $start         Inicio (inclusive).
	 * @param \DateTimeImmutable $end           Fin (inclusive).
	 * @param bool               $start_half    Si true, el primer día empieza por la tarde (-0.5).
	 * @param bool               $end_half      Si true, el último día termina por la mañana (-0.5).
	 * @return float Días contables (>= 0).
	 */
	public function compute_requested_days(
		\DateTimeImmutable $start,
		\DateTimeImmutable $end,
		bool $start_half = false,
		bool $end_half = false
	): float {
		if ( $end < $start ) {
			return 0.0;
		}

		$vacations   = $this->settings->section( CompanySettings::SECTION_VACATIONS );
		$computation = (string) ( $vacations['computation'] ?? 'working_days' );

		if ( 'natural_days' === $computation ) {
			$days = (float) ( $start->diff( $end )->days + 1 );
		} else {
			$days = (float) $this->count_working_days( $start, $end );
		}

		// Caso especial: un único día.
		if ( $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' ) ) {
			if ( $start_half && $end_half ) {
				return 0.0; // Combinación inválida en un solo día.
			}
			if ( $start_half || $end_half ) {
				return $days > 0 ? 0.5 : 0.0;
			}
			return $days;
		}

		// Rango multi-día: aplica los descuentos sólo si el día extremo es laborable.
		if ( $start_half && $this->is_working_day( $start ) ) {
			$days -= 0.5;
		}
		if ( $end_half && $this->is_working_day( $end ) ) {
			$days -= 0.5;
		}

		return max( 0.0, $days );
	}

	/**
	 * Indica si la fecha es día laborable (no fin de semana ni festivo).
	 *
	 * @param \DateTimeImmutable $day Día.
	 * @return bool
	 */
	public function is_working_day( \DateTimeImmutable $day ): bool {
		$dow = (int) $day->format( 'N' );
		if ( self::SATURDAY === $dow || self::SUNDAY === $dow ) {
			return false;
		}
		$key = $day->format( 'Y-m-d' );
		return ! in_array( $key, $this->holidays_for_year( (int) $day->format( 'Y' ) ), true );
	}

	/**
	 * Calcula el devengo (días anuales) para un (user, year) según la política.
	 *
	 * Precedencia:
	 *   1) employee.vacation_days_override si está informado.
	 *   2) VacationYear.accrual_days si está definido para el año.
	 *   3) default_days_per_year de la sección Vacaciones de los settings,
	 *      prorrateado o no según `accrual_policy`.
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return float Días anuales acreditados (>= 0).
	 */
	public function compute_accrual( int $user_id, int $year ): float {
		$employee = $this->employees->find_by_user_id( $user_id );
		if ( null !== $employee && null !== $employee->vacation_days_override ) {
			return (float) max( 0, $employee->vacation_days_override );
		}

		$year_cfg = $this->years->get( $year );
		$base     = null !== $year_cfg && null !== $year_cfg->accrual_days
			? (float) $year_cfg->accrual_days
			: (float) ( $this->settings->section( CompanySettings::SECTION_VACATIONS )['default_days_per_year'] ?? 22 );

		$policy = (string) ( $this->settings->section( CompanySettings::SECTION_VACATIONS )['accrual_policy'] ?? 'full_year' );

		if ( 'prorated' === $policy && null !== $employee ) {
			$base = $this->prorate( $base, $employee->hire_date, $employee->termination_date, $year );
		}

		return max( 0.0, round( $base, 1 ) );
	}

	/**
	 * Calcula la cantidad arrastrada desde el año anterior aplicando las
	 * reglas definidas en `VacationYear` del año actual.
	 *
	 * Si carry_over_enabled está desactivado para el año actual, devuelve 0.
	 * Si el saldo del año anterior tiene available() > carry_over_max_days,
	 * se trunca al máximo.
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año actual.
	 * @return array{days: float, expires_at: \DateTimeImmutable|null}
	 */
	public function compute_carry_over_from_prev( int $user_id, int $year ): array {
		$cfg = $this->years->get( $year );
		if ( null === $cfg || ! $cfg->carry_over_enabled ) {
			return array(
				'days'       => 0.0,
				'expires_at' => null,
			);
		}

		$prev = $this->balances->find_for_user_year( $user_id, $year - 1 );
		if ( null === $prev ) {
			return array(
				'days'       => 0.0,
				'expires_at' => $cfg->carry_over_deadline,
			);
		}

		// Saldo disponible al final del año previo (sin pendientes).
		$end_of_prev = ( new \DateTimeImmutable( sprintf( '%04d-12-31', $year - 1 ), wp_timezone() ) )->setTime( 23, 59, 59 );
		$available   = max( 0.0, $prev->available( $end_of_prev ) );

		if ( null !== $cfg->carry_over_max_days && $available > $cfg->carry_over_max_days ) {
			$available = (float) $cfg->carry_over_max_days;
		}

		return array(
			'days'       => round( $available, 1 ),
			'expires_at' => $cfg->carry_over_deadline,
		);
	}

	/**
	 * Reconstruye el saldo materializado de un (user, year) y persiste.
	 *
	 * `used` se calcula sumando requested_days de las solicitudes APPROVED
	 * (sólo tipo VACATION, según RequestRepository::sum_days_by_status).
	 * No considera las PENDING — `available_considering_pending()` las
	 * descuenta dinámicamente en las validaciones del RequestService.
	 *
	 * @param int $user_id Usuario.
	 * @param int $year    Año.
	 * @return VacationBalance Saldo reconciliado y persistido.
	 */
	public function recalculate( int $user_id, int $year ): VacationBalance {
		$accrued = $this->compute_accrual( $user_id, $year );
		$sums    = $this->requests->sum_days_by_status( $user_id, $year );
		$used    = (float) ( $sums[ RequestStatus::APPROVED->value ] ?? 0 );

		$carry = $this->compute_carry_over_from_prev( $user_id, $year );

		$bal = new VacationBalance(
			null,
			$user_id,
			$year,
			$accrued,
			$used,
			$carry['days'],
			$carry['expires_at']
		);

		$this->balances->upsert( $bal );
		return $bal;
	}

	/**
	 * Saldo disponible considerando solicitudes PENDING como ya reservadas.
	 *
	 * Si excluimos un id concreto (edición), no se descuenta esa solicitud.
	 *
	 * @param int      $user_id    Usuario.
	 * @param int      $year       Año.
	 * @param int|null $exclude_id Id a excluir del cómputo de pendientes.
	 * @return float
	 */
	public function available_considering_pending( int $user_id, int $year, ?int $exclude_id = null ): float {
		$bal = $this->balances->find_for_user_year( $user_id, $year );
		if ( null === $bal ) {
			$bal = $this->recalculate( $user_id, $year );
		}

		$base    = $bal->available();
		$sums    = $this->requests->sum_days_by_status( $user_id, $year );
		$pending = (float) ( $sums[ RequestStatus::PENDING->value ] ?? 0 );

		if ( null !== $exclude_id ) {
			$excluded = $this->requests->find_by_id( $exclude_id );
			if ( null !== $excluded && RequestStatus::PENDING === $excluded->status ) {
				$pending = max( 0.0, $pending - $excluded->requested_days );
			}
		}

		return $base - $pending;
	}

	/**
	 * Cuenta días laborables (sin half-day) entre start y end inclusive.
	 *
	 * @param \DateTimeImmutable $start Inicio.
	 * @param \DateTimeImmutable $end   Fin.
	 * @return int
	 */
	private function count_working_days( \DateTimeImmutable $start, \DateTimeImmutable $end ): int {
		$count = 0;
		$day   = $start;
		while ( $day <= $end ) {
			if ( $this->is_working_day( $day ) ) {
				++$count;
			}
			$day = $day->modify( '+1 day' );
		}
		return $count;
	}

	/**
	 * Devuelve las fechas (YYYY-MM-DD) festivas del año aplicables al
	 * usuario, considerando el scope CCAA configurado en settings.
	 *
	 * Cache estático por año dentro del request.
	 *
	 * @param int $year Año.
	 * @return string[]
	 */
	private function holidays_for_year( int $year ): array {
		static $cache = array();
		if ( isset( $cache[ $year ] ) ) {
			return $cache[ $year ];
		}
		$from = new \DateTimeImmutable( sprintf( '%04d-01-01', $year ), wp_timezone() );
		$to   = new \DateTimeImmutable( sprintf( '%04d-12-31', $year ), wp_timezone() );
		// Scope: por ahora incluimos national + regional + local + company.
		// TODO(welow): si los empleados se asocian a una sede o ccaa concreta, filtrar más fino.
		$dates = $this->holidays->find_dates_in_range( $from, $to, array( 'national', 'regional', 'local', 'company' ) );

		$cache[ $year ] = $dates;
		return $dates;
	}

	/**
	 * Prorratea los días anuales según los meses trabajados en el año dado.
	 *
	 * Cuenta los meses (1-12) en los que el empleado está activo al menos
	 * un día del mes (hire <= último día del mes Y termination >= primer
	 * día del mes). Si hire es null, se considera activo desde antes del
	 * año. Si termination es null, se considera activo hasta después.
	 *
	 * @param float                   $annual_days Días base anuales.
	 * @param \DateTimeImmutable|null $hire        Alta laboral.
	 * @param \DateTimeImmutable|null $term        Baja laboral.
	 * @param int                     $year        Año a prorratear.
	 * @return float Días prorrateados (redondeo a 0.5).
	 */
	private function prorate( float $annual_days, ?\DateTimeImmutable $hire, ?\DateTimeImmutable $term, int $year ): float {
		$months_active = 0;
		for ( $m = 1; $m <= 12; $m++ ) {
			$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $m ), wp_timezone() );
			$last  = $first->modify( 'last day of this month' );
			$ok    = true;
			if ( null !== $hire && $hire > $last ) {
				$ok = false;
			}
			if ( null !== $term && $term < $first ) {
				$ok = false;
			}
			if ( $ok ) {
				++$months_active;
			}
		}
		if ( 12 === $months_active ) {
			return $annual_days;
		}
		return round( ( $annual_days * $months_active / 12 ) * 2 ) / 2;
	}
}
