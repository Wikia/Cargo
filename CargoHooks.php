<?php

use Cargo\CargoPage;
use Cargo\Parser\CargoStore;
use Cargo\RecordStore;
use MediaWiki\MediaWikiServices;

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
		$recordStore = self::getRecordStore();
		$cargoStore = new CargoStore( $recordStore );

		$parser->setFunctionHook( 'cargo_declare', [ 'CargoDeclare', 'run' ] );
		$parser->setFunctionHook( 'cargo_attach', [ 'CargoAttach', 'run' ] );
		$parser->setFunctionHook( 'cargo_store', [ $cargoStore, 'run' ] );
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

	private static function getRecordStore(): RecordStore {
		return MediaWikiServices::getInstance()->getService( 'CargoRecordStore' );
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
		$page = new CargoPage( $title );
		$recordStore = self::getRecordStore();
		$recordStore->storePageRecords( $page, $parserOutput );
	}

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
		// This hook isn't needed if we're only beginning the tx at the first #cargo_store.
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
		self::getRecordStore()->endTransaction();
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
	public static function onArticleDeleteComplete(
		&$article, User &$user, $reason, $id, $content,
		ManualLogEntry $logEntry
	) {
		$title = $logEntry->getTarget();
		$cargoPage = new CargoPage( $title );
		self::getRecordStore()->deletePageRecords( $cargoPage );
		return true;
	}

	/**
	 * Called by the MediaWiki 'UploadComplete' hook.
	 *
	 * Updates a file's entry in the _fileData table if it has been
	 * uploaded or re-uploaded.
	 *
	 * @param UploadBase $upload
	 * @return bool true
	 */
	public static function onUploadComplete( $upload ) {
		$recordStore = self::getRecordStore();
		$page = new CargoPage( $upload->getTitle() );
		$page->setFile( $upload->getLocalFile() );
		$recordStore->storeFileData( $page );
		return true;
	}

	public static function describeDBSchema( DatabaseUpdater $updater ) {
		// DB updates
		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionTable( 'cargo_tables', __DIR__ . "/sql/Cargo.sql" );
		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_tables', __DIR__ . "/sql/Cargo.pg.sql", true ] );
		} elseif ( $updater->getDB()->getType() == 'mssql' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'cargo_tables', __DIR__ . "/sql/Cargo.mssql.sql", true ] );
		}

		// cargo_pages => _pageData migration
		$updater->addExtensionUpdate( [
			'runMaintenance',
			UpdateCargoPageData::class,
			__DIR__ . '/maintenance/updateCargoPageData.php'
		] );

		// Drop cargo_pages
		$updater->addExtensionUpdate( [ 'dropTable', 'cargo_pages' ] );

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
