<?php

namespace Cargo;

use LocalFile;
use ParserOutput;
use Status;

interface RecordStore {
	public function storePageRecords( CargoPage $page );

	public function deletePageRecords( CargoPage $page );

	public function deletePageTableRecords( CargoPage $page, CargoTable $table );

	public function storePageTableRecords( CargoPage $page, CargoTable $table );

	public function storeRecord( CargoPage $page, Record $record ): Status;

	public function blankOrRejectBadData( CargoPage $page, Record $record ): Status;

	public function storeFileData( CargoPage $page );

	public function beginTransaction( CargoPage $page, Record $record );

	public function endTransaction();
}
