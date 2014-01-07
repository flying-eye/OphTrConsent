<?php
/**
 * OpenEyes
 *
 * (C) Moorfields Eye Hospital NHS Foundation Trust, 2008-2011
 * (C) OpenEyes Foundation, 2011-2013
 * This file is part of OpenEyes.
 * OpenEyes is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * OpenEyes is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with OpenEyes in a file titled COPYING. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OpenEyes
 * @link http://www.openeyes.org.uk
 * @author OpenEyes <info@openeyes.org.uk>
 * @copyright Copyright (c) 2008-2011, Moorfields Eye Hospital NHS Foundation Trust
 * @copyright Copyright (c) 2011-2013, OpenEyes Foundation
 * @license http://www.gnu.org/licenses/gpl-3.0.html The GNU General Public License V3.0
 */

class DefaultController extends BaseEventTypeController
{
	static protected $action_types = array(
		'users' => self::ACTION_TYPE_FORM,
	);

	public $booking_event;
	public $booking_operation;
	public $unbooked = false;
	/**
	 * Set up procedures from booking event
	 *
	 * @param $element
	 * @param $action
	 */
	protected function setElementDefaultOptions_Element_OphTrConsent_Procedure($element, $action)
	{
		if ($action == 'create' && $this->booking_event) {
			$element->booking_event_id = $this->booking_event->id;
			if ($this->booking_operation) {
				$element->eye_id = $this->booking_operation->eye_id;
				$element->anaesthetic_type_id = $this->booking_operation->anaesthetic_type_id;
				$element->procedures = $this->booking_operation->procedures;
				$additional = array();
				$additional_ids = array();
				foreach ($element->procedures as $proc) {
					foreach ($proc->additional as $add) {
						if (!in_array($add->id, $additional_ids)) {
							$additional[] = $add;
							$additional_ids[] = $add->id;
						}
					}
				}
				$element->additional_procedures = $additional;
			}
		}
	}

	/**
	 * Set up benefits and risks from booking event procedures
	 *
	 * @param $element
	 * @param $action
	 */
	protected function setElementDefaultOptions_Element_OphTrConsent_BenefitsAndRisks($element, $action)
	{
		if ($action == 'create' && $this->booking_operation) {
			$element->setBenefitsAndRisksFromProcedures($this->booking_operation->procedures);
		}
	}

	/**
	 * Set the consultant id
	 *
	 * @param $element
	 * @param $action
	 */
	protected function setElementDefaultOptions_Element_OphTrConsent_Other($element, $action)
	{
		if ($action == 'create') {
			if ($this->firm->consultant) {
				$element->consultant_id = $this->firm->consultant->id;
			}
		}
	}

	/**
	 * Set the consent type from the child status of the patient
	 *
	 * @param $element
	 * @param $action
	 */
	protected function setElementDefaultOptions_Element_OphTrConsent_Type($element, $action)
	{
		if ($this->patient->isChild()) {
			$element->type_id = 2;
		}
		else {
			$element->type_id = 1;
		}
	}

	/**
	 * Process the booking event value setting
	 *
	 * @throws Exception
	 */
	protected function initActionCreate()
	{
		parent::initActionCreate();

		if (isset($_GET['booking_event_id'])) {
			if (!$api = Yii::app()->moduleAPI->get('OphTrOperationbooking')) {
				throw new Exception('invalid request for booking event');
			}

			if (!($this->booking_event = Event::model()->findByPk($_GET['booking_event_id']))
				|| (!$this->booking_operation = $api->getOperationForEvent($_GET['booking_event_id']))) {
				throw new Exception('booking event not found');
			}
		}
		elseif (isset($_GET['unbooked'])) {
			$this->unbooked = true;
		}
	}

	/**
	 * Manage picking an extant booking for setting consent form defaults
	 *
	 * (non-phpdoc)
	 * @see BaseEventTypeController::actionCreate()
	 */
	public function actionCreate()
	{
		$errors = array();

		if (!empty($_POST)) {
			if (@$_POST['SelectBooking'] == 'unbooked') {
				$this->redirect(array('/OphTrConsent/Default/create?patient_id='.$this->patient->id.'&unbooked=1'));
			} elseif (preg_match('/^booking([0-9]+)$/',@$_POST['SelectBooking'],$m)) {
				$this->redirect(array('/OphTrConsent/Default/create?patient_id='.$this->patient->id.'&booking_event_id='.$m[1]));
			}
			$errors = array('Consent form' => array('Please select a booking or Unbooked procedures'));
		}

		if ($this->booking_event || $this->unbooked) {
			parent::actionCreate();
		} else {
			$bookings = array();

			if ($api = Yii::app()->moduleAPI->get('OphTrOperationbooking')) {
				if ($episode = $this->patient->getEpisodeForCurrentSubspecialty()) {
					$bookings = $api->getBookingsForEpisode($episode->id);
				}
			}

			$this->title = "Please select booking";
			$this->event_tabs = array(
					array(
							'label' => 'Select a booking',
							'active' => true,
					),
			);
			$cancel_url = ($this->episode) ? '/patient/episode/'.$this->episode->id : '/patient/episodes/'.$this->patient->id;
			$this->event_actions = array(
					EventAction::button('Cancel',
							Yii::app()->createUrl($cancel_url),
							array('level'=>'cancel'),
							array('id'=>'et_cancel')
					)
			);
			$this->processJsVars();
			$this->render('select_event',array(
				'errors' => $errors,
				'bookings' => $bookings,
			), false, true);
		}
	}

	/**
	 * Print action
	 * @param integer $id event id
	 */
	public function actionPrint($id)
	{
		$this->printInit($id);
		$elements = array();

		$template = 'print';

		/*if (isset($_GET['lang_id'])) {
			if (!$language = Language::model()->findByPK($_GET['lang_id'])) {
				throw new Exception("Language not found: ".print_r($language->getErrors(),true));
			}
		} else {*/
			$language = Language::model()->find('name=?',array('English'));
		//}

		foreach ($this->getDefaultElements('print') as $element) {
			$elements[get_class($element)] = $element;
		}

		preg_match('/^([0-9]+)/',$elements['Element_OphTrConsent_Type']->type->name,$m);
		$template_id = $m[1];

		$template = "print{$template_id}_$language->name";

		$this->printLog($id, true);
		$this->printPDF($id, $elements, $template, array('vi' => (boolean) @$_GET['vi']));
	}

	/**
	 * Ajax action for getting list of users (json-encoded)
	 */
	public function actionUsers()
	{
		$users = array();

		$criteria = new CDbCriteria;

		$criteria->addCondition(array("active = :active"));
		$criteria->addCondition(array("LOWER(concat_ws(' ',first_name,last_name)) LIKE :term"));

		$params[':active'] = 1;
		$params[':term'] = '%' . strtolower(strtr($_GET['term'], array('%' => '\%'))) . '%';

		$criteria->params = $params;
		$criteria->order = 'first_name, last_name';

		$consultant = null;
		// only want a consultant for medical firms
		if ($specialty = $this->firm->getSpecialty()) {
			if ($specialty->medical) {
				$consultant = $firm->consultant;
			}
		}

		foreach (User::model()->findAll($criteria) as $user) {
			if ($contact = $user->contact) {

				$consultant_name = false;

				// if we have a consultant for the firm, and its not the matched user, attach the consultant name to the entry
				if ($consultant && $user->id != $consultant->id) {
					$consultant_name = trim($consultant->contact->title.' '.$consultant->contact->first_name.' '.$consultant->contact->last_name);
				}

				$users[] = array(
					'id' => $user->id,
					'value' => trim($contact->title.' '.$contact->first_name.' '.$contact->last_name.' '.$contact->qualifications).' ('.$user->role.')',
					'fullname' => trim($contact->title.' '.$contact->first_name.' '.$contact->last_name.' '.$contact->qualifications),
					'role' => $user->role,
					'consultant' => $consultant_name,
				);
			}
		}

		echo json_encode($users);
	}
}
