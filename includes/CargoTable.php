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
	public function getTableName() {
		return $this->mName;
	}

	/**
	 * @return string
	 */
	public function getReplacementTableName() {
		return $this->mName . '__NEXT';
	}

	/**
	 * @return string
	 */
	public function getTableNameForUpdate() {
		return $this->isReadOnly()
			? $this->getReplacementTableName()
			: $this->getTableName();
	}

	/**
	 * @return bool
	 */
	public function exists() {
		$cdb = CargoUtils::getDB();
		return $cdb->tableExists( $this->getTableName(), __METHOD__ );
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

	public function storeDataForPage( CargoPage $page, ParserOutput $parserOutput ) {
	}

	/**
	 * Gets the current schema for this table.
	 *
	 * @return CargoTableSchema|null
	 */
	public function getSchema( $forUpdate = false ) {
		$dbw = wfGetDB( DB_MASTER );
		$tableName = $forUpdate
			? $this->getTableNameForUpdate()
			: $this->getTableName();
		$res = $dbw->selectField( 'cargo_tables', 'table_schema', [ 'main_table' => $tableName ], __METHOD__ );
		return $res ? CargoTableSchema::newFromDBString( $res ) : null;
	}

	/**
	 * @return string[]
	 */
	private function getFieldTables() {
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->selectField( 'cargo_tables', 'field_tables', [ 'main_table' => $this->mName ], __METHOD__ );
		return unserialize( $res ) ?: [];
	}

	public function deleteDataForPage( CargoPage $page ) {
		if ( !$this->exists() ) {
			return;
		}

		if ( $page->getID() == 0 ) {
			return;
		}

		$tableName = $this->getTableNameForUpdate();

		$cdb = CargoDatabase::get();
		$cdbPageIDCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $page->getID() ];

		// First, delete from the "field" tables.
		$fieldTableNames = $this->getFieldTables();
		foreach ( $fieldTableNames as $curFieldTable ) {
			// Thankfully, the MW DB API already provides a
			// nice method for deleting based on a join.
			$cdb->deleteJoin(
				$curFieldTable,
				$tableName,
				$cdb->addIdentifierQuotes( '_rowID' ),
				$cdb->addIdentifierQuotes( '_ID' ),
				$cdbPageIDCheck,
				__METHOD__
			);
		}

		// Delete from the "files" helper table, if it exists.
		$curFilesTable = $tableName . '___files';
		if ( $cdb->tableExists( $curFilesTable, __METHOD__ ) ) {
			$cdb->delete( $curFilesTable, $cdbPageIDCheck, __METHOD__ );
		}

		// Now, delete from the "main" table.
		$cdb->delete( $tableName, $cdbPageIDCheck, __METHOD__ );
	}

	/**
	 * The table is read only if a replacement table (i.e. __NEXT) exists.
	 *
	 * @return bool
	 */
	public function isReadOnly() {
		$cdb = CargoUtils::getDB();
		return $cdb->tableExists( $this->getReplacementTableName(), __METHOD__ );
	}
}
