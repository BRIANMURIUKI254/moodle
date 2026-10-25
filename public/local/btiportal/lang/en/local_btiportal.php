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
$string['portalurl'] = 'Student portal URL';
$string['portalurl_desc'] = 'Public URL of bti LMS used in admission emails (for example https://lms.example.com). The /login path is added automatically. If empty, the portal will send the URL with each admission.';
$string['admissionwelcomesubject'] = 'Congratulations — you have been admitted to BTI College';
$string['admissionwelcomeprogrammefallback'] = 'your programme of study';
$string['admissionwelcomeidfallback'] = 'as issued';
$string['admissionwelcomebody'] = 'Dear {$a->studentname},

Congratulations, you have been admitted to BTI College.

On behalf of {$a->sitename}, we are delighted to welcome you to {$a->programmename}. Your admission has been confirmed, and your learning portal account is ready.

Please keep this message confidential. It contains the details you need to begin your studies.

Learning portal: {$a->portalurl}
Full name: {$a->studentname}
Programme: {$a->programmename}
Admission number: {$a->admissionnumber}
Username: {$a->username}
Temporary password: {$a->temppassword}

Next steps:
1. Open the learning portal using the link above.
2. Sign in with your username and temporary password.
3. You will be asked to choose a new password. Please do this immediately and do not share the temporary password.
4. Review your dashboard, enrolled units, timetable, and announcements.
5. Update your profile photo and contact details so we can reach you during the semester.

If you need assistance, contact the Admissions Office or your institution’s e-learning support team.
{$a->supportline}

We look forward to supporting your studies.

Yours sincerely,
Admissions Office
BTI College';
$string['admissionwelcomebodyexisting'] = 'Dear {$a->studentname},

Congratulations, you have been admitted to BTI College.

On behalf of {$a->sitename}, we are delighted to welcome you to {$a->programmename}. Your admission has been confirmed on your existing learning portal account.

Learning portal: {$a->portalurl}
Full name: {$a->studentname}
Programme: {$a->programmename}
Admission number: {$a->admissionnumber}
Username: {$a->username}

Next steps:
1. Open the learning portal using the link above.
2. Sign in with your existing username and password.
3. Review your dashboard, enrolled units, timetable, and announcements.
4. Update your profile photo and contact details so we can reach you during the semester.

If you need assistance, contact the Admissions Office or your institution’s e-learning support team.
{$a->supportline}

We look forward to supporting your studies.

Yours sincerely,
Admissions Office
BTI College';
$string['admissionwelcomeemailsent'] = 'A formal admission welcome email has been sent to the student.';
$string['admissionwelcomeemailfailed'] = 'The admission welcome email could not be sent. Check Moodle outgoing mail settings.';
$string['admissionwelcomeemailnouser'] = 'The student account was not found or has no email address.';
$string['notunitteacher'] = 'You are not assigned to teach this Course Unit.';
$string['teachingpermissioninactive'] = 'Assessment could not be published because your Moodle teaching permissions are not active for this Course Unit.';
$string['gradeitemnotmapped'] = 'Grade item {$a->gradeitemid} is not mapped to unit {$a->unitid}.';
$string['invalidassignmentcategory'] = 'Invalid assignment category "{$a}". Use CAT, EXAM, or ASSIGNMENT.';
$string['invalidassessmenttype'] = 'Invalid assessment type "{$a}".';
$string['invalidwarningaction'] = 'Invalid warning action "{$a}". Use flag, submit, or submit_flag.';
$string['inactivealertadminemails'] = 'Inactive student alert admin emails';
$string['inactivealertadminemails_desc'] = 'One or more email addresses that should receive inactive student alerts. Separate addresses with commas, semicolons, spaces, or new lines.';
$string['task_sendinactivealerts'] = 'Send inactive student alerts';
$string['task_syncstudentsemesters'] = 'Unlock next semester for students when timeframe ends';
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

// Academic structure capabilities (Phase 2).
$string['btiportal:manageacademic'] = 'Manage academic structure (programmes, curricula, years, semesters, course units)';
$string['btiportal:viewacademic'] = 'View academic structure';
$string['btiportal:managetimetable'] = 'Manage operational timetable entries (department-scoped for Department Leaders)';
$string['btiportal:managedepartmentcalendar'] = 'Manage department calendar events';
$string['btiportal:managedepartmentnotifications'] = 'Manage department-scoped operational notifications';
$string['btiportal:managedepartmentannouncements'] = 'Manage department-scoped announcements';
$string['btiportal:viewdepartmentreports'] = 'View department-scoped academic reports';
$string['btiportal:viewdepartmentaudit'] = 'View department-scoped operational audit records';
$string['academicenvelope'] = 'Academic API response envelope';

// Academic catalogue (Phase 3).
$string['school'] = 'School';
$string['schools'] = 'Schools';
$string['department'] = 'Department';
$string['departments'] = 'Departments';
$string['yearofstudy'] = 'Year of study';
$string['curriculumsemester'] = 'Curriculum semester';
$string['lecturerdepartment'] = 'Lecturer department membership';
$string['studentacademicprofile'] = 'Student academic profile';
$string['stafforgassignment'] = 'Staff organisational placement';
$string['awardtype'] = 'Award type';
$string['admissionstatus'] = 'Admission status';
$string['academicstatus'] = 'Academic status';
$string['statusactive'] = 'Active';
$string['statusinactive'] = 'Inactive';
$string['statusarchived'] = 'Archived';
$string['schoolnotactive'] = 'School is not active';
$string['departmentnotfound'] = 'Department not found';
$string['studentprofileexists'] = 'This user already has a student academic profile';
