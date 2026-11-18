<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$kind = required_param('kind', PARAM_ALPHA);
\local_teamscsv\course_links::display($courseid, $kind);
