<?php

namespace Cargo;

class Record {
	/**
	 * @var string $tableName
	 */
	private $tableName;

	/**
	 * @var array $fieldValues
	 */
	private $fieldValues = [];

	public function __construct( string $tableName ) {
		$this->tableName = $tableName;
	}

	public function getTable(): string {
		return $this->tableName;
	}

	public function setField( string $name, $value ) {
		$this->fieldValues[$name] = $value;
	}

	public function getField( string $name ) {
		return $this->fieldValues[$name];
	}

	public function setFieldValues( array $values ) {
		$this->fieldValues = $values;
	}

	public function getFieldValues(): array {
		return $this->fieldValues;
	}
}
