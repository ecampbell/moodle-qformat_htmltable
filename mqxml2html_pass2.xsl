<?xml version="1.0" encoding="UTF-8"?>
<!-- $Id: $ 
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

 * XSLT stylesheet to transform Moodle Question XML-formatted questions into Word-compatible HTML tables 
 *
 * @package questionbank
 * @subpackage importexport
 * @copyright 2010 Eoin Campbell
 * @author Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (5)
-->
<xsl:stylesheet exclude-result-prefixes="htm"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:htm="http://www.w3.org/1999/xhtml"
	xmlns="http://www.w3.org/1999/xhtml"
	version="1.0">

<xsl:variable name="htmltemplate" select="/container/htmltemplate" />

<xsl:variable name="ucase" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZ'" />
<xsl:variable name="lcase" select="'abcdefghijklmnopqrstuvwxyz'" />
<xsl:variable name="pluginfiles_string" select="'@@PLUGINFILE@@/'"/>
<xsl:variable name="embeddedbase64_string" select="'data:image/'"/>

<xsl:output method="xml" version="1.0" omit-xml-declaration="yes" encoding="ISO-8859-1" indent="yes" />

<!-- Read in the input XML into a variable, so that it can be processed -->
<xsl:variable name="data" select="/container/htm:container" />

<!-- Match document root node, and read in and process Word-compatible XHTML template -->
<xsl:template match="/">
  <xsl:apply-templates select="$htmltemplate/*" mode="template"/>
</xsl:template>

<!-- Throw away extra wrapper elements included in container XML -->
<xsl:template match="/container/htmltemplate"/>

<!-- Place questions in XHTML template body -->
<xsl:template match="processing-instruction('replace')[.='insert-content']">
	<!-- Handle the question tables -->
	<xsl:apply-templates select="$data/htm:html/htm:body"/>
</xsl:template>

<!-- Metadata -->
<!-- Set the title property (File->Properties... Summary tab) -->
<xsl:template match="processing-instruction('replace')[.='insert-title']">
	<!-- Place category info and course name into document title -->
	<xsl:value-of select="$data/htm:html/htm:head/htm:title"/>
</xsl:template>

<!-- Look for table cells with just text, and wrap them in a Cell paragraph style -->
<xsl:template match="htm:td">
	<td>
		<xsl:call-template name="copyAttributes"/>

		<xsl:choose>
		<xsl:when test="count(*) = 0">
			<p class="Cell">
				<xsl:apply-templates/>
			</p>
		</xsl:when>
		<xsl:otherwise><xsl:apply-templates/></xsl:otherwise>
		</xsl:choose>
	</td>
</xsl:template>

<!-- Any paragraphs without an explicit class are set to have the Cell style -->
<xsl:template match="htm:p[not(@class)]">
	<p class="Cell">
		<xsl:apply-templates/>
	</p>
</xsl:template>

<!-- Handle the img element within the main component text by replacing any internal file references with base64-encoded text-->
<xsl:template match="htm:img" priority="2">

	<xsl:choose>
	<xsl:when test="contains(@src, $pluginfiles_string)">
		<!-- Generated from Moodle 2.x, so replace file reference with base64-encoded data -->
		<xsl:variable name="image_file_name" select="substring-after(@src, $pluginfiles_string)"/>
		<xsl:variable name="image_data" select="substring-after(ancestor::htm:td//htm:p[@class = 'ImageFile' and htm:img/@title = $image_file_name]/htm:img/@src, ',')"/>
		<xsl:variable name="image_encoding" select="substring-after(substring-before(ancestor::htm:td//htm:p[@class = 'ImageFile' and htm:img/@title = $image_file_name]/htm:img/@src, ','), ';')"/>
		<xsl:variable name="image_format" select="substring-after($image_file_name, '.')"/>

		<img>
			<!-- Copy attributes, except for @src -->
			<xsl:for-each select="@*">
				<xsl:if test="name() != 'src'">
					<xsl:attribute name="{name()}"><xsl:value-of select="."/></xsl:attribute>
				</xsl:if>
			</xsl:for-each>

			<xsl:attribute name="src">
				<xsl:value-of select="concat($embeddedbase64_string, $image_format, ';base64,', $image_data)"/>
			</xsl:attribute>
		</img>
	</xsl:when>
	<xsl:otherwise>
		<img>
			<xsl:call-template name="copyAttributes"/>
		</img>
	</xsl:otherwise>
	</xsl:choose>
</xsl:template>

<!-- Delete the supplementary paragraphs containing images within each question component, as they are no longer needed -->
<xsl:template match="htm:p[@class = 'ImageFile']"/>

<!-- Preserve comments for style definitions -->
<xsl:template match="comment()">
	<xsl:comment><xsl:value-of select="."  /></xsl:comment>
</xsl:template>

<!-- Identity transformations -->
<xsl:template match="*">
	<xsl:element name="{name()}">
		<xsl:call-template name="copyAttributes" />
		<xsl:apply-templates select="node()"/>
	</xsl:element>
</xsl:template>
<!-- Identity transformations -->
<xsl:template match="*" mode="template">
	<xsl:element name="{name()}">
		<xsl:call-template name="copyAttributes" />
		<xsl:apply-templates select="node()"/>
	</xsl:element>
</xsl:template>

<xsl:template name="copyAttributes">
	<xsl:for-each select="@*">
		<xsl:attribute name="{name()}"><xsl:value-of select="."/></xsl:attribute>
	</xsl:for-each>
</xsl:template>

</xsl:stylesheet>
