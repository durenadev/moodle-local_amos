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
 * View all available stashes and allow to delete, share or pop strings from them
 *
 * @package   local-amos
 * @copyright 2010 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/mlanglib.php');
require_once(dirname(__FILE__).'/stash_form.php');

$name           = optional_param('name', null, PARAM_RAW);  // stash name
$new            = optional_param('new', null, PARAM_BOOL);  // new stash requested
$apply          = optional_param('apply', null, PARAM_INT);
$pop            = optional_param('pop', null, PARAM_INT);
$drop           = optional_param('drop', null, PARAM_INT);
$pullrequest    = optional_param('pullrequest', null, PARAM_INT);
$hide           = optional_param('hide', null, PARAM_INT);

require_login(SITEID, false);
require_capability('local/amos:stash', get_system_context());

$PAGE->set_pagelayout('standard');
$PAGE->set_url('/local/amos/stash.php');
navigation_node::override_active_url(new moodle_url('/local/amos/stash.php'));
$PAGE->set_title('AMOS stashes');
$PAGE->set_heading('AMOS stashes');
$PAGE->requires->yui_module('moodle-local_amos-stash', 'M.local_amos.init_stash');

if ($new) {
    // pushing the current stage into a new stash
    require_sesskey();
    $stage = mlang_persistent_stage::instance_for_user($USER->id, sesskey());
    $stash = mlang_stash::instance_from_stage($stage, $name);
    $stash->push();
    redirect($PAGE->url);
}

if ($apply) {
    require_sesskey();
    require_capability('local/amos:stage', get_system_context());
    $stash = mlang_stash::instance_from_id($apply);
    if (empty($stash->pullrequest) and $stash->ownerid != $USER->id) {
        print_error('stashaccessdenied', 'local_amos');
        die();
    }
    $stage = mlang_persistent_stage::instance_for_user($USER->id, sesskey());
    $stash->apply($stage);
    $stage->store();
    redirect(new moodle_url('/local/amos/stage.php'));
}

if ($pop) {
    require_sesskey();
    require_capability('local/amos:stage', get_system_context());
    $stash = mlang_stash::instance_from_id($pop);
    if ($stash->ownerid != $USER->id) {
        print_error('stashaccessdenied', 'local_amos');
        die();
    }
    $stage = mlang_persistent_stage::instance_for_user($USER->id, sesskey());
    $stash->apply($stage);
    $stage->store();
    $stash->drop();
    redirect(new moodle_url('/local/amos/stage.php'));
}

if ($drop) {
    require_sesskey();
    $stash = mlang_stash::instance_from_id($drop);
    if ($stash->ownerid != $USER->id) {
        print_error('stashaccessdenied', 'local_amos');
        die();
    }
    $stash->drop();
    redirect($PAGE->url);
}

if ($hide) {
    require_sesskey();
    if (!$DB->count_records('amos_hidden_requests', array('userid' => $USER->id, 'stashid' => $hide))) {
        $record = new stdclass();
        $record->userid = $USER->id;
        $record->stashid = $hide;
        $DB->insert_record('amos_hidden_requests', $record);
    }
    redirect($PAGE->url);
}

$pullrequestform = new local_amos_pullrequest_form();

if ($pullrequestform->is_cancelled()) {
    redirect($PAGE->url);

} elseif ($pullrequestdata = $pullrequestform->get_data()) {
    $stash = mlang_stash::instance_from_id($pullrequestdata->stashid);
    if ($stash->ownerid != $USER->id) {
        print_error('stashaccessdenied', 'local_amos');
        die();
    }
    $stash->send_pull_request($pullrequestdata->name, $pullrequestdata->message);
    redirect($PAGE->url);
}

$output = $PAGE->get_renderer('local_amos');

/// Output starts here
echo $output->header();

if ($pullrequest) {
    require_sesskey();
    $stash = mlang_stash::instance_from_id($pullrequest);
    if ($stash->ownerid != $USER->id) {
        print_error('stashaccessdenied', 'local_amos');
        die();
    }
    echo $output->heading('Pull request');
    echo $output->box('This will make the stash available to the official language maintainers. They will be able to
        pull your work into their stage, review it and eventually commit. Please provide a message for them describing
        your work and why you would like to see your contribution included.');

    $pullrequestform->set_data(array(
        'name'    => s($stash->name),
        'stashid' => $stash->id,
    ));
    $pullrequestform->display();

    echo $output->footer();
    die();
}

// Own stashes
if (!$stashes = $DB->get_records('amos_stashes', array('ownerid' => $USER->id), 'timecreated DESC')) {
    echo $output->heading(get_string('ownstashesnone', 'local_amos'));

} else {
    echo $output->heading(get_string('ownstashes', 'local_amos'));

    foreach ($stashes as $stashdata) {
        $stash = new local_amos_stash($stashdata, $USER);
        if (has_capability('local/amos:stage', get_system_context())) {
            $stash->add_action('apply', new moodle_url($PAGE->url, array('apply' => $stash->id)), get_string('stashapply', 'local_amos'));
            $stash->add_action('pop', new moodle_url($PAGE->url, array('pop' => $stash->id)), get_string('stashpop', 'local_amos'));
        }
        $stash->add_action('drop', new moodle_url($PAGE->url, array('drop' => $stash->id)), get_string('stashdrop', 'local_amos'));
        if ($stash->name !== 'AUTOSAVE') {
            $stash->add_action('submit', new moodle_url($PAGE->url, array('submit' => $stash->id)), get_string('stashsubmit', 'local_amos'));
        }

        echo $output->render($stash);
    }

}

/*
// Pull requests
if (has_capability('local/amos:commit', get_system_context())) {
    $translators = $DB->get_records('amos_translators', array('userid'=>$USER->id));
    $maintainerof = array();  // list of languages the USER is maintainer of, or 'all'
    foreach ($translators as $translator) {
        if ($translator->lang === 'X') {
            $maintainerof = 'all';
            break;
        }
        $maintainerof[] = $translator->lang;
    }

    if (empty($maintainerof)) {
        $stahes = array();

    } else {

        $params = array($USER->id);

        if (is_array($maintainerof)) {
            $langsql = array();
            foreach ($maintainerof as $lang) {
                $langsql[] = 'languages LIKE ?';
                $params[] = '%/'.$lang.'/%';
            }
            $langsql = implode(' OR ', $langsql);

        } else {
            $langsql = '';
        }

        $sql = "SELECT s.id AS stashid, s.ownerid, s.hash, s.languages, s.components, s.strings, s.timemodified, s.name, s.message,
                       ".user_picture::fields('u').",
                       COALESCE(h.id, 0) AS hidden
                  FROM {amos_stashes} s
                  JOIN {user} u ON s.ownerid = u.id
             LEFT JOIN {amos_hidden_requests} h ON (h.userid = ? AND h.stashid = s.id)
                 WHERE pullrequest = 1";

        if (!$showhidden) {
            $sql .= " AND h.id IS NULL";
        }

        if (!empty($langsql)) {
            $sql .= " AND ($langsql)";
        }

        $sql .= " ORDER BY s.timemodified DESC";
        $stashes = $DB->get_records_sql($sql, $params);
    }

    if (empty($stashes)) {
        echo $output->heading('No incoming pull requests');

    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable stashlist pullrequests';
        $table->head = array('Author', 'Created', 'Languages', 'Components', 'Strings',
            get_string('requestactions', 'local_amos') . $output->help_icon('requestactions', 'local_amos'));

        foreach ($stashes as $stash) {
            $cells = array();
            $cells[0] = new html_table_cell($output->user_picture($stash) . s(fullname($stash)));
            $cells[0]->rowspan = 2;
            $cells[1] = new html_table_cell(userdate($stash->timemodified, get_string('strftimedaydatetime', 'langconfig')));
            $cells[2] = new html_table_cell(implode(', ', explode('/', trim($stash->languages, '/'))));
            $cells[3] = new html_table_cell(implode(', ', explode('/', trim($stash->components, '/'))));
            $cells[4] = new html_table_cell($stash->strings);
            $buttons  = $output->single_button(new moodle_url($PAGE->url, array('apply' => $stash->stashid)), 'Apply');
            if ($stash->hidden) {
                $buttons .= ' Hidden';
            } else {
                $buttons .= $output->single_button(new moodle_url($PAGE->url, array('hide' => $stash->stashid)), 'Hide');
            }
            $cells[5] = new html_table_cell($buttons);
            $cells[5]->rowspan = 2;
            $row = new html_table_row($cells);
            $table->data[] = $row;

            $cells = array();
            $cells[0] = new html_table_cell(html_writer::tag('strong', s($stash->name), array('class' => 'name')) .
                                           html_writer::tag('div', s($stash->message), array('class' => 'message')));
            $cells[0]->colspan = 4;
            $cells[0]->attributes['class'] = 'namemessage';
            $row = new html_table_row($cells);
            $table->data[] = $row;
        }

        echo $output->heading('Incoming pull requests');
        echo html_writer::table($table);
    }

    if (!$showhidden) {
        echo $output->single_button(new moodle_url($PAGE->url, array('showhidden' => 1)), 'Display hidden pull requests',
            'post', array('class' => 'singlebutton togglehidden'));
    } else {
        echo $output->single_button(new moodle_url($PAGE->url, array('showhidden' => 0)), 'Do not display hidden pull requests',
            'post', array('class' => 'singlebutton togglehidden'));
    }
}
 */

echo $output->footer();
