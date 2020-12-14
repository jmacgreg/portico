<?php

/**
 * @file plugins/importexport/portico/PorticoExportDom.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportDom
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin DOM functions for export
 */

import('lib.pkp.classes.xml.XMLCustomWriter');

class PorticoExportDom extends XMLCustomWriter {
	/** @var string DTD URL of the exported XML */
	private const PUBMED_DTD_URL = 'http://dtd.nlm.nih.gov/archiving/3.0/archivearticle3.dtd';

	/** @var string DTD ID of the exported XML */
	private const PUBMED_DTD_ID = '-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN';

	/** @var Context Context */
	private $_context;

	/** @var Issue Issue */
	private $_issue;

	/** @var PublishedArticle Article */
	private $_article;

	/** @var DOMElement Document node */
	private $_document;

	/**
	 * Constructor
	 * @param Context $journal Journal
	 * @param Issue $issue Issue
	 * @param PublishedArticle $article Article
	 */
	public function __construct(Journal $context, Issue $issue, PublishedArticle $article)
	{
		$this->_context = $context;
		$this->_issue = $issue;
		$this->_article = $article;
		$this->_document = $this->createDocument('article', self::PUBMED_DTD_ID, self::PUBMED_DTD_URL);
		$articleNode = $this->_buildArticle();
		$this->appendChild($this->_document, $articleNode);
	}

	/**
	 * Serializes the document
	*/
	public function __toString() : string {
		return $this->getXML($this->_document);
	}

	/**
	 * Generate the Article node.
	 * @return DOMElement
	 */
	private function _buildArticle() : DOMElement {
		$journal = $this->_context;
		$doc = $this->_document;
		$article = $this->_article;
		$issue = $this->_issue;

		/* --- Article --- */
		$root = $this->createElement($doc, 'article');
		$this->setAttribute($root, 'xmlns:xlink', 'http://www.w3.org/1999/xlink');

		/* --- Front --- */
		$articleNode = $this->createElement($doc, 'front');
		$this->appendChild($root, $articleNode);

		/* --- Journal --- */
		$journalMetaNode = $this->createElement($doc, 'journal-meta');
		$this->appendChild($articleNode, $journalMetaNode);

		// journal-id
		$this->createChildWithText($doc, $journalMetaNode, 'journal-id', $journal->getLocalizedSetting('abbreviation'), false);

		//journal-title-group
		$journalTitleGroupNode = $this->createElement($doc, 'journal-title-group');
		$this->appendChild($journalMetaNode, $journalTitleGroupNode);

		// journal-title
		$this->createChildWithText($doc, $journalTitleGroupNode, 'journal-title', $journal->getLocalizedPageHeaderTitle());

		// issn
		foreach (['printIssn', 'issn', 'onlineIssn'] as $name) {
			if ($issn = $journal->getSetting($name)) {
				$this->createChildWithText($doc, $journalMetaNode, 'issn', $issn);
				break;
			}
		}

		// publisher
		$publisherNode = $this->createElement($doc, 'publisher');
		$this->appendChild($journalMetaNode, $publisherNode);

		// publisher-name
		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$publisherNameNode = $this->createChildWithText($doc, $publisherNode, 'publisher-name', $publisherInstitution);

		/* --- End Journal --- */

		/* --- Article-meta --- */
		$articleMetaNode = $this->createElement($doc, 'article-meta');
		$this->appendChild($articleNode, $articleMetaNode);

		// article-id (DOI)
		if ($doiNode = $this->createChildWithText($doc, $articleMetaNode, 'article-id', $article->getStoredPubId('doi'), false)) {
			$this->setAttribute($doiNode, 'pub-id-type', 'doi');
		}

		// article-title
		$titleGroupNode = $this->createElement($doc, 'title-group');
		$this->appendChild($articleMetaNode, $titleGroupNode);
		$this->createChildWithText($doc, $titleGroupNode, 'article-title', $article->getLocalizedTitle());

		// authors
		$authorsNode = $this->_buildAuthors();
		$this->appendChild($articleMetaNode, $authorsNode);

		if ($datePublished = $article->getDatePublished() ?: $issue->getDatePublished()) {
			$dateNode = $this->_buildPubDate(new DateTimeImmutable($datePublished));
			$this->appendChild($articleMetaNode, $dateNode);
		}

		// volume, issue, etc.
		$this->createChildWithText($doc, $articleMetaNode, 'volume', $issue->getVolume(), false);
		$this->createChildWithText($doc, $articleMetaNode, 'issue', $issue->getNumber(), false);
		$this->_buildPages($articleMetaNode);

		/* --- ArticleIdList --- */
		// Pubmed will accept two types of article identifier: pii and doi
		// how this is handled is journal-specific, and will require either
		// configuration in the plugin, or an update to the core code.
		// this is also related to DOI-handling within OJS
		if ($publisherId = $article->getStoredPubId('publisher-id')) {
			$articleIdListNode = $this->createElement($doc, 'ArticleIdList');
			$this->appendChild($articleNode, $articleIdListNode);

			$articleIdNode = $this->createChildWithText($doc, $articleIdListNode, 'article-id', $publisherId);
			$this->setAttribute($articleIdNode, 'pub-id-type', 'publisher');
		}

		// galley links
		foreach ($article->getGalleys() as $galley) {
			$isRemote = !!$galley->getRemoteURL();
			$url = $isRemote ? $galley->getRemoteURL() : $galley->getFile()->getClientFileName();
			$selfUriNode = $this->createChildWithText($doc, $articleMetaNode, 'self-uri', $url);
			$this->setAttribute($selfUriNode, 'xlink:href', $url);
			if (!$isRemote) {
				$this->setAttribute($selfUriNode, 'content-type', $galley->getFileType());
			}
		}

		/* --- Abstract --- */
		if ($abstract = $article->getLocalizedAbstract()) {
			$abstractNode = $this->createElement($doc, 'abstract');
			$this->appendChild($articleMetaNode, $abstractNode);
			$this->createChildWithText($doc, $abstractNode, 'p', strip_tags($abstract), false);
		}

		return $root;
	}

	/**
	 * Generate the Author node DOM for the specified author.
	 * @param Author $author Author
	 * @return DOMElement
	 */
	private function _buildAuthor(Author $author) : DOMElement {
		$doc = $this->_document;
		$locale = AppLocale::getLocale();

		$root = $this->createElement($doc, 'contrib');
		$this->setAttribute($root, 'contrib-type', 'author');

		$nameNode = $this->createElement($doc, 'name');
		$this->appendChild($root, $nameNode);

		$this->createChildWithText($doc, $nameNode, 'surname', $author->getLocalizedFamilyName($locale));
		$this->createChildWithText($doc, $nameNode, 'given-names', $author->getLocalizedGivenName($locale));

		$affiliation = $author->getLocalizedAffiliation();
		if (is_array($affiliation)) {
			$affiliation = reset($affiliation);
		}
		$this->createChildWithText($doc, $root, 'aff', $affiliation, false);
		$this->createChildWithText($doc, $root, 'uri', $author->getUrl(), false);
		if ($orcidNode = $this->createChildWithText($doc, $root, 'contrib-id', $author->getOrcid(), false)) {
			$this->setAttribute($orcidNode, 'contrib-id-type', 'orcid');
		}

		$this->createChildWithText($doc, $root, 'email', $author->getEmail(), false);
		if ($bio = $author->getLocalizedBiography()) {
			$bioNode = $this->createElement($doc, 'bio');
			$this->appendChild($root, $bioNode);
			$this->createChildWithText($doc, $bioNode, 'p', strip_tags($bio), false);
		}
		
		if ($country = $author->getCountry()) {
			$addressNode = $this->createElement($doc, 'address');
			$this->createChildWithText($doc, $addressNode, 'country', $country);
		}

		return $root;
	}

	/**
	 * Creates pub-date node
	 * @param DateTimeImmutable $date
	 * @return DOMElement
	 */
	private function _buildPubDate(DateTimeImmutable $date) : DOMElement {
		$doc = $this->_document;
		$root = $this->createElement($doc, 'pub-date');

		$this->setAttribute($root, 'pub-type', 'epublish');
		$this->createChildWithText($doc, $root, 'year', $date->format('Y'));
		$this->createChildWithText($doc, $root, 'month', $date->format('m'), false);
		$this->createChildWithText($doc, $root, 'day', $date->format('d'), false);

		return $root;
	}

	/**
	 * Creates the authors node
	 * @return DOMElement
	 */
	private function _buildAuthors() : DOMElement {
		$contribGroupNode = $this->createElement($this->_document, 'contrib-group');
		foreach ($this->_article->getAuthors() as $author) {
			$contribNode = $this->_buildAuthor($author);
			$this->appendChild($contribGroupNode, $contribNode);
		}
		return $contribGroupNode;
	}

	/**
	 * Set the pages
	 * @param DOMElement $parentNode Parent node
	 */
	private function _buildPages(DOMElement $parentNode) : void {
		$article = $this->_article;
		/* --- fpage / lpage --- */
		// there is some ambiguity for online journals as to what
		// "page numbers" are; for example, some journals (eg. JMIR)
		// use the "e-location ID" as the "page numbers" in PubMed
		$pages = $article->getPages();
		$fpage = $lpage = null;
		if (PKPString::regexp_match_get('/([0-9]+)\s*-\s*([0-9]+)/i', $pages, $matches)) {
			// simple pagination (eg. "pp. 3-8")
			[, $fpage, $lpage] = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)\s*-\s*(e[0-9]+)/i', $pages, $matches)) {
			// e9 - e14, treated as page ranges
			[, $fpage, $lpage] = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)/i', $pages, $matches)) {
			// single elocation-id (eg. "e12")
			$fpage = $lpage = $matches[1];
		} else {
			// we need to insert something, so use the best ID possible
			$fpage = $lpage = $article->getBestArticleId($this->_context);
		}
		$this->createChildWithText($this->_document, $parentNode, 'fpage', $fpage);
		$this->createChildWithText($this->_document, $parentNode, 'lpage', $lpage);
	}
}
