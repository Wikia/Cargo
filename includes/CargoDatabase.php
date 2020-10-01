<?php
/**
 * CargoDatabase provides the singleton database connection for managing cargo
 * data.
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\DatabaseDomain;
use Wikimedia\Rdbms\ILoadBalancer;

class CargoDatabase {
	/**
	 * @var IDatabase $db
	 */
	private static $db = null;

	/**
	 * @return IDatabase
	 */
	public static function get() {
		if ( self::$db !== null && self::$db->isOpen() ) {
			return self::$db;
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		$cargoDBName = $config->get( 'CargoDBname' );
		$cargoDBPrefix = $config->get( 'CargoDBprefix' );
		$cargoDBCluster = $config->get( 'CargoDBcluster' );

		// Use a new Domain with the 'cargo__' prefix for tables.
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$localDomain = DatabaseDomain::newFromId( $lbFactory->getLocalDomainID() );
		$cargoDomain = new DatabaseDomain(
			$cargoDBName ?? $localDomain->getDatabase(),
			$localDomain->getSchema(),
			$cargoDBPrefix ?? ( $localDomain->getTablePrefix() . 'cargo__' )
		);

		// Use an external cluster if configured
		if ( empty( $cargoDBCluster ) ) {
			$cargoLB = $lbFactory->getMainLB( $cargoDomain );
		} else {
			$cargoLB = $lbFactory->getExternalLB( $cargoDBCluster );
		}

		// Use DB_MASTER for now -- @TODO: use DB_REPLICA where appropriate.
		//
		// Set CONN_TRX_AUTOCOMMIT since we're manually managing transaction
		// state, and since we don't need repeatable read for #cargo_query.
		//
		// The docs explicitly say to avoid using begin() on such connections;
		// however, we're a special case since we have exclusive control over
		// the connection to this domain.
		self::$db = $cargoLB->getConnection(
			DB_MASTER,
			[],
			$cargoDomain,
			ILoadBalancer::CONN_TRX_AUTOCOMMIT
		);
		return self::$db;
	}
}
