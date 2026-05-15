<?php
/**
 * Base reutilizable para repositorios que encapsulan acceso a $wpdb.
 *
 * Mantiene el patrón establecido en §17 de la especificación: ningún
 * servicio toca $wpdb directamente — siempre a través de un repository.
 *
 * @package Welow\RRHH\Database\Repository
 */

declare( strict_types=1 );

namespace Welow\RRHH\Database\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio abstracto.
 */
abstract class AbstractRepository {

	/**
	 * Instancia compartida de wpdb.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Instancia de wpdb (inyectable para tests).
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Nombre completo (con prefijo de WP) de la tabla gestionada.
	 *
	 * @return string
	 */
	abstract protected function table(): string;

	/**
	 * Recupera una fila por id.
	 *
	 * @param int $id Identificador.
	 * @return array<string, mixed>|null Fila asociativa o null si no existe.
	 */
	public function find( int $id ): ?array {
		$table = $this->table();
		// Phpcs ignora el placeholder de tabla — está controlado por la subclase y no proviene de entrada de usuario.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserta una nueva fila.
	 *
	 * @param array<string, mixed> $data    Datos a insertar (clave → valor).
	 * @param array<int, string>   $formats Formatos para $wpdb->insert (%s/%d/%f).
	 * @return int|false Id insertado o false en error.
	 */
	protected function insert( array $data, array $formats ) {
		$ok = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $ok ) {
			return false;
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza filas por condiciones.
	 *
	 * @param array<string, mixed> $data          Datos a actualizar.
	 * @param array<string, mixed> $where         Condiciones (igualdad).
	 * @param array<int, string>   $data_format   Formatos de los datos.
	 * @param array<int, string>   $where_format  Formatos de las condiciones.
	 * @return int|false Filas afectadas o false en error.
	 */
	protected function update( array $data, array $where, array $data_format, array $where_format ) {
		return $this->wpdb->update( $this->table(), $data, $where, $data_format, $where_format );
	}

	/**
	 * Elimina filas por condiciones.
	 *
	 * @param array<string, mixed> $where         Condiciones (igualdad).
	 * @param array<int, string>   $where_format  Formatos.
	 * @return int|false Filas borradas o false en error.
	 */
	protected function delete( array $where, array $where_format ) {
		return $this->wpdb->delete( $this->table(), $where, $where_format );
	}
}
