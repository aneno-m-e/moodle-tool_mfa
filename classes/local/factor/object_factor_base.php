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

namespace tool_mfa\local\factor;

/**
 * MFA factor abstract class.
 *
 * @package     tool_mfa
 * @author      Mikhail Golenkov <golenkovm@gmail.com>
 * @copyright   Catalyst IT
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class object_factor_base implements object_factor {

    /** @var string Factor name */
    public $name;

    /** @var int Lock counter */
    private $lockcounter;

    /**
     * Secret manager
     *
     * @var \tool_mfa\local\secret_manager
     */
    protected $secretmanager;

    /**
     * Class constructor
     *
     * @param string $name factor name
     */
    public function __construct($name) {
        global $DB, $USER;
        $this->name = $name;

        // Setup secret manager.
        $this->secretmanager = new \tool_mfa\local\secret_manager($this->name);
    }

    /**
     * This loads the locked state from the DB
     *
     * Base class implementation.
     */
    public function load_locked_state() {
        global $DB, $USER;

        // Check if lockcounter column exists (incase upgrade hasnt run yet).
        // Only 'input factors' are lockable.
        if ($this->is_enabled() && $this->is_lockable()) {
            try {
                // Setup the lock counter.
                $sql = "SELECT MAX(lockcounter) FROM {tool_mfa} WHERE userid = ? AND factor = ? AND revoked = ?";
                @$this->lockcounter = $DB->get_field_sql($sql, [$USER->id, $this->name, 0]);

                if (empty($this->lockcounter)) {
                    $this->lockcounter = 0;
                }

                // Now lock this factor if over the counter.
                $lockthreshold = get_config('tool_mfa', 'lockout');
                if ($this->lockcounter >= $lockthreshold) {
                    $this->set_state(\tool_mfa\plugininfo\factor::STATE_LOCKED);
                }
            } catch (\dml_exception $e) {
                // Set counter to -1.
                $this->lockcounter = -1;
            }
        }
    }

    /**
     * Returns true if factor is enabled, otherwise false.
     *
     * Base class implementation.
     *
     * @return bool
     * @throws \dml_exception
     */
    public function is_enabled() {
        $status = get_config('factor_'.$this->name, 'enabled');
        if ($status == 1) {
            return true;
        }
        return false;
    }

    /**
     * Returns configured factor weight.
     *
     * Base class implementation.
     *
     * @return int
     * @throws \dml_exception
     */
    public function get_weight() {
        $weight = get_config('factor_'.$this->name, 'weight');
        if ($weight) {
            return (int) $weight;
        }
        return 0;
    }

    /**
     * Returns factor name from language string.
     *
     * Base class implementation.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_display_name() {
        return get_string('pluginname', 'factor_'.$this->name);
    }

    /**
     * Returns factor help from language string.
     *
     * Base class implementation.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_info() {
        return get_string('info', 'factor_'.$this->name);
    }

    /**
     * Defines setup_factor form definition page for particular factor.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param \MoodleQuickForm $mform
     * @return object $mform
     */
    public function setup_factor_form_definition($mform) {
        return $mform;
    }

    /**
     * Defines setup_factor form definition page after form data has been set.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param \MoodleQuickForm $mform
     * @return object $mform
     */
    public function setup_factor_form_definition_after_data($mform) {
        return $mform;
    }

    /**
     * Implements setup_factor form validation for particular factor.
     * Returns an array of errors, where array key = field id and array value = error text.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param array $data
     * @return array
     */
    public function setup_factor_form_validation($data) {
        return [];
    }

    /**
     * Setups given factor and adds it to user's active factors list.
     * Returns true if factor has been successfully added, otherwise false.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param array $data
     * @return stdClass the record if created, or null.
     */
    public function setup_user_factor($data) {
        return null;
    }

    /**
     * Returns an array of all user factors of given type (both active and revoked).
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param stdClass $user the user to check against.
     * @return array
     */
    public function get_all_user_factors($user) {
        return [];
    }

    /**
     * Given the user, returns a singleton record for this factor.
     * If the record does not exist, it is created.
     *
     * @param stdClass $user
     * @return array array containing the one factor.
     */
    protected function get_singleton_user_factor($user) {
        global $DB;
        $records = $DB->get_records('tool_mfa', ['userid' => $user->id, 'factor' => $this->name]);

        if (!empty($records)) {
            return $records;
        }

        // Null records returned, build new record.
        $record = [
            'userid' => $user->id,
            'factor' => $this->name,
            'timecreated' => time(),
            'createdfromip' => $user->lastip,
            'timemodified' => time(),
            'revoked' => 0,
        ];
        $record['id'] = $DB->insert_record('tool_mfa', $record, true);
        return [(object) $record];
    }

    /**
     * Returns an array of active user factor records.
     * Filters get_all_user_factors() output.
     *
     * @param stdClass $user object to check against.
     * @return array
     */
    public function get_active_user_factors($user) {
        $return = [];
        $factors = $this->get_all_user_factors($user);
        foreach ($factors as $factor) {
            if ($factor->revoked == 0) {
                $return[] = $factor;
            }
        }
        return $return;
    }

    /**
     * Defines login form definition page for particular factor.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param \MoodleQuickForm $mform
     * @return object $mform
     */
    public function login_form_definition($mform) {
        return $mform;
    }

    /**
     * Defines login form definition page after form data has been set.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param \MoodleQuickForm $mform
     * @return object $mform
     */
    public function login_form_definition_after_data($mform) {
        return $mform;
    }

    /**
     * Implements login form validation for particular factor.
     * Returns an array of errors, where array key = field id and array value = error text.
     *
     * Dummy implementation. Should be overridden in child class.
     *
     * @param array $data
     * @return array
     */
    public function login_form_validation($data) {
        return [];
    }

    /**
     * Returns true if factor class has factor records that might be revoked.
     * It means that user can revoke factor record from their profile.
     *
     * Override in child class if necessary.
     *
     * @return bool
     */
    public function has_revoke() {
        return false;
    }

    /**
     * Marks factor record as revoked.
     * If factorid is not provided, revoke all instances of factor.
     *
     * @param int $factorid
     * @return bool
     * @throws \dml_exception
     */
    public function revoke_user_factor($factorid = null) {
        global $DB, $USER;

        if (!empty($factorid)) {
            // If we have an explicit factor id, this means we need to be careful about the user.
            $params = ['id' => $factorid];
            $existing = $DB->get_record('tool_mfa', $params);
            if (empty($existing)) {
                return false;
            }
            $matchinguser = $existing->userid == $USER->id;
            if (!is_siteadmin() && !$matchinguser) {
                // We aren't admin, and this isn't our factor.
                return false;
            }
        } else {
            $params = ['userid' => $USER->id, 'factor' => $this->name];
        }
        $DB->set_field('tool_mfa', 'revoked', 1, $params);

        $event = \tool_mfa\event\user_revoked_factor::user_revoked_factor_event($USER, $this->get_display_name());
        $event->trigger();

        return true;
    }

    /**
     * When validation code is correct - update lastverified field for given factor.
     * If factor id is not provided, update all factor entries for user.
     * @param int $factorid
     * @return bool
     * @throws \dml_exception
     */
    public function update_lastverified($factorid = null) {
        global $DB, $USER;
        if (!empty($factorid)) {
            $params = ['id' => $factorid];
        } else {
            $params = ['factor' => $this->name, 'userid' => $USER->id];
        }
        return $DB->set_field('tool_mfa', 'lastverified', time(), $params);
    }

    /**
     * Gets lastverified timestamp.
     *
     * @param int $factorid
     * @return int|bool the lastverified timestamp, or false if not found.
     */
    public function get_lastverified($factorid) {
        global $DB;

        $record = $DB->get_record('tool_mfa', ['id' => $factorid]);
        return $record->lastverified;
    }

    /**
     * Returns true if factor needs to be setup by user and has setup_form.
     *
     * Override in child class if necessary.
     *
     * @return bool
     */
    public function has_setup() {
        return false;
    }

    /**
     * If has_setup returns true, decides if the setup buttons should be shown on the preferences page.
     *
     * @return bool
     */
    public function show_setup_buttons() {
        return $this->has_setup();
    }

    /**
     * Returns true if a factor requires input from the user to verify.
     *
     * Override in child class if necessary
     *
     * @return bool
     */
    public function has_input() {
        return true;
    }

    /**
     * Returns true if a factor is able to be locked if it fails.
     *
     * Generally only input factors are lockable.
     * Override in child class if necessary
     *
     * @return bool
     */
    public function is_lockable() {
        return $this->has_input();
    }

    /**
     * Returns the state of the factor from session information.
     *
     * Implementation for factors that require input.
     * Should be overridden in child classes with no input.
     *
     * @return mixed
     */
    public function get_state() {
        global $SESSION;

        $property = 'factor_'.$this->name;

        if (property_exists($SESSION, $property)) {
            return $SESSION->$property;
        } else {
            return \tool_mfa\plugininfo\factor::STATE_UNKNOWN;
        }
    }

    /**
     * Sets the state of the factor into the session.
     *
     * Implementation for factors that require input.
     * Should be overridden in child classes with no input.
     *
     * @param mixed $state the state constant to set
     * @return bool
     */
    public function set_state($state) {
        global $SESSION;

        // Do not allow overwriting fail states.
        if ($this->get_state() == \tool_mfa\plugininfo\factor::STATE_FAIL) {
            return false;
        }

        $property = 'factor_'.$this->name;
        $SESSION->$property = $state;
        return true;
    }

    /**
     * Creates an event when user successfully setup a factor
     *
     * @param object $user
     * @return void
     */
    public function create_event_after_factor_setup($user) {
        $event = \tool_mfa\event\user_setup_factor::user_setup_factor_event($user, $this->get_display_name());
        $event->trigger();
    }

    /**
     * Function for factor actions in the pass state.
     * Override in child class if necessary.
     */
    public function post_pass_state() {
        // Update lastverified for factor.
        if ($this->get_state() == \tool_mfa\plugininfo\factor::STATE_PASS) {
            $this->update_lastverified();
        }

        // Now clean temp secrets for factor.
        $this->secretmanager->cleanup_temp_secrets();
    }

    /**
     * Function to retrieve the label for a factorid.
     *
     * @param int $factorid
     */
    public function get_label($factorid) {
        global $DB;
        return $DB->get_field('tool_mfa', 'label', ['id' => $factorid]);
    }

    /**
     * Function to get urls that should not be redirected from.
     */
    public function get_no_redirect_urls() {
        return [];
    }

    /**
     * Function to get possible states for a user from factor.
     * Implementation where state is based on deterministic user data.
     * This should be overridden in factors where state is non-deterministic.
     * E.g. IP changes based on whether a user is using a VPN.
     *
     * @param \stdClass $user
     */
    public function possible_states($user) {
        return [$this->get_state()];
    }

    /**
     * Returns condition for passing factor.
     * Implementation for basic conditions.
     * Override for complex conditions such as auth type.
     */
    public function get_summary_condition() {
        return get_string('summarycondition', 'factor_'.$this->name);
    }

    /**
     * Checks whether the factor combination is valid based on factor behaviour.
     * E.g. a combination with nosetup and another factor is not valid,
     * as you cannot pass nosetup with another factor.
     *
     * @param array $combination array of factors that make up the combination
     * @return bool
     */
    public function check_combination($combination) {
        return true;
    }

    /**
     * Gets the string for setup button on preferences page.
     */
    public function get_setup_string() {
        return get_string('setupfactor', 'tool_mfa');
    }

    /**
     * Deletes all instances of factor for a user.
     *
     * @param stdClass $user the user to delete for.
     */
    public function delete_factor_for_user($user) {
        global $DB, $USER;
        $DB->delete_records('tool_mfa', ['userid' => $user->id, 'factor' => $this->name]);

        // Emit event for deletion.
        $event = \tool_mfa\event\user_deleted_factor::user_deleted_factor_event($user, $USER, $this->name);
        $event->trigger();
    }

    /**
     * Increments the lock counter for a factor.
     *
     * @return void
     */
    public function increment_lock_counter() {
        global $DB, $USER;

        // First make sure the state is loaded.
        $this->load_locked_state();

        // If lockcounter is negative, the field does not exist yet.
        if ($this->lockcounter === -1) {
            return;
        }

        $this->lockcounter++;
        // Update record in DB.
        $DB->set_field('tool_mfa', 'lockcounter', $this->lockcounter, ['userid' => $USER->id, 'factor' => $this->name]);

        // Now lock this factor if over the counter.
        $lockthreshold = get_config('tool_mfa', 'lockout');
        if ($this->lockcounter >= $lockthreshold) {
            $this->set_state(\tool_mfa\plugininfo\factor::STATE_LOCKED);

            // Lastly output a notification showing the user the factor is locked.
            \core\notification::error(get_string('factorlocked', 'tool_mfa', $this->get_display_name()));
        }
    }

    /**
     * Return the number of remaining attempts at this factor.
     *
     * @return int the number of attempts at this factor remaining.
     */
    public function get_remaining_attempts() {
        $lockthreshold = get_config('tool_mfa', 'lockout');
        if ($this->lockcounter === -1) {
            // If upgrade.php hasnt been run yet, just return 10.
            return $lockthreshold;
        } else {
            return $lockthreshold - $this->lockcounter;
        }
    }

    /**
     * Process a cancel input from a user.
     *
     * @return void
     */
    public function process_cancel_action() {
        $this->set_state(\tool_mfa\plugininfo\factor::STATE_NEUTRAL);
    }

    /**
     * Hook point for global auth form action hooks.
     *
     * @param \MoodleQuickForm $mform Form to inject global elements into.
     * @return void
     */
    public function global_definition($mform) {
        return;
    }

    /**
     * Hook point for global auth form action hooks.
     *
     * @param \MoodleQuickForm $mform Form to inject global elements into.
     * @return void
     */
    public function global_definition_after_data($mform) {
        return;
    }

    /**
     * Hook point for global auth form action hooks.
     *
     * @param array $data Data from the form.
     * @param array $files Files form the form.
     * @return array of errors from validation.
     */
    public function global_validation($data, $files): array {
        return [];
    }

    /**
     * Hook point for global auth form action hooks.
     *
     * @param object $data Data from the form.
     */
    public function global_submit($data) {
        return;
    }
}
