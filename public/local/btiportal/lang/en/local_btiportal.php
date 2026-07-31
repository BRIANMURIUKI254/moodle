<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'BTI Portal helpers';
$string['privacy:metadata'] = 'The BTI Portal helpers plugin does not store personal data.';
$string['wrongcurrentpassword'] = 'Current password is incorrect. Use the temporary password from your Moodle welcome email (or your last known password).';
$string['passwordmustdiffer'] = 'New password must be different from your current password.';
$string['passwordpolicyfailed'] = 'New password does not meet Moodle password policy: {$a}';
$string['passwordchanged'] = 'Password updated successfully.';
$string['temppasswordneedidentifier'] = 'Enter your Moodle username or the email on your account.';
$string['temppasswordemailsent'] = 'If an account matches, a temporary password has been emailed. Check your inbox (and spam), then sign in and change the password.';
$string['temppasswordemailfailed'] = 'Moodle could not send the password email. Ask an administrator to check outgoing mail settings.';
$string['notunitteacher'] = 'You are not assigned to teach this course/unit (course {$a->courseid}, unit {$a->unitid}).';
$string['gradeitemnotmapped'] = 'Grade item {$a->gradeitemid} is not mapped to unit {$a->unitid}.';
$string['invalidassignmentcategory'] = 'Invalid assignment category "{$a}". Use CAT, EXAM, or ASSIGNMENT.';
$string['inactivealertadminemails'] = 'Inactive student alert admin emails';
$string['inactivealertadminemails_desc'] = 'One or more email addresses that should receive inactive student alerts. Separate addresses with commas, semicolons, spaces, or new lines.';
$string['task_sendinactivealerts'] = 'Send inactive student alerts';
$string['inactivealertadminrecipientname'] = 'Portal admin';
$string['inactivealertadminsubject'] = '{$a->student} has not logged into {$a->course} since {$a->lastaccess}.';
$string['inactivealertadminbody'] = 'Student: {$a->student}
Course: {$a->course}
Last login in this course: {$a->lastaccess}

This student has not accessed the course in 7 or more days.';
$string['inactivealertstudentsubject'] = 'Please return to {$a->course}';
$string['inactivealertstudentbody'] = 'Hello {$a->firstname},

We noticed that you have not logged into {$a->course} since {$a->lastaccess}.

Please log back in and continue with your course as soon as you can.

Course link: {$a->courseurl}

If you are having trouble accessing the course, please contact your administrator or tutor.';
