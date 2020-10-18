<?php

/**
 * Update the _pageData table to include data from the cargo_pages table.
 *
 * @ingroup Maintenance
 */

use Cargo\CargoPageDataTable;
use Cargo\CargoTableFactory;
use Cargo\DatabaseFactory;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDatabase;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

class UpdateCargoPageData extends LoggedUpdateMaintenance {
	const TABLES_FIELD_NAME = '_tables';
	const TABLES_FIELD_TABLE_NAME = '_pageData___tables';
	const PAGE_DATA_TABLE_NAME = '_pageData';

	public function doDBUpdates() {
		$services = MediaWikiServices::getInstance();

		/**
		 * @var DatabaseFactory $databaseFactory
		 */
		$databaseFactory = $services->getService( 'CargoDatabaseFactory' );

		/**
		 * @var CargoTableFactory $tableFactory
		 */
		$tableFactory = $services->getService( 'CargoTableFactory' );

		$pageDataTable = $tableFactory->get( self::PAGE_DATA_TABLE_NAME );

		$pageDataTableSchema = CargoPageDataTable::getSchemaForCreation();

		$cdb = $databaseFactory->get();
		$dbw = $this->getDB( DB_MASTER );
		if ( !$pageDataTable->exists() ) {
			// @todo(rnix): move this to CargoTable, CargoTableStore
			CargoUtils::createCargoTableOrTables(
				$cdb,
				$dbw,
				self::PAGE_DATA_TABLE_NAME,
				$pageDataTableSchema,
				$pageDataTableSchema->toDBString(),
				0
			);
		} elseif ( !$cdb->tableExists( self::TABLES_FIELD_TABLE_NAME, __METHOD__ ) ) {
			// @todo(rnix): move this to CargoTable, CargoTableStore as part of a
			// larger effort to make schema migrations possible without recreating
			$fieldsInTable = [
				'_rowID' => 'Integer',
				'_value' => 'String',
				'_position' => 'Integer',
			];
			CargoUtils::createTable(
				$cdb,
				self::TABLES_FIELD_TABLE_NAME,
				$fieldsInTable
			);

			// @todo(rnix): mssql, pgsql support
			$cdb->query(
				'ALTER TABLE ' . $cdb->tablePrefix() . '_pageData ' .
				'ADD COLUMN `' . self::TABLES_FIELD_NAME . '__full` ' .
				'text DEFAULT NULL',
				__METHOD__
			);
		}

		$rows = $dbw->select(
			'cargo_pages',
			[ 'page_id', 'table_name' ],
			'',
			__METHOD__,
			[ 'ORDER BY page_id ASC' ]
		);

		$batch = [];
		$batchSize = $this->getBatchSize();
		$lastPageID = -1;
		foreach ( $rows as $row ) {
			$pageID = $row->page_id;
			$tableName = $row->table_name;

			if ( $pageID != $lastPageID && count( $batch ) >= $batchSize ) {
				$this->handleBatch( $cdb, $batch );
				$batch = [];
			}

			$batch[$pageID][] = $tableName;
			$lastPageID = $pageID;
		}

		if ( count( $batch ) > 0 ) {
			$this->handleBatch( $cdb, $batch );
		}

		return true;
	}

	public function getUpdateKey() {
		return self::class;
	}

	private function handleBatch( IDatabase $cdb, array $batch ) {
		// Each batch is wrapped in a transaction to prevent any issues if a
		// page inserts _pageData while the maintenance script is running.
		$cdb->begin( __METHOD__ );

		// This DB layout is ridiculous...
		$pageIDs = array_keys( $batch );
		$oldRows = $cdb->select(
			self::PAGE_DATA_TABLE_NAME,
			[ '_ID', '_pageID' ],
			[ '_pageID' => $pageIDs ],
			__METHOD__
		);

		$pageIDToPageDataID = [];
		foreach ( $oldRows as $oldRow ) {
			$pageIDToPageDataID[$oldRow->_pageID] = $oldRow->_ID;
		}

		// Insert rows for pages that don't have _pageData rows yet:
		$insertValues = [];
		foreach ( $batch as $pageID => $tableNames ) {
			if ( array_key_exists( $pageID, $pageIDToPageDataID ) ) {
				continue;
			}
			$insertValues[] = [
				'_pageID' => $pageID,
				self::TABLES_FIELD_NAME . '__full' => implode( '|', $tableNames ),
			];
		}

		$cdb->insert(
			self::PAGE_DATA_TABLE_NAME,
			$insertValues,
			__METHOD__
		);

		// Get the inserted IDs, they're needed for the field table.
		$oldRows = $cdb->select(
			self::PAGE_DATA_TABLE_NAME,
			[ '_ID', '_pageID' ],
			[ '_pageID' => $pageIDs ],
			__METHOD__
		);
		foreach ( $oldRows as $oldRow ) {
			$pageIDToPageDataID[$oldRow->_pageID] = $oldRow->_ID;
		}

		$insertValues = [];
		$fieldTableInsertValues = [];
		foreach ( $batch as $pageID => $tableNames ) {
			$id = $pageIDToPageDataID[$pageID];
			if ( empty( $id ) ) {
				throw new DBError( $cdb, 'Unable to get ID for page ID in _pageData' );
			}

			$insertValues[] = [
				'_ID' => $id,
				'_pageID' => $pageID,
				self::TABLES_FIELD_NAME . '__full' => implode( '|', $tableNames ),
			];

			$i = 1;
			foreach ( $tableNames as $tableName ) {
				$fieldTableInsertValues[] = [
					'_rowID' => $id,
					'_value' => $tableName,
					'_position' => $i++,
				];
			}
		}

		$cdb->upsert(
			self::PAGE_DATA_TABLE_NAME,
			$insertValues,
			'_ID',
			[
				self::TABLES_FIELD_NAME . '__full = VALUES(' . self::TABLES_FIELD_NAME . '__full)',
			],
			__METHOD__
		);

		// Remove any previous list values.
		// List tables should probably have a unique compound key if they're going to store "position"...
		$cdb->delete(
			self::TABLES_FIELD_TABLE_NAME,
			[ '_rowID' => array_values( $pageIDToPageDataID ) ],
			__METHOD__
		);

		$cdb->insert(
			self::TABLES_FIELD_TABLE_NAME,
			$fieldTableInsertValues,
			__METHOD__
		);

		$cdb->commit( __METHOD__ );
	}
}

$maintClass = UpdateCargoPageData::class;

require_once RUN_MAINTENANCE_IF_MAIN;
