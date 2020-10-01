<?php

/**
 * CargoTable represents a table in the Cargo database managed by #cargo
 * parser functions.
 */
class CargoTable {
	/**
	 * @var string $tableName
	 */
	private $tableName;

	/**
	 * @param string $tableName
	 */
	public function __construct( $tableName ) {
		$this->tableName = $tableName;
	}

	/**
	 * @return string
	 */
	public function getReplacementTableName() {
		return $this->tableName . '__NEXT';
	}

	/**
	 * @return bool
	 */
	public function exists() {
		$cdb = CargoUtils::getDB();
		return $cdb->tableExists( $this->tableName );
	}

	/**
	 * @return bool
	 */
	public function fullyExists() {
		$dbr = wfGetDB( DB_REPLICA );
		$numRows = $dbr->selectRowCount( 'cargo_tables', '*', [ 'main_table' => $this->tableName ], __METHOD__ );
		if ( $numRows == 0 ) {
			return false;
		}

		return $this->exists();
	}
}
