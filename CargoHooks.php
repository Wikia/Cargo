<?php

use MediaWiki\Revision\SlotRecord;

/**
 * CargoHooks class
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */
class CargoHooks {

	public static function registerExtension() {
		global $cgScriptPath, $wgScriptPath, $wgCargoFieldTypes, $wgHooks;

		define( 'CARGO_VERSION', '2.7' );

		// Script path.
		$cgScriptPath = $wgScriptPath . '/extensions/Cargo';

		$wgCargoFieldTypes = [
			'Page', 'String', 'Text', 'Integer', 'Float', 'Date',
			'Datetime', 'Boolean', 'Coordinates', 'Wikitext',
			'Searchtext', 'File', 'URL', 'Email', 'Rating'
		];

		if ( class_exists( 'MediaWiki\HookContainer\HookContainer' ) ) {
			// MW 1.35+
			$wgHooks['SidebarBeforeOutput'][] = "CargoPageValuesAction::addLink";
		} else {
			// MW < 1.35
			$wgHooks['BaseTemplateToolbox'][] = "CargoPageValuesAction::addLinkOld";
		}
	}

	public static function registerParserFunctions( &$parser ) {
		$parser->setFunctionHook( 'cargo_declare', [ 'CargoDeclare', 'run' ] );
		$parser->setFunctionHook( 'cargo_attach', [ 'CargoAttach', 'run' ] );
		$parser->setFunctionHook( 'cargo_store', [ 'CargoStore', 'run' ] );
		$parser->setFunctionHook( 'cargo_query', [ 'CargoQuery', 'run' ] );
		$parser->setFunctionHook( 'cargo_compound_query', [ 'CargoCompoundQuery', 'run' ] );
		$parser->setFunctionHook( 'recurring_event', [ 'CargoRecurringEvent', 'run' ] );
		$parser->setFunctionHook( 'cargo_display_map', [ 'CargoDisplayMap', 'run' ] );
		return true;
	}

	/**
	 * Add date-related messages to Global JS vars in user language
	 *
	 * @global int $wgCargoMapClusteringMinimum
	 * @param array &$vars Global JS vars
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function setGlobalJSVariables( array &$vars, OutputPage $out ) {
		global $wgCargoMapClusteringMinimum;

		$vars['wgCargoMapClusteringMinimum'] = $wgCargoMapClusteringMinimum;

		// Date-related arrays for the 'calendar' and 'timeline'
		// formats.
		// Built-in arrays already exist for month names, but those
		// unfortunately are based on the language of the wiki, not
		// the language of the user.
		$vars['wgCargoMonthNames'] = $out->getLanguage()->getMonthNamesArray();
		/**
		 * @TODO - should these be switched to objects with keys starting
		 *         from 1 to match month indexes instead of 0-index?
		 */
		array_shift( $vars['wgCargoMonthNames'] ); // start keys from 0

		$vars['wgCargoMonthNamesShort'] = $out->getLanguage()->getMonthAbbreviationsArray();
		array_shift( $vars['wgCargoMonthNamesShort'] ); // start keys from 0

		$vars['wgCargoWeekDays'] = [];
		$vars['wgCargoWeekDaysShort'] = [];
		for ( $i = 1; $i < 8; $i++ ) {
			$vars['wgCargoWeekDays'][] = $out->getLanguage()->getWeekdayName( $i );
			$vars['wgCargoWeekDaysShort'][] = $out->getLanguage()->getWeekdayAbbreviation( $i );
		}

		return true;
	}

	public static function registerModules( ResourceLoader &$resourceLoader ) {
		// A "shim" to allow 'oojs-ui-core' to be used as a module
		// even with MediaWiki versions (< 1.29) where it was not yet
		// defined.
		$cargoDir = __DIR__ . '/..';
		$moduleNames = $resourceLoader->getModuleNames();
		if ( in_array( 'oojs-ui-core', $moduleNames ) ) {
			return true;
		}

		$resourceLoader->register( array(
			'oojs-ui-core' => array(
				'localBasePath' => $cargoDir,
				'remoteExtPath' => 'Cargo',
				'dependencies' => 'oojs-ui'
			)
		) );
		return true;
	}

	/**
	 * Add the "purge cache" tab to actions
	 *
	 * @param SkinTemplate $skinTemplate
	 * @param array &$links
	 * @return bool
	 */
	public static function addPurgeCacheTab( SkinTemplate $skinTemplate, array &$links ) {
		// Only add this tab if Semantic MediaWiki (which has its
		// identical "refresh" tab) is not installed.
		if ( defined( 'SMW_VERSION' ) ) {
			return true;
		}

		if ( $skinTemplate->getUser()->isAllowed( 'purge' ) ) {
			$skinTemplate->getOutput()->addModules( 'ext.cargo.purge' );
			$links['actions']['cargo-purge'] = [
				'class' => false,
				'text' => $skinTemplate->msg( 'cargo-purgecache' )->text(),
				'href' => $skinTemplate->getTitle()->getLocalUrl( [ 'action' => 'purge' ] )
			];
		}

		return true;
	}

	public static function addTemplateFieldStart( $field, &$fieldStart ) {
		// If a generated template contains a field of type
		// 'Coordinates', add a #cargo_display_map call to the
		// display of that field.
		if ( $field->getFieldType() == 'Coordinates' ) {
			$fieldStart .= '{{#cargo_display_map:point=';
		}
		return true;
	}

	public static function addTemplateFieldEnd( $field, &$fieldEnd ) {
		// If a generated template contains a field of type
		// 'Coordinates', add (the end of) a #cargo_display_map call
		// to the display of that field.
		if ( $field->getFieldType() == 'Coordinates' ) {
			$fieldEnd .= '}}';
		}
		return true;
	}

	/**
	 * Deletes all Cargo data for a specific page - *except* data
	 * contained in Cargo tables which are read-only because their
	 * "replacement table" exists.
	 *
	 * @param int $pageID
	 * @todo - move this to a different class, like CargoUtils?
	 */
	public static function deletePageFromSystem( $pageID ) {
		// We'll delete every reference to this page in the
		// Cargo tables - in the data tables as well as in
		// cargo_pages. (Though we need the latter to be able to
		// efficiently delete from the former.)

		// Get all the "main" tables that this page is contained in.
		$dbw = wfGetDB( DB_MASTER );
		$cdb = CargoUtils::getDB();
		$cdb->begin();
		$cdbPageIDCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $pageID ];

		$tableNames = [];
		$res = $dbw->select( 'cargo_pages', 'table_name', [ 'page_id' => $pageID ] );
		while ( $row = $dbw->fetchRow( $res ) ) {
			$tableNames[] = $row['table_name'];
		}

		foreach ( $tableNames as $curMainTable ) {
			if ( $cdb->tableExists( $curMainTable . '__NEXT' ) ) {
				// It's a "read-only" table - ignore.
				continue;
			}

			// First, delete from the "field" tables.
			$res2 = $dbw->select( 'cargo_tables', 'field_tables', [ 'main_table' => $curMainTable ] );
			$row2 = $dbw->fetchRow( $res2 );
			$fieldTableNames = unserialize( $row2['field_tables'] );
			if ( is_array( $fieldTableNames ) ) {
				foreach ( $fieldTableNames as $curFieldTable ) {
					// Thankfully, the MW DB API already provides a
					// nice method for deleting based on a join.
					$cdb->deleteJoin(
						$curFieldTable,
						$curMainTable,
						$cdb->addIdentifierQuotes( '_rowID' ),
						$cdb->addIdentifierQuotes( '_ID' ),
						$cdbPageIDCheck
					);
				}
			}

			// Delete from the "files" helper table, if it exists.
			$curFilesTable = $curMainTable . '___files';
			if ( $cdb->tableExists( $curFilesTable ) ) {
				$cdb->delete( $curFilesTable, $cdbPageIDCheck );
			}

			// Now, delete from the "main" table.
			$cdb->delete( $curMainTable, $cdbPageIDCheck );
		}

		if ( $dbw->selectRowCount( 'cargo_tables', 'field_tables', [ 'main_table' => '_pageData' ] ) > 0 ) {
			$cdb->delete( '_pageData', $cdbPageIDCheck );
		}

		// End transaction and apply DB changes.
		$dbw->delete( 'cargo_pages', [ 'page_id' => $pageID ] );

		$cdb->commit();
	}

	/**
	 * LinksUpdate hook handler which does the actual writes to cargo tables
	 * for any #cargo_store calls on the parsed page.
	 *
	 * @param LinksUpdate &$linksUpdate
	 */
	public static function onLinksUpdate( LinksUpdate &$linksUpdate ) {
		$title = $linksUpdate->getTitle();
		$parserOutput = $linksUpdate->getParserOutput();
		CargoUtils::commitStoresForPage( $title, $parserOutput );
		return true;
	}

	/**
	 * Recursion through Content::getParserOutput should never happen, but if
	 * it does, this will protect against that.
	 */
	private static $parseDepth = 0;

	/**
	 * Called by Content::getParserOutput at the start of a top-level parse.
	 * Used to start a transaction on the cargo database.
	 *
	 * @param Content $content
	 * @param Title $title
	 * @param int $revId
	 * @param ParserOptions $options
	 * @param bool $generateHtml
	 * @param ParserOutput &$output
	 */
	public static function onContentGetParserOutput(
		Content $content,
		Title $title,
		$revId,
		ParserOptions $options,
		$generateHtml,
		ParserOutput &$output
	) {
		self::$parseDepth++;

		if ( self::$parseDepth > 1 ) {
			wfDebugLog( 'cargo', 'ContentGetParserOutput called recursively' );
		}
	}

	/**
	 * Called by Content::getParserOutput at the end of a top-level parse.
	 * Used to rollback the cargo database if any stores happened.
	 *
	 * @param Content $content
	 * @param Title $title
	 * @param ParserOutput $output
	 */
	public static function onContentAlterParserOutput(
		Content $content,
		Title $title,
		ParserOutput $output
	) {
		self::$parseDepth--;

		if ( self::$parseDepth == 0 ) {
			CargoStore::endTransaction();
		}
	}

	/**
	 * TitleMoveCompleting handler to update tracking tables after page moves.
	 *
	 * @param Title &$oldtitle Unused
	 * @param Title &$newtitle
	 * @param User &$user Unused
	 * @param int $pageid
	 * @param int $redirid Unused
	 * @param string $reason Unused
	 * @return bool
	 */
	public static function onTitleMoveCompleting( Title &$oldtitle, Title &$newtitle, User &$user, $pageid,
		$redirid, $reason ) {
		// For each main data table to which this page belongs, change
		// the page name-related fields.
		$newPageName = $newtitle->getPrefixedText();
		$newPageTitle = $newtitle->getText();
		$newPageNamespace = $newtitle->getNamespace();
		$dbw = wfGetDB( DB_MASTER );
		$cdb = CargoUtils::getDB();
		$cdb->begin();
		// We use $pageid, because that's the page ID - $redirid is the
		// ID of the newly created redirect page.
		$res = $dbw->select( 'cargo_pages', 'table_name', [ 'page_id' => $pageid ] );
		while ( $row = $dbw->fetchRow( $res ) ) {
			$curMainTable = $row['table_name'];
			$cdb->update( $curMainTable,
				[
					$cdb->addIdentifierQuotes( '_pageName' ) => $newPageName,
					$cdb->addIdentifierQuotes( '_pageTitle' ) => $newPageTitle,
					$cdb->addIdentifierQuotes( '_pageNamespace' ) => $newPageNamespace
				],
				[ $cdb->addIdentifierQuotes( '_pageID' ) => $pageid ]
			);
		}

		// Update the page title in the "general data" tables.
		$generalTables = [ '_pageData', '_fileData' ];
		foreach ( $generalTables as $generalTable ) {
			if ( $cdb->tableExists( $generalTable ) ) {
				$cdb->update( $generalTable,
					[
						$cdb->addIdentifierQuotes( '_pageName' ) => $newPageName,
						$cdb->addIdentifierQuotes( '_pageTitle' ) => $newPageTitle,
						$cdb->addIdentifierQuotes( '_pageNamespace' ) => $newPageNamespace
					],
					[ $cdb->addIdentifierQuotes( '_pageID' ) => $pageid ]
				);
			}
		}

		// End transaction and apply DB changes.
		$cdb->commit();

		return true;
	}

	/**
	 * Deletes all Cargo data about a page, if the page has been deleted.
	 */
	public static function onArticleDeleteComplete( &$article, User &$user, $reason, $id, $content,
		$logEntry ) {
		CargoUtils::deletePageFromSystem( $id );
		return true;
	}

	/**
	 * Called by the MediaWiki 'UploadComplete' hook.
	 *
	 * Updates a file's entry in the _fileData table if it has been
	 * uploaded or re-uploaded.
	 *
	 * @param Image $image
	 * @return bool true
	 */
	public static function onUploadComplete( $image ) {
		$cdb = CargoUtils::getDB();
		if ( !$cdb->tableExists( '_fileData' ) ) {
			return true;
		}

		$title = $image->getLocalFile()->getTitle();
		$useReplacementTable = $cdb->tableExists( '_fileData__NEXT' );
		$pageID = $title->getArticleID();
		$cdbPageIDCheck = [ $cdb->addIdentifierQuotes( '_pageID' ) => $pageID ];
		$fileDataTable = $useReplacementTable ? '_fileData__NEXT' : '_fileData';
		$cdb->delete( $fileDataTable, $cdbPageIDCheck );
		CargoFileData::storeValuesForFile( $title, $useReplacementTable );
		return true;
	}

	/**
	 * Called by the MediaWiki 'CategoryAfterPageAdded' hook.
	 *
	 * @param Category $category
	 * @param WikiPage $wikiPage
	 */
	public static function addCategoryToPageData( $category, $wikiPage ) {
		self::addOrRemoveCategoryData( $category, $wikiPage, true );
	}

	/**
	 * Called by the MediaWiki 'CategoryAfterPageRemoved' hook.
	 *
	 * @param Category $category
	 * @param WikiPage $wikiPage
	 */
	public static function removeCategoryFromPageData( $category, $wikiPage ) {
		self::addOrRemoveCategoryData( $category, $wikiPage, false );
	}

	/**
	 * We use hooks to modify the _categories field in _pageData, instead of
	 * saving it on page save as is done with all other fields (in _pageData
	 * and elsewhere), because the categories information is often not set
	 * until after the page has already been saved, due to the use of jobs.
	 * We can use the same function for both adding and removing categories
	 * because it's almost the same code either way.
	 * If anything gets messed up in this process, the data can be recreated
	 * by calling setCargoPageData.php.
	 */
	public static function addOrRemoveCategoryData( $category, $wikiPage, $isAdd ) {
		global $wgCargoPageDataColumns;
		if ( !in_array( 'categories', $wgCargoPageDataColumns ) ) {
			return true;
		}

		$cdb = CargoUtils::getDB();

		// We need to make sure that the "categories" field table
		// already exists, because we're only modifying it here, not
		// creating it.
		if ( $cdb->tableExists( '_pageData__NEXT___categories' ) ) {
			$pageDataTable = '_pageData__NEXT';
		} elseif ( $cdb->tableExists( '_pageData___categories' ) ) {
			$pageDataTable = '_pageData';
		} else {
			return true;
		}
		$categoriesTable = $pageDataTable . '___categories';
		$categoryName = $category->getName();
		$pageID = $wikiPage->getId();

		$cdb = CargoUtils::getDB();
		$cdb->begin();
		$res = $cdb->select( $pageDataTable, '_ID', [ '_pageID' => $pageID ] );
		if ( $cdb->numRows( $res ) == 0 ) {
			$cdb->commit();
			return true;
		}
		$row = $res->fetchRow();
		$rowID = $row['_ID'];
		$categoriesForPage = [];
		$res2 = $cdb->select( $categoriesTable, '_value',  [ '_rowID' => $rowID ] );
		while ( $row2 = $res2->fetchRow() ) {
			$categoriesForPage[] = $row2['_value'];
		}
		$categoryAlreadyListed = in_array( $categoryName, $categoriesForPage );
		// This can be done with a NOT XOR (i.e. XNOR), but let's not make it more confusing.
		if ( ( $isAdd && $categoryAlreadyListed ) || ( !$isAdd && !$categoryAlreadyListed ) ) {
			$cdb->commit();
			return true;
		}

		// The real operation is here.
		if ( $isAdd ) {
			$categoriesForPage[] = $categoryName;
		} else {
			foreach ( $categoriesForPage as $i => $cat ) {
				if ( $cat == $categoryName ) {
					unset( $categoriesForPage[$i] );
				}
			}
		}
		$newCategoriesFull = implode( '|', $categoriesForPage );
		$cdb->update( $pageDataTable, [ '_categories__full' => $newCategoriesFull ], [ '_pageID' => $pageID ] );
		if ( $isAdd ) {
			$res3 = $cdb->select( $categoriesTable, 'MAX(_position) as MaxPosition',  [ '_rowID' => $rowID ] );
			$row3 = $res3->fetchRow();
			$maxPosition = $row3['MaxPosition'];
			$cdb->insert( $categoriesTable, [ '_rowID' => $rowID, '_value' => $categoryName, '_position' => $maxPosition + 1 ] );
		} else {
			$cdb->delete( $categoriesTable, [ '_rowID' => $rowID, '_value' => $categoryName ] );
		}

		// End transaction and apply DB changes.
		$cdb->commit();
		return true;
	}

	public static function describeDBSchema( DatabaseUpdater $updater ) {
		// DB updates
		// For now, there's just a single SQL file for all DB types.

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionTable( 'cargo_tables', __DIR__ . "/sql/Cargo.sql" );
			$updater->addExtensionTable( 'cargo_pages', __DIR__ . "/sql/Cargo.sql" );
		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_tables', __DIR__ . "/sql/Cargo.pg.sql", true ] );
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_pages', __DIR__ . "/sql/Cargo.pg.sql", true ] );
		} elseif ( $updater->getDB()->getType() == 'mssql' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_tables', __DIR__ . "/sql/Cargo.mssql.sql", true ] );
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_pages', __DIR__ . "/sql/Cargo.mssql.sql", true ] );
		}
		return true;
	}

	/**
	 * Called by a hook in the Admin Links extension.
	 *
	 * @param ALTree &$adminLinksTree
	 * @return bool
	 */
	public static function addToAdminLinks( &$adminLinksTree ) {
		$browseSearchSection = $adminLinksTree->getSection(
			wfMessage( 'adminlinks_browsesearch' )->text() );
		$cargoRow = new ALRow( 'cargo' );
		$cargoRow->addItem( ALItem::newFromSpecialPage( 'CargoTables' ) );
		$cargoRow->addItem( ALItem::newFromSpecialPage( 'Drilldown' ) );
		$cargoRow->addItem( ALItem::newFromSpecialPage( 'CargoQuery' ) );
		$browseSearchSection->addRow( $cargoRow );

		return true;
	}

	/**
	 * Called by MediaWiki's ResourceLoaderStartUpModule::getConfig()
	 * to set static (not request-specific) configuration variables
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		global $cgScriptPath;

		$vars['cgDownArrowImage'] = "$cgScriptPath/drilldown/resources/down-arrow.png";
		$vars['cgRightArrowImage'] = "$cgScriptPath/drilldown/resources/right-arrow.png";

		return true;
	}

	public static function addLuaLibrary( $engine, &$extraLibraries ) {
		$extraLibraries['mw.ext.cargo'] = 'CargoLuaLibrary';
		return true;
	}

	public static function cargoSchemaUpdates( DatabaseUpdater $updater ) {
		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionField( 'cargo_tables', 'field_helper_tables', __DIR__ . '/sql/cargo_tables.patch.field_helper_tables.sql', true );
			$updater->dropExtensionIndex( 'cargo_tables', 'cargo_tables_template_id', __DIR__ . '/sql/cargo_tables.patch.index_template_id.sql', true );
		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionField( 'cargo_tables', 'field_helper_tables', __DIR__ . '/sql/cargo_tables.patch.field_helper_tables.pg.sql', true );
			$updater->dropExtensionIndex( 'cargo_tables', 'cargo_tables_template_id', __DIR__ . '/sql/cargo_tables.patch.index_template_id.pg.sql', true );
		} elseif ( $updater->getDB()->getType() == 'mssql' ) {
			$updater->addExtensionField( 'cargo_tables', 'field_helper_tables', __DIR__ . '/sql/cargo_tables.patch.field_helper_tables.mssql.sql', true );
			$updater->dropExtensionIndex( 'cargo_tables', 'cargo_tables_template_id', __DIR__ . '/sql/cargo_tables.patch.index_template_id.mssql.sql', true );
		}
		return true;
	}

}
