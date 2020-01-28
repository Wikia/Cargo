<?php
/**
 * Handles the 'recreatedata' action.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoRecreateDataAction extends Action {
	/**
	 * Return the name of the action this object responds to
	 * @return String lowercase
	 */
	public function getName() {
		return 'recreatedata';
	}

	/**
	 * The main action entry point. Do all output for display and send it
	 * to the context output.
	 * $this->getOutput(), etc.
	 */
	public function show() {
		$title = $this->page->getTitle();

		// These tabs should only exist for template pages, that
		// either call (or called) #cargo_declare, or call
		// #cargo_attach.
		list( $tableName, $isDeclared ) = CargoUtils::getTableNameForTemplate( $title );

		if ( $tableName == '' ) {
			// @TODO - display an error message here.
			return;
		}

		$recreateDataPage = new CargoRecreateData( $title, $tableName, $isDeclared );
		$recreateDataPage->execute();
	}

	/**
	 * Adds an "action" (i.e., a tab) to recreate the current article's data
	 *
	 * @param Title $obj
	 * @param array &$links
	 * @return bool
	 */
	static function displayTab( $obj, &$links ) {
		$title = $obj->getTitle();
		if ( !$title || $title->getNamespace() !== NS_TEMPLATE ||
			!$title->userCan( 'recreatecargodata' ) ) {
			return true;
		}
		$request = $obj->getRequest();

		// Make sure that this is a template page, that it either
		// has (or had) a #cargo_declare call or has a #cargo_attach
		// call, and that the user is allowed to recreate its data.
		list( $tableName, $isDeclared ) = CargoUtils::getTableNameForTemplate( $title );
		if ( $tableName == '' ) {
			return true;
		}

		// Check if table already exists, and set tab accordingly.
		$cdb = CargoUtils::getDB();
		if ( $cdb->tableExists( $tableName ) ) {
			$recreateDataTabMsg = 'recreatedata';
		} else {
			$recreateDataTabMsg = 'cargo-createdatatable';
		}

		$recreateDataTab = array(
			'class' => ( $request->getVal( 'action' ) == 'recreatedata' ) ? 'selected' : '',
			'text' => $obj->msg( $recreateDataTabMsg )->parse(),
			'href' => $title->getLocalURL( 'action=recreatedata' )
		);

		$links['views']['recreatedata'] = $recreateDataTab;

		return true;
	}

}
