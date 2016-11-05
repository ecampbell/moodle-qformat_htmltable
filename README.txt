Release notes
-------------

Date          Version   Comment
2016/11/05    1.5       Support Calculated and Multichoiceset questions, improve support for Polish export
2015/07/15    1.4       Support 2.7+ Essay question enhancements, improve HTML clean-up, 
                        handle paragraph style attributes better, add environment.xml
2014/10/08    1.3       Handle images inside Cloze questions, and highlight Cloze question components in boxes.
2014/07/28    1.2       Handle badly-formed question content much better by using HTMLTidy or strip_tags, etc.
2014/02/03    1.1       Initial release.



HTML review table overview
--------------------------
HTMLTable is a plugin that allows Question bank questions to be exported from Moodle into a HTML file.
The HTML file can then be used to quickly review large numbers of questions.

If there are images in the questions, then the HTML file must be viewed in a modern browser like Internet
Explorer 9 or higher in order to see them.

The XSL PHP extension is required, see http://www.php.net/manual/en/xsl.installation.php for 
installation details.

The Tidy PHP extension is desirable, see http://www.php.net/manual/de/install.windows.extensions.php
for instructions on how to enable it.