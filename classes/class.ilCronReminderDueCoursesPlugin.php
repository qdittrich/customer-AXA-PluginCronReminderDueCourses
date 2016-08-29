<?php
/**
 * A cron-hook plugin to send mails to participants of a course that will start soon.
 *
 */
require_once("./Services/Cron/classes/class.ilCronHookPlugin.php");
 
class ilCronReminderDueCoursesPlugin extends ilCronHookPlugin {
	function getPluginName() {
		return "CronReminderDueCourses";
	}
	function getCronJobInstances() {
		require_once $this->getDirectory()."/classes/class.cronReminderDueCoursesJob.php";
		$job = new cronReminderDueCoursesJob();
		return array($job);
	}
	function getCronJobInstance($a_job_id) {                
		require_once $this->getDirectory()."/classes/class.cronReminderDueCoursesJob.php";
		$job = new cronReminderDueCoursesJob();
		return $job;
	}
}

?>
