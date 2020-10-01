<?php

/**
 * CargoTable represents a table in the Cargo database managed by #cargo
 * parser functions.
 */
class CargoTable {
	/**
	 * @var string $mName
	 */
	private $mName;

	/**
	 * @param string $tableName
	 */
	public function __construct( $tableName ) {
		$this->mName = $tableName;
	}

	/**
	 * @return string
	 */
	public function getReplacementTableName() {
		return $this->mName . '__NEXT';
	}

	/**
	 * @return bool
	 */
	public function exists() {
		$cdb = CargoUtils::getDB();
		return $cdb->tableExists( $this->mName );
	}

	/**
	 * @return bool
	 */
	public function fullyExists() {
		$dbr = wfGetDB( DB_REPLICA );
		$numRows = $dbr->selectRowCount( 'cargo_tables', '*', [ 'main_table' => $this->mName ], __METHOD__ );
		if ( $numRows == 0 ) {
			return false;
		}

		return $this->exists();
	}

	/**
	 * @return string[]
	 */
	private function getFieldTables() {
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->selectField( 'cargo_tables', 'field_tables', [ 'main_table' => $this->mName ] );
		return unserialize( $res ) ?? [];
	}

	public function deleteDataForPage( CargoPage $page ) {
		if ( $this->isReadOnly() ) {
			return;
		}

		$cdb = CargoDatabase::get();
		$cdbPageIDCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $page->getID() ];

		// First, delete from the "field" tables.
		$fieldTableNames = $this->getFieldTables();
		foreach ( $fieldTableNames as $curFieldTable ) {
			// Thankfully, the MW DB API already provides a
			// nice method for deleting based on a join.
			$cdb->deleteJoin(
				$curFieldTable,
				$this->mName,
				$cdb->addIdentifierQuotes( '_rowID' ),
				$cdb->addIdentifierQuotes( '_ID' ),
				$cdbPageIDCheck
			);
		}

		// Delete from the "files" helper table, if it exists.
		$curFilesTable = $this->mName . '___files';
		if ( $cdb->tableExists( $curFilesTable ) ) {
			$cdb->delete( $curFilesTable, $cdbPageIDCheck );
		}

		// Now, delete from the "main" table.
		$cdb->delete( $this->mName, $cdbPageIDCheck );
	}

	/**
	 * The table is read only if a replacement table (i.e. __NEXT) exists.
	 *
	 * @return bool
	 */
	public function isReadOnly() {
		return false;
	}
}
