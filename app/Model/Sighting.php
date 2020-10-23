<?php
App::uses('AppModel', 'Model');
App::uses('RandomTool', 'Tools');
App::uses('TmpFileTool', 'Tools');

/**
 * @property Attribute $Attribute
 * @property Event $Event
 */
class Sighting extends AppModel
{
    public $useTable = 'sightings';

    public $recursive = -1;

    public $actsAs = array(
            'Containable',
    );

    public $validate = array(
        'event_id' => 'numeric',
        'attribute_id' => 'numeric',
        'org_id' => 'numeric',
        'date_sighting' => 'numeric',
        'uuid' => 'uuid',
        'type' => array(
            'rule' => array('inList', array(0, 1, 2)),
            'message' => 'Invalid type. Valid options are: 0 (Sighting), 1 (False-positive), 2 (Expiration).'
        )
    );

    public $belongsTo = array(
            'Attribute',
            'Event',
            'Organisation' => array(
                    'className' => 'Organisation',
                    'foreignKey' => 'org_id'
            ),
    );

    public $type = array(
        0 => 'sighting',
        1 => 'false-positive',
        2 => 'expiration'
    );

    public $validFormats = array(
        'json' => array('json', 'JsonExport', 'json'),
        'xml' => array('xml', 'XmlExport', 'xml'),
        'csv' => array('csv', 'CsvExport', 'csv')
    );

    /** @var array */
    private $orgCache = [];

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        if (empty($this->data['Sighting']['id']) && empty($this->data['Sighting']['date_sighting'])) {
            $this->data['Sighting']['date_sighting'] = date('Y-m-d H:i:s');
        }
        if (empty($this->data['Sighting']['uuid'])) {
            $this->data['Sighting']['uuid'] = CakeText::uuid();
        } else {
            $this->data['Sighting']['uuid'] = strtolower($this->data['Sighting']['uuid']);
        }
        return true;
    }

    public function afterSave($created, $options = array())
    {
        parent::afterSave($created, $options = array());
        $pubToZmq = Configure::read('Plugin.ZeroMQ_enable') && Configure::read('Plugin.ZeroMQ_sighting_notifications_enable');
        $kafkaTopic = Configure::read('Plugin.Kafka_sighting_notifications_topic');
        $pubToKafka = Configure::read('Plugin.Kafka_enable') && Configure::read('Plugin.Kafka_sighting_notifications_enable') && !empty($kafkaTopic);
        if ($pubToZmq || $pubToKafka) {
            $user = array(
                'org_id' => -1,
                'Role' => array(
                    'perm_site_admin' => 1
                )
            );
            $sighting = $this->getSighting($this->id, $user);
            if ($pubToZmq) {
                $pubSubTool = $this->getPubSubTool();
                $pubSubTool->sighting_save($sighting, 'add');
            }
            if ($pubToKafka) {
                $kafkaPubTool = $this->getKafkaPubTool();
                $kafkaPubTool->publishJson($kafkaTopic, $sighting, 'add');
            }
        }
        return true;
    }

    public function beforeDelete($cascade = true)
    {
        parent::beforeDelete();
        $pubToZmq = Configure::read('Plugin.ZeroMQ_enable') && Configure::read('Plugin.ZeroMQ_sighting_notifications_enable');
        $kafkaTopic = Configure::read('Plugin.Kafka_sighting_notifications_topic');
        $pubToKafka = Configure::read('Plugin.Kafka_enable') && Configure::read('Plugin.Kafka_sighting_notifications_enable') && !empty($kafkaTopic);
        if ($pubToZmq || $pubToKafka) {
            $user = array(
                'org_id' => -1,
                'Role' => array(
                    'perm_site_admin' => 1
                )
            );
            $sighting = $this->getSighting($this->id, $user);
            if ($pubToZmq) {
                $pubSubTool = $this->getPubSubTool();
                $pubSubTool->sighting_save($sighting, 'delete');
            }
            if ($pubToKafka) {
                $kafkaPubTool = $this->getKafkaPubTool();
                $kafkaPubTool->publishJson($kafkaTopic, $sighting, 'delete');
            }
        }
    }

    public function captureSighting($sighting, $attribute_id, $event_id, $user)
    {
        $org_id = 0;
        if (!empty($sighting['Organisation'])) {
            $org_id = $this->Organisation->captureOrg($sighting['Organisation'], $user);
        }
        if (isset($sighting['id'])) {
            unset($sighting['id']);
        }
        $sighting['org_id'] = $org_id;
        $sighting['event_id'] = $event_id;
        $sighting['attribute_id'] = $attribute_id;
        $this->create();
        return $this->save($sighting);
    }

    public function getSighting($id, $user)
    {
        $sighting = $this->find('first', array(
            'recursive' => -1,
            'contain' => array(
                'Attribute' => array(
                    'fields' => array('Attribute.value', 'Attribute.id', 'Attribute.uuid', 'Attribute.type', 'Attribute.category', 'Attribute.to_ids')
                ),
                'Event' => array(
                    'fields' => array('Event.id', 'Event.uuid', 'Event.orgc_id', 'Event.org_id', 'Event.info'),
                    'Orgc' => array(
                        'fields' => array('Orgc.name')
                    )
                )
            ),
            'conditions' => array('Sighting.id' => $id)
        ));
        if (empty($sighting)) {
            return array();
        }

        if (!isset($event)) {
            $event = array('Event' => $sighting['Event']);
        }

        $ownEvent = false;
        if ($user['Role']['perm_site_admin'] || $event['Event']['org_id'] == $user['org_id']) {
            $ownEvent = true;
        }
        if (!$ownEvent) {
            // if sighting policy == 0 then return false if the sighting doesn't belong to the user
            if (!Configure::read('Plugin.Sightings_policy') || Configure::read('Plugin.Sightings_policy') == 0) {
                if ($sighting['Sighting']['org_id'] != $user['org_id']) {
                    return array();
                }
            }
            // if sighting policy == 1, the user can only see the sighting if they've sighted something in the event once
            if (Configure::read('Plugin.Sightings_policy') == 1) {
                $temp = $this->find(
                    'first',
                    array(
                        'recursive' => -1,
                        'conditions' => array(
                            'Sighting.event_id' => $sighting['Sighting']['event_id'],
                            'Sighting.org_id' => $user['org_id']
                        )
                    )
                );
                if (empty($temp)) {
                    return array();
                }
            }
        }
        $anonymise = Configure::read('Plugin.Sightings_anonymise');
        if ($anonymise) {
            if ($sighting['Sighting']['org_id'] != $user['org_id']) {
                unset($sighting['Sighting']['org_id']);
                unset($sighting['Organisation']);
            }
        }
        // rearrange it to match the event format of fetchevent
        if (isset($sighting['Organisation'])) {
            $sighting['Sighting']['Organisation'] = $sighting['Organisation'];
            unset($sighting['Organisation']);
        }
        $result = array(
            'Sighting' => $sighting['Sighting']
        );
        $result['Sighting']['Event'] = $sighting['Event'];
        $result['Sighting']['Attribute'] = $sighting['Attribute'];
        if (!empty($sighting['Organisation'])) {
            $result['Sighting']['Organisation'] = $sighting['Organisation'];
        }
        return $result;
    }

    /**
     * @param array $event
     * @param array $user
     * @param array|int|null $attribute Attribute model or attribute ID
     * @param array|bool $extraConditions
     * @param bool $forSync
     * @return array|int
     */
    public function attachToEvent(array $event, array $user, $attribute = null, $extraConditions = false, $forSync = false)
    {
        $ownEvent = $user['Role']['perm_site_admin'] || $event['Event']['org_id'] == $user['org_id'];

        $contain = [];
        $conditions = array('Sighting.event_id' => $event['Event']['id']);
        if (isset($attribute['Attribute']['id'])) {
            $conditions['Sighting.attribute_id'] = $attribute['Attribute']['id'];
        } elseif (is_numeric($attribute)) {
            $conditions['Sighting.attribute_id'] = $attribute;
            $attribute = $this->Attribute->find('first', [
                'recursive' => -1,
                'conditions' => ['Attribute.id' => $attribute],
                'fields' => ['Attribute.uuid']
            ]);
        } else {
            $contain['Attribute'] = ['fields' => 'Attribute.uuid'];
        }

        if (!$ownEvent && (!Configure::read('Plugin.Sightings_policy') || Configure::read('Plugin.Sightings_policy') == 0)) {
            $conditions['Sighting.org_id'] = $user['org_id'];
        }
        if ($extraConditions !== false) {
            $conditions['AND'] = $extraConditions;
        }
        // Sighting reporters setting
        // If the event has any sightings for the user's org, then the user is a sighting reporter for the event too.
        // This means that he /she has access to the sightings data contained within
        if (!$ownEvent && Configure::read('Plugin.Sightings_policy') == 1) {
            $temp = $this->find('first', array(
                'recursive' => -1,
                'conditions' => array(
                    'Sighting.event_id' => $event['Event']['id'],
                    'Sighting.org_id' => $user['org_id'],
                )
            ));
            if (empty($temp)) {
                return array();
            }
        }

        $sightings = $this->find('all', array(
            'conditions' => $conditions,
            'recursive' => -1,
            'contain' => $contain,
            'callbacks' => 'before', // disable after callbacks for every attribute
        ));
        if (empty($sightings)) {
            return array();
        }
        $anonymise = Configure::read('Plugin.Sightings_anonymise');
        $anonymiseAs = Configure::read('Plugin.Sightings_anonymise_as');
        $anonOrg = null;
        if ($forSync && !empty($anonymiseAs)) {
            $anonOrg = $this->getOrganisationById($anonymiseAs);
        }
        $showOrg = Configure::read('MISP.showorg');
        foreach ($sightings as $k => $sighting) {
            if ($showOrg && $sighting['Sighting']['org_id']) {
                $sighting['Organisation'] = $this->getOrganisationById($sighting['Sighting']['org_id']);
            }

            if (
                ($sighting['Sighting']['org_id'] == 0 && !empty($sighting['Organisation'])) ||
                $anonymise || !empty($anonOrg)
            ) {
                if ($sighting['Sighting']['org_id'] != $user['org_id']) {
                    if (empty($anonOrg)) {
                        unset($sighting['Sighting']['org_id']);
                        unset($sighting['Organisation']);
                    } else {
                        $sighting['Sighting']['org_id'] = $anonOrg['id'];
                        $sighting['Organisation'] = $anonOrg;
                    }
                }
            }
            // rearrange it to match the event format of fetchevent
            if (isset($sighting['Organisation'])) {
                $sighting['Sighting']['Organisation'] = $sighting['Organisation'];
            }
            // zeroq: add attribute UUID to sighting to make synchronization easier
            if (isset($sighting['Attribute']['uuid'])) {
                $sighting['Sighting']['attribute_uuid'] = $sighting['Attribute']['uuid'];
            } else {
                $sighting['Sighting']['attribute_uuid'] = $attribute['Attribute']['uuid'];
            }

            $sightings[$k] = $sighting['Sighting'] ;
        }
        return $sightings;
    }

    public function saveSightings($id, $values, $timestamp, $user, $type = false, $source = false, $sighting_uuid = false, $publish = false, $saveOnBehalfOf = false)
    {
        if (!in_array($type, array(0, 1, 2))) {
            return 'Invalid type, please change it before you POST 1000000 sightings.';
        }

        if ($sighting_uuid) {
            // Since sightings are immutable (it is not possible to change it from web interface), we can check
            // if sighting with given uuid already exists and quit early
            $existing_sighting = $this->find('count', array(
                'recursive' => -1,
                'conditions' => array('uuid' => $sighting_uuid)
            ));
            if ($existing_sighting) {
                return 0;
            }
        }

        $conditions = array();
        if ($id && $id !== 'stix') {
            $id = $this->explodeIdList($id);
            if (!is_array($id) && strlen($id) == 36) {
                $conditions = array('Attribute.uuid' => $id);
            } else {
                $conditions = array('Attribute.id' => $id);
            }
        } else {
            if (!$values) {
                return 'No valid attributes found.';
            }
            if (!is_array($values)) {
                $values = array($values);
            }
            foreach ($values as $value) {
                foreach (array('value1', 'value2') as $field) {
                    $conditions['OR'][] = array(
                        'LOWER(Attribute.' . $field . ') LIKE' => strtolower($value)
                    );
                }
            }
        }
        $attributes = $this->Attribute->fetchAttributes($user, array('conditions' => $conditions, 'flatten' => 1));
        if (empty($attributes)) {
            return 'No valid attributes found that match the criteria.';
        }
        $sightingsAdded = 0;
        foreach ($attributes as $attribute) {
            if ($type === '2') {
                // remove existing expiration by the same org if it exists
                $this->deleteAll(array(
                    'Sighting.org_id' => $user['org_id'],
                    'Sighting.type' => $type,
                    'Sighting.attribute_id' => $attribute['Attribute']['id'],
                ));
            }
            $this->create();
            $sighting = array(
                'attribute_id' => $attribute['Attribute']['id'],
                'event_id' => $attribute['Attribute']['event_id'],
                'org_id' => ($saveOnBehalfOf === false) ? $user['org_id'] : $saveOnBehalfOf,
                'date_sighting' => $timestamp,
                'type' => $type,
                'source' => $source,
            );
            // zeroq: allow setting a specific uuid
            if ($sighting_uuid) {
                $sighting['uuid'] = $sighting_uuid;
            }
            $result = $this->save($sighting);
            if ($result === false) {
                return json_encode($this->validationErrors);
            }
            ++$sightingsAdded;
            if ($publish) {
                $this->Event->publishRouter($sighting['event_id'], null, $user, 'sightings');
            }
        }
        return $sightingsAdded;
    }

    public function handleStixSighting($data)
    {
        $randomFileName = $this->generateRandomFileName();
        $tempFile = new File(APP . "files" . DS . "scripts" . DS . "tmp" . DS . $randomFileName, true, 0644);

        // save the json_encoded event(s) to the temporary file
        if (!$tempFile->write($data)) {
            return array('success' => 0, 'message' => 'Could not write the Sightings file to disk.');
        }
        $tempFile->close();
        $scriptFile = APP . "files" . DS . "scripts" . DS . "stixsighting2misp.py";
        // Execute the python script and point it to the temporary filename
        $result = shell_exec($this->getPythonVersion() . ' ' . $scriptFile . ' ' . $randomFileName);
        // The result of the script will be a returned JSON object with 2 variables: success (boolean) and message
        // If success = 1 then the temporary output file was successfully written, otherwise an error message is passed along
        $result = json_decode($result, true);

        if ($result['success'] == 1) {
            $file = new File(APP . "files" . DS . "scripts" . DS . "tmp" . DS . $randomFileName . ".out");
            $result['data'] = $file->read();
            $file->close();
            $file->delete();
        }
        $tempFile->delete();
        return $result;
    }

    public function addUuids()
    {
        $sightings = $this->find('all', array(
            'recursive' => -1,
            'conditions' => array('uuid' => '')
        ));
        $this->saveMany($sightings);
        return true;
    }

    public function explodeIdList($id)
    {
        if (strpos($id, '|')) {
            $id = explode('|', $id);
            foreach ($id as $k => $v) {
                if (!is_numeric($v)) {
                    unset($id[$k]);
                }
            }
            $id = array_values($id);
        }
        return $id;
    }

    public function getSightingsForTag($user, $tag_id, $sgids = array(), $type = false)
    {
        $conditions = array(
            'Sighting.date_sighting >' => $this->getMaximumRange(),
            'EventTag.tag_id' => $tag_id
        );
        if ($type !== false) {
            $conditions['Sighting.type'] = $type;
        }
        $this->bindModel(
            array(
                'hasOne' => array(
                    'EventTag' => array(
                        'className' => 'EventTag',
                        'foreignKey' => false,
                        'conditions' => 'EventTag.event_id = Sighting.event_id'
                    )
                )
            )
        );
        $sightings = $this->find('all', array(
            'recursive' => -1,
            'contain' => array('EventTag'),
            'conditions' => $conditions,
            'fields' => array('Sighting.id', 'Sighting.event_id', 'Sighting.date_sighting', 'EventTag.tag_id')
        ));
        $sightingsRearranged = array();
        foreach ($sightings as $sighting) {
            $date = date("Y-m-d", $sighting['Sighting']['date_sighting']);
            if (isset($sightingsRearranged[$date])) {
                $sightingsRearranged[$date]++;
            } else {
                $sightingsRearranged[$date] = 1;
            }
        }
        return $sightingsRearranged;
    }

    /**
     * @param $user - not used
     * @param array $tagIds
     * @param string $context 'event' or 'attribute'
     * @param string|false $type
     * @return array
     */
    public function getSightingsForObjectIds($user, array $tagIds, $context = 'event', $type = '0')
    {
        $conditions = array(
            'Sighting.date_sighting >' => $this->getMaximumRange(),
            ucfirst($context) . 'Tag.tag_id' => $tagIds
        );
        if ($type !== false) {
            $conditions['Sighting.type'] = $type;
        }
        $this->bindModel(array('hasOne' => array(ucfirst($context) . 'Tag' => array('foreignKey' => false, 'conditions' => ucfirst($context) . 'Tag.' . $context . '_id = Sighting.' . $context . '_id'))));
        $sightings = $this->find('all', array(
            'recursive' => -1,
            'contain' => array(ucfirst($context) . 'Tag'),
            'conditions' => $conditions,
            'fields' => array('Sighting.' . $context . '_id', 'Sighting.date_sighting')
        ));
        $sightingsRearranged = array();
        foreach ($sightings as $sighting) {
            $date = date("Y-m-d", $sighting['Sighting']['date_sighting']);
            $contextId = $sighting['Sighting'][$context . '_id'];
            if (isset($sightingsRearranged[$contextId][$date])) {
                $sightingsRearranged[$contextId][$date]++;
            } else {
                $sightingsRearranged[$contextId][$date] = 1;
            }
        }
        return $sightingsRearranged;
    }

    public function listSightings($user, $id, $context, $org_id = false, $sightings_type = false, $order_desc = true)
    {
        $this->Event = ClassRegistry::init('Event');
        $id = is_array($id) ? $id : $this->explodeIdList($id);
        if ($context === 'attribute') {
            $object = $this->Event->Attribute->fetchAttributes($user, array('conditions' => array('Attribute.id' => $id, 'Attribute.deleted' => 0), 'flatten' => 1));
        } else {
            // let's set the context to event here, since we reuse the variable later on for some additional lookups.
            // Passing $context = 'org' could have interesting results otherwise...
            $context = 'event';
            $object = $this->Event->fetchEvent($user, $options = array('eventid' => $id, 'metadata' => true));
        }
        if (empty($object)) {
            throw new MethodNotAllowedException('Invalid object.');
        }
        $conditions = array(
            'Sighting.' . $context . '_id' => $id
        );
        if ($org_id) {
            $conditions[] = array('Sighting.org_id' => $org_id);
        }
        if ($sightings_type !== false) {
            $conditions[] = array('Sighting.type' => $sightings_type);
        }
        $sightings = $this->find('all', array(
            'conditions' => $conditions,
            'recursive' => -1,
            'order' => array(sprintf('Sighting.date_sighting %s', $order_desc ? 'DESC' : ''))
        ));
        if (!empty($sightings) && empty(Configure::read('Plugin.Sightings_policy')) && !$user['Role']['perm_site_admin']) {
            $eventOwnerOrgIdList = array();
            foreach ($sightings as $k => $sighting) {
                if (empty($eventOwnerOrgIdList[$sighting['Sighting']['event_id']])) {
                    $temp_event = $this->Event->find('first', array(
                        'recursive' => -1,
                        'conditions' => array('Event.id' => $sighting['Sighting']['event_id']),
                        'fields' => array('Event.id', 'Event.orgc_id')
                    ));
                    $eventOwnerOrgIdList[$temp_event['Event']['id']] = $temp_event['Event']['orgc_id'];
                }
                if (
                    empty($eventOwnerOrgIdList[$sighting['Sighting']['event_id']]) ||
                    ($eventOwnerOrgIdList[$sighting['Sighting']['event_id']] !== $user['org_id'] && $sighting['Sighting']['org_id'] !== $user['org_id'])
                ) {
                    unset($sightings[$k]);
                }
            }
            $sightings = array_values($sightings);
        } else if (!empty($sightings) && Configure::read('Plugin.Sightings_policy') == 1 && !$user['Role']['perm_site_admin']) {
            $eventsWithOwnSightings = array();
            foreach ($sightings as $k => $sighting) {
                if (empty($eventsWithOwnSightings[$sighting['Sighting']['event_id']])) {
                    $eventsWithOwnSightings[$sighting['Sighting']['event_id']] = false;
                    $sighting_temp = $this->find('first', array(
                        'recursive' => -1,
                        'conditions' => array(
                            'Sighting.event_id' => $sighting['Sighting']['event_id'],
                            'Sighting.org_id' => $user['org_id']
                        )
                    ));
                    if (empty($sighting_temp)) {
                        $temp_event = $this->Event->find('first', array(
                            'recursive' => -1,
                            'conditions' => array(
                                'Event.id' => $sighting['Sighting']['event_id'],
                                'Event.orgc_id' => $user['org_id']
                            ),
                            'fields' => array('Event.id', 'Event.orgc_id')
                        ));
                        $eventsWithOwnSightings[$sighting['Sighting']['event_id']] = !empty($temp_event);
                    } else {
                        $eventsWithOwnSightings[$sighting['Sighting']['event_id']] = true;
                    }
                }
                if (!$eventsWithOwnSightings[$sighting['Sighting']['event_id']]) {
                    unset($sightings[$k]);
                }
            }
            $sightings = array_values($sightings);
        }

        foreach ($sightings as $k => $sighting) {
            $sightings[$k]['Organisation'] = $this->getOrganisationById($sighting['Sighting']['org_id']);
        }

        return $sightings;
    }

    public function restSearch($user, $returnFormat, $filters)
    {
        $allowedContext = array('event', 'attribute');
        // validate context
        if (isset($filters['context']) && !in_array($filters['context'], $allowedContext, true)) {
            throw new MethodNotAllowedException(__('Invalid context.'));
        }
        // ensure that an id is provided if context is set
        if (!empty($filters['context']) && !isset($filters['id'])) {
            throw new MethodNotAllowedException(__('An id must be provided if the context is set.'));
        }

        if (!isset($this->validFormats[$returnFormat][1])) {
            throw new NotFoundException('Invalid output format.');
        }
        App::uses($this->validFormats[$returnFormat][1], 'Export');
        $exportTool = new $this->validFormats[$returnFormat][1]();

        // construct filtering conditions
        if (isset($filters['from']) && isset($filters['to'])) {
            $timeCondition = array($filters['from'], $filters['to']);
            unset($filters['from']);
            unset($filters['to']);
        } elseif (isset($filters['last'])) {
            $timeCondition = $filters['last'];
            unset($filters['last']);
        } else {
            $timeCondition = '30d';
        }
        $conditions = $this->Attribute->setTimestampConditions($timeCondition, array(), $scope = 'Sighting.date_sighting');

        if (isset($filters['type'])) {
            $conditions['Sighting.type'] = $filters['type'];
        }

        if (isset($filters['org_id'])) {
            $this->Organisation = ClassRegistry::init('Organisation');
            if (!is_array($filters['org_id'])) {
                $filters['org_id'] = array($filters['org_id']);
            }
            foreach ($filters['org_id'] as $k => $org_id) {
                if (Validation::uuid($org_id)) {
                    $org = $this->Organisation->find('first', array('conditions' => array('Organisation.uuid' => $org_id), 'recursive' => -1, 'fields' => array('Organisation.id')));
                    if (empty($org)) {
                        $filters['org_id'][$k] = -1;
                    } else {
                        $filters['org_id'][$k] = $org['Organisation']['id'];
                    }
                }
            }
            $conditions['Sighting.org_id'] = $filters['org_id'];
        }

        if (isset($filters['source'])) {
            $conditions['Sighting.source'] = $filters['source'];
        }

        if (!empty($filters['id'])) {
            if ($filters['context'] === 'attribute') {
                $conditions['Sighting.attribute_id'] = $filters['id'];
            } elseif ($filters['context'] === 'event') {
                $conditions['Sighting.event_id'] = $filters['id'];
            }
        }

        // fetch sightings matching the query
        $sightings = $this->find('list', array(
            'recursive' => -1,
            'conditions' => $conditions,
            'fields' => array('id'),
        ));
        $sightings = array_values($sightings);

        $filters['requested_attributes'] = array('id', 'attribute_id', 'event_id', 'org_id', 'date_sighting', 'uuid', 'source', 'type');

        // apply ACL and sighting policies
        $allowedSightings = array();
        $additional_attribute_added = false;
        $additional_event_added = false;
        foreach ($sightings as $sid) {
            $sight = $this->getSighting($sid, $user);
            if (!empty($sight)) {
                $sight['Sighting']['value'] = $sight['Sighting']['Attribute']['value'];
                // by default, do not include event and attribute
                if (!isset($filters['includeAttribute']) || !$filters['includeAttribute']) {
                    unset($sight["Sighting"]["Attribute"]);
                } else if (!$additional_attribute_added) {
                    $filters['requested_attributes'] = array_merge($filters['requested_attributes'], array('attribute_uuid', 'attribute_type', 'attribute_category', 'attribute_to_ids', 'attribute_value'));
                    $additional_attribute_added = true;
                }

                if (!isset($filters['includeEvent']) || !$filters['includeEvent']) {
                    unset($sight["Sighting"]["Event"]);
                } else if (!$additional_event_added) {
                    $filters['requested_attributes'] = array_merge($filters['requested_attributes'], array('event_uuid', 'event_orgc_id', 'event_org_id', 'event_info', 'event_Orgc_name'));
                    $additional_event_added = true;
                }
                if (!empty($sight)) {
                    array_push($allowedSightings, $sight);
                }
            }
        }

        $params = array(
            'conditions' => array(), //result already filtered
        );

        if (!isset($this->validFormats[$returnFormat])) {
            // this is where the new code path for the export modules will go
            throw new NotFoundException('Invalid export format.');
        }

        $exportToolParams = array(
            'user' => $user,
            'params' => $params,
            'returnFormat' => $returnFormat,
            'scope' => 'Sighting',
            'filters' => $filters
        );

        $tmpfile = new TmpFileTool();
        $tmpfile->write($exportTool->header($exportToolParams));

        $temp = '';
        $i = 0;
        foreach ($allowedSightings as $sighting) {
            $temp .= $exportTool->handler($sighting, $exportToolParams);
            if ($temp !== '') {
                if ($i != count($allowedSightings) -1) {
                    $temp .= $exportTool->separator($exportToolParams);
                }
            }
            $i++;
        }
        $tmpfile->write($temp);
        $tmpfile->write($exportTool->footer($exportToolParams));
        return $tmpfile->finish();
    }

    /**
     * @param int|string $eventId Event ID or UUID
     * @param array $sightings
     * @param array $user
     * @param null $passAlong
     * @return int|string Number of saved sightings or error message as string
     */
    public function bulkSaveSightings($eventId, $sightings, $user, $passAlong = null)
    {
        $event = $this->Event->fetchSimpleEvent($user, $eventId);
        if (empty($event)) {
            return 'Event not found or not accessible by this user.';
        }
        $saved = 0;
        foreach ($sightings as $s) {
            $saveOnBehalfOf = false;
            if ($user['Role']['perm_sync']) {
                if (isset($s['org_id'])) {
                    if ($s['org_id'] != 0 && !empty($s['Organisation'])) {
                        $saveOnBehalfOf = $this->Event->Orgc->captureOrg($s['Organisation'], $user);
                    } else {
                        $saveOnBehalfOf = 0;
                    }
                }
            }
            $result = $this->saveSightings($s['attribute_uuid'], false, $s['date_sighting'], $user, $s['type'], $s['source'], $s['uuid'], false, $saveOnBehalfOf);
            if (is_numeric($result)) {
                $saved += $result;
            }
        }
        if ($saved > 0) {
            $this->Event->publishRouter($event['Event']['id'], $passAlong, $user, 'sightings');
        }
        return $saved;
    }

    public function pullSightings($user, $server)
    {
        $HttpSocket = $this->setupHttpSocket($server);
        $this->Server = ClassRegistry::init('Server');
        try {
            $eventIds = $this->Server->getEventIdsFromServer($server, false, $HttpSocket, false, 'sightings');
        } catch (Exception $e) {
            $this->logException("Could not fetch event IDs from server {$server['Server']['name']}", $e);
            return 0;
        }
        $saved = 0;
        // now process the $eventIds to pull each of the events sequentially
        // download each event and save sightings
        foreach ($eventIds as $k => $eventId) {
            try {
                $event = $this->Event->downloadEventFromServer($eventId, $server);
            } catch (Exception $e) {
                $this->logException("Failed downloading the event $eventId from {$server['Server']['name']}.", $e);
                continue;
            }
            $sightings = array();
            if (!empty($event) && !empty($event['Event']['Attribute'])) {
                foreach ($event['Event']['Attribute'] as $attribute) {
                    if (!empty($attribute['Sighting'])) {
                        $sightings = array_merge($sightings, $attribute['Sighting']);
                    }
                }
            }
            if (!empty($event) && !empty($sightings)) {
                $result = $this->bulkSaveSightings($event['Event']['uuid'], $sightings, $user, $server['Server']['id']);
                if (is_numeric($result)) {
                    $saved += $result;
                }
            }
        }
        return $saved;
    }

    /**
     * @return int Timestamp
     */
    public function getMaximumRange()
    {
        $rangeInDays = Configure::read('MISP.Sightings_range');
        $rangeInDays = (!empty($rangeInDays) && is_numeric($rangeInDays)) ? $rangeInDays : 365;
        return strtotime("-$rangeInDays days");
    }

    /**
     * Reduce memory usage by not fetching organisation object for every sighting but just once. Then organisation
     * object will be deduplicated in memory.
     *
     * @param int $orgId
     * @return array
     */
    private function getOrganisationById($orgId)
    {
        if (isset($this->orgCache[$orgId])) {
            return $this->orgCache[$orgId];
        }

        if (!isset($this->Organisation)) {
            $this->Organisation = ClassRegistry::init('Organisation');
        }
        $org = $this->Organisation->find('first', [
            'recursive' => -1,
            'conditions' => ['Organisation.id' => $orgId],
            'fields' => ['Organisation.id', 'Organisation.uuid', 'Organisation.name']
        ]);
        if (!empty($org)) {
            $org = $org['Organisation'];
        }
        $this->orgCache[$orgId] = $org;
        return $this->orgCache[$orgId];
    }
}
