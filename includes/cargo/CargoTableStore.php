<?php

namespace Cargo;

use CargoTableSchema;
use Wikimedia\Rdbms\ILoadBalancer;

class CargoTableStore {
	/**
	 * @var DatabaseFactory
	 */
	private $databaseFactory;

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var bool[]
	 */
	private $existsCache = [];

	/**
	 * @var bool[]
	 */
	private $fullyExistsCache = [];

	/**
	 * @var CargoTableSchema[]|null
	 */
	private $schemaCache = [];

	/**
	 * @var string[][]
	 */
	private $fieldTablesCache = [];

	public function __construct( DatabaseFactory $databaseFactory, ILoadBalancer $loadBalancer ) {
		$this->databaseFactory = $databaseFactory;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * The table exists if the database table exists in the cargo database.
	 *
	 * @return bool
	 */
	public function exists( string $tableName ) {
		if ( isset( $this->existsCache[$tableName] ) ) {
			return $this->existsCache[$tableName];
		}

		$cdb = $this->databaseFactory->get();
		$exists = $cdb->tableExists( $tableName, __METHOD__ );

		$this->existsCache[$tableName] = $exists;
		return $exists;
	}

	/**
	 * The table fully exists if it has an entry in cargo_tables and exists in the cargo database.
	 *
	 * @return bool
	 */
	public function fullyExists( string $tableName ) {
		if ( isset( $this->fullyExistsCache[$tableName] ) ) {
			return $this->fullyExistsCache[$tableName];
		}

		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$numRows = $dbr->selectRowCount( 'cargo_tables', '*', [ 'main_table' => $tableName ], __METHOD__ );
		$exists = ( $numRows == 0 ) ? false : $this->exists( $tableName );

		$this->fullyExistsCache[$tableName] = $exists;
		return $exists;
	}

	/**
	 * Gets the current schema for this table.
	 *
	 * @return CargoTableSchema|null
	 */
	public function getSchema( string $tableName ) {
		if ( isset( $this->schemaCache[$tableName] ) ) {
			return $this->schemaCache[$tableName];
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER );
		$res = $dbw->selectField( 'cargo_tables', 'table_schema', [ 'main_table' => $tableName ], __METHOD__ );
		$schema = $res ? CargoTableSchema::newFromDBString( $res ) : null;

		$this->schemaCache[$tableName] = $schema;
		return $schema;
	}

	/**
	 * @return string[]
	 */
	public function getFieldTables( string $tableName ) {
		if ( isset( $this->fieldTablesCache[$tableName] ) ) {
			return $this->fieldTablesCache[$tableName];
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER );
		$res = $dbw->selectField( 'cargo_tables', 'field_tables', [ 'main_table' => $tableName ], __METHOD__ );
		$fieldTables = unserialize( $res ) ?: [];

		$this->fieldTablesCache[$tableName] = $fieldTables;
		return $fieldTables;
	}
}
