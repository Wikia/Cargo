<?php

namespace Cargo;

use CargoUtils;
use Hooks;
use MWException;
use ParserOutput;
use Status;
use Title;
use Wikimedia\Rdbms\IDatabase;

class DatabaseRecordStore implements RecordStore {
	const DATE_AND_TIME = 0;
	const DATE_ONLY = 1;
	const MONTH_ONLY = 2;
	const YEAR_ONLY = 3;

	private $databaseFactory;

	private $tableFactory;

	public function __construct( DatabaseFactory $databaseFactory, CargoTableFactory $tableFactory ) {
		$this->databaseFactory = $databaseFactory;
		$this->tableFactory = $tableFactory;
	}

	public function storeRecord( CargoPage $page, Record $record ): Status {
		$pageID = $page->getID();
		$pageName = $page->getName();
		$pageTitle = $page->getTitle();
		$pageNamespace = $page->getNamespace();

		$table = $this->tableFactory->get( $record->getTable() );
		$tableSchema = $table->getSchema( true );
		$tableName = $table->getTableNameForUpdate();

		$tableFieldValues = $record->getFieldValues();

		// We're still here! Let's add to the DB table(s).
		// First, though, let's do some processing:
		// - remove invalid values, if any
		// - put dates and numbers into correct format
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			// If it's null or not set, skip this value.
			if ( !array_key_exists( $fieldName, $tableFieldValues ) ) {
				continue;
			}
			$curValue = $tableFieldValues[$fieldName];
			if ( $curValue === null ) {
				continue;
			}

			// @todo(rnix): this logic belongs in CargoField or something
			// Change from the format stored in the DB to the
			// "real" one.
			$fieldType = $fieldDescription->mType;
			if ( $fieldDescription->mAllowedValues != null ) {
				$allowedValues = $fieldDescription->mAllowedValues;
				if ( $fieldDescription->mIsList ) {
					$delimiter = $fieldDescription->getDelimiter();
					$individualValues = explode( $delimiter, $curValue );
					$valuesToBeKept = array();
					foreach ( $individualValues as $individualValue ) {
						$realIndividualVal = trim( $individualValue );
						if ( in_array( $realIndividualVal, $allowedValues ) ) {
							$valuesToBeKept[] = $realIndividualVal;
						}
					}
					$tableFieldValues[$fieldName] = implode( $delimiter, $valuesToBeKept );
				} else {
					if ( !in_array( $curValue, $allowedValues ) ) {
						$tableFieldValues[$fieldName] = null;
					}
				}
			}
			if ( $fieldType == 'Date' || $fieldType == 'Datetime' || $fieldType == 'Start date' ||
				 $fieldType == 'Start datetime' || $fieldType == 'End date' || $fieldType == 'End datetime' ) {
				if ( $curValue == '' ) {
					continue;
				}
				if ( $fieldDescription->mIsList ) {
					$delimiter = $fieldDescription->getDelimiter();
					$individualValues = explode( $delimiter, $curValue );
					// There's unfortunately only one
					// precision value per field, even if it
					// holds more than one date - store the
					// most "precise" of the precision
					// values.
					$maxPrecision = self::YEAR_ONLY;
					$dateValues = array();
					foreach ( $individualValues as $individualValue ) {
						$realIndividualVal = trim( $individualValue );
						if ( $realIndividualVal == '' ) {
							continue;
						}
						list( $dateValue, $precision ) = $this->getDateValueAndPrecision( $realIndividualVal, $fieldType );
						$dateValues[] = $dateValue;
						if ( $precision < $maxPrecision ) {
							$maxPrecision = $precision;
						}
					}
					$tableFieldValues[$fieldName] = implode( $delimiter, $dateValues );
					$tableFieldValues[$fieldName . '__precision'] = $maxPrecision;
				} else {
					list( $dateValue, $precision ) = $this->getDateValueAndPrecision( $curValue, $fieldType );
					$tableFieldValues[$fieldName] = $dateValue;
					$tableFieldValues[$fieldName . '__precision'] = $precision;
				}
			} elseif ( $fieldType == 'Integer' ) {
				// Remove digit-grouping character.
				global $wgCargoDigitGroupingCharacter;
				$tableFieldValues[$fieldName] = str_replace( $wgCargoDigitGroupingCharacter, '', $curValue );
			} elseif ( $fieldType == 'Float' || $fieldType == 'Rating' ) {
				// Remove digit-grouping character, and change
				// decimal mark to '.' if it's anything else.
				global $wgCargoDigitGroupingCharacter;
				global $wgCargoDecimalMark;
				$curValue = str_replace( $wgCargoDigitGroupingCharacter, '', $curValue );
				$curValue = str_replace( $wgCargoDecimalMark, '.', $curValue );
				$tableFieldValues[$fieldName] = $curValue;
			} elseif ( $fieldType == 'Boolean' ) {
				// True = 1, "yes"
				// False = 0, "no"
				$msgForNo = wfMessage( 'htmlform-no' )->text();
				if ( $curValue === '' || $curValue === null ) {
					// Do nothing.
				} elseif ( $curValue === 0
					|| $curValue === '0'
					|| strtolower( $curValue ) === 'no'
					|| strtolower( $curValue ) == strtolower( $msgForNo ) ) {
					$tableFieldValues[$fieldName] = '0';
				} else {
					$tableFieldValues[$fieldName] = '1';
				}
			}
		}

		// Add the "metadata" field values.
		$tableFieldValues['_pageName'] = $pageName;
		$tableFieldValues['_pageTitle'] = $pageTitle;
		$tableFieldValues['_pageNamespace'] = $pageNamespace;
		$tableFieldValues['_pageID'] = $pageID;

		// Allow other hooks to modify the values.
		// @todo(rnix): does anything use this? hate instantiating a Title here...
		Hooks::run( 'CargoBeforeStoreData', [ Title::newFromText( $pageName ), $tableName, &$tableSchema, &$tableFieldValues ] );

		$cdb = CargoUtils::getDB();

		// Determine whether we're using AUTO_INCREMENT
		// @todo(rnix): mssql/pgsql compat?
		$row = $cdb->selectRow(
			'INFORMATION_SCHEMA.TABLES',
			'AUTO_INCREMENT',
			[
				'TABLE_NAME' => $cdb->tablePrefix() . $tableName,
				'TABLE_SCHEMA' => $cdb->getDBname(),
			]
		);
		if ( $row == false || $row->AUTO_INCREMENT == null ) {
			// Set _ID manually if we're not using AUTO_INCREMENT.
			// This is likely to cause errors when cargo_store is being ran concurrently.
			$cdb->lockForUpdate( $tableName );
			$res = $cdb->select( $tableName, 'MAX(' .
				$cdb->addIdentifierQuotes( '_ID' ) . ') AS "ID"' );
			$row = $cdb->fetchRow( $res );
			$curRowID = $row['ID'] + 1;
			$tableFieldValues['_ID'] = $curRowID;
		} else {
			$curRowID = null;
		}

		// Somewhat of a @HACK - recreating a Cargo table from the web
		// interface can lead to duplicate rows, due to the use of jobs.
		// So before we store this data, check if a row with this
		// exact set of data is already in the database. If it is, just
		// ignore this #cargo_store call.
		// This is not ideal, because there can be valid duplicate
		// data - a page can have multiple calls to the same template,
		// with identical data, for various reasons. However, that's
		// a very rare case, while unwanted code duplication is
		// unfortunately a common case. So until there's a real
		// solution, this workaround will be helpful.
		$rowAlreadyExists = $this->doesRowAlreadyExist( $cdb, $page, $record, $table );
		if ( $rowAlreadyExists ) {
			return Status::newGood();
		}

		// The _position field was only added to list tables in Cargo
		// 2.1, which means that any list table last created or
		// re-created before then will not have that field. How to know
		// whether to populate that field? We go to the first list
		// table for this main table (there may be more than one), query
		// that field, and see whether it throws an exception. (We'll
		// assume that either all the list tables for this main table
		// have a _position field, or none do.)
		$hasPositionField = true;
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			if ( $fieldDescription->mIsList ) {
				$listFieldTableName = $tableName . '__' . $fieldName;
				$hasPositionField = $cdb->fieldExists( $listFieldTableName, '_position' );
				break;
			}
		}

		$fieldTableFieldValues = [];

		// For each field that holds a list of values, also add its
		// values to its own table; and rename the actual field.
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			if ( !array_key_exists( $fieldName, $tableFieldValues ) ) {
				continue;
			}
			$fieldType = $fieldDescription->mType;
			if ( $fieldDescription->mIsList ) {
				$fieldTableName = $tableName . '__' . $fieldName;
				$delimiter = $fieldDescription->getDelimiter();
				$individualValues = explode( $delimiter, $tableFieldValues[$fieldName] );
				$valueNum = 1;
				foreach ( $individualValues as $individualValue ) {
					$individualValue = trim( $individualValue );
					// Ignore blank values.
					if ( $individualValue == '' ) {
						continue;
					}
					$fieldValues = [
						'_rowID' => $curRowID,
						'_value' => $individualValue
					];
					if ( $hasPositionField ) {
						$fieldValues['_position'] = $valueNum++;
					}
					// For coordinates, there are two more
					// fields, for latitude and longitude.
					if ( $fieldType == 'Coordinates' ) {
						try {
							list( $latitude, $longitude ) = CargoUtils::parseCoordinatesString( $individualValue );
						} catch ( MWException $e ) {
							continue;
						}
						$fieldValues['_lat'] = $latitude;
						$fieldValues['_lon'] = $longitude;
					}
					// We could store these values in the DB
					// now, but we'll do it later, to keep
					// the transaction as short as possible.
					$fieldTableFieldValues[] = [ $fieldTableName, $fieldValues ];
				}

				// Now rename the field.
				$tableFieldValues[$fieldName . '__full'] = $tableFieldValues[$fieldName];
				unset( $tableFieldValues[$fieldName] );
			} elseif ( $fieldType == 'Coordinates' ) {
				try {
					list( $latitude, $longitude ) = CargoUtils::parseCoordinatesString( $tableFieldValues[$fieldName] );
				} catch ( MWException $e ) {
					unset( $tableFieldValues[$fieldName] );
					continue;
				}
				// Rename the field.
				$tableFieldValues[$fieldName . '__full'] = $tableFieldValues[$fieldName];
				unset( $tableFieldValues[$fieldName] );
				$tableFieldValues[$fieldName . '__lat'] = $latitude;
				$tableFieldValues[$fieldName . '__lon'] = $longitude;
			}
		}

		// Insert the current data into the main table.
		CargoUtils::escapedInsert( $cdb, $tableName, $tableFieldValues );
		if ( $curRowID == null ) {
			$curRowID = $cdb->insertId();
		}

		// Now, store the data for all the "field tables".
		foreach ( $fieldTableFieldValues as $tableNameAndValues ) {
			list( $fieldTableName, $fieldValues ) = $tableNameAndValues;
			// Update _rowID if using AUTO_INCREMENT.
			if ( array_key_exists( '_rowID', $fieldValues ) && $fieldValues['_rowID'] == null ) {
				$fieldValues['_rowID'] = $curRowID;
			}
			CargoUtils::escapedInsert( $cdb, $fieldTableName, $fieldValues );
		}

		// Also insert the names of any "attached" files into the
		// "files" helper table.
		$fileTableName = $tableName . '___files';
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			$fieldType = $fieldDescription->mType;
			if ( $fieldType != 'File' ) {
				continue;
			}
			if ( $fieldDescription->mIsList ) {
				$delimiter = $fieldDescription->getDelimiter();
				$individualValues = explode( $delimiter, $tableFieldValues[$fieldName . '__full'] );
				foreach ( $individualValues as $individualValue ) {
					$individualValue = trim( $individualValue );
					// Ignore blank values.
					if ( $individualValue == '' ) {
						continue;
					}
					$fileName = CargoUtils::removeNamespaceFromFileName( $individualValue );
					$fieldValues = [
						'_pageName' => $pageName,
						'_pageID' => $pageID,
						'_fieldName' => $fieldName,
						'_fileName' => $fileName
					];
					CargoUtils::escapedInsert( $cdb, $fileTableName, $fieldValues );
				}
			} else {
				$fullFileName = $tableFieldValues[$fieldName];
				if ( $fullFileName == '' ) {
					continue;
				}
				$fileName = CargoUtils::removeNamespaceFromFileName( $fullFileName );
				$fieldValues = [
					'_pageName' => $pageName,
					'_pageID' => $pageID,
					'_fieldName' => $fieldName,
					'_fileName' => $fileName
				];
				CargoUtils::escapedInsert( $cdb, $fileTableName, $fieldValues );
			}
		}

		return Status::newGood();
	}

	private function getDateValueAndPrecision( $dateStr, $fieldType ) {
		$precision = null;

		// Special handling if it's just a year. If it's a number and
		// less than 8 digits, assume it's a year (hey, it could be a
		// very large BC year). If it's 8 digits, it's probably a full
		// date in the form YYYYMMDD.
		if ( ctype_digit( $dateStr ) && strlen( $dateStr ) < 8 ) {
			// Add a fake date - it will get ignored later.
			return [ "$dateStr-01-01", self::YEAR_ONLY ];
		}

		// Determine if there's a month but no day. There's no ideal
		// way to do this, so: we'll just look for the total number of
		// spaces, slashes and dashes, and if there's exactly one
		// altogether, we'll guess that it's a month only.
		$numSpecialChars = substr_count( $dateStr, ' ' ) +
			substr_count( $dateStr, '/' ) + substr_count( $dateStr, '-' );
		if ( $numSpecialChars == 1 ) {
			// No need to add anything - PHP will set it to the
			// first of the month.
			$precision = self::MONTH_ONLY;
		} else {
			// We have at least a full date.
			if ( $fieldType == 'Date' ) {
				$precision = self::DATE_ONLY;
			}
		}

		$seconds = strtotime( $dateStr );
		// If the precision has already been set, then we know it
		// doesn't include a time value - we can set the value already.
		if ( $precision != null ) {
			// Put into YYYY-MM-DD format.
			return [ date( 'Y-m-d', $seconds ), $precision ];
		}

		// It's a Datetime field, which may or may not have a time -
		// check for that now.
		$datePortion = date( 'Y-m-d', $seconds );
		$timePortion = date( 'G:i:s', $seconds );
		// If it's not right at midnight, there's definitely a time
		// there.
		$precision = self::DATE_AND_TIME;
		if ( $timePortion !== '0:00:00' ) {
			return [ $datePortion . ' ' . $timePortion, $precision ];
		}

		// It's midnight, so chances are good that there was no time
		// specified, but how do we know for sure?
		// Slight @HACK - look for either "00" or "AM" (or "am") in the
		// original date string. If neither one is there, there's
		// probably no time.
		if ( strpos( $dateStr, '00' ) === false &&
			strpos( $dateStr, 'AM' ) === false &&
			strpos( $dateStr, 'am' ) === false ) {
			$precision = self::DATE_ONLY;
		}
		// Either way, we just need the date portion.
		return [ $datePortion, $precision ];
	}

	public function blankOrRejectBadData( CargoPage $page, Record $record ): Status {
		$tableFieldValues = $record->getFieldValues();
		$tableName = $record->getTable();
		$table = $this->tableFactory->get( $tableName );
		$tableSchema = $table->getSchema( true );

		foreach ( $tableFieldValues as $fieldName => $fieldValue ) {
			if ( !array_key_exists( $fieldName, $tableSchema->mFieldDescriptions ) ) {
				unset( $tableFieldValues[$fieldName] );
			}
		}

		$cdb = $this->databaseFactory->get();
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			if ( !array_key_exists( $fieldName, $tableFieldValues ) ) {
				continue;
			}
			$fieldValue = $tableFieldValues[$fieldName];
			if ( $fieldDescription->mIsMandatory && $fieldValue == '' ) {
				return "Mandatory field, \"$fieldName\", cannot have a blank value.";
			}
			if ( $fieldDescription->mIsUnique && $fieldValue != '' ) {
				$res = $cdb->select( $tableName, 'COUNT(*)', [ $fieldName => $fieldValue ] );
				$row = $cdb->fetchRow( $res );
				$numExistingValues = $row['COUNT(*)'];
				if ( $numExistingValues == 1 ) {
					$rowAlreadyExists = $this->doesRowAlreadyExist( $cdb, $page, $record, $table );
					if ( $rowAlreadyExists ) {
						$numExistingValues = 0;
					}
				}
				if ( $numExistingValues > 0 ) {
					$tableFieldValues[$fieldName] = null;
				}
			}
			if ( $fieldDescription->mRegex != null && !preg_match( '/^' . $fieldDescription->mRegex . '$/', $fieldValue ) ) {
				$tableFieldValues[$fieldName] = null;
			}
		}

		$record->setFieldValues( $tableFieldValues );

		return Status::newGood();
	}

	private function doesRowAlreadyExist( IDatabase $cdb, CargoPage $page, Record $record, CargoTable $table ): bool {
		$pageID = $page->getID();
		$tableFieldValues = $record->getFieldValues();
		$tableSchema = $table->getSchema( true );
		$tableFieldValuesForCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $pageID ];
		foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
			if ( !array_key_exists( $fieldName, $tableFieldValues ) ) {
				continue;
			}
			if ( $fieldDescription->mIsList || $fieldDescription->mType == 'Coordinates' ) {
				$quotedFieldName = $cdb->addIdentifierQuotes( $fieldName . '__full' );
			} else {
				$quotedFieldName = $cdb->addIdentifierQuotes( $fieldName );
			}
			$fieldValue = $tableFieldValues[$fieldName];

			if ( in_array( $fieldDescription->mType, [ 'Text', 'Wikitext', 'Searchtext' ] ) ) {
				// @HACK - for some reason, there are times
				// when, for long values, the check only works
				// if there's some kind of limit in place.
				// Rather than delve into that, we'll just
				// make sure to only check a (relatively large)
				// substring - which should be good enough.
				$fieldSize = 1000;
			} else {
				$fieldSize = $fieldDescription->getFieldSize();
			}

			if ( $fieldValue === '' ) {
				// Needed for correct SQL handling of blank values, for some reason.
				$fieldValue = null;
			} elseif ( $fieldSize != null && strlen( $fieldValue ) > $fieldSize ) {
				// In theory, this SUBSTR() call is not needed,
				// since the value stored in the DB won't be
				// greater than this size. But that's not
				// always true - there's the hack mentioned
				// above, plus some other cases.
				$quotedFieldName = "SUBSTR($quotedFieldName, 1, $fieldSize)";
				$fieldValue = mb_substr( $fieldValue, 0, $fieldSize );
			}

			$tableFieldValuesForCheck[$quotedFieldName] = $fieldValue;
		}
		$count = $cdb->selectRowCount( $table->getTableNameForUpdate(), '*', $tableFieldValuesForCheck );
		return $count > 0;
	}

	/**
	 * Insert rows from calls to the #cargo_store parser function logged in the
	 * parser output.
	 *
	 * @param ParserOutput $parserOutput
	 */
	public function storePageRecords( CargoPage $page ) {
		$cdb = $this->getDatabase();
		$cdb->begin( __METHOD__ );

		$this->deleteCargoTableData( $page );

		$pageDataTable = $this->getTable( '_pageData' );
		$this->deletePageTableRecords( $page, $pageDataTable );
		$this->storePageTableRecords( $page, $pageDataTable );

		$parserOutput = $page->getParserOutput();
		if ( $parserOutput == null ) {
			$cdb->commit( __METHOD__ );
			return;
		}

		$cargoStorage = $parserOutput->getExtensionData( 'CargoStorage' );
		foreach ( array_keys( $cargoStorage ) as $tableName ) {
			$table = $this->getTable( $tableName );
			$this->storePageTableRecords( $page, $table );
		}

		$cdb->commit( __METHOD__ );
	}

	/**
	 * Remove records stored by $page.
	 */
	public function deletePageRecords( CargoPage $page ) {
		$cdb = $this->getDatabase();
		$cdb->begin();
		$this->deleteCargoPageTableData( $page );
		$this->deleteCargoTableData( $page );
		$cdb->commit();
	}

	public function deletePageTableRecords( CargoPage $page, CargoTable $table ) {
		$cdb = $this->getDatabase();
		$cdbPageIDCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $page->getID() ];

		$tableName = $table->getTableNameForUpdate();

		// First, delete from the "field" tables.
		$fieldTableNames = $table->getFieldTables();
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

	public function storePageTableRecords( CargoPage $page, CargoTable $table ) {
		$records = $table->getRecordsForPage( $page );

		foreach ( $records as $record ) {
			$this->storeRecord( $page, $record );
		}
	}

	/**
	 * Remove data in user cargo tables stored by this page.
	 */
	public function deleteCargoTableData( CargoPage $page ) {
		$tables = $this->getTables( $page );
		foreach ( $tables as $table ) {
			$this->deletePageTableRecords( $page, $table );
		}
	}

	/**
	 * Remove data in the _pageData table stored by this page.
	 */
	public function deleteCargoPageTableData( CargoPage $page ) {
		$pageDataTable = $this->getTable( '_pageData' );
		$this->deletePageTableRecords( $page, $pageDataTable );
	}

	/**
	 * @return CargoTable[]
	 */
	private function getTables( CargoPage $page ) {
		$cdb = $this->getDatabase();
		$tableNames = $cdb->selectFieldValues(
			[ '_pageData', '_pageData___tables' ],
			'_pageData___tables._value',
			[
				'_pageID' => $page->getID(),
				'_pageData._ID = _pageData___tables._rowID',
			],
			__METHOD__
		);
		$tables = [];
		foreach ( $tableNames as $tableName ) {
			$tables[] = $this->getTable( $tableName );
		}
		return $tables;
	}

	/**
	 * @var string $tableName
	 * @return CargoTable
	 */
	private function getTable( string $tableName ): CargoTable {
		return $this->tableFactory->get( $tableName );
	}

	private function getDatabase(): IDatabase {
		return $this->databaseFactory->get();
	}

	public function storeFileData( CargoPage $page ) {
		$cdb = $this->getDatabase();
		$cdb->begin( __METHOD__ );

		$table = $this->getTable( '_fileData' );
		$this->deletePageTableRecords( $page, $table );
		$this->storePageTableRecords( $page, $table );

		$cdb->commit( __METHOD__ );
	}

	/**
	 * Begin a transaction before processing the first #cargo_store in a parse.
	 *
	 * This means that results of any #cargo_query which occurs before the
	 * first #cargo_store can vary depending on the data in the cargo tables
	 * at the start of the parse.
	 */
	public function beginTransaction( CargoPage $page, Record $record ) {
		$cdb = $this->getDatabase();
		if ( $cdb->trxLevel() ) {
			return;
		}
		$cdb->begin( __METHOD__ );

		// Delete rows for the page from cargo tables to avoid duplicating data.
		$this->deleteCargoTableData( $page );
	}

	/**
	 * Rollback the database transaction at the end of a parse.
	 */
	public function endTransaction() {
		$cdb = $this->getDatabase();
		if ( !$cdb->trxLevel() ) {
			return;
		}
		$cdb->rollback( __METHOD__ );
	}
}
