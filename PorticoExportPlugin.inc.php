<?php

/**
 * @file plugins/importexport/portico/PorticoExportPlugin.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PorticoExportPlugin
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

import('lib.pkp.classes.xml.XMLCustomWriter');

class PorticoExportPlugin extends ImportExportPlugin {
	/** @var $parentPluginName string Name of parent plugin */
	var $parentPluginName;
	
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'PorticoExportPlugin';
	}

	function getDisplayName() {
		return __('plugins.importexport.portico.displayName');
	}

	function getDescription() {
		return __('plugins.importexport.portico.description.short');
	}

	/**
	 * Get the portico plugin
	 * @return object
	 */
	function &getPorticoPlugin() {
		$plugin =& PluginRegistry::getPlugin('importexport', $this->parentPluginName);
		return $plugin;
	}

	/**
	 * Display verbs for the management interface.
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array(
				'disable',
				__('manager.plugins.disable')
			);
			$verbs[] = array(
				'settings',
				__('plugins.importexport.portico.settings')
			);
		} elseif ($this->getSupported()) {
			$verbs[] = array(
				'enable',
				__('manager.plugins.enable')
			);
		}
		return $verbs;
	}

	/**
	 * Check whether or not this plugin is enabled
	 * @return boolean
	 */
	function getEnabled() {
		$journal =& Request::getJournal();
		$journalId = $journal?$journal->getId():0;
		return $this->getSetting($journalId, 'enabled');
	}

	/**
	 * Determine whether or not this plugin is supported.
	 * @return boolean
	 */
	function getSupported() {
		return class_exists('ZipArchive');
	}

	function display(&$args, $request) {
		$templateMgr =& TemplateManager::getManager();
		parent::display($args, $request);
		$issueDao =& DAORegistry::getDAO('IssueDAO');

		$journal =& $request->getJournal();
		
		// set the issn and abbreviation template variables
		if ($journal->getSetting("onlineIssn")) {
			$templateMgr->assign('issn', $journal->getSetting("onlineIssn"));
		}
		elseif ($journal->getSetting("printIssn")) {
			$templateMgr->assign('issn', $journal->getSetting("printIssn"));
		}
		
		if ($journal->getLocalizedSetting("abbreviation")) {
			$templateMgr->assign('abbreviation', $journal->getLocalizedSetting("abbreviation"));
		}

		switch (array_shift($args)) {
			case 'exportIssues':
				if ($request->getUserVar('export') == 'download') {
					$issueIds = $request->getUserVar('issueId');
					if (!isset($issueIds)) $issueIds = array();
					$issues = array();
					foreach ($issueIds as $issueId) {
						$issue =& $issueDao->getById($issueId, $journal->getId());
						if (!$issue) $request->redirect();
						$issues[] =& $issue;
						unset($issue);
					}
					$this->exportIssues($journal, $issues);
					break;	
				}
				elseif ($request->getUserVar('export') == 'ftp') {
					$issueIds = $request->getUserVar('issueId');
					if (!isset($issueIds)) $issueIds = array();
					$issues = array();
					foreach ($issueIds as $issueId) {
						$issue =& $issueDao->getById($issueId, $journal->getId());
						if (!$issue) $request->redirect();
						$issues[] =& $issue;
						unset($issue);
					}
					$this->ftpIssues($journal, $issues);
					break;
				}
			case 'exportIssue':
				$issueId = array_shift($args);
				$issue =& $issueDao->getById($issueId, $journal->getId());
				if (!$issue) $request->redirect();
				$this->exportIssue($journal, $issue);
				break;
			case 'ftpIssues':
				$issueIds = $request->getUserVar('issueId');
				if (!isset($issueIds)) $issueIds = array();
				$issues = array();
				foreach ($issueIds as $issueId) {
					$issue =& $issueDao->getIssueById($issueId, $journal->getId());
					if (!$issue) $request->redirect();
					$issues[] =& $issue;
					unset($issue);
				}
				$this->ftpIssues($journal, $issues);
				break;
			case 'ftpIssue':
				$issueId = array_shift($args);
				$issue =& $issueDao->getById($issueId, $journal->getId());
				if (!$issue) $request->redirect();
				$this->ftpIssue($journal, $issue);
				break;
			case 'issues':
				// Display a list of issues for export
				$this->setBreadcrumbs(array(), true);
				AppLocale::requireComponents(LOCALE_COMPONENT_OJS_EDITOR);
				$issueDao =& DAORegistry::getDAO('IssueDAO');
				$issues =& $issueDao->getIssues($journal->getId(), Handler::getRangeInfo($request,'issues'));

				$templateMgr->assign_by_ref('issues', $issues);
				$templateMgr->display($this->getTemplatePath() . 'issues.tpl');
				break;
			case 'settings':
				$this->manage('settings', $args, $message, $messageParams = array());
				break;
			default:
				$this->setBreadcrumbs();
				$templateMgr->display($this->getTemplatePath() . 'index.tpl');
		}
	}

	function exportIssues(&$journal, &$issues) {
		$this->import('PorticoExportDom');
		$doc =& XMLCustomWriter::createDocument('issues');
		$issuesNode =& XMLCustomWriter::createElement($doc, 'issues');
		XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('initials') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');

		foreach ($issues as $issue) {		
			// add submission XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
				$section = $sectionDao->getById($article->getSectionId());
				$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
				$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
								
				// add galleys
				import('lib.pkp.classes.file.SubmissionFileManager');
				$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				
				foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
					$galleyId = $galley->getId();

					if ($journal->getSetting('enablePublicGalleyId')) {
						$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
					} else {
						$galley =& $galleyDao->getById($galleyId, $article->getId());
					}

					if ($article && $galley) {
						$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
						$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
						if (isset($submissionFile)) {
							$fileType = $submissionFile->getFileType();
							$filePath = $submissionFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $submissionFile->getName(null);
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			
				// add Supplementary Files
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				$genreDao =& DAORegistry::getDAO('GenreDAO');
				$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();

				foreach ($suppFiles as $sup) {
					$suppId = $sup->getFileId();
					$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
					
					if ($journal->getSetting('rtSupplementaryFiles')) {
						$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
					} else {
						$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
					}
					if ($article && $suppFile) {
						import('lib.pkp.classes.file.SubmissionFileManager');
						$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
						
						$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getOriginalFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

	function exportIssue(&$journal, &$issue) {
		$this->import('PorticoExportDom');
		
		// create zip file

		// build zipName filename
		$zipName = $journal->getLocalizedSetting('initials');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add submission XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
			$section = $sectionDao->getById($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('lib.pkp.classes.file.SubmissionFileManager');
			$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			
			foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
				$galleyId = $galley->getId();
				
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getById($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
					$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
					if (isset($submissionFile)) {
						$fileType = $submissionFile->getFileType();
						$filePath = $submissionFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $submissionFile->getName(null);
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
			
			// add Supplementary Files
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
			
			foreach ($suppFiles as $sup) {
				$suppId = $sup->getFileId();
				$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
				
				if ($journal->getSetting('rtSupplementaryFiles')) {
					$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
				} else {
					$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
				}
				
				if ($article && $suppFile) {
					import('lib.pkp.classes.file.SubmissionFileManager');
					$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
					
					$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getOriginalFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();
		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

	function ftpIssue(&$journal, &$issue) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		
		// create zip file
		$zipName = $journal->getLocalizedSetting('initials');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';

		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add submission XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
			$section = $sectionDao->getById($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('lib.pkp.classes.file.SubmissionFileManager');
			$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			
			foreach ($article->getGalleys() as $galley) {
				$galleyId = $galley->getId();
				
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getById($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
					$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
					
					if (isset($submissionFile)) {
						$fileType = $submissionFile->getFileType();
						$filePath = $submissionFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $submissionFile->getName(null);
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
			
			// add Supplementary Files
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$genreDao =& DAORegistry::getDAO('GenreDAO');
			$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
			
			foreach ($suppFiles as $sup) {
				$suppId = $sup->getFileId();
				$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
				
				if ($journal->getSetting('rtSupplementaryFiles')) {
					$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
				} else {
					$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
				}
				
				if ($article && $suppFile) {
					import('lib.pkp.classes.file.SubmissionFileManager');
					$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
					
					$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getOriginalFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();
		
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server); 

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass); 
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) { 
			return $templateMgr->display($this->getTemplatePath() . 'exportSuccess.tpl');
			exit; 
		} else { 
			return $templateMgr->display($this->getTemplatePath() . 'exportFailure.tpl');
			exit; 
		} 
		// close the connection 
		ftp_close($conn_id);
		unlink($zipName);
		return true;
	}
	
	function ftpIssues(&$journal, &$issues) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		$doc =& XMLCustomWriter::createDocument('issues');
		$issuesNode =& XMLCustomWriter::createElement($doc, 'issues');
		XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('initials') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');

		foreach ($issues as $issue) {
			// add submission XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
				$section = $sectionDao->getById($article->getSectionId());
				$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
				$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
				// add galleys
				import('lib.pkp.classes.file.SubmissionFileManager');
				$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				
				foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
					$galleyId = $galley->getId();
					
					if ($journal->getSetting('enablePublicGalleyId')) {
						$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
					} else {
						$galley =& $galleyDao->getById($galleyId, $article->getId());
					}

					if ($article && $galley) {
						$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
						$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
						if (isset($submissionFile)) {
							$fileType = $submissionFile->getFileType();
							$filePath = $submissionFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $submissionFile->getName(null);
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			
				// add Supplementary Files
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				$genreDao =& DAORegistry::getDAO('GenreDAO');
				$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
				
				foreach ($suppFiles as $sup) {
					$suppId = $sup->getFileId();
					$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
					
					if ($journal->getSetting('rtSupplementaryFiles')) {
						$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
					} else {
						$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
					}
					
					if ($article && $suppFile) {
						import('lib.pkp.classes.file.SubmissionFileManager');
						$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
						
						$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getOriginalFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
				
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server);

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) {
			return $templateMgr->display($this->getTemplatePath() . 'exportSuccess.tpl');
			exit;
		} else {
			return $templateMgr->display($this->getTemplatePath() . 'exportFailure.tpl');
			exit; 
		}
		// close the connection 
		ftp_close($conn_id);
		unlink($zipName);
		return true;
	}
	
 	/**
 	 * Execute a management verb on this plugin
 	 * @param $verb string
 	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for status message
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		$returner = true;
		$journal =& Request::getJournal();
		$this->addLocaleData();

		switch ($verb) {
			case 'settings':
				AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('PorticoSettingsForm');
				$form = new PorticoSettingsForm($this, $journal->getId());

				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, null, null, array('plugin', $this->getName()));
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				break;
			case 'enable':
				$this->updateSetting($journal->getId(), 'enabled', true);
				$message = NOTIFICATION_TYPE_PLUGIN_ENABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				$returner = false;
				break;
			case 'disable':
				$this->updateSetting($journal->getId(), 'enabled', false);
				$message = NOTIFICATION_TYPE_PLUGIN_DISABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				$returner = false;
				break;
		}

		return $returner;
	}
	
	/////////////////////////Added missing abstract methods/////////////////////////////////////
	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $crumbs Array ($url, $name, $isTranslated)
	 * @param $subclass boolean
	 */
	function setBreadcrumbs($crumbs = array(), $isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
				array(
						Request::url(null, 'user'),
						'navigation.user'
				),
				array(
						Request::url(null, 'manager'),
						'user.role.manager'
				),
				array (
						Request::url(null, 'manager', 'importexport'),
						'manager.importExport'
				)
		);
		if ($isSubclass) $pageCrumbs[] = array(
				Request::url(null, 'manager', 'importexport', array('plugin', $this->getName())),
				$this->getDisplayName(),
				true
		);
		
		$templateMgr->assign('pageHierarchy', array_merge($pageCrumbs, $crumbs));
	}
	
	/**
	 * Execute import/export tasks using the command-line interface.
	 * @param $scriptName The name of the command-line script (displayed as usage info)
	 * @param $args Parameters to the plugin
	 */
	function executeCLI($scriptName, &$args){
		
	}
	
	/**
	 * Display the command-line usage information
	 * @param $scriptName string
	 */
	function usage($scriptName){
		
	}
}

?>
