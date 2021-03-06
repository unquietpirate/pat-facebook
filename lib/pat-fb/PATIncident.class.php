<?php
require_once 'PATFacebookDatabase.class.php';

class PATIncident {
    private $db;
    private $reader; // The entity against whom to determine visibility settings.

    function PATIncident ($info) {
        $this->db = new PATFacebookDatabase('postgres');
        $this->db->connect(psqlConnectionStringFromDatabaseUrl());
        if (is_array($info)) {
            foreach ($info as $k => $v) {
                $this->$k = $v;
            }
            if (1 === count($info) && isset($this->id)) {
                $this->loadFromDatabase();
            }
        }
    }

    public function setReader ($entity) {
        $this->reader = $entity;
    }

    public function fieldsValidate () {
        // Validate individual field values.
        foreach ($this as $k => $v) {
            switch ($k) {
                case 'reporter_id':
                    $this->validateReporterId();
                    break;
                case 'reportee_id':
                    $this->validateReporteeId();
                    break;
                case 'report_title':
                    $this->validateReportTitle();
                    break;
                case 'report_text':
                    $this->validateReportText();
                    break;
                case 'report_visibility':
                    $this->validateReportVisibility();
                    break;
                case 'contactable':
                    $this->validateContactable();
                    break;
            }
        }
        // Validate field combinations.
        // Disallow pseudonymous self-reporting.
        if (($this->reporter_id === $this->reportee_id) && ($this->contactable != 'allowed')) {
            $this->validation_errors['contactable'] = array('You may not hide your identity from statements you share about yourself.');
        }

        return ($this->getValidationErrors()) ? false : true;
    }

    public function getValidationErrors ($field = false) {
        return ($field) ? $this->validation_errors[$field] : $this->validation_errors;
    }

    private function validateReporterId () {
        if (!$this->isValidId($this->reporter_id)) {
            $this->validation_errors['reporter_id'] = array('Reporter ID not a valid ID.');
            return false;
        }
        return true;
    }
    private function validateReporteeId () {
        if (!$this->isValidId($this->reportee_id)) {
            $this->validation_errors['reportee_id'] = array('Reportee ID not a valid ID.');
            return false;
        }
        return true;
    }
    private function isValidId ($x) {
        return (is_numeric($x) && ($x > 0)) ? true : false;
    }

    private function validateReportTitle () {
        if (strlen($this->report_title) > 255) {
            $this->validation_errors['report_title'] = array('Report title must be less than 255 characters long.');
            return false;
        }
        return true;
    }

    private function validateReportText () {
        if (249 > strlen($this->report_text)) {
            $this->validation_errors['report_text'] = array('Report text must be at least 250 characters.');
            return false;
        }
        return true;
    }

    private function validateReportVisibility () {
        switch ($this->report_visibility) {
            case 'public':
            case 'friends':
            case 'reporters':
            case 'reporter_friends':
                return true;
                break;
            default:
                $this->validation_errors['report_visibility'] = array("Report visibility '{$this->report_visibility}' not an understood value.");
                return false;
        }
    }

    private function validateContactable () {
        switch ($this->contactable) {
            case 'approval':
            case 'allowed' :
                return true;
                break;
            default:
                $this->validation_errors['contactable'] = array("Contactable preference '{$this->contactable}' not an understood value.");
                return false;
        }
    }

    /**
     * This assumes a table kind of like the following:
     * CREATE TABLE incidents (
     *   id            BIGSERIAL PRIMARY KEY,
     *   reporter_id   BIGINT,
     *   reportee_id   BIGINT NOT NULL,
     *   report_title  VARCHAR(255),
     *   report_text   TEXT NOT NULL,
     *   report_visibility VARCHAR(255) NOT NULL,
     *   contactable   VARCHAR(255) NOT NULL,
     *   report_date   TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
     * );
     */
    public function save () {
        if ('postgres' === $this->db->getType()) {
            $result = pg_query_params(
                $this->db->getHandle(),
                'INSERT INTO incidents (reporter_id, reportee_id, report_title, report_text, report_visibility, contactable)' .
                ' VALUES ($1, $2, $3, $4, $5, $6) RETURNING id;',
                array($this->reporter_id, $this->reportee_id, $this->report_title, $this->report_text, $this->report_visibility, $this->contactable)
            );
            if (pg_num_rows($result)) {
                $r = pg_fetch_object($result);
                return $r->id;
            }
        }
    }

    private function loadFromDatabase () {
        if ('postgres' === $this->db->getType()) {
            $result = pg_query_params(
                $this->db->getHandle(),
                'SELECT * FROM incidents WHERE id = $1 LIMIT 1',
                array($this->id)
            );
            if (pg_num_rows($result)) {
                foreach (pg_fetch_object($result) as $k => $v) {
                    $this->$k = $v;
                }
            }
        }
    }

    public function isVisible() {
        if (!$this->reader) {
            // If we don't have a reader, the user is "anonymous"
            // so only public reports should be visible.
            return ($this->report_visibility === 'public') ? true : false;
        }
        // Always visible to oneself.
        if ($this->reporter_id == $this->reader->getId()) {
            return true;
        }

        switch ($this->report_visibility) {
            case 'public':
                return true;
            case 'friends':
                return $this->reader->isFriendsWith($this->reporter_id);
            case 'reporters':
                // This report visible to "only other people who have shared".
                // Get a list of all other people who have shared.
                foreach ($this->getAllReporters() as $reporter) {
                    // For each person who has shared,
                    // check if this reader is in that list.
                    if ($this->reader->getId() == $reporter) {
                        // This reader is a fellow reporter.
                        // Returning TRUE here means that anyone who reported this
                        // account can see this report, including the person who
                        // this report is about, if that person has also submitted
                        // a report about themselves. (I.e., if reporter_id===reportee_id)
                        // So, is this reader the person whom this report is about?
                        if ($this->reader->getId() !== $this->reportee_id) {
                            // No, this reader is a fellow reporter, but not the reportee.
                            return true;
                        } else {
                            // Yes, this reader is trying to look at a report about themself.
                            // So, has this person submitted a report about themselves?
                            $result = pg_query_params($this->db->getHandle(),
                                'SELECT DISTINCT report_visibility FROM incidents WHERE reporter_id=$1 AND reportee_id=$1',
                                array($this->reader->getId())
                            );
                            if (pg_num_rows($result)) {
                                // Yes, this person has submitted a report about themselves.
                                // But have they self-reported with the same visibility setting?
                                while ($row = pg_fetch_object($result)) {
                                    if ($row->report_visibility === $this->report_visibility) {
                                        return true; // Yes, they ante'd up.
                                    }
                                }
                            }
                        }
                    }
                }
                return false;
            case 'reporter_friends':
                if ($this->reader->isFriendsWith($this->reporter_id)) {
                    foreach ($this->getAllReporters() as $reporter) {
                        if ($this->reader->getId() == $reporter) {
                            return true;
                        }
                    }
                }
                return false;
            default:
                return false; // Better safe than sorry.
        }
    }

    private function getAllReporters () {
        $r = array();
        if ('postgres' === $this->db->getType()) {
            $result = pg_query_params($this->db->getHandle(),
                'SELECT DISTINCT reporter_id FROM incidents WHERE reportee_id=$1',
                array($this->reportee_id)
            );
            if (pg_num_rows($result)) {
                while ($row = pg_fetch_object($result)) {
                    $r[] = $row->reporter_id;
                }
            }
        }
        return $r;
    }

}
