<?php

/**
 * @file plugins/importexport/portico/PorticoSettingsForm.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PorticoSettingsForm
 * @ingroup plugins_importexport_portico
 *
 * @brief Form for journal managers to modify Portico plugin settings
 */


import('lib.pkp.classes.form.Form');

class PorticoSettingsForm extends Form {

	/** @var $journalId int */
	var $journalId;

	/** @var $plugin object */
	var $plugin;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $journalId int
	 */
	function PorticoSettingsForm(&$plugin, $journalId) {
		$this->journalId = $journalId;
		$this->plugin =& $plugin;

		parent::__construct($plugin->getTemplatePath() . 'settingsForm.tpl');

		$this->addCheck(new FormValidator($this, 'porticoHost', 'required', 'plugins.importexport.portico.manager.settings.porticoHostRequired'));

		$this->addCheck(new FormValidator($this, 'porticoUsername', 'required', 'plugins.importexport.portico.manager.settings.porticoUsernameRequired'));
		$this->addCheck(new FormValidator($this, 'porticoPassword', 'required', 'plugins.importexport.portico.manager.settings.porticoPasswordRequired'));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		$journalId = $this->journalId;
		$plugin =& $this->plugin;

		$this->setData('porticoHost', $plugin->getSetting($journalId, 'porticoHost'));
		$this->setData('porticoUsername', $plugin->getSetting($journalId, 'porticoUsername'));
		$this->setData('porticoPassword', $plugin->getSetting($journalId, 'porticoPassword'));
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('porticoHost', 'porticoUsername', 'porticoPassword'));
	}

	/**
	 * Save settings. 
	 */
	function execute() {
		$plugin =& $this->plugin;
		$journalId = $this->journalId;

		$plugin->updateSetting($journalId, 'porticoHost', $this->getData('porticoHost'));
		$plugin->updateSetting($journalId, 'porticoUsername', $this->getData('porticoUsername'));
		$plugin->updateSetting($journalId, 'porticoPassword', $this->getData('porticoPassword'));
	}
}

?>