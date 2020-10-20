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

namespace mod_ivs\ivs_match;

class MatchResponse {

    private $status;
    private $data;

    /**
     * MatchResponse constructor.
     *
     * @param $status
     * @param $data
     */
    public function __construct($data = array(), $status = 200) {
        $this->status = $status;
        $this->data = $data;
    }

    /**
     * @return int
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function set_status($status) {
        $this->status = $status;
    }

    /**
     * @return mixed
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function set_data($data) {
        $this->data = $data;
    }

}
