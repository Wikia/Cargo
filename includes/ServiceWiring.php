<?php

use Cargo\CargoTableFactory;
use Cargo\CargoTableStore;
use Cargo\DatabaseFactory;
use Cargo\DatabaseRecordStore;
use MediaWiki\MediaWikiServices;

return [
	'CargoDatabaseFactory' => function( MediaWikiServices $services ) {
		$config = $services->getMainConfig();
		return new DatabaseFactory(
			$config->get( 'CargoDBname' ),
			$config->get( 'CargoDBprefix' ),
			$config->get( 'CargoDBcluster' ),
			$services->getDBLoadBalancerFactory()
		);
	},
	'CargoTableFactory' => function( MediaWikiServices $services ) {
		return new CargoTableFactory(
			$services->getService( 'CargoTableStore' )
		);
	},
	'CargoTableStore' => function( MediaWikiServices $services ) {
		return new CargoTableStore(
			$services->getService( 'CargoDatabaseFactory' ),
			$services->getDBLoadBalancer()
		);
	},
	'CargoRecordStore' => function( MediaWikiServices $services ) {
		return new DatabaseRecordStore(
			$services->getService( 'CargoDatabaseFactory' ),
			$services->getService( 'CargoTableFactory' )
		);
	}
];