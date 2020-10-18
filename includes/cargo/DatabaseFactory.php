<?php

namespace Cargo;

use Config;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DatabaseDomain;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\ILoadBalancer;

class DatabaseFactory {
	/**
	 * @var string|null
	 */
	private $dbName;

	/**
	 * @var string|null
	 */
	private $dbPrefix;

	/**
	 * @var string|null
	 */
	private $dbCluster;

	/**
	 * @var ILBFactory
	 */
	private $lbFactory;

	/**
	 * @var IDatabase $db
	 */
	private $db;

	public function __construct(
		?string $dbName,
		?string $dbPrefix,
		?string $dbCluster,
		ILBFactory $lbFactory
	) {
		$this->dbName = $dbName;
		$this->dbPrefix = $dbPrefix;
		$this->dbCluster = $dbCluster;
		$this->lbFactory = $lbFactory;
	}

	/**
	 * @return IDatabase
	 */
	public function get(): IDatabase {
		if ( $this->db !== null && $this->db->isOpen() ) {
			return $this->db;
		}

		// Use a new Domain with the 'cargo__' prefix for tables.
		$localDomain = DatabaseDomain::newFromId( $this->lbFactory->getLocalDomainID() );
		$cargoDomain = new DatabaseDomain(
			$this->dbName ?? $localDomain->getDatabase(),
			$localDomain->getSchema(),
			$this->dbPrefix ?? ( $localDomain->getTablePrefix() . 'cargo__' )
		);

		// Use an external cluster if configured
		if ( empty( $this->dbCluster ) ) {
			$cargoLB = $this->lbFactory->getMainLB( $cargoDomain );
		} else {
			$cargoLB = $this->lbFactory->getExternalLB( $this->dbCluster );
		}

		// Use DB_MASTER for now -- @TODO: use DB_REPLICA where appropriate.
		//
		// Set CONN_TRX_AUTOCOMMIT since we're manually managing transaction
		// state, and since we don't need repeatable read for #cargo_query.
		//
		// The docs explicitly say to avoid using begin() on such connections;
		// however, we're a special case since we have exclusive control over
		// the connection to this domain.
		$this->db = $cargoLB->getConnection(
			DB_MASTER,
			[],
			$cargoDomain,
			ILoadBalancer::CONN_TRX_AUTOCOMMIT
		);
		return $this->db;
	}
}
