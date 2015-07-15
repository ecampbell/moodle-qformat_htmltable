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

 * XSLT stylesheet to process HTML-formatted text inside CDATA sections by embedding images
 *
 * @package questionbank
 * @subpackage importexport
 * @copyright 2014 Eoin Campbell
 * @author Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (5)
-->
<xsl:stylesheet exclude-result-prefixes="htm"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:htm="http://www.w3.org/1999/xhtml"
	xmlns="http://www.w3.org/1999/xhtml"
	version="1.0">

<xsl:param name="course_name"/>
<xsl:param name="moodle_language" select="'en'"/> <!-- Interface language for user -->
<xsl:param name="moodle_textdirection" select="'ltr'"/>  <!-- ltr/rtl, ltr except for Arabic, Hebrew, Urdu, Farsi, Maldivian (who knew?) -->
<xsl:param name="transformationfailed"/> <!-- Error message to display in Word file if transformation fails -->
<xsl:param name="debug_flag" select="'0'"/>      <!-- Debugging on or off -->

<xsl:variable name="htmltemplate" select="/container/htmltemplate" />

<xsl:variable name="ucase" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZ'" />
<xsl:variable name="lcase" select="'abcdefghijklmnopqrstuvwxyz'" />
<xsl:variable name="pluginfiles_string" select="'@@PLUGINFILE@@/'"/>
<xsl:variable name="embeddedbase64_string" select="'data:image/'"/>

<xsl:output method="xml" version="1.0" omit-xml-declaration="no" encoding="ISO-8859-1" indent="yes" />

<!-- Read in the input XML into a variable, and handle unusual situation where the inner container element doesn't have an explicit namespace declaration  -->
<xsl:variable name="data" select="/container/*[local-name() = 'container']" />

<!-- Match document root node, and read in and process XHTML template -->
<xsl:template match="/">
	<html lang="{translate($moodle_language, $ucase, $lcase)}" dir="{$moodle_textdirection}">
		<xsl:apply-templates select="$htmltemplate/htm:html/*" />
	</html>
</xsl:template>

<!-- Throw away extra wrapper elements included in container XML -->
<xsl:template match="/container/htmltemplate"/>

<!-- Place questions in XHTML template body -->
<xsl:template match="processing-instruction('replace')[.='insert-content']">
	<!-- Handle the question tables -->
	<xsl:apply-templates select="$data/htm:html/htm:body"/>

	<!-- Check that the content has been successfully read in: if the title is empty, include an error message in the HTML file rather than leave it blank -->
	<xsl:if test="$data/htm:html/htm:head/htm:title = ''">
		<p class="MsoTitle"><xsl:value-of disable-output-escaping="yes" select="$transformationfailed"/></p>
	</xsl:if>
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
		<xsl:call-template name="copyAttributes"/>
		<xsl:apply-templates/>
	</p>
</xsl:template>

<!-- Handle the img element within the main component text by replacing any internal file references with base64-encoded text-->
<xsl:template match="htm:img" priority="2">

	<xsl:choose>
	<xsl:when test="contains(@src, $pluginfiles_string)">
		<!-- Generated from Moodle 2.x, so replace file reference with base64-encoded data -->
		<xsl:variable name="raw_image_file_name" select="substring-after(@src, $pluginfiles_string)"/>
		<xsl:variable name="image_file_name">
			<xsl:choose>
			<xsl:when test="contains($raw_image_file_name, '%')">
				<xsl:call-template name="url-decode">
					<xsl:with-param name="str" select="$raw_image_file_name"/>
				</xsl:call-template>
			</xsl:when>
			<xsl:otherwise>
				<xsl:value-of select="$raw_image_file_name"/>
			</xsl:otherwise>
			</xsl:choose>
		</xsl:variable>
		<xsl:variable name="image_data" select="substring-after(ancestor::htm:td//htm:p[@class = 'ImageFile' and htm:img/@title = $image_file_name]/htm:img/@src, ',')"/>
		<xsl:variable name="image_encoding" select="substring-after(substring-before(ancestor::htm:td//htm:p[@class = 'ImageFile' and htm:img/@title = $image_file_name]/htm:img/@src, ','), ';')"/>
		<xsl:variable name="image_format" select="substring-after($image_file_name, '.')"/>

		<xsl:if test="contains($raw_image_file_name, '%')">
			<xsl:call-template name="debugComment">
				<xsl:with-param name="comment_text" select="concat('raw_image_file_name: ', $raw_image_file_name, '; image_file_name: ', $image_file_name)"/>
			</xsl:call-template>
		</xsl:if>
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

<xsl:template name="copyAttributes">
	<xsl:for-each select="@*">
		<xsl:attribute name="{name()}"><xsl:value-of select="."/></xsl:attribute>
	</xsl:for-each>
</xsl:template>

<!--
	ISO-8859-1 based URL-encoding demo
	Written by Mike J. Brown, mike@skew.org.
	Updated 2002-05-20.
 
	No license; use freely, but credit me if reproducing in print.
 
	Also see http://skew.org/xml/misc/URI-i18n/ for a discussion of
	non-ASCII characters in URIs.
 
Copied from: https://gist.github.com/nils-werner/721650
-->

<xsl:variable name="hex" select="'0123456789ABCDEF'"/>
<xsl:variable name="ascii"> !"#$%&amp;'()*+,-./0123456789:;&lt;=&gt;?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~</xsl:variable>
<xsl:variable name="safe">!'()*-.0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz~</xsl:variable>
<xsl:variable name="latin1">&#160;&#161;&#162;&#163;&#164;&#165;&#166;&#167;&#168;&#169;&#170;&#171;&#172;&#173;&#174;&#175;&#176;&#177;&#178;&#179;&#180;&#181;&#182;&#183;&#184;&#185;&#186;&#187;&#188;&#189;&#190;&#191;&#192;&#193;&#194;&#195;&#196;&#197;&#198;&#199;&#200;&#201;&#202;&#203;&#204;&#205;&#206;&#207;&#208;&#209;&#210;&#211;&#212;&#213;&#214;&#215;&#216;&#217;&#218;&#219;&#220;&#221;&#222;&#223;&#224;&#225;&#226;&#227;&#228;&#229;&#230;&#231;&#232;&#233;&#234;&#235;&#236;&#237;&#238;&#239;&#240;&#241;&#242;&#243;&#244;&#245;&#246;&#247;&#248;&#249;&#250;&#251;&#252;&#253;&#254;&#255;</xsl:variable>

<xsl:template name="url-decode">
	<xsl:param name="str"/>

	<xsl:choose>
	<xsl:when test="contains($str,'%')">
		<xsl:value-of select="substring-before($str,'%')"/>
		<xsl:variable name="hexpair" select="translate(substring(substring-after($str,'%'),1,2),'abcdef','ABCDEF')"/>
		<xsl:variable name="decimal" select="(string-length(substring-before($hex,substring($hexpair,1,1))))*16 + string-length(substring-before($hex,substring($hexpair,2,1)))"/>
		<xsl:choose>
			<xsl:when test="$decimal &lt; 127 and $decimal &gt; 31">
				<xsl:value-of select="substring($ascii,$decimal - 31,1)"/>
			</xsl:when>
			<xsl:when test="$decimal &gt; 159">
				<xsl:value-of select="substring($latin1,$decimal - 159,1)"/>
			</xsl:when>
			<xsl:otherwise>?</xsl:otherwise>
		</xsl:choose>
		<xsl:call-template name="url-decode">
			<xsl:with-param name="str" select="substring(substring-after($str,'%'),3)"/>
		</xsl:call-template>
	</xsl:when>
	<xsl:otherwise>
		<xsl:value-of select="$str"/>
	</xsl:otherwise>
	</xsl:choose>
</xsl:template>

<!-- Include debugging information in the output -->
<xsl:template name="debugComment">
	<xsl:param name="comment_text"/>

	<xsl:if test="$debug_flag = '1'">
		<xsl:text>&#x0a;</xsl:text>
		<xsl:comment><xsl:value-of select="concat('Debug: ', $comment_text)"/></xsl:comment>
		<xsl:text>&#x0a;</xsl:text>
	</xsl:if>
</xsl:template>

</xsl:stylesheet>