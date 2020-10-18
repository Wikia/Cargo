<?php

namespace Cargo;

use CargoTableSchema;

/**
 * CargoTable represents a table in the Cargo database managed by #cargo
 * parser functions.
 */
class CargoTable {
	/**
	 * @var CargoTableStore $tableStore
	 */
	private $tableStore;

	/**
	 * @var string $name
	 */
	private $name;

	/**
	 * @param string $tableName
	 */
	public function __construct( CargoTableStore $tableStore, string $tableName ) {
		$this->tableStore = $tableStore;
		$this->name = $tableName;
	}

	/**
	 * @return string
	 */
	public function getTableName() {
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function getReplacementTableName() {
		return $this->name . '__NEXT';
	}

	/**
	 * Returns the table name, returning the replacement table if one exists.
	 *
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
		return $this->tableStore->exists( $this->getTableName() );
	}

	/**
	 * @return bool
	 */
	public function fullyExists() {
		return $this->tableStore->fullyExists( $this->getTableName() );
	}

	/**
	 * The table is read only if a replacement table (i.e. __NEXT) exists.
	 *
	 * @return bool
	 */
	public function isReadOnly() {
		return $this->tableStore->exists( $this->getReplacementTableName() );
	}

	/**
	 * Gets the current schema for this table.
	 *
	 * @return CargoTableSchema|null
	 */
	public function getSchema( $forUpdate = false ) {
		$tableName = $forUpdate
			? $this->getTableNameForUpdate()
			: $this->getTableName();
		return $this->tableStore->getSchema( $tableName );
	}

	/**
	 * @return string[]
	 */
	public function getFieldTables( $forUpdate = false ) {
		$tableName = $forUpdate
			? $this->getTableNameForUpdate()
			: $this->getTableName();
		return $this->tableStore->getFieldTables( $tableName );
	}

	/**
	 * @return Record[] Records to store
	 */
	public function getRecordsForPage( CargoPage $page ) {
		$parserOutput = $page->getParserOutput();
		if ( $parserOutput == null ) {
			return [];
		}

		$mainTable = $this->getTableName();
		$storeData = $parserOutput->getExtensionData( 'CargoStorage' )[$mainTable] ?? false;
		if ( !$storeData ) {
			return [];
		}

		$records = [];
		foreach ( $storeData as $tableFieldValues ) {
			$record = new Record( $mainTable );
			$record->setFieldValues( $tableFieldValues );
			$records[] = $record;
		}

		return $records;
	}
}
