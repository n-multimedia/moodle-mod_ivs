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
 * @package mod_ivs
 * @author Ghostthinker GmbH <info@interactive-video-suite.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2017 onwards Ghostthinker GmbH (https://ghostthinker.de/)
 */

// Standard GPL and phpdocs.
namespace mod_ivs\output;

use mod_ivs\IvsHelper;
use renderable;
use renderer_base;
use templatable;
use stdClass;

class annotation_report_view implements renderable, templatable {

    /** @var \mod_ivs\annotation */
    var $annotation = null;
    var $ivs = null;
    var $module;

    /**
     * annotation_view constructor.
     *
     * @param \mod_ivs\annotation $annotation
     * @param null $ivs
     */
    public function __construct(\mod_ivs\annotation $annotation, $ivs, $module, $userTo) {
        $this->annotation = $annotation;
        $this->ivs = $ivs;
        $this->module = $module;
        $this->userTo = $userTo;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @return stdClass
     */
    public function export_for_template(renderer_base $output) {

        $data = new stdClass();

        $user = IvsHelper::get_user($this->annotation->get_userid());
        $userTo = $this->userTo;

        $data->comment_body = $this->annotation->get_body();
        $data->comment_author_link = $user['fullname'];
        $data->comment_created = userdate($this->annotation->get_timecreated());
        $data->comment_timestamp = $this->annotation->get_timestamp() / 1000;
        $data->timecode = $this->annotation->get_timecode(true);
        $data->cockpit_report_mail_annotation_header_part_1 =
                get_string_manager()->get_string('cockpit_report_mail_annotation_header_part_1', 'ivs', null, $userTo->lang);
        $data->cockpit_report_mail_annotation_header_part_2 =
                get_string_manager()->get_string('cockpit_report_mail_annotation_header_part_2', 'ivs', null, $userTo->lang);
        $data->cockpit_report_mail_annotation_header_part_3 =
                get_string_manager()->get_string('cockpit_report_mail_annotation_header_part_3', 'ivs', null, $userTo->lang);

        $data->player_link =
                new \moodle_url('/mod/ivs/view.php', array('id' => $this->module->id, 'cid' => $this->annotation->get_id()));

        return $data;
    }
}
