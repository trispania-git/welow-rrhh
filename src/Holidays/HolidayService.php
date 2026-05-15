<?php
/**
 * Servicio de festivos.
 *
 * @package Welow\RRHH\Holidays
 */

declare( strict_types=1 );

namespace Welow\RRHH\Holidays;

use Welow\RRHH\Audit\AuditLogger;
use Welow\RRHH\Support\Data\Holiday;
use Welow\RRHH\Support\Data\HolidayScope;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones de alto nivel sobre festivos.
 */
final class HolidayService {

	private const AUDIT_ENTITY = 'holiday';

	/**
	 * Repositorio.
	 *
	 * @var HolidayRepository
	 */
	private HolidayRepository $repository;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param HolidayRepository $repository Repositorio.
	 * @param AuditLogger       $audit      Audit logger.
	 */
	public function __construct( HolidayRepository $repository, AuditLogger $audit ) {
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Acceso al repositorio.
	 *
	 * @return HolidayRepository
	 */
	public function repository(): HolidayRepository {
		return $this->repository;
	}

	/**
	 * Crea un festivo.
	 *
	 * @param array<string, mixed> $data Datos (date|fecha, name|nombre, scope|tipo).
	 * @return Holiday|\WP_Error
	 */
	public function create( array $data ) {
		$dto = self::build_dto( $data );
		if ( $dto instanceof \WP_Error ) {
			return $dto;
		}

		if ( null !== $this->repository->find_by_date_scope( $dto->date->format( 'Y-m-d' ), $dto->scope ) ) {
			$err = new \WP_Error();
			$err->add( 'duplicate', __( 'Ya existe un festivo con esa fecha y ámbito.', 'welow-rrhh' ) );
			return $err;
		}

		try {
			$id = $this->repository->create( $dto );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_holiday_create_failed', $e->getMessage() );
		}

		$created = $this->repository->find_by_id( $id );
		$this->audit->log(
			'create',
			self::AUDIT_ENTITY,
			$id,
			array(
				'date'  => $dto->date->format( 'Y-m-d' ),
				'name'  => $dto->name,
				'scope' => $dto->scope->value,
			)
		);
		return $created;
	}

	/**
	 * Actualiza un festivo.
	 *
	 * @param int                  $id   Identificador.
	 * @param array<string, mixed> $data Cambios.
	 * @return Holiday|\WP_Error
	 */
	public function update( int $id, array $data ) {
		$current = $this->repository->find_by_id( $id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_holiday_not_found', __( 'Festivo no encontrado.', 'welow-rrhh' ) );
		}

		$dto = self::build_dto( $data, $current );
		if ( $dto instanceof \WP_Error ) {
			return $dto;
		}

		// Colisión sólo si cambian fecha o scope.
		if (
			$dto->date->format( 'Y-m-d' ) !== $current->date->format( 'Y-m-d' )
			|| $dto->scope !== $current->scope
		) {
			$colliding = $this->repository->find_by_date_scope( $dto->date->format( 'Y-m-d' ), $dto->scope );
			if ( null !== $colliding && $colliding->id !== $current->id ) {
				$err = new \WP_Error();
				$err->add( 'duplicate', __( 'Ya existe otro festivo con esa fecha y ámbito.', 'welow-rrhh' ) );
				return $err;
			}
		}

		$changes = array(
			'holiday_date' => $dto->date->format( 'Y-m-d' ),
			'name'         => $dto->name,
			'scope'        => $dto->scope->value,
			'year'         => (int) $dto->date->format( 'Y' ),
		);

		try {
			$this->repository->update_changes( $id, $changes );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'welow_holiday_update_failed', $e->getMessage() );
		}

		$updated = $this->repository->find_by_id( $id );
		$this->audit->log(
			'update',
			self::AUDIT_ENTITY,
			$id,
			array(
				'before' => array(
					'date'  => $current->date->format( 'Y-m-d' ),
					'scope' => $current->scope->value,
				),
				'after'  => array(
					'date'  => $dto->date->format( 'Y-m-d' ),
					'scope' => $dto->scope->value,
				),
			)
		);
		return $updated ?? $current;
	}

	/**
	 * Elimina un festivo.
	 *
	 * @param int $id Identificador.
	 * @return true|\WP_Error
	 */
	public function delete( int $id ) {
		$current = $this->repository->find_by_id( $id );
		if ( null === $current ) {
			return new \WP_Error( 'welow_holiday_not_found', __( 'Festivo no encontrado.', 'welow-rrhh' ) );
		}
		if ( ! $this->repository->delete_by_id( $id ) ) {
			return new \WP_Error( 'welow_holiday_delete_failed', __( 'No se pudo eliminar el festivo.', 'welow-rrhh' ) );
		}
		$this->audit->log(
			'delete',
			self::AUDIT_ENTITY,
			$id,
			array(
				'date' => $current->date->format( 'Y-m-d' ),
				'name' => $current->name,
			)
		);
		return true;
	}

	/**
	 * Construye un Holiday DTO a partir de un payload (acepta nombres de columna
	 * tanto en inglés como en español del CSV — fecha/nombre/tipo).
	 *
	 * @param array<string, mixed> $data    Datos.
	 * @param Holiday|null         $current Para fallback en update.
	 * @return Holiday|\WP_Error
	 */
	private static function build_dto( array $data, ?Holiday $current = null ) {
		$errors = new \WP_Error();

		$raw_date  = self::pick( $data, array( 'date', 'fecha', 'holiday_date' ) );
		$raw_name  = self::pick( $data, array( 'name', 'nombre' ) );
		$raw_scope = self::pick( $data, array( 'scope', 'tipo' ) );

		$date_str = is_string( $raw_date ) ? trim( $raw_date ) : '';
		if ( '' === $date_str && null !== $current ) {
			$date = $current->date;
		} else {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_str );
			if ( false === $date ) {
				$errors->add( 'date', __( 'Fecha inválida (use YYYY-MM-DD).', 'welow-rrhh' ) );
			}
		}

		$name = is_string( $raw_name ) ? sanitize_text_field( $raw_name ) : '';
		if ( '' === $name && null !== $current ) {
			$name = $current->name;
		} elseif ( '' === $name ) {
			$errors->add( 'name', __( 'El nombre del festivo es obligatorio.', 'welow-rrhh' ) );
		}

		if ( null !== $raw_scope && '' !== $raw_scope ) {
			$scope = HolidayScope::tryFrom( strtolower( trim( (string) $raw_scope ) ) );
			if ( null === $scope ) {
				$errors->add( 'scope', __( 'Ámbito no válido. Valores: national, regional, local, company.', 'welow-rrhh' ) );
			}
		} else {
			$scope = null !== $current ? $current->scope : HolidayScope::get_default();
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$year = (int) $date->format( 'Y' );
		return new Holiday( $current?->id, $date, $name, $scope, $year );
	}

	/**
	 * Devuelve el primer valor presente del array con la primera clave que coincida.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @param string[]             $keys Claves candidatas en orden.
	 * @return mixed|null
	 */
	private static function pick( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) && '' !== $data[ $key ] && null !== $data[ $key ] ) {
				return $data[ $key ];
			}
		}
		return null;
	}
}
