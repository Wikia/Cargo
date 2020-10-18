<?php

namespace Cargo\Parser;

use Parser;

interface ParserFunction {
	/**
	 * @param Parser &$parser
	 */
	public function run( Parser &$parser );
}
