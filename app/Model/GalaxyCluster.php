<?php
App::uses('AppModel', 'Model');
class GalaxyCluster extends AppModel
{
    public $useTable = 'galaxy_clusters';

    public $recursive = -1;

    public $actsAs = array(
            'Containable',
    );

    public $validate = array(
    );

    public $belongsTo = array(
        'Galaxy' => array(
            'className' => 'Galaxy',
            'foreignKey' => 'galaxy_id',
        ),
        'Tag' => array(
            'foreignKey' => false,
            'conditions' => array('GalaxyCluster.tag_name = Tag.name')
        )
    );

    private $__clusterCache = array();

    public $hasMany = array(
        'GalaxyElement' => array('dependent' => true),
    //  'GalaxyReference'
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        if (!isset($this->data['GalaxyCluster']['description'])) {
            $this->data['GalaxyCluster']['description'] = '';
        }
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (isset($results[$k]['GalaxyCluster']['authors'])) {
                $results[$k]['GalaxyCluster']['authors'] = json_decode($results[$k]['GalaxyCluster']['authors'], true);
            }
        }
        return $results;
    }

    public function beforeDelete($cascade = true)
    {
        $this->GalaxyElement->deleteAll(array('GalaxyElement.galaxy_cluster_id' => $this->id));
    }


    // receive a full galaxy and add all new clusters, update existing ones contained in the new galaxy, cull old clusters that are removed from the galaxy
    public function update($id, $galaxy)
    {
        $existingClusters = $this->find('all', array(
            'conditions' => array('GalaxyCluster.galaxy_id' => $id),
            'recursive' => -1,
        ));
        foreach ($galaxy['values'] as $cluster) {
            $oldCluster = false;
            if (!empty($existingClusters)) {
                foreach ($existingClusters as $k => $existingCluster) {
                    if ($existingCluster['GalaxyCluster']['value'] == $cluster['value']) {
                        $oldCluster = true;
                        if ($cluster['description'] != $existingCluster['GalaxyCluster']['description']) {
                            $existingCluster['GalaxyCluster']['description'] = $cluster['description'];
                            $this->GalaxyElement->deleteAll('galaxy_cluster_id' == $existingCluster['GalaxyCluster']['id']);
                            $this->save($existingCluster);
                            $template = array('galaxy_cluster_id' => $this->id);
                            $toSave = array();
                            foreach ($cluster as $key => $value) {
                                if (in_array($key, array('value', 'description'))) {
                                    continue;
                                }
                                $tosave[] = array_merge($template, array('key' => $key, 'value' => $value));
                            }
                            $this->GalaxyElement->saveMany($toSave);
                        }
                        unset($existingClusters[$k]);
                    }
                }
            }
            if (!$oldCluster) {
                $newCluster = array_intersect_key($cluster, array_flip(array('value', 'description')));
                $newCluster['galaxy_id'] = $id;
                $newCluster['type'] = $galaxy['type'];
                $newCluster['collection_uuid'] = $newCluster['uuid'];
                $toSave[] = $newCluster;
            }
            $final = array();
            if (!empty($existingCluster)) {
                $fieldsToUpdate = array('description', '');
                $final = $existingCluster;
            }
        }
        $this->saveMany($toSave);
        // Let's retrieve the full list of clusters we have for the given galaxy and pass it to the element system
        $existingClusters = $this->find('all', array(
                'conditions' => array('GalaxyCluster.galaxy_id'),
                'contain' => array('GalaxyElement'/*, 'GalaxyReference'*/)
        ));
        $this->GalaxyElement->update($id, $existingClusters, $galaxy['values']);
    }

    /* Return a list of all tags associated with the cluster specific cluster within the galaxy (or all clusters if $clusterValue is false)
     * The counts are restricted to the event IDs that the user is allowed to see.
    */
    public function getTags($galaxyType, $clusterValue = false, $user)
    {
        $this->Event = ClassRegistry::init('Event');
        $event_ids = $this->Event->fetchEventIds($user, false, false, false, true);
        $tags = $this->Event->EventTag->Tag->find('list', array(
                'conditions' => array('name LIKE' => 'misp-galaxy:' . $galaxyType . '="' . ($clusterValue ? $clusterValue : '%') .'"'),
                'fields' => array('name', 'id'),
        ));
        $this->Event->EventTag->virtualFields['tag_count'] = 'COUNT(id)';
        $tagCounts = $this->Event->EventTag->find('list', array(
                'conditions' => array('EventTag.tag_id' => array_values($tags), 'EventTag.event_id' => $event_ids),
                'fields' => array('EventTag.tag_id', 'EventTag.tag_count'),
                'group' => array('EventTag.tag_id')
        ));
        foreach ($tags as $k => $v) {
            if (isset($tagCounts[$v])) {
                $tags[$k] = array('count' => $tagCounts[$v], 'tag_id' => $v);
            } else {
                unset($tags[$k]);
            }
        }
        return $tags;
    }

    /* Fetch a cluster along with all elements and the galaxy it belongs to
     *   - In the future, once we move to galaxy 2.0, pass a user along for access control
     *   - maybe in the future remove the galaxy itself once we have logos with each galaxy
    */
    public function getCluster($name)
    {
        if (isset($this->__clusterCache[$name])) {
            return $this->__clusterCache[$name];
        }

        if (is_numeric($name)) {
            $conditions = array('GalaxyCluster.id' => $name);
        } else {
            $conditions = array('LOWER(GalaxyCluster.tag_name)' => strtolower($name));
        }

        $cluster = $this->find('first', array(
            'conditions' => $conditions,
            'contain' => array('Galaxy', 'GalaxyElement')
        ));

        if (!empty($cluster)) {
            $cluster = $this->postprocess($cluster);
        }

        $this->__clusterCache[$name] = $cluster;
        return $cluster;
    }

    /**
     * @param array $events
     * @param bool $replace
     * @return array
     */
    public function attachClustersToEventIndex(array $events, $replace = false)
    {
        $clusterTagNames = array();
        foreach ($events as $event) {
            foreach ($event['EventTag'] as $k2 => $eventTag) {
                if (substr($eventTag['Tag']['name'], 0, strlen('misp-galaxy:')) === 'misp-galaxy:') {
                    $clusterTagNames[] = strtolower($eventTag['Tag']['name']);
                }
            }
        }

        if (empty($clusterTagNames)) {
            return $events;
        }

        $clusters = $this->find('all', array(
            'conditions' => array('LOWER(GalaxyCluster.tag_name)' => $clusterTagNames),
            'contain' => array('Galaxy', 'GalaxyElement'),
        ));

        $clustersByTagName = array();
        foreach ($clusters as $cluster) {
            $clustersByTagName[strtolower($cluster['GalaxyCluster']['tag_name'])] = $cluster;
        }

        foreach ($events as $k => $event) {
            foreach ($event['EventTag'] as $k2 => $eventTag) {
                $tagName = strtolower($eventTag['Tag']['name']);
                if (isset($clustersByTagName[$tagName])) {
                    $cluster = $this->postprocess($clustersByTagName[$tagName], $eventTag['Tag']['id']);
                    $cluster['GalaxyCluster']['tag_id'] = $eventTag['Tag']['id'];
                    $cluster['GalaxyCluster']['local'] = $eventTag['local'];
                    $events[$k]['GalaxyCluster'][] = $cluster['GalaxyCluster'];
                    if ($replace) {
                        unset($events[$k]['EventTag'][$k2]);
                    }
                }
            }
        }
        return $events;
    }

    /**
     * @param array $cluster
     * @param int|null $tagId
     * @return array
     */
    private function postprocess(array $cluster, $tagId = null)
    {
        if (isset($cluster['Galaxy'])) {
            $cluster['GalaxyCluster']['Galaxy'] = $cluster['Galaxy'];
            unset($cluster['Galaxy']);
        }

        $elements = array();
        foreach ($cluster['GalaxyElement'] as $element) {
            if (!isset($elements[$element['key']])) {
                $elements[$element['key']] = array($element['value']);
            } else {
                $elements[$element['key']][] = $element['value'];
            }
        }
        unset($cluster['GalaxyElement']);
        $cluster['GalaxyCluster']['meta'] = $elements;

        if ($tagId) {
            $cluster['GalaxyCluster']['tag_id'] = $tagId;
        } else {
            $this->Tag = ClassRegistry::init('Tag');
            $tag_id = $this->Tag->find(
                'first',
                array(
                    'conditions' => array(
                        'LOWER(Tag.name)' => strtolower($cluster['GalaxyCluster']['tag_name'])
                    ),
                    'recursive' => -1,
                    'fields' => array('Tag.id')
                )
            );
            if (!empty($tag_id)) {
                $cluster['GalaxyCluster']['tag_id'] = $tag_id['Tag']['id'];
            }
        }

        return $cluster;
    }

    public function getClusterTagsFromMeta($galaxyElements)
    {
        // AND operator between cluster metas
        $tmpResults = array();
        foreach ($galaxyElements as $galaxyElementKey => $galaxyElementValue) {
            $tmpResults[] = array_values($this->GalaxyElement->find('list', array(
                'conditions' => array(
                    'key' => $galaxyElementKey,
                    'value' => $galaxyElementValue,
                ),
                'fields' => array('galaxy_cluster_id'),
                'recursive' => -1
            )));
        }
        $clusterTags = array();
        if (!empty($tmpResults)) {
            // Get all Clusters matching all conditions
            $matchingClusters = $tmpResults[0];
            array_shift($tmpResults);
            foreach ($tmpResults as $tmpResult) {
                $matchingClusters = array_intersect($matchingClusters, $tmpResult);
            }
    
            $clusterTags = $this->find('list', array(
                'conditions' => array('id' => $matchingClusters),
                'fields' => array('GalaxyCluster.tag_name'),
                'recursive' => -1
            ));
        }
        return array_values($clusterTags);
    }
}
