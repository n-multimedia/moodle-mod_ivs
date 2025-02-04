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
 * All form elements to create or edit an Interactive video suite
 *
 * @package mod_ivs
 * @author Ghostthinker GmbH <info@interactive-video-suite.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2017 onwards Ghostthinker GmbH (https://ghostthinker.de/)
 */

defined('MOODLE_INTERNAL') || die();

use \mod_ivs\ivs_match\AssessmentConfig;
use mod_ivs\settings\SettingsService;
use \tool_opencast\local\api;

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');

if (file_exists($CFG->dirroot . '/blocks/panopto/lib/block_panopto_lib.php')) {
    require_once($CFG->dirroot . '/blocks/panopto/lib/block_panopto_lib.php');
}
/**
 * Module instance settings form
 *
 * @package    mod_ivs
 * @author Ghostthinker GmbH <info@interactive-video-suite.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2017 onwards Ghostthinker GmbH (https://ghostthinker.de/)
 */
class mod_ivs_mod_form extends moodleform_mod {

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG;

        global $PAGE;

        global $course;
        global $USER;

        $panoptoblocksenabled = file_exists($CFG->dirroot . '/blocks/panopto/lib/block_panopto_lib.php');

        $panoptodata = '';
        if($panoptoblocksenabled) {

            $configuredserverarray = panopto_get_configured_panopto_servers();

            if (file_exists(dirname(__FILE__) . '/../../blocks/panopto/lib/panopto_data.php')) {
                require_once(dirname(__FILE__) . '/../../blocks/panopto/lib/panopto_data.php');
                $panoptodata = new \panopto_data($course->id);
                if (!empty($panoptodata->servername) && !empty($panoptodata->applicationkey)) {
                    $panoptodata->sync_external_user($USER->id);
                }
            }

            $panoptodata->buttonname = get_string('ivs_setting_panopto_menu_button', 'ivs');
            $panoptodata->tooltip = get_string('ivs_setting_panopto_menu_tooltip', 'ivs');
        }

        $PAGE->requires->js_call_amd('mod_ivs/ivs_activity_settings_page', 'init', ['panopto_data' => $panoptodata]);

        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are showed.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('ivsname', 'ivs'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'ivsname', 'ivs');

        // Adding the standard "intro" and "introformat" fields.
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        if ((int) $CFG->ivs_panopto_external_files_enabled && $panoptoblocksenabled) {
            $mform->addElement('hidden', 'panopto_video_json_field', get_string('ivs_setting_panopto_menu_title', 'ivs'),
              ['id' => 'id_panopto_video_json_field']);
            $mform->addElement('text', 'panopto_video', get_string('ivs_setting_panopto_menu_title', 'ivs'),
              ['readonly' => true, 'size' => '64']);
            if (!empty($CFG->formatstringstriptags)) {
                $mform->setType('panopto_video_json_field', PARAM_TEXT);
                $mform->setType('panopto_video', PARAM_TEXT);
            } else {
                $mform->setType('panopto_video_json_field', PARAM_CLEANHTML);
                $mform->setType('panopto_video', PARAM_CLEANHTML);
            }
        }

        if ((int) $CFG->ivs_opencast_external_files_enabled) {

            try {
                $opencastvideos = $this->get_videos_for_select();
                if ($opencastvideos && count($opencastvideos) > 0) {
                    $select =
                            $mform->addElement('select', 'opencast_video', get_string('ivs_setting_opencast_menu_title', 'ivs'),
                                    $opencastvideos);
                }

            } catch (Exception $e) {
                \core\notification::error($e->getMessage());
            }
        }

        if ((int) $CFG->ivs_opencast_internal_files_enabled) {
            $mform->addElement('filepicker', 'video_file', get_string('file'), null,
                    array(
                            'subdirs' => 0,
                            'maxbytes' => 0,
                            'areamaxbytes' => 10485760,
                            'maxfiles' => 1,
                            'accepted_types' => array('.mp4'),
                            'return_types' => FILE_INTERNAL,
                    ));
        }

        // Grade settings.
        $this->standard_grading_coursemodule_elements();

        $mform->addElement('header', 'mod_ivs/playersettings', get_string('ivs_player_settings', 'ivs'));
        $settingsdefinitions = \mod_ivs\settings\SettingsService::get_settings_definitions();

        $settingscontroller = new SettingsService();
        $parentsettings = $settingscontroller->get_rarent_settings_for_activity($this->_course->id);

        if (!empty($this->_instance)) {
            $activiysettings = $settingscontroller->load_settings($this->_instance, 'activity');
        }

        $lockreadaccessoptions = SettingsService::get_ivs_read_access_options();

        /** @var \mod_ivs\settings\SettingsDefinition $settingsdefinition */
        foreach ($settingsdefinitions as $settingsdefinition) {
            $settingscontroller::add_vis_setting_to_form($settingsdefinition->type, $parentsettings, $settingsdefinition, $mform,
                    false, $lockreadaccessoptions);

            if (isset($activiysettings[$settingsdefinition->name])) {
                if (!$parentsettings[$settingsdefinition->name]->locked) {
                    $mform->setDefault($settingsdefinition->name . "[value]",
                            $activiysettings[$settingsdefinition->name]->value);
                    $mform->setDefault($settingsdefinition->name . "[locked]",
                            $activiysettings[$settingsdefinition->name]->locked);
                } else {
                    $mform->setDefault($settingsdefinition->name . "[value]",
                            $parentsettings[$settingsdefinition->name]->value);
                    $mform->setDefault($settingsdefinition->name . "[locked]",
                            $parentsettings[$settingsdefinition->name]->locked);
                }
            } else {
                $mform->setDefault($settingsdefinition->name . "[value]",
                        $parentsettings[$settingsdefinition->name]->value);
                $mform->setDefault($settingsdefinition->name . "[locked]",
                        $parentsettings[$settingsdefinition->name]->locked);
            }
        }

        $mform->addElement('header', 'mod_ivs/match_config_video_test', get_string('ivs_match_config_video_test', 'ivs'));

        // Assessment Mode.
        $attemptoptions = array(
                AssessmentConfig::ASSESSMENT_TYPE_FORMATIVE => get_string('ivs_match_config_assessment_mode_formative', 'ivs'));

        $mform->addElement('select', 'match_config_assessment_mode', get_string('ivs_match_config_mode', 'ivs'),
                $attemptoptions);

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();

        // Add standard buttons, common to all modules.
        $this->add_action_buttons();

    }

    /**
     * Process default values
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        if ($this->current->instance) {
            $options = array(
                    'subdirs' => false,
                    'maxbytes' => 0,
                    'maxfiles' => -1
            );
            $draftitemid = file_get_submitted_draft_itemid('video_file');

            file_prepare_draft_area($draftitemid,
                    $this->context->id,
                    'mod_ivs',
                    'videos',
                    0,
                    $options);
            $defaultvalues['video_file'] = $draftitemid;
        }

        if (!empty($defaultvalues['videourl'])) {

            $parts = explode("://", $defaultvalues['videourl']);
            if ($parts[0] == "OpenCastFileVideoHost" || $parts[0] == "SwitchCastFileVideoHost") {
                $defaultvalues['opencast_video'] = $parts[1];
            } else if ($parts[0] == "PanoptoFileVideoHost") {
                $defaultvalues['panopto_video_json_field'] = $parts[1];
                $decodedvalues = json_decode($parts[1]);
                if(!empty($decodedvalues)) {
                    $defaultvalues['panopto_video'] = $decodedvalues->videoname[0];
                }
            }
        }

    }

    /**
     * Get all videos from opencast
     * @return array|void
     */
    public function get_videos_for_select() {

        global $COURSE;
        $publishedvideos = array();

        if (!class_exists('\\tool_opencast\\seriesmapping') || !class_exists('\\tool_opencast\\local\\api')) {
            return array(get_string('ivs_opencast_video_chooser', 'ivs'));
        }
        $mapping = \tool_opencast\seriesmapping::get_record(array('courseid' => $COURSE->id));
        if (!is_object($mapping)) {
            return;
        }
        $seriesid = $mapping->get('series');
        $seriesfilter = "series:" . $seriesid;

        $query = '/api/events?sign=1&withmetadata=1&withpublications=1&filter=' . urlencode($seriesfilter);

        $api = new api();
        $videos = $api->oc_get($query);
        $videos = json_decode($videos);

        $publishedvideos = array(get_string('ivs_opencast_video_chooser', 'ivs'));

        if (empty($videos)) {
            return $publishedvideos;
        }

        foreach ($videos as $video) {
            if (in_array('opencast-api', $video->publication_status)) {
                $publishedvideos[$video->identifier] = $video->title;
            } else if (in_array('switchcast-api', $video->publication_status)) {
                $publishedvideos[$video->identifier] = $video->title;
            }
        }

        return $publishedvideos;
    }
}
