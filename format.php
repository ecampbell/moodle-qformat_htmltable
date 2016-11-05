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

        global $CFG;
        // Release-independent list of all strings required in the XSLT stylesheets for labels etc.
        $textstrings = array(
            'grades' => array('item'),
            'moodle' => array('categoryname', 'no', 'yes', 'feedback', 'format', 'formathtml', 'formatmarkdown', 'formatplain', 'formattext', 'grade', 'question', 'tags'),
            'qformat_wordtable' => array('cloze_instructions', 'cloze_distractor_column_label', 'cloze_feedback_column_label', 'cloze_mcformat_label', 'description_instructions', 'essay_instructions', 'interface_language_mismatch', 'multichoice_instructions', 'truefalse_instructions', 'transformationfailed'),
            'qtype_description' => array('pluginnamesummary'),
            'qtype_essay' => array('allowattachments', 'graderinfo', 'formateditor', 'formateditorfilepicker', 'formatmonospaced', 'formatplain', 'pluginnamesummary', 'responsefieldlines', 'responseformat'),
            'qtype_match' => array('filloutthreeqsandtwoas'),
            'qtype_multichoice' => array('answernumbering', 'choiceno', 'correctfeedback', 'incorrectfeedback', 'partiallycorrectfeedback', 'pluginnamesummary', 'shuffleanswers'),
            'qtype_shortanswer' => array('casesensitive', 'filloutoneanswer'),
            'qtype_truefalse' => array('false', 'true'),
            'question' => array('category', 'clearwrongparts', 'defaultmark', 'generalfeedback', 'hintn','penaltyforeachincorrecttry', 'questioncategory','shownumpartscorrect', 'shownumpartscorrectwhenfinished'),
            'quiz' => array('answer', 'answers', 'casesensitive', 'correct', 'correctanswers', 'defaultgrade', 'incorrect', 'shuffle')
            );

        // Append Moodle release-specific text strings, thus avoiding any errors being generated when absent strings are requested
        if ($CFG->release < '2.0') {
            $textstrings['quiz'][] = 'choice';
            $textstrings['quiz'][] = 'penaltyfactor';
        } else if ($CFG->release >= '2.5') {
            $textstrings['qtype_essay'][] = 'responsetemplate';
            $textstrings['qtype_essay'][] = 'responsetemplate_help';
            $textstrings['qtype_match'][] = 'blanksforxmorequestions';
            $textstrings['question'][] = 'addmorechoiceblanks';
            $textstrings['question'][] = 'correctfeedbackdefault';
            $textstrings['question'][] = 'hintnoptions';
            $textstrings['question'][] = 'incorrectfeedbackdefault';
            $textstrings['question'][] = 'partiallycorrectfeedbackdefault';
        }
        if ($CFG->release >= '2.7') {
            $textstrings['qtype_essay'][] = 'attachmentsrequired';
        }
        if ($CFG->release >= '2.9') {
            $textstrings['qtype_essay'][] = 'responserequired';
            $textstrings['qtype_essay'][] = 'responseisrequired';
            $textstrings['qtype_essay'][] = 'responsenotrequired';
        }

        $expout = "<moodlelabels>\n";
        foreach ($textstrings as $type_group => $group_array) {
            foreach ($group_array as $string_id) {
                $name_string = $type_group . '_' . $string_id;
                $expout .= '<data name="' . $name_string . '"><value>' . get_string($string_id, $type_group) . "</value></data>\n";
            }
        }
        $expout .= "</moodlelabels>";
        // Convert HTML to XHTML markup, needed in Polish
        $expout = str_replace("<br>", "<br/>", $expout);
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
            // Has the question been imported using WordTable? If so, assume it is clean and don't process it
            //$imported_from_wordtable = preg_match('~ImportFromWordTable~', $question_content);
            //if ($imported_from_wordtable !== FALSE and $imported_from_wordtable != 0) {
            //    debugging(__FUNCTION__ . ":" . __LINE__ . ": Skip cleaning previously imported question " . $i + 1, DEBUG_DEVELOPER);
            //    $clean_output_string .= $question_matches[$i][0];
            //} else if ($found_cdata_sections === FALSE) {
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

        //debugging(__FUNCTION__ . "(text_content_string = " . str_replace("\n", "", substr($text_content_string, 0, 100)) . " ...)", DEBUG_DEVELOPER);
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
            $keep_tag_list = "<b><br><em><i><img><strong><sub><sup><u><table><tbody><td><th><thead><tr>";
            $keep_tag_list .= "<p>";
            $clean_html = strip_tags($text_content_string, $keep_tag_list);

            // The strip_tags function treats empty elements like HTML, not XHTML, so fix <br> and <img src=""> manually (i.e. <br/>, <img/>)
            $clean_html = preg_replace('~<img([^>]*?)/?>~si', '<img$1/>', $clean_html, PREG_SET_ORDER);
            $clean_html = preg_replace('~<br([^>]*?)/?>~si', '<br/>', $clean_html, PREG_SET_ORDER);

            // Look for named character entities (e.g. &nbsp;) and replace them with numeric ones, to avoid XSLT processing errors
            $found_numeric_entities = preg_match('~&[a-zA-Z]~', $clean_html);
            if ($found_numeric_entities !== FALSE and $found_numeric_entities != 0) {
                $clean_html = $this->clean_entities($clean_html);
            }
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

    /**
     * Replace named with numeric entities in XML
     *
     * A string containing XML with numeric entities. Note that some entities may appear OK in Word, while others don't seem to map properly to Word Unicode.
     * Also note that many numeric entities aren't supported in HTML, so won't appear in the questions, even though they look OK in Word.
     *
     * @return string
     */
    private function clean_entities($string) {
        debugging(__FUNCTION__ . "(string = " . str_replace("\n", "", substr($string, 0, 100)) . " ...)", DEBUG_DEVELOPER);

        $string = str_replace("&Aacute;", "&#193;", $string);
        $string = str_replace("&aacute;", "&#225;", $string);
        $string = str_replace("&Acirc;", "&#194;", $string);
        $string = str_replace("&acirc;", "&#226;", $string);
        $string = str_replace("&acute;", "&#180;", $string);
        $string = str_replace("&AElig;", "&#198;", $string);
        $string = str_replace("&aelig;", "&#230;", $string);
        $string = str_replace("&Agrave;", "&#192;", $string);
        $string = str_replace("&agrave;", "&#224;", $string);
        $string = str_replace("&alefsym;", "&#8501;", $string);
        $string = str_replace("&aleph;", "&#x2135;", $string);
        $string = str_replace("&Alpha;", "&#913;", $string);
        $string = str_replace("&alpha;", "&#945;", $string);
        //$string = str_replace("&amp;", "&#38;", $string);
        $string = str_replace("&and;", "&#x2227;", $string);
        $string = str_replace("&ang90;", "&#x221F;", $string);
        $string = str_replace("&ang;", "&#8736;", $string);
        $string = str_replace("&angsph;", "&#x2222;", $string);
        $string = str_replace("&angst;", "&#x212B;", $string);
        $string = str_replace("&ap;", "&#x2248;", $string);
        //$string = str_replace("&apos;", "&#x0027;", $string);
        $string = str_replace("&Aring;", "&#197;", $string);
        $string = str_replace("&aring;", "&#229;", $string);
        $string = str_replace("&ast;", "&#x002A;", $string);
        $string = str_replace("&asymp;", "&#8776;", $string);
        $string = str_replace("&Atilde;", "&#195;", $string);
        $string = str_replace("&atilde;", "&#227;", $string);
        $string = str_replace("&Auml;", "&#196;", $string);
        $string = str_replace("&auml;", "&#228;", $string);
        $string = str_replace("&becaus;", "&#x2235;", $string);
        $string = str_replace("&bernou;", "&#x212C;", $string);
        $string = str_replace("&Beta;", "&#914;", $string);
        $string = str_replace("&beta;", "&#946;", $string);
        $string = str_replace("&blank;", "&#x2423;", $string);
        $string = str_replace("&blk12;", "&#x2592;", $string);
        $string = str_replace("&blk14;", "&#x2591;", $string);
        $string = str_replace("&blk34;", "&#x2593;", $string);
        $string = str_replace("&block;", "&#x2588;", $string);
        $string = str_replace("&bottom;", "&#x22A5;", $string);
        $string = str_replace("&brvbar;", "&#166;", $string);
        $string = str_replace("&brvbar;", "&#x00A6;", $string);
        $string = str_replace("&bsol;", "&#x005C;", $string);
        $string = str_replace("&bull;", "&#x2022;", $string);
        $string = str_replace("&cap;", "&#x2229;", $string);
        $string = str_replace("&caret;", "&#x2041;", $string);
        $string = str_replace("&Ccedil;", "&#199;", $string);
        $string = str_replace("&ccedil;", "&#231;", $string);
        $string = str_replace("&cedil;", "&#184;", $string);
        $string = str_replace("&cent;", "&#x00A2;", $string);
        $string = str_replace("&check;", "&#x2713;", $string);
        $string = str_replace("&Chi;", "&#935;", $string);
        $string = str_replace("&chi;", "&#967;", $string);
        $string = str_replace("&cir;", "&#x25CB;", $string);
        $string = str_replace("&clubs;", "&#x2663;", $string);
        $string = str_replace("&colon;", "&#x003A;", $string);
        $string = str_replace("&comma;", "&#x002C;", $string);
        $string = str_replace("&commat;", "&#x0040;", $string);
        $string = str_replace("&compfn;", "&#x2218;", $string);
        $string = str_replace("&cong;", "&#x2245;", $string);
        $string = str_replace("&conint;", "&#x222E;", $string);
        $string = str_replace("&copy;", "&#x00A9;", $string);
        $string = str_replace("&copysr;", "&#x2117;", $string);
        $string = str_replace("&crarr;", "&#8629;", $string);
        $string = str_replace("&cross;", "&#x2717;", $string);
        $string = str_replace("&cup;", "&#x222A;", $string);
        $string = str_replace("&curren;", "&#x00A4;", $string);
        $string = str_replace("&dagger;", "&#x2020;", $string);
        $string = str_replace("&Dagger;", "&#x2021;", $string);
        $string = str_replace("&dArr;", "&#8659;", $string);
        $string = str_replace("&darr;", "&#x2193;", $string);
        $string = str_replace("&dash;", "&#x2010;", $string);
        $string = str_replace("&deg;", "&#x00B0;", $string);
        $string = str_replace("&Delta;", "&#916;", $string);
        $string = str_replace("&delta;", "&#948;", $string);
        $string = str_replace("&diams;", "&#9830;", $string);
        $string = str_replace("&diams;", "&#x2666;", $string);
        $string = str_replace("&divide;", "&#x00F7;", $string);
        $string = str_replace("&dlcrop;", "&#x230D;", $string);
        $string = str_replace("&dollar;", "&#x0024;", $string);
        $string = str_replace("&Dot;", "&#x00A8;", $string);
        $string = str_replace("&DotDot;", "&#x20DC;", $string);
        $string = str_replace("&drcrop;", "&#x230C;", $string);
        $string = str_replace("&dtri;", "&#x25BF;", $string);
        $string = str_replace("&dtrif;", "&#x25BE;", $string);
        $string = str_replace("&Eacute;", "&#201;", $string);
        $string = str_replace("&eacute;", "&#233;", $string);
        $string = str_replace("&Ecirc;", "&#202;", $string);
        $string = str_replace("&ecirc;", "&#234;", $string);
        $string = str_replace("&Egrave;", "&#200;", $string);
        $string = str_replace("&egrave;", "&#232;", $string);
        $string = str_replace("&empty;", "&#8709;", $string);
        $string = str_replace("&emsp13;", "&#x2004;", $string);
        $string = str_replace("&emsp14;", "&#x2005;", $string);
        $string = str_replace("&emsp;", "&#x2003;", $string);
        $string = str_replace("&ensp;", "&#8194;", $string);
        $string = str_replace("&ensp;", "&#x2002;", $string);
        $string = str_replace("&Epsilon;", "&#917;", $string);
        $string = str_replace("&epsilon;", "&#949;", $string);
        $string = str_replace("&equals;", "&#x003D;", $string);
        $string = str_replace("&equiv;", "&#x2261;", $string);
        $string = str_replace("&Eta;", "&#919;", $string);
        $string = str_replace("&eta;", "&#951;", $string);
        $string = str_replace("&ETH;", "&#208;", $string);
        $string = str_replace("&eth;", "&#240;", $string);
        $string = str_replace("&Euml;", "&#203;", $string);
        $string = str_replace("&euml;", "&#235;", $string);
        $string = str_replace("&excl;", "&#x0021;", $string);
        $string = str_replace("&exist;", "&#8707;", $string);
        $string = str_replace("&exist;", "&#x2203;", $string);
        $string = str_replace("&female;", "&#x2640;", $string);
        $string = str_replace("&ffilig;", "&#xFB03;", $string);
        $string = str_replace("&fflig;", "&#xFB00;", $string);
        $string = str_replace("&ffllig;", "&#xFB04;", $string);
        $string = str_replace("&filig;", "&#xFB01;", $string);
        $string = str_replace("&flat;", "&#x266D;", $string);
        $string = str_replace("&fllig;", "&#xFB02;", $string);
        $string = str_replace("&fnof;", "&#402;", $string);
        $string = str_replace("&fnof;", "&#x0192;", $string);
        $string = str_replace("&forall;", "&#x2200;", $string);
        $string = str_replace("&frac12;", "&#x00BD;", $string);
        $string = str_replace("&frac13;", "&#x2153;", $string);
        $string = str_replace("&frac14;", "&#x00BC;", $string);
        $string = str_replace("&frac15;", "&#x2155;", $string);
        $string = str_replace("&frac16;", "&#x2159;", $string);
        $string = str_replace("&frac18;", "&#x215B;", $string);
        $string = str_replace("&frac23;", "&#x2154;", $string);
        $string = str_replace("&frac25;", "&#x2156;", $string);
        $string = str_replace("&frac34;", "&#x00BE;", $string);
        $string = str_replace("&frac35;", "&#x2157;", $string);
        $string = str_replace("&frac38;", "&#x215C;", $string);
        $string = str_replace("&frac45;", "&#x2158;", $string);
        $string = str_replace("&frac56;", "&#x215A;", $string);
        $string = str_replace("&frac58;", "&#x215D;", $string);
        $string = str_replace("&frac78;", "&#x215E;", $string);
        $string = str_replace("&frasl;", "&#8260;", $string);
        $string = str_replace("&Gamma;", "&#915;", $string);
        $string = str_replace("&gamma;", "&#947;", $string);
        $string = str_replace("&ge;", "&#x2265;", $string);
        //$string = str_replace("&gt;", "&#x003E;", $string);
        $string = str_replace("&hairsp;", "&#x200A;", $string);
        $string = str_replace("&half;", "&#x00BD;", $string);
        $string = str_replace("&hamilt;", "&#x210B;", $string);
        $string = str_replace("&harr;", "&#8596;", $string);
        $string = str_replace("&hArr;", "&#8660;", $string);
        $string = str_replace("&hearts;", "&#x2665;", $string);
        $string = str_replace("&hellip;", "&#x2026;", $string);
        $string = str_replace("&horbar;", "&#x2015;", $string);
        $string = str_replace("&hybull;", "&#x2043;", $string);
        $string = str_replace("&hyphen;", "&#x002D;", $string);
        $string = str_replace("&Iacute;", "&#205;", $string);
        $string = str_replace("&iacute;", "&#237;", $string);
        $string = str_replace("&Icirc;", "&#206;", $string);
        $string = str_replace("&icirc;", "&#238;", $string);
        $string = str_replace("&iexcl;", "&#161;", $string);
        $string = str_replace("&iexcl;", "&#x00A1;", $string);
        $string = str_replace("&iff;", "&#x21D4;", $string);
        $string = str_replace("&Igrave;", "&#204;", $string);
        $string = str_replace("&igrave;", "&#236;", $string);
        $string = str_replace("&image;", "&#8465;", $string);
        $string = str_replace("&incare;", "&#x2105;", $string);
        $string = str_replace("&infin;", "&#x221E;", $string);
        $string = str_replace("&int;", "&#x222B;", $string);
        $string = str_replace("&Iota;", "&#921;", $string);
        $string = str_replace("&iota;", "&#953;", $string);
        $string = str_replace("&iquest;", "&#x00BF;", $string);
        $string = str_replace("&isin;", "&#x220A;", $string);
        $string = str_replace("&Iuml;", "&#207;", $string);
        $string = str_replace("&iuml;", "&#239;", $string);
        $string = str_replace("&Kappa;", "&#922;", $string);
        $string = str_replace("&kappa;", "&#954;", $string);
        $string = str_replace("&lagran;", "&#x2112;", $string);
        $string = str_replace("&Lambda;", "&#923;", $string);
        $string = str_replace("&lambda;", "&#955;", $string);
        $string = str_replace("&lang;", "&#x3008;", $string);
        $string = str_replace("&laquo;", "&#x00AB;", $string);
        $string = str_replace("&larr;", "&#x2190;", $string);
        $string = str_replace("&lArr;", "&#x21D0;", $string);
        $string = str_replace("&lceil;", "&#8968;", $string);
        $string = str_replace("&lcub;", "&#x007B;", $string);
        $string = str_replace("&ldquo;", "&#x201C;", $string);
        $string = str_replace("&ldquor;", "&#x201E;", $string);
        $string = str_replace("&le;", "&#x2264;", $string);
        $string = str_replace("&lfloor;", "&#8970;", $string);
        $string = str_replace("&lhblk;", "&#x2584;", $string);
        $string = str_replace("&lowast;", "&#x2217;", $string);
        $string = str_replace("&lowbar;", "&#x005F;", $string);
        $string = str_replace("&loz;", "&#x25CA;", $string);
        $string = str_replace("&lozf;", "&#x2726;", $string);
        $string = str_replace("&lpar;", "&#x0028;", $string);
        $string = str_replace("&lrm;", "&#8206;", $string);
        $string = str_replace("&lsaquo;", "&#8249;", $string);
        $string = str_replace("&lsqb;", "&#x005B;", $string);
        $string = str_replace("&lsquo;", "&#x2018;", $string);
        $string = str_replace("&lsquor;", "&#x201A;", $string);
        //$string = str_replace("&lt;", "&#38;#60;", $string);
        $string = str_replace("&ltri;", "&#x25C3;", $string);
        $string = str_replace("&ltrif;", "&#x25C2;", $string);
        $string = str_replace("&macr;", "&#175;", $string);
        $string = str_replace("&male;", "&#x2642;", $string);
        $string = str_replace("&malt;", "&#x2720;", $string);
        $string = str_replace("&marker;", "&#x25AE;", $string);
        $string = str_replace("&mdash;", "&#x2014;", $string);
        $string = str_replace("&micro;", "&#x00B5;", $string);
        $string = str_replace("&middot;", "&#x00B7;", $string);
        $string = str_replace("&minus;", "&#x2212;", $string);
        $string = str_replace("&mldr;", "&#x2026;", $string);
        $string = str_replace("&mnplus;", "&#x2213;", $string);
        $string = str_replace("&Mu;", "&#924;", $string);
        $string = str_replace("&mu;", "&#956;", $string);
        $string = str_replace("&nabla;", "&#x2207;", $string);
        $string = str_replace("&natur;", "&#x266E;", $string);
        $string = str_replace("&nbsp;", "&#x00A0;", $string);
        $string = str_replace("&ndash;", "&#x2013;", $string);
        $string = str_replace("&ne;", "&#x2260;", $string);
        $string = str_replace("&ni;", "&#x220D;", $string);
        $string = str_replace("&nldr;", "&#x2025;", $string);
        $string = str_replace("&not;", "&#x00AC;", $string);
        $string = str_replace("&notin;", "&#x2209;", $string);
        $string = str_replace("&nsub;", "&#8836;", $string);
        $string = str_replace("&Ntilde;", "&#209;", $string);
        $string = str_replace("&ntilde;", "&#241;", $string);
        $string = str_replace("&Nu;", "&#925;", $string);
        $string = str_replace("&nu;", "&#957;", $string);
        $string = str_replace("&num;", "&#x0023;", $string);
        $string = str_replace("&numsp;", "&#x2007;", $string);
        $string = str_replace("&Oacute;", "&#211;", $string);
        $string = str_replace("&oacute;", "&#243;", $string);
        $string = str_replace("&Ocirc;", "&#212;", $string);
        $string = str_replace("&ocirc;", "&#244;", $string);
        $string = str_replace("&oelig;", "&#339;", $string);
        $string = str_replace("&Ograve;", "&#210;", $string);
        $string = str_replace("&ograve;", "&#242;", $string);
        $string = str_replace("&ohm;", "&#x2126;", $string);
        $string = str_replace("&oline;", "&#8254;", $string);
        $string = str_replace("&Omega;", "&#937;", $string);
        $string = str_replace("&omega;", "&#969;", $string);
        $string = str_replace("&Omicron;", "&#927;", $string);
        $string = str_replace("&omicron;", "&#959;", $string);
        $string = str_replace("&oplus;", "&#8853;", $string);
        $string = str_replace("&or;", "&#x2228;", $string);
        $string = str_replace("&order;", "&#x2134;", $string);
        $string = str_replace("&ordf;", "&#x00AA;", $string);
        $string = str_replace("&ordm;", "&#x00BA;", $string);
        $string = str_replace("&Oslash;", "&#216;", $string);
        $string = str_replace("&oslash;", "&#248;", $string);
        $string = str_replace("&Otilde;", "&#213;", $string);
        $string = str_replace("&otilde;", "&#245;", $string);
        $string = str_replace("&otimes;", "&#8855;", $string);
        $string = str_replace("&Ouml;", "&#214;", $string);
        $string = str_replace("&ouml;", "&#246;", $string);
        $string = str_replace("&par;", "&#x2225;", $string);
        $string = str_replace("&para;", "&#x00B6;", $string);
        $string = str_replace("&part;", "&#x2202;", $string);
        $string = str_replace("&percnt;", "&#x0025;", $string);
        $string = str_replace("&period;", "&#x002E;", $string);
        $string = str_replace("&permil;", "&#x2030;", $string);
        $string = str_replace("&perp;", "&#x22A5;", $string);
        $string = str_replace("&Phi;", "&#934;", $string);
        $string = str_replace("&phi;", "&#966;", $string);
        $string = str_replace("&phmmat;", "&#x2133;", $string);
        $string = str_replace("&phone;", "&#x260E;", $string);
        $string = str_replace("&Pi;", "&#928;", $string);
        $string = str_replace("&pi;", "&#960;", $string);
        $string = str_replace("&piv;", "&#982;", $string);
        $string = str_replace("&plus;", "&#x002B;", $string);
        $string = str_replace("&plusmn;", "&#x00B1;", $string);
        $string = str_replace("&pound;", "&#x00A3;", $string);
        $string = str_replace("&prime;", "&#x2032;", $string);
        $string = str_replace("&Prime;", "&#x2033;", $string);
        $string = str_replace("&prod;", "&#8719;", $string);
        $string = str_replace("&prop;", "&#x221D;", $string);
        $string = str_replace("&Psi;", "&#936;", $string);
        $string = str_replace("&psi;", "&#968;", $string);
        $string = str_replace("&puncsp;", "&#x2008;", $string);
        $string = str_replace("&quest;", "&#x003F;", $string);
        //$string = str_replace("&quot;", "&#x0022;", $string);
        $string = str_replace("&radic;", "&#x221A;", $string);
        $string = str_replace("&rang;", "&#9002;", $string);
        $string = str_replace("&rang;", "&#x3009;", $string);
        $string = str_replace("&raquo;", "&#x00BB;", $string);
        $string = str_replace("&rarr;", "&#x2192;", $string);
        $string = str_replace("&rArr;", "&#x21D2;", $string);
        $string = str_replace("&rceil;", "&#8969;", $string);
        $string = str_replace("&rcub;", "&#x007D;", $string);
        $string = str_replace("&rdquo;", "&#x201D;", $string);
        $string = str_replace("&rdquor;", "&#x201C;", $string);
        $string = str_replace("&real;", "&#8476;", $string);
        $string = str_replace("&rect;", "&#x25AD;", $string);
        $string = str_replace("&reg;", "&#x00AE;", $string);
        $string = str_replace("&rfloor;", "&#8971;", $string);
        $string = str_replace("&Rho;", "&#929;", $string);
        $string = str_replace("&rho;", "&#961;", $string);
        $string = str_replace("&rpar;", "&#x0029;", $string);
        $string = str_replace("&rsaquo;", "&#8250;", $string);
        $string = str_replace("&rsqb;", "&#x005D;", $string);
        $string = str_replace("&rsquo;", "&#x2019;", $string);
        $string = str_replace("&rsquor;", "&#x2018;", $string);
        $string = str_replace("&rtri;", "&#x25B9;", $string);
        $string = str_replace("&rtrif;", "&#x25B8;", $string);
        $string = str_replace("&rx;", "&#x211E;", $string);
        $string = str_replace("&scaron;", "&#353;", $string);
        $string = str_replace("&sdot;", "&#8901;", $string);
        $string = str_replace("&sect;", "&#x00A7;", $string);
        $string = str_replace("&semi;", "&#x003B;", $string);
        $string = str_replace("&sext;", "&#x2736;", $string);
        $string = str_replace("&sharp;", "&#x266F;", $string);
        $string = str_replace("&shy;", "&#x00AD;", $string);
        $string = str_replace("&Sigma;", "&#931;", $string);
        $string = str_replace("&sigma;", "&#963;", $string);
        $string = str_replace("&sigmaf;", "&#962;", $string);
        $string = str_replace("&sim;", "&#x223C;", $string);
        $string = str_replace("&sime;", "&#x2243;", $string);
        $string = str_replace("&sol;", "&#x002F;", $string);
        $string = str_replace("&spades;", "&#9824;", $string);
        $string = str_replace("&squ;", "&#x25A1;", $string);
        $string = str_replace("&square;", "&#x25A1;", $string);
        $string = str_replace("&squf;", "&#x25AA;", $string);
        $string = str_replace("&star;", "&#x22C6;", $string);
        $string = str_replace("&starf;", "&#x2605;", $string);
        $string = str_replace("&sub;", "&#8834;", $string);
        $string = str_replace("&sube;", "&#x2286;", $string);
        $string = str_replace("&sum;", "&#8721;", $string);
        $string = str_replace("&sung;", "&#x2669;", $string);
        $string = str_replace("&sup1;", "&#x00B9;", $string);
        $string = str_replace("&sup2;", "&#x00B2;", $string);
        $string = str_replace("&sup3;", "&#x00B3;", $string);
        $string = str_replace("&sup;", "&#x2283;", $string);
        $string = str_replace("&supe;", "&#x2287;", $string);
        $string = str_replace("&szlig;", "&#223;", $string);
        $string = str_replace("&target;", "&#x2316;", $string);
        $string = str_replace("&Tau;", "&#932;", $string);
        $string = str_replace("&tau;", "&#964;", $string);
        $string = str_replace("&tdot;", "&#x20DB;", $string);
        $string = str_replace("&telrec;", "&#x2315;", $string);
        $string = str_replace("&there4;", "&#x2234;", $string);
        $string = str_replace("&Theta;", "&#920;", $string);
        $string = str_replace("&theta;", "&#952;", $string);
        $string = str_replace("&thetasym;", "&#977;", $string);
        $string = str_replace("&thinsp;", "&#x2009;", $string);
        $string = str_replace("&THORN;", "&#222;", $string);
        $string = str_replace("&thorn;", "&#254;", $string);
        $string = str_replace("&tilde;", "&#732;", $string);
        $string = str_replace("&times;", "&#x00D7;", $string);
        $string = str_replace("&tprime;", "&#x2034;", $string);
        $string = str_replace("&trade;", "&#x2122;", $string);
        $string = str_replace("&Uacute;", "&#218;", $string);
        $string = str_replace("&uacute;", "&#250;", $string);
        $string = str_replace("&uArr;", "&#8657;", $string);
        $string = str_replace("&uarr;", "&#x2191;", $string);
        $string = str_replace("&Ucirc;", "&#219;", $string);
        $string = str_replace("&ucirc;", "&#251;", $string);
        $string = str_replace("&Ugrave;", "&#217;", $string);
        $string = str_replace("&ugrave;", "&#249;", $string);
        $string = str_replace("&uhblk;", "&#x2580;", $string);
        $string = str_replace("&ulcrop;", "&#x230F;", $string);
        $string = str_replace("&uml;", "&#168;", $string);
        $string = str_replace("&upsih;", "&#978;", $string);
        $string = str_replace("&Upsilon;", "&#933;", $string);
        $string = str_replace("&upsilon;", "&#965;", $string);
        $string = str_replace("&urcrop;", "&#x230E;", $string);
        $string = str_replace("&utri;", "&#x25B5;", $string);
        $string = str_replace("&utrif;", "&#x25B4;", $string);
        $string = str_replace("&Uuml;", "&#220;", $string);
        $string = str_replace("&uuml;", "&#252;", $string);
        $string = str_replace("&vellip;", "&#x22EE;", $string);
        $string = str_replace("&verbar;", "&#x007C;", $string);
        $string = str_replace("&Verbar;", "&#x2016;", $string);
        $string = str_replace("&wedgeq;", "&#x2259;", $string);
        $string = str_replace("&weierp;", "&#8472;", $string);
        $string = str_replace("&Xi;", "&#926;", $string);
        $string = str_replace("&xi;", "&#958;", $string);
        $string = str_replace("&Yacute;", "&#221;", $string);
        $string = str_replace("&yacute;", "&#253;", $string);
        $string = str_replace("&yen;", "&#x00A5;", $string);
        $string = str_replace("&yuml;", "&#255;", $string);
        $string = str_replace("&Yuml;", "&#376;", $string);
        $string = str_replace("&Zeta;", "&#918;", $string);
        $string = str_replace("&zeta;", "&#950;", $string);
        $string = str_replace("&zwnj;", "&#8204;", $string);

        debugging(__FUNCTION__ . "() -> |" . str_replace("\n", "", substr($string, 0, 100)) . " ...)", DEBUG_DEVELOPER);
        return $string;
    }
}
?>
