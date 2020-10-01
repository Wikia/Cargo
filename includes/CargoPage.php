<?php

class CargoPage {
	/**
	 * @var int page ID
	 */
	private $mID;

	/**
	 * @param int $pageID ID of the underlying Page
	 */
	public function __construct( $pageID ) {
		$this->mID = $pageID;
	}

	/**
	 * @return int
	 */
	public function getID() {
		return $this->mID;
	}

	public function deleteCargoTableData() {
		$tables = $this->getTables();

		foreach ( $tables as $table ) {
			$table->deleteDataForPage( $this );
		}
	}

	/**
	 * @return CargoTable[]
	 */
	private function getTables() {
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->select( 'cargo_pages', 'table_name', [ 'page_id' => $this->mID ] );
		while ( $row = $dbw->fetchRow( $res ) ) {
			$tables[] = new CargoTable( $row['table_name'] );
		}
		return $tables;
	}
}
