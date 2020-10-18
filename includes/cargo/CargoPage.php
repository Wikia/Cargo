<?php

namespace Cargo;

use LocalFile;
use ParserOutput;
use Title;

class CargoPage {
	/**
	 * @var int Page ID
	 */
	private $id;

	/**
	 * @var Title Page title
	 */
	private $title;

	/**
	 * @var LocalFile|null File associated with the page, if it exists
	 */
	private $file;

	/**
	 * @var ParserOutput|null ParserOutput associated with the page, if it exists
	 */
	private $parserOutput;

	/**
	 * @param Title $title Page title
	 * @param int|null $pageID Article ID, will be loaded from database if null
	 */
	public function __construct( Title $title, $pageID = null ) {
		$this->id = $pageID ?? $title->getArticleID( Title::GAID_FOR_UPDATE );
		$this->title = $title;
		$this->name = $this->title->getPrefixedText();
		$this->namespace = $this->title->getNamespace();
	}

	/**
	 * @return int
	 */
	public function getID() {
		return $this->id;
	}

	/**
	 * @return Title
	 */
	public function getTitle() {
		return $this->title;
	}

	public function setFile( LocalFile $file ) {
		$this->file = $file;
	}

	public function setParserOutput( ParserOutput $parserOutput ) {
		$this->parserOutput = $parserOutput;
	}

	/**
	 * @return LocalFile|null
	 */
	public function getFile(): ?LocalFile {
		return $this->file;
	}

	/**
	 * @return ParserOutput|null
	 */
	public function getParserOutput(): ?ParserOutput {
		return $this->parserOutput;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->title->getPrefixedText();
	}

	/**
	 * @return string
	 */
	public function getTitleText() {
		return $this->title->getText();
	}

	/**
	 * @return int
	 */
	public function getNamespace() {
		return $this->title->getNamespace();
	}
}
