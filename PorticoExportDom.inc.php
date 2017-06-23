<?php

/**
 * @file plugins/importexport/portico/PorticoExportDom.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PorticoExportDom
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin DOM functions for export
 */

import('lib.pkp.classes.xml.XMLCustomWriter');

define('PUBMED_DTD_URL', 'http://dtd.nlm.nih.gov/archiving/3.0/archivearticle3.dtd');
define('PUBMED_DTD_ID', '-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN');

class PorticoExportDom {
	function &generateArticleDom(&$doc, &$journal, &$issue, &$section, &$article) {

		/* --- Article --- */
		$root =& XMLCustomWriter::createElement($doc, 'article');
		XMLCustomWriter::setAttribute($root, 'xmlns:xlink', 'http://www.w3.org/1999/xlink');

		/* --- Front --- */
		$articleNode =& XMLCustomWriter::createElement($doc, 'front');
		XMLCustomWriter::appendChild($root, $articleNode);
		
		/* --- Journal --- */
		$journalMetaNode =& XMLCustomWriter::createElement($doc, 'journal-meta');
		XMLCustomWriter::appendChild($articleNode, $journalMetaNode);

		// journal-id
		if ($journal->getLocalizedSetting("abbreviation")) {
			XMLCustomWriter::createChildWithText($doc, $journalMetaNode, 'journal-id', $journal->getLocalizedSetting("abbreviation"));
		}

		//journal-title-group
		$journalTitleGroupNode = XMLCustomWriter::createElement($doc, 'journal-title-group');
		XMLCustomWriter::appendChild($journalMetaNode, $journalTitleGroupNode);

		// journal-title
		XMLCustomWriter::createChildWithText($doc, $journalTitleGroupNode, 'journal-title', $journal->getLocalizedPageHeaderTitle());

		// issn
		if ($journal->getSetting('printIssn') != '') $ISSN = $journal->getSetting('printIssn');
		elseif ($journal->getSetting('issn') != '') $ISSN = $journal->getSetting('issn');
		elseif ($journal->getSetting('onlineIssn') != '') $ISSN = $journal->getSetting('onlineIssn');
		else $ISSN = '';

		if ($ISSN != '') XMLCustomWriter::createChildWithText($doc, $journalMetaNode, 'issn', $ISSN);

		// publisher
		$publisherNode = XMLCustomWriter::createElement($doc, 'publisher');
		XMLCustomWriter::appendChild($journalMetaNode, $publisherNode);
		
		// publisher-name
		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$publisherNameNode = XMLCustomWriter::createChildWithText($doc, $publisherNode, 'publisher-name', $publisherInstitution);

		/* --- End Journal --- */


		/* --- Article-meta --- */
		$articleMetaNode =& XMLCustomWriter::createElement($doc, 'article-meta');
		XMLCustomWriter::appendChild($articleNode, $articleMetaNode);
		
		// article-id (DOI)
		if ($doi = $article->getStoredPubId('doi')) {
			$doiNode =& XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'article-id', $doi, false);
			XMLCustomWriter::setAttribute($doiNode, 'pub-id-type', 'doi');
			
		}

		// article-title
		$titleGroupNode =& XMLCustomWriter::createElement($doc, 'title-group');
		XMLCustomWriter::appendChild($articleMetaNode, $titleGroupNode);
		XMLCustomWriter::createChildWithText($doc, $titleGroupNode, 'article-title', $article->getLocalizedTitle());
		
		// authors
		$contribGroupNode =& XMLCustomWriter::createElement($doc, 'contrib-group');
		XMLCustomWriter::appendChild($articleMetaNode, $contribGroupNode);

		$authorIndex = 0;
		foreach ($article->getAuthors() as $author) {
			$contribNode =& PorticoExportDom::generateAuthorDom($doc, $author, $authorIndex++);
			XMLCustomWriter::appendChild($contribGroupNode, $contribNode);
		}

		$datePublished = $article->getDatePublished();
		if (!$datePublished) $datePublished = $issue->getDatePublished();
		if ($datePublished) {
			$pubDateNode =& PorticoExportDom::generatePubDateDom($doc, $datePublished, 'epublish');
			XMLCustomWriter::appendChild($articleMetaNode, $pubDateNode);
		}

		// volume, issue, etc.
		XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'volume', $issue->getVolume());
		XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'issue', $issue->getNumber(), false);
		
		/* --- fpage / lpage --- */
		// there is some ambiguity for online journals as to what
		// "page numbers" are; for example, some journals (eg. JMIR)
		// use the "e-location ID" as the "page numbers" in PubMed
		$pages = $article->getPages();
		if (preg_match("/([0-9]+)\s*-\s*([0-9]+)/i", $pages, $matches)) {
			// simple pagination (eg. "pp. 3- 		8")
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'fpage', $matches[1]);
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'lpage', $matches[2]);
		} elseif (preg_match("/(e[0-9]+)\s*-\s*(e[0-9]+)/i", $pages, $matches)) { // e9 - e14, treated as page ranges
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'fpage', $matches[1]);
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'lpage', $matches[2]);
		} elseif (preg_match("/(e[0-9]+)/i", $pages, $matches)) {
			// single elocation-id (eg. "e12")
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'fpage', $matches[1]);
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'lpage', $matches[1]);
		} else {
			// we need to insert something, so use the best ID possible
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'fpage', $article->getBestArticleId($journal));
			XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'lpage', $article->getBestArticleId($journal));
		}

		/* --- ArticleIdList --- */
		// Pubmed will accept two types of article identifier: pii and doi
		// how this is handled is journal-specific, and will require either
		// configuration in the plugin, or an update to the core code.
		// this is also related to DOI-handling within OJS
		if ($article->getStoredPubId('publisher-id')) {
			$articleIdListNode =& XMLCustomWriter::createElement($doc, 'ArticleIdList');
			XMLCustomWriter::appendChild($articleNode, $articleIdListNode);

			$articleIdNode =& XMLCustomWriter::createChildWithText($doc, $articleIdListNode, 'article-id', $article->getPubId('publisher-id'));
			XMLCustomWriter::setAttribute($articleIdNode, 'pub-id-type', 'publisher');
		}

		// supplementary file links
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$genreDao =& DAORegistry::getDAO('GenreDAO');
		$suppFiles= $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
		
		foreach ($suppFiles as $suppFile) {
			$supplementaryMaterialNode =& XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'supplementary-material');
			XMLCustomWriter::setAttribute($supplementaryMaterialNode, 'xlink:href', $suppFile->getLocalizedName());
			XMLCustomWriter::setAttribute($supplementaryMaterialNode, 'content-type', $suppFile->getFileType());
		}

		// galley links
		import('lib.pkp.classes.file.SubmissionFileManager');
		$submissionFileManager = new SubmissionFileManager($article->getContextId(),$article->getId());
			foreach ($galleys as $galley) {
			$selfUriNode =& XMLCustomWriter::createChildWithText($doc, $articleMetaNode, 'self-uri', $galley->getName($galley->getLocale()));
			XMLCustomWriter::setAttribute($selfUriNode, 'xlink:href', $galley->getName($galley->getLocale()));
			XMLCustomWriter::setAttribute($selfUriNode, 'content-type', $galley->getFileType());
		}
			
		/* --- Abstract --- */
		if ($article->getLocalizedAbstract()) {
			$abstractNode =& XMLCustomWriter::createElement($doc, 'abstract');
			XMLCustomWriter::appendChild($articleMetaNode, $abstractNode);
			$abstractPNode = XMLCustomWriter::createChildWithText($doc, $abstractNode, 'p', strip_tags($article->getLocalizedAbstract()), false);
		}

		return $root;
	}

	/**
	 * Generate the Author node DOM for the specified author.
	 * @param $doc DOMDocument
	 * @param $author PKPAuthor
	 * @param $authorIndex 0-based index of current author
	 */
	function &generateAuthorDom(&$doc, &$author, $authorIndex) {
		$root =& XMLCustomWriter::createElement($doc, 'contrib');
		XMLCustomWriter::setAttribute($root, 'contrib-type', 'author');

		$nameNode =& XMLCustomWriter::createElement($doc, 'name');
		XMLCustomWriter::appendChild($root, $nameNode);

		$givenNames = $author->getFirstName(); 
		if ($author->getMiddleName()) {
			$givenNames .= ' ' . $author->getMiddleName();
		}
		XMLCustomWriter::createChildWithText($doc, $nameNode, 'surname', ucfirst($author->getLastName()));
		XMLCustomWriter::createChildWithText($doc, $nameNode, 'given-names', ucwords($givenNames));

		if ($authorIndex == 0) {
			// See http://pkp.sfu.ca/bugzilla/show_bug.cgi?id=7774
			XMLCustomWriter::createChildWithText($doc, $root, 'aff', $author->getLocalizedAffiliation() . '. ' . $author->getEmail(), false);
		}

		return $root;
	}

	function &generatePubDateDom(&$doc, $pubdate, $pubstatus) {
		$root =& XMLCustomWriter::createElement($doc, 'pub-date');

		XMLCustomWriter::setAttribute($root, 'pub-type', $pubstatus);

		XMLCustomWriter::createChildWithText($doc, $root, 'year', date('Y', strtotime($pubdate)) );
		XMLCustomWriter::createChildWithText($doc, $root, 'month', date('m', strtotime($pubdate)), false );
		XMLCustomWriter::createChildWithText($doc, $root, 'day', date('d', strtotime($pubdate)), false );

		return $root;
	}

	function &generateGalleyDom(&$doc, &$journal, &$issue, &$article, &$galley) {
		$isHtml = $galley->isHTMLGalley();

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($article->getId());
		$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');

		$root =& XMLCustomWriter::createElement($doc, $isHtml?'htmlgalley':'galley');
		XMLCustomWriter::setAttribute($root, 'locale', $galley->getLocale());
		XMLCustomWriter::setAttribute($root, 'public_id', $galley->getPubId('publisher-id'), false);

		PorticoExportDom::generatePubId($doc, $root, $galley, $issue);

		XMLCustomWriter::createChildWithText($doc, $root, 'label', $galley->getLabel());

		/* --- Galley file --- */
		$fileNode =& XMLCustomWriter::createElement($doc, 'file');
		XMLCustomWriter::appendChild($root, $fileNode);
		if ($galley->getRemoteURL()) {
			$remoteNode =& XMLCustomWriter::createElement($doc, 'remote');
			XMLCustomWriter::appendChild($fileNode, $remoteNode);
			XMLCustomWriter::setAttribute($remoteNode, 'src', $galley->getRemoteURL());
		} else {
			$articleFile =& $articleFileDao->getArticleFile($galley->getFileId());
			if (!$articleFile) return $articleFile; // Stupidity check
			$embedNode =& XMLCustomWriter::createChildWithText($doc, $fileNode, 'fileName', $articleFile->getOriginalFileName());
			$articleFile =& $articleFileDao->getArticleFile($galley->getFileId());
			if (!$articleFile) return $articleFile; // Stupidity check

			/* --- HTML-specific data: Stylesheet and/or images --- */

			if ($isHtml) {
				$styleFile = $galley->getStyleFile();
				if ($styleFile) {
					$styleNode =& XMLCustomWriter::createElement($doc, 'stylesheet');
					XMLCustomWriter::appendChild($root, $styleNode);
					$embedNode =& XMLCustomWriter::createChildWithText($doc, $styleNode, 'fileName', $styleFile->getOriginalFileName());
				}

				foreach ($galley->getImageFiles() as $imageFile) {
					$imageNode =& XMLCustomWriter::createElement($doc, 'image');
					XMLCustomWriter::appendChild($root, $imageNode);
					$embedNode =& XMLCustomWriter::createChildWithText($doc, $imageNode, 'fileName', $imageFile->getOriginalFileName());
					unset($imageNode);
					unset($embedNode);
				}
			}
		}

		return $root;
	}

	function &generateSuppFileDom(&$doc, &$journal, &$issue, &$article, &$suppFile) {
		$root =& XMLCustomWriter::createElement($doc, 'supplemental_file');

		PorticoExportDom::generatePubId($doc, $root, $suppFile, $issue);

		// FIXME: These should be constants!
		switch ($suppFile->getType()) {
			case __('author.submit.suppFile.researchInstrument'):
				$suppFileType = 'research_instrument';
				break;
			case __('author.submit.suppFile.researchMaterials'):
				$suppFileType = 'research_materials';
				break;
			case __('author.submit.suppFile.researchResults'):
				$suppFileType = 'research_results';
				break;
			case __('author.submit.suppFile.transcripts'):
				$suppFileType = 'transcripts';
				break;
			case __('author.submit.suppFile.dataAnalysis'):
				$suppFileType = 'data_analysis';
				break;
			case __('author.submit.suppFile.dataSet'):
				$suppFileType = 'data_set';
				break;
			case __('author.submit.suppFile.sourceText'):
				$suppFileType = 'source_text';
				break;
			default:
				$suppFileType = 'other';
				break;
		}

		XMLCustomWriter::setAttribute($root, 'type', $suppFileType);
		XMLCustomWriter::setAttribute($root, 'public_id', $suppFile->getPubId('publisher-id'), false);
		XMLCustomWriter::setAttribute($root, 'language', $suppFile->getLanguage(), false);
		XMLCustomWriter::setAttribute($root, 'show_reviewers', $suppFile->getShowReviewers()?'true':'false');

		if (is_array($suppFile->getTitle(null))) foreach ($suppFile->getTitle(null) as $locale => $title) {
			$titleNode =& XMLCustomWriter::createChildWithText($doc, $root, 'title', $title, false);
			if ($titleNode) XMLCustomWriter::setAttribute($titleNode, 'locale', $locale);
			unset($titleNode);
		}
		if (is_array($suppFile->getCreator(null))) foreach ($suppFile->getCreator(null) as $locale => $creator) {
			$creatorNode =& XMLCustomWriter::createChildWithText($doc, $root, 'creator', $creator, false);
			if ($creatorNode) XMLCustomWriter::setAttribute($creatorNode, 'locale', $locale);
			unset($creatorNode);
		}
		if (is_array($suppFile->getSubject(null))) foreach ($suppFile->getSubject(null) as $locale => $subject) {
			$subjectNode =& XMLCustomWriter::createChildWithText($doc, $root, 'subject', $subject, false);
			if ($subjectNode) XMLCustomWriter::setAttribute($subjectNode, 'locale', $locale);
			unset($subjectNode);
		}
		if ($suppFileType == 'other') {
			if (is_array($suppFile->getTypeOther(null))) foreach ($suppFile->getTypeOther(null) as $locale => $typeOther) {
				$typeOtherNode =& XMLCustomWriter::createChildWithText($doc, $root, 'type_other', $typeOther, false);
				if ($typeOtherNode) XMLCustomWriter::setAttribute($typeOtherNode, 'locale', $locale);
				unset($typeOtherNode);
			}
		}
		if (is_array($suppFile->getDescription(null))) foreach ($suppFile->getDescription(null) as $locale => $description) {
			$descriptionNode =& XMLCustomWriter::createChildWithText($doc, $root, 'description', $description, false);
			if ($descriptionNode) XMLCustomWriter::setAttribute($descriptionNode, 'locale', $locale);
			unset($descriptionNode);
		}
		if (is_array($suppFile->getPublisher(null))) foreach ($suppFile->getPublisher(null) as $locale => $publisher) {
			$publisherNode =& XMLCustomWriter::createChildWithText($doc, $root, 'publisher', $publisher, false);
			if ($publisherNode) XMLCustomWriter::setAttribute($publisherNode, 'locale', $locale);
			unset($publisherNode);
		}
		if (is_array($suppFile->getSponsor(null))) foreach ($suppFile->getSponsor(null) as $locale => $sponsor) {
			$sponsorNode =& XMLCustomWriter::createChildWithText($doc, $root, 'sponsor', $sponsor, false);
			if ($sponsorNode) XMLCustomWriter::setAttribute($sponsorNode, 'locale', $locale);
			unset($sponsorNode);
		}
		XMLCustomWriter::createChildWithText($doc, $root, 'date_created', PorticoExportDom::formatDate($suppFile->getDateCreated()), false);
		if (is_array($suppFile->getSource(null))) foreach ($suppFile->getSource(null) as $locale => $source) {
			$sourceNode =& XMLCustomWriter::createChildWithText($doc, $root, 'source', $source, false);
			if ($sourceNode) XMLCustomWriter::setAttribute($sourceNode, 'locale', $locale);
			unset($sourceNode);
		}

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($article->getId());
		$fileNode =& XMLCustomWriter::createElement($doc, 'file');
		XMLCustomWriter::appendChild($root, $fileNode);
		if ($suppFile->getRemoteURL()) {
			$remoteNode =& XMLCustomWriter::createElement($doc, 'remote');
			XMLCustomWriter::appendChild($fileNode, $remoteNode);
			XMLCustomWriter::setAttribute($remoteNode, 'src', $suppFile->getRemoteURL());
		} else {
			$embedNode =& XMLCustomWriter::createChildWithText($doc, $fileNode, 'fileName', $suppFile->getOriginalFileName());
		}
		return $root;
	}

	function formatDate($date) {
		if ($date == '') return null;
		return date('Y-m-d', strtotime($date));
	}

	/**
	 * Add ID-nodes to the given node.
	 * @param $doc DOMDocument
	 * @param $node DOMNode
	 * @param $pubObject object
	 * @param $issue Issue
	 */
	function generatePubId(&$doc, &$node, &$pubObject, &$issue) {
		$pubIdPlugins =& PluginRegistry::loadCategory('pubIds', true, $issue->getJournalId());
		if (is_array($pubIdPlugins)) foreach ($pubIdPlugins as $pubIdPlugin) {
			if ($issue->getPublished()) {
				$pubId = $pubIdPlugin->getPubId($pubObject);
			} else {
				$pubId = $pubIdPlugin->getPubId($pubObject, true);
			}
			if ($pubId) {
				$pubIdType = $pubIdPlugin->getPubIdType();
				$idNode =& XMLCustomWriter::createChildWithText($doc, $node, 'id', $pubId);
				XMLCustomWriter::setAttribute($idNode, 'type', $pubIdType);
			}
		}
	}
}

?>
