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

// $Id: $
/**
 * Code fragment to define the version of htmltable for Moodle 2.0
 * This fragment is called by moodle_needs_upgrading() and /admin/index.php
 *
 * @author Eoin Campbell
 * @package htmltable
 **/

$plugin->version  = 2014012801;  // The current module version (Date: YYYYMMDDXX)
$plugin->maturity  = MATURITY_STABLE;  // Maturity level
$plugin->component  = 'qformat_htmltable';  // Plugin name
$plugin->release  = '1.0 (Build: 20140128)';  // The current module release in human-readable form (x.y)
$plugin->requires = 2011070100.03;  // Requires Moodle 2.1 or later
$plugin->cron     = 0;           // Period for cron to check this module (secs)

?>
