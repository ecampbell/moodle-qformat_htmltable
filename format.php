<?php

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


/**
 * Convert Moodle Question XML into HTML table format
 *
 * The htmltable class inherits from the XML question import/export class, rather than the
 * default question format class, as this minimises code duplication.
 *
 * This code converts quiz questions from Moodle Question XML format into structured HTML tables,
 * for easy review of all question components,including feedback, hints, tags, and metadata.
 *
 * @package questionbank
 * @subpackage importexport
 * @copyright 2014 Eoin Campbell
 * @author Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (5)
 */


require_once("$CFG->libdir/xmlize.php");
require_once($CFG->dirroot.'/lib/uploadlib.php');

// htmltable just extends XML import/export
require_once("$CFG->dirroot/question/format/xml/format.php");

// Include XSLT processor functions
require_once("$CFG->dirroot/question/format/htmltable/xsl_emulate_xslt.inc");

class qformat_htmltable extends qformat_xml {

    private $question_icons = 'qtype_icons_base64.xml';  // Data file containing base64-encoded question icon images
    private $htmlfile_template = 'htmlfile_template.html';  // XHTML template file containing CSS style definitions
    private $mqxml2html_stylesheet1 = 'mqxml2html_pass1.xsl';      // XSLT stylesheet containing code to convert Moodle Question XML into XHTML
    private $mqxml2html_stylesheet2 = 'mqxml2html_pass2.xsl';      // XSLT stylesheet containing code to process CDATA sections untouched in pass1

    public function mime_type() {
        return 'text/html';
    }


    public function provide_import() {
        return false;
    }

    // EXPORT FUNCTIONS START HERE

    /**
     * Use a .htm file extension when exporting
     * @return string file extension
     */
    function export_file_extension() {
        return ".htm";
    }

    /**
     * Convert the Moodle Question XML into XHTML format
     * just prior to the file being saved
     *
     * Use an XSLT script to do the job, as it is much easier to implement this,
     * and Moodle sites are guaranteed to have an XSLT processor available (I think).
     *
     * @param string  $content Question XML text
     * @return string XHTML text
     */
    function presave_process( $content ) {
        // override method to allow us convert to XHTML format
        global $CFG, $USER;
        global $OUTPUT;
        // declare empty array to prevent each debug message from including a complete backtrace
        $backtrace = array();

        debugging("presave_process(content = " . str_replace("\n", " ", substr($content, 80, 50)) . "):", DEBUG_DEVELOPER, $backtrace);

        // XSLT stylesheet to convert Moodle Question XML into XHTML format
        $stylesheet = dirname(__FILE__) . "/" . $this->mqxml2html_stylesheet1;
        // XHTML template for XHTML file CSS styles formatting
        $htmltemplatefile_url = dirname(__FILE__) . "/" . $this->htmlfile_template;
        $iconfile_url = dirname(__FILE__) . "/" . $this->question_icons;

        // Check that XSLT is installed, and the XSLT stylesheet and XHTML template are present
        if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
            debugging("presave_process(): XSLT not installed", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('xsltunavailable', 'qformat_htmltable'));
            return false;
        } else if(!file_exists($stylesheet)) {
            // XSLT stylesheet to transform Moodle Question XML into XHTML doesn't exist
            debugging("presave_process(): XSLT stylesheet missing: $stylesheet", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('stylesheetunavailable', 'qformat_htmltable', $stylesheet));
            return false;
        }

        // Check that there is some content to convert into XHTML
        if (!strlen($content)) {
            debugging("presave_process(): No XML questions in category", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('noquestions', 'qformat_htmltable'));
            return false;
        }

        // Create a temporary file to store the XML content to transform
        if (!($temp_xml_filename = tempnam($CFG->dataroot . "/temp/", "m2w-"))) {
            debugging("presave_process(): Cannot open temporary file ('$temp_xml_filename') to store XML", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('cannotopentempfile', 'qformat_htmltable', $temp_xml_filename));
            return false;
        }

        // Write the XML contents to be transformed, and also include labels and icons data, to avoid having to use document() inside XSLT
        if (($nbytes = file_put_contents($temp_xml_filename, "<container><quiz>" . $content . "</quiz>" . $this->get_text_labels() . file_get_contents($iconfile_url) . "</container>")) == 0) {
            debugging("presave_process(): Failed to save XML data to temporary file ('$temp_xml_filename')", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('cannotwritetotempfile', 'qformat_htmltable', $temp_xml_filename . "(" . $nbytes . ")"));
            return false;
        }
        debugging("presave_process(): XML data saved to $temp_xml_filename", DEBUG_DEVELOPER, $backtrace);

        // Set parameters for XSLT transformation. Note that we cannot use arguments though
        $parameters = array (
            'course_name' => $this->course->fullname,
            'moodle_language' => current_language(),
            'moodle_release' => $CFG->release
        );

        debugging("presave_process(): Calling XSLT Pass 1 with stylesheet \"" . $stylesheet . "\"", DEBUG_DEVELOPER, $backtrace);
        $xsltproc = xslt_create();
        if(!($xslt_output = xslt_process($xsltproc, $temp_xml_filename, $stylesheet, null, null, $parameters))) {
            debugging("presave_process(): Transformation failed", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('transformationfailed', 'qformat_htmltable', "(XSLT: " . $stylesheet . "; XML: " . $temp_xml_filename . ")"));
            $this->debug_unlink($temp_xml_filename);
            return false;
        }
        $this->debug_unlink($temp_xml_filename);
        debugging("presave_process(): Transformation Pass 1 succeeded, XHTML output fragment = " . str_replace("\n", "", substr($xslt_output, 1, 200)), DEBUG_DEVELOPER, $backtrace);

        // Write the intermediate (Pass 1) XHTML contents to be transformed in Pass 2, re-using the temporary XML file, this time including the HTML template
        if (($nbytes = file_put_contents($temp_xml_filename, "<container>" . $xslt_output . "<htmltemplate>" . file_get_contents($htmltemplatefile_url) . "</htmltemplate></container>")) == 0) {
            debugging("presave_process(): Failed to save XHTML data to temporary file ('$temp_xml_filename')", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('cannotwritetotempfile', 'qformat_htmltable', $temp_xml_filename . "(" . $nbytes . ")"));
            return false;
        }
        debugging("presave_process(): Intermediate XHTML data saved to $temp_xml_filename", DEBUG_DEVELOPER, $backtrace);

        // Prepare for Pass 2 XSLT transformation
        $stylesheet = dirname(__FILE__) . "/" . $this->mqxml2html_stylesheet2;
        debugging("presave_process(): Calling XSLT Pass 2 with stylesheet \"" . $stylesheet . "\"", DEBUG_DEVELOPER, $backtrace);
        if(!($xslt_output = xslt_process($xsltproc, $temp_xml_filename, $stylesheet, null, null, $parameters))) {
            debugging("presave_process(): Pass 2 Transformation failed", DEBUG_DEVELOPER, $backtrace);
            echo $OUTPUT->notification(get_string('transformationfailed', 'qformat_htmltable', "(XSLT: " . $stylesheet . "; XHTML: " . $temp_xml_filename . ")"));
            $this->debug_unlink($temp_xml_filename);
            return false;
        }
        debugging("presave_process(): Transformation Pass 2 succeeded, HTML output fragment = " . str_replace("\n", "", substr($xslt_output, 400, 100)), DEBUG_DEVELOPER, $backtrace);

        $this->debug_unlink($temp_xml_filename);

        $content = $xslt_output;

        return $content;
    }   // end presave_process


    protected function debug_unlink($filename) {
        // declare empty array to prevent each debug message from including a complete backtrace
        $backtrace = array();

        debugging("debug_unlink(\"" . $filename . "\")", DEBUG_DEVELOPER, $backtrace);
        if (!debugging(null, DEBUG_DEVELOPER, null)) {
            unlink($filename);
        }
    }

    /**
     * Get all the text strings needed to fill in the HTML file labels in a language-dependent way
     *
     * A string containing XML data, populated from the language folders, is returned
     *
     * @return string
     */
    protected function get_text_labels() {
        $textstrings = array(
            'assignment' => array('uploaderror', 'uploadafile', 'uploadfiletoobig'),
            'grades' => array('item'),
            'moodle' => array('categoryname', 'no', 'yes', 'feedback', 'format', 'formathtml', 'formatmarkdown', 'formatplain', 'formattext', 'grade', 'question', 'tags', 'uploadserverlimit', 'uploadedfile'),
            'qtype_calculated' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'addmoreanswerblanks'),
            'qtype_description' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary'),
            'qtype_essay' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'allowattachments', 'graderinfo', 'formateditor', 'formateditorfilepicker', 'formatmonospaced', 'formatplain', 'responsefieldlines', 'responseformat', 'responsetemplate', 'responsetemplate_help'),
            'qtype_match' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'blanksforxmorequestions', 'filloutthreeqsandtwoas'),
            'qtype_multianswer' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary'), // 'Embedded answers (Cloze)'
            'qtype_multichoice' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'answerhowmany', 'answernumbering', 'answersingleno', 'answersingleyes', 'choiceno', 'correctfeedback', 'fillouttwochoices', 'incorrectfeedback', 'partiallycorrectfeedback', 'shuffleanswers'),
            'qtype_shortanswer' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'addmoreanswerblanks', 'casesensitive', 'filloutoneanswer'),
            'qtype_truefalse' => array('pluginname', 'pluginnameadding', 'pluginnameediting', 'pluginnamesummary', 'false', 'true'),
            'question' => array('addmorechoiceblanks', 'category', 'combinedfeedback', 'correctfeedbackdefault', 'defaultmark', 'fillincorrect', 'flagged', 'flagthisquestion', 'generalfeedback', 'addanotherhint', 'hintn', 'hintnoptions', 'hinttext', 'clearwrongparts', 'penaltyforeachincorrecttry', 'incorrect', 'incorrectfeedbackdefault', 'partiallycorrect', 'partiallycorrectfeedbackdefault', 'questions', 'questionx', 'questioncategory', 'questiontext', 'specificfeedback', 'shownumpartscorrect', 'shownumpartscorrectwhenfinished'),
            'quiz' => array('answer', 'answers', 'choice', 'correct', 'correctanswers', 'defaultgrade', 'generalfeedback', 'feedback', 'incorrect', 'penaltyfactor', 'shuffle'),
            'repository_upload' => array('pluginname', 'pluginname_help', 'upload_error_no_file')
            );

        $expout = "<moodlelabels>\n";
        foreach ($textstrings as $type_group => $group_array) {
            foreach ($group_array as $string_id) {
                $name_string = $type_group . '_' . $string_id;
                $expout .= '<data name="' . $name_string . '"><value>' . get_string($string_id, $type_group) . "</value></data>\n";
            }
        }
        $expout .= "</moodlelabels>";

        return $expout;
    }
}
?>
