<?php

namespace Cargo\Parser;

use Cargo\CargoPage;
use Cargo\Record;
use Cargo\RecordStore;
use MWException;
use Parser;

/**
 * Class for the #cargo_store function.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoStore implements ParserFunction {
	private $recordStore;

	public function __construct( RecordStore $recordStore ) {
		$this->recordStore = $recordStore;
	}

	/**
	 * Handles the #cargo_store parser function - saves data for one
	 * template call.
	 *
	 * @param Parser &$parser
	 * @throws MWException
	 */
	public function run( Parser &$parser ) {
		$tableName = '';
		$tableFieldValues = [];
		$params = func_get_args();
		array_shift( $params ); // we already know the $parser...
		foreach ( $params as $param ) {
			$parts = explode( '=', $param, 2 );

			if ( count( $parts ) != 2 ) {
				continue;
			}
			$key = trim( $parts[0] );
			$value = trim( $parts[1] );
			if ( $key == '_table' ) {
				$tableName = $value;
			} else {
				// Since we don't know whether any empty
				// value is meant to be blank or null, let's
				// go with null.
				if ( $value == '' ) {
					$value = null;
				}
				$tableFieldValues[$key] = $value;
			}
		}

		if ( $tableName == '' ) {
			return;
		}

		$record = new Record( $tableName );
		$record->setFieldValues( $tableFieldValues );

		$page = new CargoPage( $parser->getTitle() );

		// @todo(rnix): encapsulate parseroutput handling in the same place as it's read (currently RecordStore)
		$parserOutput = $parser->getOutput();

		// @todo(rnix): move this to storeRecord where the first store that actually changes data happens
		// also means not deleting until then, and tracking deleted stores properly
		$this->recordStore->beginTransaction( $page, $record );

		$status = $this->recordStore->blankOrRejectBadData( $page, $record );
		if ( !$status->isGood() ) {
			// @todo(rnix) $errors shouldn't be stored as the value for a page_prop. this should probably be extensionData, since pagevalues can get that fine
			// @todo(rnix) dedup with below
			$parser->addTrackingCategory( 'cargo-store-error-tracking-category' );
			$errors = $status->getErrors();
			$parserOutput->setProperty( 'CargoStorageError', $errors );
			wfDebugLog( 'cargo', "CargoStore::run() - skipping; storage error encountered.\n" );
			return;
		}

		$status = $this->recordStore->storeRecord( $page, $record );
		if ( !$status->isGood() ) {
			$parser->addTrackingCategory( 'cargo-store-error-tracking-category' );
			$errors = $status->getErrors();
			$parserOutput->setProperty( 'CargoStorageError', $errors );
		} else {
			$cargoStorage = $parserOutput->getExtensionData( 'CargoStorage' ) ?? [];
			$cargoStorage[$record->getTable()][] = $record;
			$parserOutput->setExtensionData( 'CargoStorage', $cargoStorage );
		}
	}
}
