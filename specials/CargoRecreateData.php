<?php
/**
 * Displays an interface to let users recreate data via the Cargo
 * extension.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoRecreateData extends UnlistedSpecialPage {
	public $mTemplateTitle;
	public $mTableName;
	public $mIsDeclared;

	function __construct( $templateTitle, $tableName, $isDeclared ) {
		parent::__construct( 'RecreateData', 'recreatecargodata' );
		$this->mTemplateTitle = $templateTitle;
		$this->mTableName = $tableName;
		$this->mIsDeclared = $isDeclared;
	}

	function execute( $query = null ) {
		global $wgScriptPath, $cgScriptPath;

		// Check permissions.
		if ( !$this->getUser()->isAllowed( 'recreatecargodata' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$out = $this->getOutput();
		$out->enableOOUI();

		$this->setHeaders();

		$cdb = CargoUtils::getDB();
		$tableExists = $cdb->tableExists( $this->mTableName );
		if ( !$tableExists ) {
			$out->setPageTitle( $this->msg( 'cargo-createdatatable' )->parse() );
		}

		// Disable page if "replacement table" exists.
		$possibleReplacementTable = $this->mTableName . '__NEXT';
		if ( $cdb->tableExists( $possibleReplacementTable ) ) {
			$text = $this->msg( 'cargo-recreatedata-replacementexists', $this->mTableName, $possibleReplacementTable )->parse();
			$ctURL = SpecialPage::getTitleFor( 'CargoTables' )->getFullURL();
			$viewURL = $ctURL . '/' . $this->mTableName;
			$viewURL .= strpos( $viewURL, '?' ) ? '&' : '?';
			$viewURL .= "_replacement";
			$viewReplacementText = $this->msg( 'cargo-cargotables-viewreplacementlink' )->parse();

			$text .= ' (' . Xml::element( 'a', array( 'href' => $viewURL ), $viewReplacementText ) . ')';
			$out->addHTML( $text );
			return true;
		}

		if ( empty( $this->mTemplateTitle ) ) {
			// No template.
			// TODO - show an error message.
			return true;
		}

		$out->addModules( 'ext.cargo.recreatedata' );

		$templateData = array();
		$dbw = wfGetDB( DB_MASTER );

		$templateData[] = array(
			'name' => $this->mTemplateTitle->getText(),
			'numPages' => $this->getNumPagesThatCallTemplate( $dbw, $this->mTemplateTitle )
		);

		if ( $this->mIsDeclared ) {
			// Get all attached templates.
			$res = $dbw->select( 'page_props',
				array(
					'pp_page'
				),
				array(
					'pp_value' => $this->mTableName,
					'pp_propname' => 'CargoAttachedTable'
				)
			);
			while ( $row = $dbw->fetchRow( $res ) ) {
				$templateID = $row['pp_page'];
				$attachedTemplateTitle = Title::newFromID( $templateID );
				$numPages = $this->getNumPagesThatCallTemplate( $dbw, $attachedTemplateTitle );
				$attachedTemplateName = $attachedTemplateTitle->getText();
				$templateData[] = array(
					'name' => $attachedTemplateName,
					'numPages' => $numPages
				);
			}
		}

		$ct = SpecialPage::getTitleFor( 'CargoTables' );
		$viewTableURL = $ct->getInternalURL() . '/' . $this->mTableName;

		$out->addJsConfigVars( 'cargoScriptPath', $cgScriptPath );
		$out->addJsConfigVars( 'cargoTableName', $this->mTableName );
		$out->addJsConfigVars( 'cargoIsDeclared', $this->mIsDeclared );
		$out->addJsConfigVars( 'cargoViewTableUrl', $viewTableURL );
		$out->addJsConfigVars( 'cargoTemplateData', $templateData );

		// Simple form.
		$text = '<div id="recreateDataCanvas">' . "\n";
		if ( $tableExists ) {
			// Possibly disable checkbox, to avoid problems if the
			// DB hasn't been updated for version 1.5+.
			$indexExists = $dbw->indexExists( 'cargo_tables', 'cargo_tables_template_id' );
			if ( $indexExists ) {
				$text .= '<p><em>The checkbox intended to go here is temporarily disabled; please run <tt>update.php</tt> to see it.</em></p>';
			} else {
				$checkBox = new OOUI\FieldLayout(
					new OOUI\CheckboxInputWidget( array(
						'name' => 'createReplacement',
						'selected' => true,
						'value' => 1,
					) ),
					array(
						'label' => $this->msg( 'cargo-recreatedata-createreplacement' )->parse(),
						'align' => 'inline',
						'infusable' => true,
					)
				);
				$text .= Html::rawElement( 'p', null, $checkBox );
			}
		}
		$msg = $tableExists ? 'cargo-recreatedata-desc' : 'cargo-recreatedata-createdata';
		$text .= Html::element( 'p', null, $this->msg( $msg )->parse() );
		$text .= new OOUI\ButtonInputWidget( array(
			'id' => 'cargoSubmit',
			'label' => $this->msg( 'ok' )->parse(),
			'flags' => array( 'primary', 'progressive' )
		 ) );
		$text .= "\n</div>";

		$out->addHTML( $text );

		return true;
	}

	function getNumPagesThatCallTemplate( $dbw, $templateTitle ) {
		$res = $dbw->select(
			array( 'page', 'templatelinks' ),
			'COUNT(*) AS total',
			array(
				"tl_from=page_id",
				"tl_namespace" => $templateTitle->getNamespace(),
				"tl_title" => $templateTitle->getDBkey() ),
			__METHOD__,
			array()
		);
		$row = $dbw->fetchRow( $res );
		return intval( $row['total'] );
	}
}
