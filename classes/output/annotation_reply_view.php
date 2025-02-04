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
 * Output class for rendering annotations replies
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

/**
 * Class annotation_reply_view
 */
class annotation_reply_view implements renderable, templatable {

    /**
     * @var \mod_ivs\annotation|null
     */
    public $annotation = null;

    /**
     * @var null|stdClass
     */
    public $ivs = null;

    /**
     * @var stdClass
     */
    public $module;

    /**
     * annotation_reply_view constructor.
     *
     * @param \mod_ivs\annotation $annotation
     */
    public function __construct(\mod_ivs\annotation $annotation) {
        $this->annotation = $annotation;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     * @param \renderer_base $output
     *
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output) {
        $data = new stdClass();

        $user = IvsHelper::get_user($this->annotation->get_userid());

        $data->comment_body = $this->annotation->get_rendered_body();
        $data->id = $this->annotation->get_id();
        $data->user_picture = $user['picture'];
        $data->comment_author_link = $user['fullname'];
        $data->comment_created = userdate($this->annotation->get_timecreated());

        return $data;
    }
}
