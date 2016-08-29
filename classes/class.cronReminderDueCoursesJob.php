<?php

/* Copyright (c) 1998-2015 ILIAS open source, Extended GPL, see docs/LICENSE */
/**
* Class		cronReminderDueCoursesJob
*
* CronJob:	Send reminder-mails to participants 
*
* @author Nils Haagen
* @version $Id$
*/

require_once "Services/Cron/classes/class.ilCronManager.php";
require_once "Services/Cron/classes/class.ilCronJob.php";
require_once "Services/Cron/classes/class.ilCronJobResult.php";
class cronReminderDueCoursesJob extends ilCronJob {
	private $gIldb;
	private $gLog;
	private $gLng;
	private $gRbacadmin;
	
	private $relevantCourses;
	private $jCustomer;
	private $noUICM;
	private $axaSettings;


	public function __construct() {
		global $ilDB, $ilLog, $lng, $rbacadmin;
		$this->gIldb = $ilDB;
		$this->gLog = $ilLog;
		$this->gLng = $lng;
		$this->gRbacadmin = $rbacadmin;

		$this->setup_custom();

	}


	/**
	 * Hooks into custom-implementation of jill
	 * @return	null
	 */
	private function setup_custom() {
		//axa:
		global $j_customer;
		
		$cfg_file = './noUI/include/config.php';
		require($cfg_file); //$JILL_CUSTOMER_PATH
		$inc_cfg_path = dirname(realpath($cfg_file)) 
			.'/../..' 
			.$JILL_CUSTOMER_PATH;

		if(!$j_customer) {
			require($inc_cfg_path .'/config.customer.php'); //$j_customer
		}
	
		$this->jCustomer = $j_customer;

		require_once('./noUI/classes/nouiCourseMembership.php');
		$this->noUICM = new nouiCourseMembership();        

		require_once("./Services/AXA/Utils/classes/class.axaSettings.php");
		$this->axaSettings = axaSettings::getInstance();
	}


	
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	string
	 */
	public function getId() {
		return "cron_reminder_due_courses";
	}
	
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	string
	 */
	public function getTitle() {
		return "Erinnerungsmails an Kurs-Teilnehmer";
	}


	/**
	 * Get description
	 * 
	 * @return string
	 */
	public function getDescription() {
		return "Verschickt Mails an die Teilnehmer von Kursen, 
			dass der Kurs bald stattfindet";
	}


	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	bool
	 */
	public function hasAutoActivation() {
		return true;
	}
	
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	bool
	 */
	public function hasFlexibleSchedule() {
		return false;
	}
	
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	int
	 */
	public function getDefaultScheduleType() {
		//return ilCronJob::SCHEDULE_TYPE_DAILY;
		return ilCronJob::SCHEDULE_TYPE_IN_HOURS;
	}
	
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	int
	 */
	public function getDefaultScheduleValue() {
		return 1;
	}
	/**
	 * Implementation of abstract function from ilCronJob
	 * @return	ilCronJobResult
	 */
	public function run() {
		$cron_result = new ilCronJobResult();
		$this->gLog->write("### cronReminderDueCoursesJob: STARTING ###");
		
		$crss = $this->getDueCourses();
		$cnt = count($crss);
		$this->gLog->write("### cronReminderDueCoursesJob: $cnt courses found ###");
		
		ilCronManager::ping($this->getId());

		$this->triggerMailingEvents($crss);
		
		$cron_result->setStatus(ilCronJobResult::STATUS_OK);
		$this->gLog->write("### cronReminderDueCoursesJob: done. ###");

		return $cron_result;
	}



	/**
	 * Get list of relevant courses
	 *
	 * @return	array
	 */
	private function getDueCourses() {	

		$this->relevantCourses = array();

		$courses = array();
		//get available courses by sections
		foreach ($this->jCustomer->checkAvailableCourses as $section => $ref_id) {
			$courses = $courses + $this->noUICM->getAvailableCourses($ref_id);
		}

		//only programs relevant on this level:
		foreach ($courses as $obj_id => $crs_data) {
			$crs_type = $crs_data['type'];
			if($crs_type === 'prg') {
				$prg_ref_id = $crs_data['refId'];
				$this->getCoursesBelowPrg($prg_ref_id); //fills this->relevantCourses
			}
		}
		

		$ret = array();
		foreach ($this->relevantCourses as $crs) {
		
			$crs_start_date = $crs['courseStart']; //dd.mm.YYYY
			$crs_start_time = $crs['courseStartTime']; //hh:MM
			$start_date_str = $crs_start_date .' ' .$crs_start_time;


			//webinars: 1 hour;
			//f2f: 3 days
			$ctype = str_replace('prg_amd_type_', '', $crs['in_subtype']);
			if( in_array($ctype, array(
					'od01','od02','od03','od04'
					,'fk01','fk02','fk03','fk04','fk05'
				))) {
				$ctype = 'webinar';
			}
			if( in_array($ctype, array(
					'odfinal'
					,'fkfinal'
				))) {
				$ctype = 'f2f';
			}



			$start_date = DateTime::createFromFormat('d.m.Y H:i', $start_date_str);
			$today = new DateTime('NOW');


			if ($start_date > $today) { //only future dates
				
				$diff = $today->diff($start_date);

				$hours = $diff->h;
				$hours = $diff->h + ($diff->d * 24);
				
				if($ctype == 'webinar' && $hours == 1) {
					array_push($ret, $crs);
				}

				if($ctype == 'f2f' && $hours == 72) {
					array_push($ret, $crs);
				}
			}
		}

		return $ret;
	}


	/**
	 * get members of course and trigger mailing-event
	 * @return	[int]
	 */
	private function triggerMailingEvents(array $a_courses) {
		global $ilAppEventHandler;

		require_once("./Modules/Course/classes/class.ilObjCourse.php");

		foreach ($a_courses as $course) {


			$crs = new \ilObjCourse($course['objId'], false);
			$members = $crs->getMembersObject()->getMembers();
			
			$this->gLog->write(print_r($course, true));
			$this->gLog->write(print_r($members, true));

			foreach ($members as $usr_id) {

				$ilAppEventHandler->raise(
					"Modules/Course", 
					'remindDueCourse', 
					array(
						'obj_id' => $course['objId'],
						'usr_id' => $usr_id
					)
				);
				ilCronManager::ping($this->getId());
			}

		}
	}





	/**
	 * get courses below (subtyped) program 
	 * dig deeper, if there are more prg-leafes
	 *
	 * @return	null
	 */
	private function getCoursesBelowPrg($a_prg_ref_id, $subtype=0) {
		
		ilCronManager::ping($this->getId());

		$children = $this->noUICM->getAvailableCourses($a_prg_ref_id);
		foreach($children as $obj_id => $entry) {

			if ($entry['type'] === 'prg'  
				&& (
						$entry['subtype_id'] != 0 
						|| 
						$this->noUICM->getStudyProgram($obj_id)->getAmountOfChildren() > 0
					)
				) {
				
				$subtype = $this->axaSettings->getConstById($entry['subtype_id']);
				$this->getCoursesBelowPrg($entry['refId'], $subtype);
			}

			if ($entry['type'] === 'crs') {
				$entry['objId'] = $obj_id;
				$entry['in_subtype'] = $subtype;

				array_push($this->relevantCourses, $entry);
			}
		}
	}





}
?>
