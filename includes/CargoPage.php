<?php

class CargoPage {
	/**
	 * @var int Page ID
	 */
	private $mID;

	/**
	 * @var Title Page title
	 */
	private $mTitle;

	/**
	 * @param Title $title Page title
	 * @param int|null $pageID Article ID, will be loaded from database if null
	 */
	public function __construct( Title $title, $pageID = null ) {
		$this->mTitle = $title;
		$this->mID = $pageID ?? $title->getArticleID( Title::GAID_FOR_UPDATE );
	}

	/**
	 * @return int
	 */
	public function getID() {
		return $this->mID;
	}

	/**
	 * @return Title
	 */
	public function getTitle() {
		return $this->mTitle;
	}

	public function storeData( Title $title, ParserOutput $parserOutput ) {
		$cdb = CargoDatabase::get();
		$cdb->begin();

		$this->deleteCargoTableData( true );

		$pageDataTable = new CargoPageDataTable();
		$pageDataTable->deleteDataForPage( $this );
		$pageDataTable->storeDataForPage( $this, $parserOutput );

		$cargoStorage = $parserOutput->getExtensionData( 'CargoStorage' );
		$cargoPagesData = [];
		foreach ( $cargoStorage as $mainTable => $storeData ) {
			$table = new CargoTable( $mainTable );
			$schema = $table->getSchema( true );
			if ( $schema == null ) {
				wfDebugLog( 'cargo', "Skipping Cargo store on page save: table ($mainTable) does not exist.\n" );
				continue;
			}
			$cargoPagesData[] = [
				'table_name' => $mainTable,
				'page_id' => $this->getID(),
			];
			$tableName = $table->getTableNameForUpdate();
			foreach ( $storeData as $tableFieldValues ) {
				CargoStore::storeAllData( $title, $tableName, $tableFieldValues, $schema );
			}
		}

		if ( count( $cargoPagesData ) ) {
			$dbw = wfGetDB( DB_MASTER );
			$dbw->insert( 'cargo_pages', $cargoPagesData, __METHOD__ );
		}

		$cdb->commit();
	}

	/**
	 * @todo(rnix): private
	 */
	public function storePageData( ParserOutput $parserOutput ) {
		$pageDataTable = new CargoPageDataTable();
		$pageDataTable->storeDataForPage( $this, $parserOutput );
	}

	/**
	 * @todo(rnix): private
	 */
	public function storeFileData( ParserOutput $parserOutput ) {
		$fileDataTable = new CargoFileDataTable();
		$fileDataTable->storeDataForPage( $this, $parserOutput );
	}

	/**
	 * Remove all data in cargo tables stored by this page.
	 *
	 * @TODO(rnix): move cargo_pages to cargo database, then $commit can go away.
	 *
	 * @param bool $commit True if the current transaction is intended to be committed.
	 */
	public function deleteCargoTableData( $commit ) {
		$tables = $this->getTables();

		foreach ( $tables as $table ) {
			$table->deleteDataForPage( $this );
		}

		if ( $commit ) {
			$this->clearTables();
		}
	}

	/**
	 * @return CargoTable[]
	 */
	private function getTables() {
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->select( 'cargo_pages', 'table_name', [ 'page_id' => $this->mID ], __METHOD__ );
		while ( $row = $dbw->fetchRow( $res ) ) {
			$tables[] = new CargoTable( $row['table_name'] );
		}
		return $tables;
	}

	private function clearTables() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'cargo_pages', [ 'page_id' => $this->mID ], __METHOD__ );
	}
}
