<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'BTI Portal helpers';
$string['privacy:metadata'] = 'The BTI Portal helpers plugin does not store personal data.';
$string['wrongcurrentpassword'] = 'Current password is incorrect. Use the temporary password from your Moodle welcome email (or your last known password).';
$string['passwordmustdiffer'] = 'New password must be different from your current password.';
$string['passwordpolicyfailed'] = 'New password does not meet Moodle password policy: {$a}';
$string['passwordchanged'] = 'Password updated successfully.';
$string['temppasswordneedidentifier'] = 'Enter your Moodle username or the email on your account.';
$string['temppasswordemailsent'] = 'If an account matches, a password reset link has been emailed. Check your inbox (and spam), then choose a new password on the bti LMS portal.';
$string['passwordresetrequestaccepted'] = 'If an account matches, a password reset link has been emailed. Check your inbox (and spam).';
$string['passwordresetemailsubject'] = '{$a}: reset your bti LMS password';
$string['passwordresetemailintro'] = 'We received a request to reset your {$a} password.';
$string['passwordresetemailbutton'] = 'Choose a new password';
$string['passwordresetemailexpiry'] = 'This one-use link expires in {$a} minutes. If you did not request it, you can ignore this email.';
$string['passwordresetemailbody'] = 'We received a request to reset your {$a->sitename} password.

Choose a new password using this secure, one-use link:
{$a->link}

If you did not request a reset, you can ignore this email.';
$string['temppasswordemailfailed'] = 'Moodle could not send the password email. Ask an administrator to check outgoing mail settings.';
$string['portalurl'] = 'Student portal URL';
$string['portalurl_desc'] = 'Trusted public HTTPS origin of bti LMS (for example https://lms.example.com). Used in password reset links. Configure this before enabling portal password reset.';
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
$string['notcourseteacher'] = 'You are not assigned to teach this course.';
$string['notprogrammeassignment'] = 'You need an active Course Unit teaching assignment in this programme to publish its Final Exam.';
$string['missingquizcapability'] = 'Your Moodle role does not have the capabilities required to create quizzes and questions in this course.';
$string['invalidcourseid'] = 'The selected Moodle course is invalid or unavailable.';
$string['quizcreationfailed'] = 'Moodle could not create the quiz. No assessment was published. Please try again or contact your Moodle administrator.';
$string['quizcreationbusy'] = 'A Final Exam publish for this course is already in progress. Wait briefly before retrying.';
$string['studentnotenrolled'] = 'You currently do not have access to this assessment. Please contact your administrator for assistance.';
$string['not_enrolled'] = 'You are not enrolled in this course (code: not_enrolled). {$a}';
$string['enrol_suspended'] = 'Your enrolment in this course is suspended (code: enrol_suspended). {$a}';
$string['enrol_expired'] = 'Your enrolment in this course has expired (code: enrol_expired). {$a}';
$string['not_yet_open'] = 'This assessment is not open yet (code: not_yet_open). {$a}';
$string['closed'] = 'This assessment is closed (code: closed). {$a}';
$string['restricted'] = 'This assessment is restricted (code: restricted). {$a}';
$string['attempts_exhausted'] = 'You have used all permitted attempts (code: attempts_exhausted). {$a}';
$string['password_required'] = 'This assessment requires a password (code: password_required). {$a}';
$string['activity_hidden'] = 'This assessment is hidden (code: activity_hidden). {$a}';
$string['quizaccessdenied'] = 'This assessment is restricted (code: restricted). {$a}';
$string['enrolmentsuspended'] = 'Your Moodle enrolment in this course is suspended.';
$string['enrolmentexpired'] = 'Your Moodle enrolment in this course has expired.';
$string['task_reconcilestudentenrolments'] = 'Reconcile student Moodle enrolments for programme assessments';
$string['courseunavailable'] = 'This course is unavailable.';
$string['quizdifferentcourse'] = 'The quiz belongs to a different course.';
$string['quizinvalidcm'] = 'The Moodle course module mapping for this quiz is invalid.';
$string['quiznoquestions'] = 'This quiz has no questions available.';
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
$string['gradingconflict'] = 'This question was changed after you opened it. Reload the attempt before saving again.';
$string['gradelocked'] = 'This grade is locked in Moodle and was not changed.';
$string['gradeoverridden'] = 'This grade is overridden in the Moodle gradebook and was not changed.';
$string['feesgate_enabled'] = 'Enable student fees gate';
$string['feesgate_enabled_desc'] = 'When enabled, students with an academic profile or fees record who have not paid tuition cannot access learning data via the portal API. Run the fees migration backfill before enabling.';
$string['feesunpaid'] = 'Tuition fees must be cleared before you can access this area.';
$string['feesmessagingblocked'] = 'You can only message administration while your fees are outstanding.';
$string['feespaymentstudentonly'] = 'Only students with an academic profile may pay tuition via the portal.';
$string['feespaymentnoprofile'] = 'Your student academic profile must be set up before you can pay tuition online.';
$string['paystack_secret_key'] = 'Paystack secret key';
$string['paystack_secret_key_desc'] = 'Test or live secret key for server-side Paystack calls. You may also set BTI_PAYSTACK_SECRET_KEY in the environment. If empty, the live/test keys from the Paystack enrolment plugin are used. Never expose this in the portal frontend.';
$string['paystack_public_key'] = 'Paystack public key';
$string['paystack_public_key_desc'] = 'Test or live public key (pk_…) for Paystack inline card checkout in the student portal. You may also set BTI_PAYSTACK_PUBLIC_KEY in the Moodle server environment. If empty, keys from the Paystack enrolment plugin are used.';
$string['paystack_collection_currency'] = 'Paystack collection currency';
$string['paystack_collection_currency_desc'] = 'ISO currency sent to Paystack (default KES for Kenyan merchants). Stored USD fees are converted to this currency for display and payment.';
$string['fx_usd_kes_rate'] = 'USD to KES fallback rate';
$string['fx_usd_kes_rate_desc'] = 'Used when live FX is unavailable (e.g. 129.50 means 1 USD = 129.50 KES). Students are not shown the rate.';
$string['fx_live_enabled'] = 'Use live USD/KES rate';
$string['fx_live_enabled_desc'] = 'Fetches from open.er-api.com and caches the rate. If live and cache fail, the fallback rate is used; if that is empty, online pay is blocked.';
$string['fx_cache_hours'] = 'FX cache lifetime (hours)';
$string['fx_cache_hours_desc'] = 'How long to reuse the last live USD/KES rate before refreshing.';
$string['paystack_channels'] = 'Paystack checkout channels';
$string['paystack_channels_desc'] = 'Use auto to let Paystack apply dashboard settings (recommended). Or comma-separated channels: card,mobile_money. If checkout shows only M-Pesa while Card is ticked in Paystack, contact Paystack support to activate live card processing for Kenya.';
$string['paystackhttpfailed'] = 'Paystack request failed: {$a}';
$string['admissions_fees_callback_url'] = 'Admissions CMS fees callback URL';
$string['admissions_fees_callback_url_desc'] = 'HTTPS URL for Admissions CMS (POST). Fired when tuition is newly marked paid (Paystack, set_fees_paid, or CLI).';
$string['admissions_fees_callback_secret'] = 'Admissions fees callback HMAC secret';
$string['admissions_fees_callback_secret_desc'] = 'Shared secret for X-BTI-Signature (SHA-256 HMAC of the JSON body). May also use BTI_ADMISSIONS_FEES_CALLBACK_SECRET in the environment.';
