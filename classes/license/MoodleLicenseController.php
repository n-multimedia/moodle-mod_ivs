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
namespace mod_ivs\license;

use ArrayIterator;

defined('MOODLE_INTERNAL') || die();

define('IVS_CORE_URL', 'https://interactive-video-suite.de');
define('IVS_CORE_API_PREFIX', '/wp-json/interactive-video-suite/v1.0');
define('IVS_CORE_API_CALLBACK_REGISTER', '/client/register');
define('IVS_CORE_API_CALLBACK_STATUS', '/client/status');
define('IVS_CORE_API_CALLBACK_CDN', '/client/cdn/src');
define('IVS_CORE_API_CALLBACK_ACTIVATE', '/client/activate');
define('IVS_CORE_API_CALLBACK_RELEASE', '/client/release');
define('IVS_CORE_API_CALLBACK_USAGE', '/client/usage');
define('IVS_CORE_API_CALLBACK_INSTANCE', '/client/instances');
define('IVS_CORE_CRON_WAITING_TIME', 129600);
define('IVS_LICENCSE_ACTIVE', 1);
define('IVS_LICENCSE_OVERBOOKED', 2);
define('IVS_LICENCSE_EXPIRED', 3);
define('IVS_SYSTEM_TYPE_MAIN', 'main');
define('IVS_SYSTEM_TYPE_TEST', 'testsystem');
define('IVS_ACTION_TESTSYSTEM', 'test');
define('IVS_ACTION_PLAYERVERSION', 'player');

class MoodleLicenseController implements ILicenseController
{

    public function generate_instance_id()
    {

        // Prevent overriding existing instance id.
        $instanceid = get_config('mod_ivs', 'ivs_instance_id');
        if (!empty($instanceid)) {
            return false;
        }

        set_config('ivs_installation_date', date('Y-m-d H:i:s', time()), 'mod_ivs');
        if ($response = $this->core_register($instanceid)) {
            $responseobj = json_decode($response);
            set_config('ivs_instance_id', $responseobj->instance_id, 'mod_ivs');
            set_config('ivs_schedule_task', date('Y-m-d H:i:s', time()), 'mod_ivs');
            return $responseobj->instance_id;
        }
        return false;
    }

    /**
     * @param $instanceId
     *
     * @return bool|string
     * @throws \dml_exception
     */
    public function core_register($instanceid)
    {
        global $CFG;

        $requestdata = [
                'instance_id' => $instanceid,
                'system_name' => "Moodle",
                'system_ip' => $_SERVER['SERVER_ADDR'],
                'version_lms' => $CFG->release,
                'version_plugin' => get_config('mod_ivs', 'version'),
                'installation_date' => get_config('mod_ivs', 'ivs_installation_date'),
        ];

        $result = $this->send_request("coreRegister", $requestdata);

        return $result;
    }

    /**
     * get InstanceID stored in config
     *
     * @return mixed
     * @throws \dml_exception
     */
    public function get_instance_id()
    {
        $instanceid = get_config('mod_ivs', 'ivs_instance_id');
        if (empty($instanceid)) {
            $instanceid = $this->generate_instance_id();
        }
        return $instanceid;
    }

    /**
     * get current license
     *
     * @param null $context
     *
     * @return mixed|null
     * @throws \dml_exception
     */
    public function get_active_license($context = null)
    {
        // Check licenses.

        // 1)   do we have course licenses?
        // 1.1) check if we have a valid license for active course
        // Check if license is active for a special course.

        if (!empty($context['course'])) {
            $courselicenses = $this->get_course_licenses([IVS_LICENCSE_ACTIVE]);
            foreach ($courselicenses as $license) {
                if ($license->course_id == $context['course']->id) {
                    return $license;
                }
            }
        }

        // 2.)  check if we  have an instance license
        // Check course_id from context.

        $instancelicenses = $this->get_instance_licenses([IVS_LICENCSE_ACTIVE]);
        if ($instancelicenses != null) {
            if (count($instancelicenses) > 0) {
                return current($instancelicenses);
            }
        }

        return null;
    }

    /**
     * check if there is a active license
     *
     * @param null $context e.g. course
     *
     * @return bool
     * @throws \dml_exception
     */
    public function has_active_license($context = null)
    {
        // Check licenses.
        $status = $this->get_status();
        if ($this->cron_runtime_too_old()) {
            $this->send_usage();
            $this->set_last_runtime();
        }
        if (empty($context) && !empty($status->active)) {
            return true;
        }

        $activelicense = $this->get_active_license($context);
        return !empty($activelicense);
    }

    /**
     * @param bool $reset
     *
     * @return mixed
     * @throws \dml_exception
     */
    public function get_status($reset = false)
    {
        global $CFG;
        static $status;
        if (!$reset && !empty($status)) {
            return $status;
        }

        $instanceid = $this->get_instance_id();

        $requestdata = [
                'instance_id' => $instanceid,
                'version_lms' => $CFG->release,
                'version_plugin' => get_config('mod_ivs', 'version'),
        ];

        $statusresponse = $this->send_request("status", $requestdata);
        $status = $statusresponse != false ? json_decode($statusresponse) : false;
        return $status;
    }

    /**
     * @param $status
     *
     * @return mixed
     */
    public function get_license_type($status)
    {
        return $status['type'];
    }

    /**
     * @return array|bool|string
     * @throws \dml_exception
     */
    public function get_cdn_source($licenseid)
    {

        $instanceid = $this->get_instance_id();

        $requestdata = [
                'instance_id' => $instanceid,
                'license_id' => $licenseid,
        ];

        $result = $this->send_request("callback_cdn", $requestdata);

        return $result != false ? json_decode($result) : $result;
    }

    /**
     * @return bool
     */
    public function check_is_online()
    {
        $domain = $this->get_core_url(true);
        $curlinit = curl_init($domain);
        curl_setopt($curlinit, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curlinit, CURLOPT_HEADER, true);
        curl_setopt($curlinit, CURLOPT_NOBODY, true);
        curl_setopt($curlinit, CURLOPT_RETURNTRANSFER, true);

        // Get answer.
        $response = curl_exec($curlinit);

        curl_close($curlinit);
        if ($response) {
            return true;
        }
        return false;
    }

    /**
     * send POST request
     *
     * @param $url
     * @param $requestdata
     *
     * @return bool|string
     */
    protected function send_curl_request($path, $method = "POST", $requestdata)
    {

        $coreurl = $this->get_core_url(true);
        $url = $coreurl . IVS_CORE_API_PREFIX . $path;

        // Url-ify the data for the POST.
        $requestjson = json_encode($requestdata);

        // Open connection.
        $ch = curl_init($url);

        switch ($method) {
            case "POST":
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestjson);
                break;
            case "GET":
                break;
            case "PUT":
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestjson);
                break;
            case "PATCH":
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestjson);
                break;
            case "DELETE":
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        // Set the url, number of POST vars, POST data.
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        // So that curl_exec returns the contents of the cURL; rather than echoing it.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute post.
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!curl_errno($ch)) {
            switch ($httpcode) {
                case 200:
                case 201:  // OK -> created.
                    break;
                default:
                    // E.g. 409!
                    return false;
            }
        }

        curl_close($ch);

        return $result;
    }

    /**
     * @param $courseid
     * @param $licenseid
     *
     * @return bool|string
     * @throws \dml_exception
     */
    public function activate_course_license($courseid, $licenseid)
    {

        $requestdata = [
                "instance_id" => $this->get_instance_id(),
                "license_id" => $licenseid,
                "course_id" => $courseid,
        ];

        return $this->send_request("activate", $requestdata);
    }

    /**
     * @param $courseid
     * @param $licenseid
     *
     * @return bool|string
     * @throws \dml_exception
     */
    public function release_course_license($courseid, $licenseid)
    {

        $requestdata = [
                "instance_id" => $this->get_instance_id(),
                "license_id" => $licenseid,
                "course_id" => $courseid,
        ];

        return $this->send_request("release", $requestdata);
    }

    /**
     * @param bool $reset
     *
     * @return array
     */
    public function get_course_licenses($licensestatus, $reset = false)
    {
        $courselicenses = $this->get_instance_licenses_by_type('course', $licensestatus, $reset);

        return $courselicenses;
    }

    /**
     * @param bool $reset
     *
     * @return array
     */
    public function get_instance_licenses($licensestatus, $reset = false)
    {
        $instancelicenses = $this->get_instance_licenses_by_type('instance', $licensestatus, $reset);

        return $instancelicenses;
    }

    /**
     * @param $type
     * @param $licensestatus
     * @param bool $reset
     *
     * @return array
     * @throws \dml_exception
     */
    public function get_instance_licenses_by_type($type, $licensestatus, $reset = false)
    {
        $status = $this->get_status($reset);
        $licenses = [];

        if (in_array(IVS_LICENCSE_ACTIVE, $licensestatus)) {
            if (!empty($status->active)) {
                foreach ($status->active->licenses as $license) {
                    if ($license->type == $type) {
                        $licenses[] = $license;
                    }
                }
            }
        }

        if (in_array(IVS_LICENCSE_OVERBOOKED, $licensestatus)) {
            if (!empty($status->overbooked)) {
                foreach ($status->overbooked->licenses as $license) {
                    if ($license->type == $type) {
                        $licenses[] = $license;
                    }
                }
            }
        }

        if (in_array(IVS_LICENCSE_EXPIRED, $licensestatus)) {
            if (!empty($status->expired)) {
                foreach ($status->expired->licenses as $license) {
                    if ($license->type == $type) {
                        $licenses[] = $license;
                    }
                }
            }
        }

        return $licenses;
    }

    /**
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_settings_license_none_data()
    {
        $data = new \stdClass;

        $data->current_package_label = get_string('ivs_package_label', 'ivs');
        $data->current_package = get_string('ivs_package_inactive', 'ivs');
        $data->shop_hint = get_string('ivs_shop_hint', 'ivs');

        return $data;
    }

    /**
     * @return string
     */
    public function get_core_url($internal = false)
    {
        global $CFG;

        // Overriden CORE URL.
        if (!$internal && !empty($CFG->IVS_CORE_DOCKER_URL)) {
            return $CFG->IVS_CORE_DOCKER_URL;
        }
        if (!empty($CFG->IVS_CORE_URL)) {
            return $CFG->IVS_CORE_URL;
        }
        return IVS_CORE_URL;
    }

    /**
     * @param $courselicenses
     * @param $output
     *
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_settings_license_course_data($courselicenses, $instancelicenses, $output)
    {
        global $CFG;
        $data = new \stdClass;

        $lc = $this;

        $packageinfo = $lc->get_current_license_package_info($courselicenses);

        $data->license_package_label = get_string('ivs_package_label', 'ivs');
        $data->license_package_info = $packageinfo['assigned_course_licenses'] . '/' . $packageinfo['max_course_licenses'] . ' ' .
                get_string('ivs_current_package_courses_label', 'ivs');

        $data->course_title = get_string('ivs_course_title', 'ivs');
        $data->course_spots_title = get_string('ivs_course_spots_title', 'ivs');
        $data->course_package_title = get_string('ivs_course_package_title', 'ivs');
        $data->course_reassign_title = get_string('ivs_course_package_reassign', 'ivs');
        $data->remove_icon = $output->image_url('move-icon', 'ivs');

        $courselicensesassigned = [];

        foreach ($courselicenses as $courselicense) {
            if (!empty($courselicense->course_id)) {
                $course = get_course($courselicense->course_id);
                $dateformat = get_string('strftimedatefullshort', 'langconfig');
                $courselicensesassigned[$courselicense->course_id]['title'] = $course->fullname;
                $courselicensesassigned[$courselicense->course_id]['course_spots'] =
                        $courselicense->spots_in_use . '/' . $courselicense->spots;
                if ($courselicense->overbooked_spots > 0 && !empty($instancelicenses)) {
                    $courselicensesassigned[$courselicense->course_id]['course_spots'] =
                            $courselicense->spots_in_use . '/' . $courselicense->spots . ' ' .
                            get_string('ivs_move_user_to_instance_from_course', 'ivs', [
                                    'overbooked_spots' => $courselicense->overbooked_spots,
                                    'product_name' => $instancelicenses[0]->product_name,
                            ]);
                }
                $courselicensesassigned[$courselicense->course_id]['product_name'] =
                        $courselicense->product_name . " (" . strftime($dateformat, strtotime($courselicense->created_at)) .
                        " - " . strftime($dateformat, strtotime($courselicense->expires_at)) . ")";
                $courselicensesassigned[$courselicense->course_id]['remove_link'] =
                        $CFG->wwwroot . '/mod/ivs/admin/admin_settings_license.php?course_id=' . $courselicense->course_id .
                        '&license_id=' . $courselicense->id . '&remove=true';
            }
        }

        $data->course_license_has_items = count($courselicensesassigned) > 0;
        $data->course_license = new ArrayIterator($courselicensesassigned);

        return $data;
    }

    /**
     * @param $courselicenses
     * @param $instancelicences
     * @param $output
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_settings_overbooked_license_data($courselicenses, $instancelicences, $output)
    {
        global $CFG;
        $data = new \stdClass;

        $data->license_package_label = get_string('ivs_package_label_overbooked', 'ivs');
        $data->course_title = get_string('ivs_course_title', 'ivs');
        $data->course_spots_title = get_string('ivs_course_spots_title', 'ivs');
        $data->course_package_title = get_string('ivs_course_package_title', 'ivs');
        $data->course_reassign_title = get_string('ivs_course_package_reassign', 'ivs');
        $data->remove_icon = $output->image_url('move-icon', 'ivs');

        $courselicensesoverbooked = [];
        $instancelicensesoverbooked = [];
        foreach ($courselicenses as $courselicense) {
            if (!empty($courselicense->course_id)) {
                $course = get_course($courselicense->course_id);
                $dateformat = get_string('strftimedatefullshort', 'langconfig');
                $courselicensesoverbooked[$courselicense->id]['title'] = $course->fullname;
                $courselicensesoverbooked[$courselicense->id]['course_spots'] =
                        $courselicense->spots_in_use . '/' . $courselicense->spots;
                $courselicensesoverbooked[$courselicense->id]['product_name'] =
                        $courselicense->product_name . " (" . strftime($dateformat, strtotime($courselicense->created_at)) .
                        " - " . strftime($dateformat, strtotime($courselicense->expires_at)) . ")";
                $courselicensesoverbooked[$courselicense->id]['remove_link'] =
                        $CFG->wwwroot . '/mod/ivs/admin/admin_settings_license.php?course_id=' . $courselicense->course_id .
                        '&license_id=' . $courselicense->id . '&remove=true';
            }
        }

        if (!empty($instancelicences)) {
            $dateformat = get_string('strftimedatefullshort', 'langconfig');
            $instancelicensesoverbooked[$instancelicences[0]->id]['title'] = "Instance Flat";
            $instancelicensesoverbooked[$instancelicences[0]->id]['course_spots'] =
                    $instancelicences[0]->spots_in_use . '/' . $instancelicences[0]->spots;
            $instancelicensesoverbooked[$instancelicences[0]->id]['product_name'] = $instancelicences[0]->product_name . " (" .
                    strftime($dateformat, strtotime($instancelicences[0]->created_at)) . " - " .
                    strftime($dateformat, strtotime($instancelicences[0]->expires_at)) . ")";
        }

        $data->course_license_has_items = count($courselicensesoverbooked) + count($instancelicensesoverbooked);
        $data->course_license = new ArrayIterator($courselicensesoverbooked);
        $data->instance_licences = new ArrayIterator($instancelicensesoverbooked);
        return $data;
    }

    public function get_settings_expired_license_data($courselicenses, $instancelicences, $output)
    {
        global $CFG;
        $data = new \stdClass;

        $data->license_package_label = get_string('ivs_package_label_expired', 'ivs');
        $data->course_title = get_string('ivs_course_title', 'ivs');
        $data->course_spots_title = get_string('ivs_course_spots_title', 'ivs');
        $data->course_package_title = get_string('ivs_course_package_title', 'ivs');
        $data->course_delete_title = get_string('ivs_course_package_delete', 'ivs');
        $data->remove_icon = $output->image_url('delete_black', 'ivs');

        $courselicensesexpired = [];
        $instancelicensesexpired = [];

        foreach ($courselicenses as $courselicense) {
            $course = "";
            if (!empty($courselicense->course_id)) {
                $course = get_course($courselicense->course_id)->fullname;
            }
            $dateformat = get_string('strftimedatefullshort', 'langconfig');
            $courselicensesexpired[$courselicense->id]['title'] = $course;
            $courselicensesexpired[$courselicense->id]['course_spots'] =
                    $courselicense->spots_in_use . '/' . $courselicense->spots;
            $courselicensesexpired[$courselicense->id]['product_name'] =
                    $courselicense->product_name . " (" . strftime($dateformat, strtotime($courselicense->created_at)) . " - " .
                    strftime($dateformat, strtotime($courselicense->expires_at)) . ")";
            $courselicensesexpired[$courselicense->id]['remove_link'] =
                    $CFG->wwwroot . '/mod/ivs/admin/admin_settings_license.php?course_id=' . $courselicense->course_id .
                    '&license_id=' . $courselicense->id . '&remove=true';
        }
        if (!empty($instancelicences)) {
            $dateformat = get_string('strftimedatefullshort', 'langconfig');
            $instancelicensesexpired[$instancelicences[0]->id]['title'] = "Instance Flat";
            $instancelicensesexpired[$instancelicences[0]->id]['course_spots'] =
                    $instancelicences[0]->spots_in_use . '/' . $instancelicences[0]->spots;
            $instancelicensesexpired[$instancelicences[0]->id]['product_name'] = $instancelicences[0]->product_name . " (" .
                    strftime($dateformat, strtotime($instancelicences[0]->created_at)) . " - " .
                    strftime($dateformat, strtotime($instancelicences[0]->expires_at)) . ")";
            $instancelicensesexpired[$instancelicences[0]->id]['remove_link'] =
                    $CFG->wwwroot . '/mod/ivs/admin/admin_settings_license.php?course_id=' . $instancelicences[0]->type .
                    '&license_id=' . $instancelicences[0]->id . '&remove=true';
        }

        $data->course_license_has_items = count($courselicensesexpired) + count($instancelicensesexpired);
        $data->course_license = new ArrayIterator($courselicensesexpired);
        $data->instance_license = new ArrayIterator($instancelicensesexpired);

        return $data;
    }

    /**
     * @param $license
     *
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_settings_license_instance_data($license)
    {
        $data = new \stdClass;
        $lc = $this;

        $data->instance_id_label = get_string('ivs_instance_id_label', 'ivs');
        $data->license_instance_id = $lc->get_instance_id();
        $data->license_package_label = get_string('ivs_package_label', 'ivs');
        $data->license_package_info = $license->product_name;
        $data->manage_license_label = get_string('ivs_package_button_label', 'ivs');
        $data->manage_license_href = $this->get_core_url();

        $data->license_instance_view_data = $lc->get_license_instance_view_data($license);

        return $data;
    }

    /**
     * @param $courselicenses
     *
     * @return array
     */
    public function get_current_license_package_info($courselicenses)
    {
        $assignedcourselicenses = 0;
        $maxcourselicenses = 0;

        foreach ($courselicenses as $course) {
            $maxcourselicenses++;
            if (!empty($course->course_id)) {
                $assignedcourselicenses++;
            }
        }
        return [
                'assigned_course_licenses' => $assignedcourselicenses,
                'max_course_licenses' => $maxcourselicenses,
        ];
    }

    /**
     * @param $license
     *
     * @return array
     */
    public function get_license_instance_view_data($license)
    {
        $createdat = strtotime($license->created_at);
        $expiresat = strtotime($license->expires_at);
        $datenow = time();
        $runtimerest = $expiresat - $datenow;
        if ($runtimerest < 0) {
            $runtimepercentage = 1;
        } else {
            $runtimecomplete = $expiresat - $createdat;
            $runtimepercentage = ($runtimerest / $runtimecomplete) / 100;
        }
        $spots = $license->spots;
        $spotsinuse = $license->spots_in_use;
        $spotspercentage = ($spotsinuse / $spots);
        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $viewdata = [
                'expires_at' => strftime($dateformat, $expiresat),
                'runtime_percentage' => $runtimepercentage,
                'spots_left' => $license->spots - $spotsinuse > 0 ? $license->spots - $spotsinuse : 0,
                'spots_in_use' => $spotsinuse,
                'spots_percentage' => $spotspercentage <= 1.0 ? $spotspercentage : 1.0,
                'runtime_percentage_label' => number_format($runtimepercentage * 100, 0) . ' %',
                'spots_percentage_label' => number_format($spotspercentage * 100, 0) . ' %',
        ];
        return $viewdata;
    }

    /**
     * @param $courselicenses
     *
     * @return array
     */
    public function get_course_license_options($courselicenses)
    {
        $courselicenseoptions = [];

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        foreach ($courselicenses as $courselicense) {
            if (empty($courselicense->course_id)) {
                $courselicenseoptions[$courselicense->id] =
                        $courselicense->product_name . ' (' . strftime($dateformat, strtotime($courselicense->created_at)) .
                        ' - ' . strftime($dateformat, strtotime($courselicense->expires_at)) . ')';
            }
        }

        return $courselicenseoptions;
    }

    public function get_all_user_from_instance()
    {
        global $DB;
        $sql = "SELECT * FROM {user} WHERE suspended = 0";
        $users = $DB->get_records_sql($sql);

        return $users;
    }

    public function get_user_from_course($courseid)
    {
        global $DB;
        $sql = 'SELECT u.id FROM {user} u'
                . ' JOIN {user_enrolments} ue ON u.id = ue.userid'
                . ' JOIN {enrol} e ON e.id = ue.enrolid'
                . ' WHERE suspended = 0 AND e.courseid = ?';
        $user = $DB->get_records_sql($sql ,[$courseid]);
        return $user;
    }

    /**
     * Sends the actual usage statistic to the shop
     *
     * @throws \dml_exception
     */
    public function send_usage()
    {
        $instanceid = $this->get_instance_id();

        $response = false;

        $courselicenses = $this->get_course_licenses([
                IVS_LICENCSE_ACTIVE,
                IVS_LICENCSE_OVERBOOKED,
        ]);
        $instancelicenses = $this->get_instance_licenses([
                IVS_LICENCSE_ACTIVE,
                IVS_LICENCSE_OVERBOOKED,
        ]);
        if (empty($courselicenses) && empty($instancelicenses)) {
            return $response;
        }

        // Usage course licenses.
        $courseusers = [
                'already' => [],
                'users' => 0,
        ];

        $sumcourseusers = 0;

        foreach ($courselicenses as $cl) {
            // We are interested in active licenses.
            if (empty($cl->course_id)) {
                continue;
            }

            $courseusers = $this->get_num_course_members($cl->course_id, $courseusers);
            $sumcourseusers += $courseusers['users'];

            $requestdata = [
                    "instance_id" => $instanceid,
                    'license_id' => $cl->id,
                    'spots_in_use' => $courseusers['users'],
            ];
            $this->send_request("usage", $requestdata);
        }

        foreach ($instancelicenses as $il) {
            $alluser = $this->get_num_instance_members();
            $spotsneeded = $alluser - $sumcourseusers;
            $requestdata = [
                    "instance_id" => $instanceid,
                    'license_id' => $il->id,
                    'spots_in_use' => $spotsneeded,
            ];
            $response = $this->send_request("usage", $requestdata);
        }

        $this->get_status(true);

        return $response;
    }

    /**
     * Gives the amount of users on a specified course
     *
     * @param $courseid
     * @param array $allreadygotenusers
     *
     * @return array
     */
    public function get_num_course_members($courseid, $allreadygotenusers = [])
    {
        $users = $this->get_user_from_course($courseid);

        foreach ($users as $key => $value) {
            $allreadygotenusers['already'][$key] = $value;
        }

        $allreadygotenusers['users'] = count($users);
        return $allreadygotenusers;
    }

    /**
     * Gives the amount of members on the instance
     *
     * @return int
     */
    public function get_num_instance_members()
    {
        return count($this->get_all_user_from_instance());
    }

    public function send_request($type, $requestdata)
    {
        $pathavailable = [
                "usage" => IVS_CORE_API_CALLBACK_USAGE,
                "coreRegister" => IVS_CORE_API_CALLBACK_REGISTER,
                "status" => IVS_CORE_API_CALLBACK_STATUS,
                "callback_cdn" => IVS_CORE_API_CALLBACK_CDN,
                "activate" => IVS_CORE_API_CALLBACK_ACTIVATE,
                "release" => IVS_CORE_API_CALLBACK_RELEASE,
                "instance" => IVS_CORE_API_CALLBACK_INSTANCE,
        ];
        $path = $pathavailable[$type];
        if (empty($path)) {
            throw new \Exception('Unknown path ' . $type);
        }

        return $this->send_curl_request($path, "POST", $requestdata);
    }

    public function cron_runtime_too_old()
    {
        $lastrun = strtotime('NOW') - strtotime(get_config('mod_ivs', 'ivs_schedule_task'));
        $maxtime = IVS_CORE_CRON_WAITING_TIME;
        if ($lastrun > $maxtime) {
            return true;
        }
        return false;
    }

    public function set_last_runtime()
    {
        set_config('ivs_schedule_task', date('Y-m-d H:i:s', time()), 'mod_ivs');
    }

    /**
     * @param $testsysteminstanceid
     * @return bool|string
     * @throws \dml_exception
     */
    final public function set_testsystem_instance_id($testsysteminstanceid)
    {
        $requestdata = [
                'instance_id' => $this->get_instance_id(),
                'testsystem_instance_id' => $testsysteminstanceid,
                'action' => IVS_ACTION_TESTSYSTEM
        ];
        return $this->send_request("instance", $requestdata);
    }

    public function set_player_version($playerversion)
    {
        $requestdata = [
                'instance_id' => $this->get_instance_id(),
                'player_version' => $playerversion,
                'action' => IVS_ACTION_PLAYERVERSION
        ];
        return  $this->send_request("instance", $requestdata);
    }
}
