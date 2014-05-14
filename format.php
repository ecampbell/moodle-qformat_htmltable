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

// htmltable just extends XML import/export
require_once("$CFG->dirroot/question/format/xml/format.php");

// Include XSLT processor functions
require_once(dirname(__FILE__) . "/xsl_emulate_xslt.inc");

class qformat_htmltable extends qformat_xml {

    private $question_icons = 'qtype_icons_base64.xml';  // Data file containing base64-encoded question icon images
    private $htmlfile_template = 'htmlfile_template.html';  // XHTML template file containing CSS style definitions
    private $mqxml2html_stylesheet1 = 'mqxml2html_pass1.xsl';      // XSLT stylesheet containing code to convert Moodle Question XML into XHTML
    private $mqxml2html_stylesheet2 = 'mqxml2html_pass2.xsl';      // XSLT stylesheet containing code to convert initial XHTML with CDATA section into XHTML for question export

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
     * Convert the Moodle Question XML into XHTML table format
     * just prior to the file being saved
     *
     * Use an XSLT script to do the job, as it is much easier to implement this,
     * and Moodle sites should have an XSLT processor available.
     *
     * @param string  $content Question XML text
     * @return string XHTML text
     */
    function presave_process( $content ) {
        // override method to allow us convert to XHTML table format
        global $CFG, $USER;
        global $OUTPUT;

        debugging(__FUNCTION__ . '($content = "' . str_replace("\n", "", substr($content, 80, 50)) . ' ...")', DEBUG_DEVELOPER);

        // XSLT stylesheet to convert Moodle Question XML into XHTML format
        $stylesheet = dirname(__FILE__) . "/" . $this->mqxml2html_stylesheet1;
        // XHTML template for XHTML file CSS styles formatting
        $htmltemplatefile_path = dirname(__FILE__) . "/" . $this->htmlfile_template;
        $iconfile_path = dirname(__FILE__) . "/" . $this->question_icons;

        // Check that XSLT is installed, and the XSLT stylesheet and XHTML template are present
        if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": XSLT not installed", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('xsltunavailable', 'qformat_htmltable'));
            return false;
        } else if(!file_exists($stylesheet)) {
            // XSLT stylesheet to transform Moodle Question XML into XHTML doesn't exist
            debugging(__FUNCTION__ . ":" . __LINE__ . ": XSLT stylesheet missing: $stylesheet", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('stylesheetunavailable', 'qformat_htmltable', $stylesheet));
            return false;
        }

        // Check that there is some content to convert into XHTML
        if (!strlen($content)) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": No XML questions in category", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('noquestions', 'qformat_htmltable'));
            return false;
        }

        debugging(__FUNCTION__ . ":" . __LINE__ . ": preflight checks complete, xmldata length = " . strlen($content), DEBUG_DEVELOPER);

        // Create a temporary file to store the XML content to transform
        if (!($temp_xml_filename = tempnam($CFG->dataroot . "/temp/", "ht1-"))) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Cannot open temporary file ('$temp_xml_filename') to store XML", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('cannotopentempfile', 'qformat_htmltable', $temp_xml_filename));
            return false;
        }

        // Clean the CDATA sections in all question components, in case they contain badly-formed HTML
        $clean_content = $this->clean_all_questions($content);

        // Write the XML contents to be transformed, and also include labels and icons data, to avoid having to use document() inside XSLT
        if (($nbytes = file_put_contents($temp_xml_filename, "<container>\n<quiz>" . $clean_content . "\n</quiz>\n" . $this->get_text_labels() . file_get_contents($iconfile_path) . "</container>")) == 0) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Failed to save XML data to temporary file ('$temp_xml_filename')", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('cannotwritetotempfile', 'qformat_htmltable', $temp_xml_filename . "(" . $nbytes . ")"));
            return false;
        }
        debugging(__FUNCTION__ . ":" . __LINE__ . ": XML data saved to $temp_xml_filename", DEBUG_DEVELOPER);

        // Get the locale, so we can set the language and locale in XHTML for better spell-checking
        $locale_country = $CFG->country;
        if (empty($CFG->country) or $CFG->country == 0 or $CFG->country == '0') {
            $admin_user_config = get_admin();
            $locale_country = $admin_user_config->country;
        }

        // Set parameters for XSLT transformation. Note that we cannot use arguments though
        $parameters = array (
            'course_name' => $this->course->fullname,
            'moodle_country' => $locale_country,
            'moodle_language' => current_language(),
            'moodle_textdirection' => (right_to_left())? 'rtl': 'ltr',
            'moodle_release' => $CFG->release,
            'transformationfailed' => get_string('transformationfailed', 'qformat_htmltable', "(XSLT: $this->mqxml2word_stylesheet2)")
        );

        debugging(__FUNCTION__ . ":" . __LINE__ . ": Calling XSLT Pass 1 with stylesheet \"" . $stylesheet . "\"", DEBUG_DEVELOPER);
        $xsltproc = xslt_create();
        if(!($xslt_output = xslt_process($xsltproc, $temp_xml_filename, $stylesheet, null, null, $parameters))) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Transformation failed", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('transformationfailed', 'qformat_htmltable', "(XSLT: " . $stylesheet . "; XML: " . $temp_xml_filename . ")"));
            $this->debug_unlink($temp_xml_filename);
            return false;
        }
        $this->debug_unlink($temp_xml_filename);
        debugging(__FUNCTION__ . ":" . __LINE__ . ": Transformation Pass 1 succeeded, XHTML output fragment = " . str_replace("\n", "", substr($xslt_output, 0, 200)), DEBUG_DEVELOPER);

        // Write the intermediate (Pass 1) XHTML contents to be transformed in Pass 2, using a temporary XML file, this time including the HTML template too
        $temp_xml_filename = tempnam($CFG->dataroot . "/temp/", "ht2-");
        if (($nbytes = file_put_contents($temp_xml_filename, "<container>\n" . $xslt_output . "\n<htmltemplate>\n" . file_get_contents($htmltemplatefile_path) . "\n</htmltemplate>\n" . $this->get_text_labels() . "\n</container>")) == 0) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Failed to save XHTML data to temporary file ('$temp_xml_filename')", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('cannotwritetotempfile', 'qformat_htmltable', $temp_xml_filename . "(" . $nbytes . ")"));
            return false;
        }
        debugging(__FUNCTION__ . ":" . __LINE__ . ": Intermediate XHTML data saved to $temp_xml_filename", DEBUG_DEVELOPER);

        // Prepare for Pass 2 XSLT transformation
        $stylesheet = dirname(__FILE__) . "/" . $this->mqxml2html_stylesheet2;
        debugging(__FUNCTION__ . ":" . __LINE__ . ": Calling XSLT Pass 2 with stylesheet \"" . $stylesheet . "\"", DEBUG_DEVELOPER);
        if(!($xslt_output = xslt_process($xsltproc, $temp_xml_filename, $stylesheet, null, null, $parameters))) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Pass 2 Transformation failed", DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('transformationfailed', 'qformat_htmltable', "(XSLT: " . $stylesheet . "; XHTML: " . $temp_xml_filename . ")"));
            $this->debug_unlink($temp_xml_filename);
            return false;
        }
        debugging(__FUNCTION__ . ":" . __LINE__ . ": Transformation Pass 2 succeeded, HTML output fragment = " . str_replace("\n", "", substr($xslt_output, 400, 100)), DEBUG_DEVELOPER);

        $this->debug_unlink($temp_xml_filename);

        $content = $xslt_output;

        return $content;
    }   // end presave_process

    /*
     * Delete temporary files if debugging disabled
     */
    private function debug_unlink($filename) {
        if (!debugging(null, DEBUG_DEVELOPER)) {
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
    private function get_text_labels() {
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
            'quiz' => array('answer', 'answers', 'choice', 'correct', 'correctanswers', 'defaultgrade', 'generalfeedback', 'feedback', 'incorrect', 'penaltyfactor', 'shuffle')
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

    /**
     * Clean HTML markup inside question text element content
     *
     * A string containing Moodle Question XML with clean HTML inside the text elements is returned
     *
     * @return string
     */
    private function clean_all_questions($input_string) {
        debugging(__FUNCTION__ . "(input_string = " . str_replace("\n", "", substr($input_string, 0, 1000)) . " ...)", DEBUG_DEVELOPER);
        // Start assembling the cleaned output string, starting with empty
        $clean_output_string =  "";

        // Split the string into questions in order to check the text fields for clean HTML
        $found_questions = preg_match_all('~(.*?)<question type="([^"]*)"[^>]*>(.*?)</question>~s', $input_string, $question_matches, PREG_SET_ORDER);
        $n_questions = count($question_matches);
        if ($found_questions === FALSE or $found_questions == 0) {
            debugging(__FUNCTION__ . "() -> Cannot decompose questions", DEBUG_DEVELOPER);
            return $input_string;
        }
        //debugging(__FUNCTION__ . ":" . __LINE__ . ": " . $n_questions . " questions found", DEBUG_DEVELOPER);

        // Split the questions into text strings to check the HTML
        for ($i = 0; $i < $n_questions; $i++) {
            $question_type = $question_matches[$i][2];
            $question_content = $question_matches[$i][3];
            debugging(__FUNCTION__ . ":" . __LINE__ . ": Processing question " . $i + 1 . " of $n_questions, type $question_type, question length = " . strlen($question_content), DEBUG_DEVELOPER);
            // Split the question into chunks at CDATA boundaries, using an ungreedy search (?), and matching across newlines (s modifier)
            $found_cdata_sections = preg_match_all('~(.*?)<\!\[CDATA\[(.*?)\]\]>~s', $question_content, $cdata_matches, PREG_SET_ORDER);
            if ($found_cdata_sections === FALSE) {
                debugging(__FUNCTION__ . ":" . __LINE__ . ": Cannot decompose CDATA sections in question " . $i + 1, DEBUG_DEVELOPER);
                $clean_output_string .= $question_matches[$i][0];
            } else if ($found_cdata_sections != 0) {
                $n_cdata_sections = count($cdata_matches);
                debugging(__FUNCTION__ . ":" . __LINE__ . ": " . $n_cdata_sections  . " CDATA sections found in question " . $i + 1 . ", question length = " . strlen($question_content), DEBUG_DEVELOPER);
                // Found CDATA sections, so first add the question start tag and then process the body
                $clean_output_string .= '<question type="' . $question_type . '">';

                // Process content of each CDATA section to clean the HTML
                for ($j = 0; $j < $n_cdata_sections; $j++) {
                    $cdata_content = $cdata_matches[$j][2];
                    $clean_cdata_content = $this->clean_html_text($cdata_matches[$j][2]);

                    // Add all the text before the first CDATA start boundary, and the cleaned string, to the output string
                    $clean_output_string .= $cdata_matches[$j][1] . '<![CDATA[' . $clean_cdata_content . ']]>' ;
                } // End CDATA section loop

                // Add the text after the last CDATA section closing delimiter
                $text_after_last_CDATA_string = substr($question_matches[$i][0], strrpos($question_matches[$i][0], "]]>") + 3);
                $clean_output_string .= $text_after_last_CDATA_string;
            } else {
                //debugging(__FUNCTION__ . ":" . __LINE__ . ": No CDATA sections in question " . $i + 1, DEBUG_DEVELOPER);
                $clean_output_string .= $question_matches[$i][0];
            }
        } // End question element loop

        debugging(__FUNCTION__ . "() -> " . substr($clean_output_string, 0, 1000) . "..." . substr($clean_output_string, -1000), DEBUG_DEVELOPER);
        return $clean_output_string;
}

    /**
     * Clean HTML content
     *
     * A string containing clean XHTML is returned
     *
     * @return string
     */
    private function clean_html_text($text_content_string) {
        $tidy_type = "strip_tags";

        // Check if Tidy extension loaded, and use it to clean the CDATA section if present
        if (extension_loaded('tidy')) {
            // cf. http://tidy.sourceforge.net/docs/quickref.html
            $tidy_type = "tidy";
            $tidy_config = array(
                'bare' => true, // Strip Microsoft Word 2000-specific markup
                'clean' => true, // Replace presentational with structural tags 
                'word-2000' => true, // Strip out other Microsoft Word gunk
                'drop-font-tags' => true, // Discard font
                'drop-proprietary-attributes' => true, // Discard font
                'output-xhtml' => true, // Output XML, to format empty elements properly
                'show-body-only'   => true,
            );
            $clean_html = tidy_repair_string($text_content_string, $tidy_config, 'utf8');
        } else { 
            // Tidy not available, so just strip most HTML tags except character-level markup and table tags
            $clean_html = strip_tags($text_content_string, "<b><br><em><i><img><strong><sub><sup><u><table><tbody><td><th><thead><tr>");

            // The strip_tags function treats empty elements like HTML, not XHTML, so fix <br> and <img src=""> manually (i.e. <br/>, <img/>)
            $clean_html = preg_replace('~<img([^>]*?)/?>~si', '<img$1/>', $clean_html, PREG_SET_ORDER);
            $clean_html = preg_replace('~<br([^>]*?)/?>~si', '<br/>', $clean_html, PREG_SET_ORDER);

            // Look for spurious img/@complete attribute and try and fix it
            $found_img_complete_attr = preg_match_all('~(.*?)<img([^>]*complete="true"[^>]*?)/>(.*)~s', $clean_html, $complete_attr_matches, PREG_SET_ORDER);
            $n_attr_matches = count($complete_attr_matches);
            if ($found_img_complete_attr !== FALSE and $found_img_complete_attr != 0) {
                debugging(__FUNCTION__ . ":" . __LINE__ . ": $n_attr_matches illegal img/@complete attributes found: |" . $complete_attr_matches[0][0] . "|", DEBUG_DEVELOPER);
                // Process the illegal attribute
                $cleaned_images_string = "";
                for ($i = 0; $i < $n_attr_matches; $i++) {
                    // Delete the attribute, which may occur more than once inside a single img element
                    $img_attrs = str_replace('complete="true"', '', $complete_attr_matches[$i][2]);
                    $revised_img_element = "<img" . $img_attrs . "/>";
                    debugging(__FUNCTION__ . ":" . __LINE__ . ": revised img element: |" . $revised_img_element . "|", DEBUG_DEVELOPER);
                    $cleaned_images_string .= $complete_attr_matches[$i][1] . $revised_img_element . $complete_attr_matches[$i][3];
                }
                $clean_html = $cleaned_images_string;
            }
        } // End HTML tidy using strip_tags

        // Fix up filenames after @@PLUGINFILE@@ to replace URL-encoded characters with ordinary characters
        $found_pluginfilenames = preg_match_all('~(.*?)<img src="@@PLUGINFILE@@/([^"]*)(.*)~s', $clean_html, $pluginfile_matches, PREG_SET_ORDER);
        $n_matches = count($pluginfile_matches);
        if ($found_pluginfilenames !== FALSE and $found_pluginfilenames != 0) {
            $urldecoded_string = "";
            // Process the possibly-URL-escaped filename so that it matches the name in the file element
            for ($i = 0; $i < $n_matches; $i++) {
                // Decode the filename and add the surrounding text
                $decoded_filename = urldecode($pluginfile_matches[$i][2]);
                $urldecoded_string .= $pluginfile_matches[$i][1] . '<img src="@@PLUGINFILE@@/' . $decoded_filename . $pluginfile_matches[$i][3];
            }
            $clean_html = $urldecoded_string;
        }

        // Strip soft hyphens (0xAD, or decimal 173)
        $clean_html = preg_replace('/\xad/u', '', $clean_html);

        debugging(__FUNCTION__ . "() [using " . $tidy_type . "] -> |" . $clean_html . "|", DEBUG_DEVELOPER);
        return $clean_html;
    }
}
?>
