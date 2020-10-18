<?php

namespace Cargo;

class CargoTableFactory {
	/**
	 * @var CargoTableStore
	 */
	private $tableStore;

	/**
	 * @var array table name => CargoTable cache
	 */
	private $tables = [];

	public function __construct( CargoTableStore $tableStore ) {
		$this->tableStore = $tableStore;
	}

	/**
	 * Get a CargoTable with the specified (main) table name.
	 *
	 * @param string $tableName
	 * @return CargoTable
	 */
	public function get( string $tableName ): CargoTable {
		if ( isset( $this->tables[$tableName] ) ) {
			return $this->tables[$tableName];
		}

		if ( $tableName == '_pageData' ) {
			$table = new CargoPageDataTable( $this->tableStore );
		} elseif ( $tableName == '_fileData' ) {
			$table = new CargoFileDataTable( $this->tableStore );
		} else {
			$table = new CargoTable( $this->tableStore, $tableName );
		}
		$this->tables[$tableName] = $table;
		return $table;
	}
}
