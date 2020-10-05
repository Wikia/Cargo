<?php

use MediaWiki\MediaWikiServices;

class CargoPageDataTable extends CargoTable {
	public function __construct() {
		parent::__construct( '_pageData' );
	}

	public static function getSchemaForCreation() {
		global $wgCargoPageDataColumns;

		$fieldTypes = [];

		if ( in_array( 'creationDate', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_creationDate'] = [ 'Datetime', false ];
		}
		if ( in_array( 'modificationDate', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_modificationDate'] = [ 'Datetime', false ];
		}
		if ( in_array( 'creator', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_creator'] = [ 'String', false ];
		}
		if ( in_array( 'fullText', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_fullText'] = [ 'Searchtext', false ];
		}
		if ( in_array( 'categories', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_categories'] = [ 'String', true ];
		}
		if ( in_array( 'numRevisions', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_numRevisions'] = [ 'Integer', false ];
		}
		if ( in_array( 'isRedirect', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_isRedirect'] = [ 'Boolean', false ];
		}
		if ( in_array( 'pageNameOrRedirect', $wgCargoPageDataColumns ) ) {
			$fieldTypes['_pageNameOrRedirect'] = [ 'String', false ];
		}
		$fieldTypes['_cargoTables'] = [ 'String', true ];

		$tableSchema = new CargoTableSchema();
		foreach ( $fieldTypes as $field => $fieldVals ) {
			list( $type, $isList ) = $fieldVals;
			$fieldDesc = new CargoFieldDescription();
			$fieldDesc->mType = $type;
			if ( $isList ) {
				$fieldDesc->mIsList = true;
				$fieldDesc->setDelimiter( '|' );
			}
			$tableSchema->mFieldDescriptions[$field] = $fieldDesc;
		}

		return $tableSchema;
	}

	public function storeDataForPage( CargoPage $page, ParserOutput $parserOutput ) {
		$title = $page->getTitle();
		if ( $title == null ) {
			return;
		}

		$tableSchema = $this->getSchema( true );
		if ( $tableSchema == null ) {
			return;
		}

		$wikiPage = WikiPage::factory( $title );
		$pageDataValues = [];

		if ( $tableSchema->hasField( '_creationDate' ) || $tableSchema->hasField( '_creator' ) ) {
			if ( method_exists( 'MediaWiki\Revision\RevisionLookup', 'getFirstRevision' ) ) {
				// MW >= 1.35
				$firstRevision = MediaWikiServices::getInstance()->getRevisionLookup()->getFirstRevision( $title );
			} else {
				$firstRevision = $title->getFirstRevision();
			}
		}

		if ( $tableSchema->hasField( '_creationDate' ) ) {
			if ( $firstRevision == null ) {
				// This can sometimes happen.
				$pageDataValues['_creationDate'] = null;
			} else {
				$pageDataValues['_creationDate'] = $firstRevision->getTimestamp();
			}
		}

		if ( $tableSchema->hasField( '_modificationDate' ) ) {
			$pageDataValues['_modificationDate'] = $parserOutput->getTimestamp();
		}

		if ( $tableSchema->hasField( '_creator' ) ) {
			$pageDataValues['_creator'] = $wikiPage->getCreator();
		}

		if ( $tableSchema->hasField( '_fullText' ) ) {
			$pageDataValues['_fullText'] = ContentHandler::getContentText( $wikiPage->getContent() );
		}

		if ( $tableSchema->hasField( '_categories' ) ) {
			$pageCategoriesString = implode( '|', $parserOutput->getCategoryLinks() );
			$pageDataValues['_categories'] = $pageCategoriesString;
		}

		if ( $tableSchema->hasField( '_numRevisions' ) ) {
			$dbw = wfGetDB( DB_MASTER );
			$count = $dbw->selectRowCount(
				'revision',
				'*',
				[ 'rev_page' => $title->getArticleID() ],
				__METHOD__
			);
			$pageDataValues['_numRevisions'] = $count;
		}

		if ( $tableSchema->hasField( '_isRedirect' ) ) {
			$pageDataValues['_isRedirect'] = ( $title->isRedirect() ? 1 : 0 );
		}

		if ( $tableSchema->hasField( '_pageNameOrRedirect' ) ) {
			if ( $title->isRedirect() ) {
				$redirTitle = $wikiPage->getRedirectTarget();
				$pageDataValues['_pageNameOrRedirect'] = $redirTitle->getPrefixedText();
			} else {
				$pageDataValues['_pageNameOrRedirect'] = $title->getPrefixedText();
			}
		}

		CargoStore::storeAllData( $title, $this->getTableNameForUpdate(), $pageDataValues, $tableSchema );
	}
}
