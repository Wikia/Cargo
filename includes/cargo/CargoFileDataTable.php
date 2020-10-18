<?php

namespace Cargo;

use CargoFieldDescription;
use CargoTableSchema;

class CargoFileDataTable extends CargoTable {
	public function __construct( CargoTableStore $tableStore ) {
		parent::__construct( $tableStore, '_fileData' );
	}

	public static function getSchemaForCreation() {
		global $wgCargoFileDataColumns;

		$fieldTypes = [];

		if ( in_array( 'mediaType', $wgCargoFileDataColumns ) ) {
			$fieldTypes['_mediaType'] = [ 'type' => 'String' ];
		}
		if ( in_array( 'path', $wgCargoFileDataColumns ) ) {
			$fieldTypes['_path'] = [ 'type' => 'String', 'hidden' => true ];
		}
		if ( in_array( 'lastUploadDate', $wgCargoFileDataColumns ) ) {
			$fieldTypes['_lastUploadDate'] = [ 'type' => 'Datetime' ];
		}
		if ( in_array( 'fullText', $wgCargoFileDataColumns ) ) {
			$fieldTypes['_fullText'] = [ 'type' => 'Searchtext' ];
		}
		if ( in_array( 'numPages', $wgCargoFileDataColumns ) ) {
			$fieldTypes['_numPages'] = [ 'type' => 'Integer' ];
		}

		$tableSchema = new CargoTableSchema();
		foreach ( $fieldTypes as $field => $fieldVals ) {
			$fieldDesc = new CargoFieldDescription();
			foreach ( $fieldVals as $fieldKey => $fieldVal ) {
				if ( $fieldKey == 'type' ) {
					$fieldDesc->mType = $fieldVal;
				} elseif ( $fieldKey == 'list' ) {
					// Not currently used.
					$fieldDesc->mIsList = true;
					$fieldDesc->setDelimiter( '|' );
				} elseif ( $fieldKey == 'hidden' ) {
					$fieldDesc->mIsHidden = true;
				}
			}
			$tableSchema->mFieldDescriptions[$field] = $fieldDesc;
		}

		return $tableSchema;
	}

	public function getRecordsForPage( CargoPage $page ) {
		global $wgCargoPDFToText, $wgCargoPDFInfo;

		// Exit if we're not in the File namespace.
		if ( $page->getNamespace() != NS_FILE ) {
			return [];
		}

		$tableSchema = $this->getSchema( true );
		if ( $tableSchema == null ) {
			return [];
		}

		$file = $page->getFile();
		if ( $file == null ) {
			return [];
		}

		$fileDataValues = [];

		if ( $tableSchema->hasField( '_mediaType' ) ) {
			$fileDataValues['_mediaType'] = $file->getMimeType();
		}

		if ( $tableSchema->hasField( '_path' ) ) {
			$fileDataValues['_path'] = $file->getLocalRefPath();
		}

		if ( $tableSchema->hasField( '_lastUploadDate' ) ) {
			$fileDataValues['_lastUploadDate'] = $file->getTimestamp();
		}

		if ( $tableSchema->hasField( '_fullText' ) ) {
			if ( $wgCargoPDFToText == '' ) {
				// Display an error message?
			} elseif ( $file->getMimeType() != 'application/pdf' ) {
				// We only handle PDF files.
			} else {
				// Copied in part from the PdfHandler extension.
				$filePath = $file->getLocalRefPath();
				$cmd = wfEscapeShellArg( $wgCargoPDFToText ) . ' ' . wfEscapeShellArg( $filePath ) . ' - ';
				$retval = '';
				$txt = wfShellExec( $cmd, $retval );
				if ( $retval == 0 ) {
					$txt = str_replace( "\r\n", "\n", $txt );
					$txt = str_replace( "\f", "\n\n", $txt );
					$fileDataValues['_fullText'] = $txt;
				}
			}
		}

		if ( $tableSchema->hasField( '_numPages' ) ) {
			if ( $wgCargoPDFInfo == '' ) {
				// Display an error message?
			} elseif ( $file->getMimeType() != 'application/pdf' ) {
				// We only handle PDF files.
			} else {
				$filePath = $file->getLocalRefPath();
				$cmd = wfEscapeShellArg( $wgCargoPDFInfo ) . ' ' . wfEscapeShellArg( $filePath );
				$retval = '';
				$txt = wfShellExec( $cmd, $retval );
				if ( $retval == 0 ) {
					$lines = explode( PHP_EOL, $txt );
					$matched = preg_grep( '/^Pages\:/', $lines );
					foreach ( $matched as $line ) {
						$fileDataValues['_numPages'] = intval( trim( substr( $line, 7 ) ) );
					}
				}
			}
		}

		$record = new Record( $this->getTableName() );
		$record->setFieldValues( $fileDataValues );
		return [ $record ];
	}
}
