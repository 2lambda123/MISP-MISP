<?php

App::uses('AppModel', 'Model');

/**
 * TemplateElementText Model
 *
*/
class TemplateElementText extends AppModel {
	public $actsAs = array('Containable');
	public $belongsTo = array('TemplateElement');
	
	public $validate = array(
			'name' => array(
					'rule' => 'valueNotEmpty',
					'message' => 'Please enter a Name',
			),
			'text' => array(
					'rule' => 'valueNotEmpty',
					'message' => 'Please fill out the text field',
			),
	);
}
