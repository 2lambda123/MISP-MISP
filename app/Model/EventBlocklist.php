<?php
App::uses('AppModel', 'Model');
class EventBlocklist extends AppModel
{
    public $useTable = 'event_blocklists';

    public $recursive = -1;

    public $actsAs = array(
            'SysLogLogable.SysLogLogable' => array( // TODO Audit, logable
                    'userModel' => 'User',
                    'userKey' => 'user_id',
                    'change' => 'full'),
            'Containable',
    );

    public $blocklistFields = array('event_uuid', 'comment', 'event_info', 'event_orgc');

    public $blocklistTarget = 'event';

    public $validate = array(
            'event_uuid' => array(
                    'unique' => array(
                            'rule' => 'isUnique',
                            'message' => 'Event already blocklisted.'
                    ),
                    'uuid' => array(
                            'rule' => array('uuid'),
                            'message' => 'Please provide a valid UUID'
                    ),
            )
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        $schema = $this->schema();
        if (!isset($schema['event_info'])) {
            $this->updateDatabase('addEventBlocklistsContext');
        }
        $date = date('Y-m-d H:i:s');
        if (empty($this->data['EventBlocklist']['id'])) {
            $this->data['EventBlocklist']['date_created'] = $date;
        }
        if (empty($this->data['EventBlocklist']['comment'])) {
            $this->data['EventBlocklist']['comment'] = '';
        }
        return true;
    }
}
