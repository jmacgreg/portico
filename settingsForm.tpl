{**
 * plugins/importexport/portico/settingsForm.tpl
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Portico plugin settings
 *
 *}
{strip}
{assign var="pageTitle" value="plugins.importexport.portico.displayName"}
{include file="common/header.tpl"}
{/strip}
<div id="porticoSettings">
<p>{translate key="plugins.importexport.portico.description"}</p>

<div class="separator">&nbsp;</div>

<form method="post" action="settings">
{include file="common/formErrors.tpl"}

<h3>{translate key="plugins.importexport.portico.ftpConfig"}</h3>

{plugin_url|assign:"exportUrl" op='importexport' path='issues'}
<p>{translate key="plugins.importexport.portico.continue" exportUrl=$exportUrl}</p>

<table width="100%" class="data">
	<tr valign="top">
		<td width="20%" class="label">{fieldLabel name="porticoHost" required="true" key="plugins.importexport.portico.porticoHost"}</td>
		<td width="80%" class="value"><input type="text" name="porticoHost" id="porticoHost" value="{$porticoHost|escape}" size="15" maxlength="25" class="textField" /> <span class="instruct">{translate key="plugins.importexport.portico.porticoHostInstructions"}</span>
		</td></tr>

	<tr valign="top">
		<td width="20%" class="label">{fieldLabel name="porticoUsername" required="true" key="plugins.importexport.portico.porticoUsername"}</td>
		<td width="80%" class="value"><input type="text" name="porticoUsername" id="porticoUsername" value="{$porticoUsername|escape}" size="15" maxlength="25" class="textField" />
	</td>
	</tr>
	<tr valign="top">
		<td width="20%" class="label">{fieldLabel name="porticoPassword" required="true" key="plugins.importexport.portico.porticoPassword"}</td>
		<td width="80%" class="value"><input type="password" name="porticoPassword" id="porticoPassword" value="{$porticoPassword|escape}" size="15" maxlength="25" class="textField" /> <span class="instruct">{translate key="plugins.importexport.portico.password.description"}</span>
	</td>
	</tr>
</table>

<input type="submit" name="save" class="button defaultButton" value="{translate key="common.save"}" /><input type="button" class="button" value="{translate key="common.cancel"}" onclick="document.location='{url|cat:'/plugin/PorticoExportPlugin'}';"/>
</form>
</div><!-- porticoSettings -->
<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
{include file="common/footer.tpl"}