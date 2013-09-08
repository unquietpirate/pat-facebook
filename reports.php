<?php
require_once 'lib/pat-fb-init.php';

$reportee_id = ($_REQUEST['reportee_id']) ? $_REQUEST['reportee_id'] : '';
$search_results = array();
if (is_numeric($reportee_id)) {
    try {
        $reportee_data = $FB->api("/$reportee_id");
    } catch (Exception $e) {
        // TODO: Figure out why I can't seem to catch this error if the $reportee_id
        //       is a user who the current user has blocked.
        // If the user we're looking up was blocked, we should get an Exception.
        // In that case, ask the user to triple-check that this is the correct
        // user ID number for the user they wish to report.
    }
} else if (empty($reportee_id) && !empty($_REQUEST['reportee_name'])) {
    // If the "name" is numeric or doesn't have spaces, assume it's an ID or an
    // unique username, so do that search first.
    if (is_numeric($_REQUEST['reportee_name']) || (false === strpos($_REQUEST['reportee_name'], ' '))) {
        $search_results[] = $FB->api("/{$_REQUEST['reportee_name']}");
    }
    // But then always do a Graph Search, too.
    $x = $FB->api(
        '/search?type=user&q=' . urlencode($_REQUEST['reportee_name']) .
        '&fields=id,name,picture.type(square),gender,bio,birthday,link' .
        '&limit=200' // TODO: Customize or paginate this.
    );
    if ($x['data']) {
        foreach ($x['data'] as $result) {
            array_push($search_results, $result);
        }
    } else if (false !== strpos($_REQUEST['reportee_name'], ' ')) {
        // If we didn't get any results, try guessing their username.
        $username = str_replace(' ', '', $_REQUEST['reportee_name']);
        $x = $FB->api("/$username");
        if ($x) {
            $search_results[] = $x;
        }
    }
}

// Offer a tab separated values download of the user's own reports.
if ('export' === $_GET['action']) {
    $db = new PATFacebookDatabase();
    $db->connect(psqlConnectionStringFromDatabaseUrl());
    // Learn column placements to strip incident ID, ensure only own reports are exported.
    $result = pg_query_params($db->getHandle(),
        'SELECT column_name FROM information_schema.columns WHERE table_name=$1',
        array('incidents')
    );
    $pos_id = 0;
    $pos_reporter_id = 0;
    $i = 0;
    $field_headings = array();
    while ($row = pg_fetch_object($result)) {
        $field_headings[] = $row->column_name;
        switch ($row->column_name) {
            case 'id':
                $pos_id = $i;
                break;
            case 'reporter_id':
                $pos_reporter_id = $i;
                break;
        }
        $i++;
    }
    array_splice($field_headings, $pos_id, 1);
    if ($data = pg_copy_to($db->getHandle(), 'incidents', "\t")) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="My PAT Facebook reports.tsv"');
        header('Pragma: no-cache');
        if (isset($_GET['header'])) {
            print implode("\t", $field_headings) . "\n";
        }
        foreach ($data as $line) {
            $fields = explode("\t", $line);
            if ($user_id == $fields[$pos_reporter_id]) {
                array_splice($fields, $pos_id, 1);
                print implode("\t", $fields);
            }
        }
    }
    exit();
}

ob_start(); // Sometimes we put headers in bad places. :P
include 'templates/header.php';
switch ($_GET['action']) {
    case 'new':
        include 'templates/report_new.php';
        break;
    case 'lookup':
    default:
        include 'templates/report_lookup.php';
        break;
}
include 'templates/footer.php';
ob_end_flush();
