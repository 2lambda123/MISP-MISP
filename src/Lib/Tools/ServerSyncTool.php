<?php

namespace App\Lib\Tools;

use App\Http\Exception\HttpSocketHttpException;
use Cake\Core\Configure;
use Cake\Http\Client\Response;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use InvalidArgumentException;

class ServerSyncTool
{
    use LocatorAwareTrait;

    const FEATURE_BR = 'br',
        FEATURE_GZIP = 'gzip',
        FEATURE_ORG_RULE = 'org_rule',
        FEATURE_FILTER_SIGHTINGS = 'filter_sightings',
        FEATURE_PROPOSALS = 'proposals',
        FEATURE_PROTECTED_EVENT = 'protected_event',
        FEATURE_POST_TEST = 'post_test',
        FEATURE_EDIT_OF_GALAXY_CLUSTER = 'edit_of_galaxy_cluster',
        PERM_SYNC = 'perm_sync',
        PERM_GALAXY_EDITOR = 'perm_galaxy_editor',
        FEATURE_SIGHTING_REST_SEARCH = 'sighting_rest';

    /** @var array */
    private $server;

    /** @var array */
    private $request;

    /** @var HttpTool */
    private $socket;

    /** @var CryptographicKey */
    private $cryptographicKey;

    /** @var array|null */
    private $info;

    /**
     * @param array $server
     * @param array $request
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function __construct(array $server, array $request)
    {
        if (!isset($server)) {
            throw new InvalidArgumentException("Invalid server provided.");
        }

        $this->server = $server;
        $this->request = $request;

        $HttpTool = new HttpTool();
        $HttpTool->configFromServer($server);

        $this->socket = $HttpTool;
    }

    /**
     * Check if event exists on remote server by event UUID.
     * @param array $event
     * @return bool
     * @throws Exception
     */
    public function eventExists(array $event)
    {
        $url = $this->server['url'] . '/events/view/' . $event['Event']['uuid'];
        $start = microtime(true);
        $exists = $this->socket->head($url, [], $this->request);
        $this->log($start, 'HEAD', $url, $exists);
        if ($exists->getStatusCode() == '404') {
            return false;
        }
        if ($exists->getStatusCode() == '200') {
            return true;
        }
        throw new HttpSocketHttpException($exists, $url);
    }

    /**
     * @param array $params
     * @param string|null $etag
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function eventIndex($params = [], $etag = null)
    {
        return $this->post('/events/index', $params, null, $etag);
    }

    /**
     * @param int|string $eventId Event ID or UUID
     * @param array $params
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    public function fetchEvent($eventId, array $params = [])
    {
        $url = "/events/view/$eventId";
        $url .= $this->createParams($params);
        return $this->get($url);
    }

    /**
     * @param array $events
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function filterEventIdsForPush(array $events)
    {
        return $this->post('/events/filterEventIdsForPush', $events);
    }

    /**
     * @param array $event
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function pushEvent(array $event)
    {
        try {
            // Check if event exists on remote server to use proper endpoint
            $exists = $this->eventExists($event);
        } catch (Exception $e) {
            // In case of failure consider that event doesn't exists
            $exists = false;
        }

        try {
            return $exists ? $this->updateEvent($event) : $this->createEvent($event);
        } catch (HttpSocketHttpException $e) {
            if ($e->getCode() === 404) {
                // Maybe the check if event exists was not correct, try to create a new event
                if ($exists) {
                    return $this->createEvent($event);

                    // There is bug in MISP API, that returns response code 404 with Location if event already exists
                } else if ($e->getResponse()->getHeader('Location')) {
                    $urlPath = $e->getResponse()->getHeader('Location')[0];
                    $pieces = explode('/', $urlPath);
                    $lastPart = end($pieces);
                    return $this->updateEvent($event, $lastPart);
                }
            }
            throw $e;
        }
    }

    /**
     * @param array $event
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function createEvent(array $event)
    {
        $logMessage = "Pushing Event #{$event['Event']['id']} to Server #{$this->serverId()}";
        return $this->post("/events/add/metadata:1", $event, $logMessage);
    }

    /**
     * @param array $event
     * @param int|string|null Event ID or UUID that should be updated. If not provieded, UUID from $event will be used
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function updateEvent(array $event, $eventId = null)
    {
        if ($eventId === null) {
            $eventId = $event['uuid'];
        }
        $logMessage = "Pushing Event #{$event['id']} to Server #{$this->serverId()}";
        return $this->post("/events/edit/$eventId/metadata:1", $event, $logMessage);
    }

    /**
     * @param array $rules
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function attributeSearch(array $rules)
    {
        return $this->post('/attributes/restSearch.json', $rules);
    }

    /**
     * @param array $rules
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function galaxyClusterSearch(array $rules)
    {
        return $this->post('/galaxy_clusters/restSearch', $rules);
    }

    /**
     * @param int|string $galaxyClusterId Galaxy Cluster ID or UUID
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    public function fetchGalaxyCluster($galaxyClusterId)
    {
        return $this->get('/galaxy_clusters/view/' . $galaxyClusterId);
    }

    /**
     * @param array $cluster
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function pushGalaxyCluster(array $cluster)
    {
        $logMessage = "Pushing Galaxy Cluster #{$cluster['GalaxyCluster']['id']} to Server #{$this->serverId()}";
        return $this->post('/galaxies/pushCluster', [$cluster], $logMessage);
    }

    /**
     * @param array $params
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    public function fetchProposals(array $params = [])
    {
        $url = '/shadow_attributes/index';
        $url .= $this->createParams($params);
        $url .= '.json';
        return $this->get($url);
    }

    /**
     * @param array $eventUuids
     * @return array
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     * @throws JsonException
     */
    public function fetchSightingsForEvents(array $eventUuids)
    {
        return $this->post(
            '/sightings/restSearch/event',
            [
                'returnFormat' => 'json',
                'last' => 0, // fetch all
                'includeUuid' => true,
                'uuid' => $eventUuids,
            ]
        )->getJson()['response'];
    }

    /**
     * @param array $event
     * @param array $sightingUuids
     * @return array Sighting UUIDs that exists on remote side
     * @throws HttpSocketJsonException
     * @throws HttpSocketHttpException
     */
    public function filterSightingUuidsForPush(array $event, array $sightingUuids)
    {
        if (!$this->isSupported(self::FEATURE_FILTER_SIGHTINGS)) {
            return [];
        }

        $response = $this->post('/sightings/filterSightingUuidsForPush/' . $event['uuid'], $sightingUuids);
        return $response->getJson();
    }

    /**
     * @param array $sightings
     * @param string $eventUuid
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function uploadSightings(array $sightings, $eventUuid)
    {
        foreach ($sightings as &$sighting) {
            if (!isset($sighting['org_id'])) {
                $sighting['org_id'] = '0';
            }
        }

        $logMessage = "Pushing Sightings for Event #{$eventUuid} to Server #{$this->serverId()}";
        $this->post('/sightings/bulkSaveSightings/' . $eventUuid, $sightings, $logMessage);
    }

    /**
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    public function getAvailableSyncFilteringRules()
    {
        return $this->get('/servers/getAvailableSyncFilteringRules');
    }

    /**
     * @return array
     * @throws HttpSocketJsonException
     * @throws HttpSocketHttpException
     * @throws Exception
     */
    public function info()
    {
        if ($this->info) {
            return $this->info;
        }

        $response = $this->get('/servers/getVersion');
        $info = $response->getJson();
        if (!isset($info['version'])) {
            throw new Exception("Invalid response when fetching server version: `version` field missing.");
        }
        $this->info = $info;
        return $info;
    }

    /**
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    public function userInfo()
    {
        return $this->get('/users/view/me.json');
    }

    /**
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     */
    public function resetAuthKey()
    {
        return $this->post('/users/resetauthkey/me', []);
    }

    /**
     * @param string $testString
     * @return HttpSocketResponseExtended
     * @throws Exception
     */
    public function postTest($testString)
    {
        return $this->post('/servers/postTest', ['testString' => $testString]);
    }

    /**
     * @return array
     */
    public function server()
    {
        return $this->server;
    }

    /**
     * @return int
     */
    public function serverId()
    {
        return $this->server['id'];
    }

    /**
     * @return string
     */
    public function serverName()
    {
        return $this->server['name'];
    }

    /**
     * @return array
     */
    public function pullRules()
    {
        return $this->server['pull_rules'];
    }

    /**
     * @return array
     */
    public function pushRules()
    {
        return $this->server['push_rules'];
    }

    /**
     * @param string $flag
     * @return bool
     * @throws HttpSocketJsonException
     * @throws HttpSocketHttpException
     * @throws InvalidArgumentException
     */
    public function isSupported($flag)
    {
        $info = $this->info();
        switch ($flag) {
            case self::FEATURE_BR:
                return isset($info['request_encoding']) && in_array('br', $info['request_encoding'], true);
            case self::FEATURE_GZIP:
                return isset($info['request_encoding']) && in_array('gzip', $info['request_encoding'], true);
            case self::FEATURE_FILTER_SIGHTINGS:
                return isset($info['filter_sightings']) && $info['filter_sightings'];
            case self::FEATURE_ORG_RULE:
                $version = explode('.', $info['version']);
                return $version[0] == 2 && $version[1] == 4 && $version[2] > 123;
            case self::FEATURE_PROPOSALS:
                $version = explode('.', $info['version']);
                return $version[0] == 2 && $version[1] == 4 && $version[2] >= 111;
            case self::FEATURE_POST_TEST:
                $version = explode('.', $info['version']);
                return $version[0] == 2 && $version[1] == 4 && $version[2] > 68;
            case self::FEATURE_PROTECTED_EVENT:
                $version = explode('.', $info['version']);
                return $version[0] == 2 && $version[1] == 4 && $version[2] > 155;
            case self::FEATURE_EDIT_OF_GALAXY_CLUSTER:
                return isset($info['perm_galaxy_editor']);
            case self::PERM_SYNC:
                return isset($info['perm_sync']) && $info['perm_sync'];
            case self::PERM_GALAXY_EDITOR:
                return isset($info['perm_galaxy_editor']) && $info['perm_galaxy_editor'];
            case self::FEATURE_SIGHTING_REST_SEARCH:
                $version = explode('.', $info['version']);
                return $version[0] == 2 && $version[1] == 4 && $version[2] > 164;
            default:
                throw new InvalidArgumentException("Invalid flag `$flag` provided");
        }
    }

    /**
     * @return array|null
     */
    public function connectionMetaData()
    {
        return $this->socket->getMetaData();
    }

    /**
     * @params string $url Relative URL
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     */
    private function get($url)
    {
        $url = $this->server['url'] . $url;
        $start = microtime(true);
        $response = $this->socket->get($url, [], $this->request);
        $this->log($start, 'GET', $url, $response);
        if (!$response->isOk()) {
            throw new HttpSocketHttpException($response, $url);
        }
        return $response;
    }

    /**
     * @param string $url Relative URL
     * @param mixed $data
     * @param string|null $logMessage
     * @param string|null $etag
     * @return HttpSocketResponseExtended
     * @throws HttpSocketHttpException
     * @throws HttpSocketJsonException
     * @throws JsonException
     */
    private function post($url, $data, $logMessage = null, $etag = null)
    {
        $protectedMode = !empty($data['protected']);
        $data = JsonTool::encode($data);

        if ($logMessage && !empty(Configure::read('Security.sync_audit'))) {
            $pushLogEntry = sprintf(
                "==============================================================\n\n[%s] %s:\n\n%s\n\n",
                date("Y-m-d H:i:s"),
                $logMessage,
                $data
            );
            file_put_contents(APP . 'files/scripts/tmp/debug_server_' . $this->serverId() . '.log', $pushLogEntry, FILE_APPEND | LOCK_EX);
        }

        $request = $this->request;

        if ($protectedMode) {
            $request['header']['x-pgp-signature'] = $this->signEvent($data);
        }

        if ($etag) {
            // Remove compression marks that adds Apache for compressed content
            $etagWithoutQuotes = trim($etag, '"');
            $dashPos = strrpos($etagWithoutQuotes, '-');
            if ($dashPos && in_array(substr($etagWithoutQuotes, $dashPos + 1), ['br', 'gzip'], true)) {
                $etag = '"' . substr($etagWithoutQuotes, 0, $dashPos) . '"';
            }
            $request['header']['If-None-Match'] = $etag;
        }

        if (strlen($data) > 1024) { // do not compress small body
            if ($this->isSupported(self::FEATURE_BR) && function_exists('brotli_compress')) {
                $request['header']['Content-Encoding'] = 'br';
                $data = brotli_compress($data, 1, BROTLI_TEXT);
            } else if ($this->isSupported(self::FEATURE_GZIP) && function_exists('gzencode')) {
                $request['header']['Content-Encoding'] = 'gzip';
                $data = gzencode($data, 1);
            }
        }
        $url = $this->server['url'] . $url;
        $start = microtime(true);
        $response = $this->socket->post($url, $data, $request);
        $this->log($start, 'POST', $url, $response);
        if ($etag && $response->getStatusCode() === 304) {
            return $response; // if etag was provided and response code is 304, it is valid response
        }
        if (!$response->isOk()) {
            throw new HttpSocketHttpException($response, $url);
        }
        return $response;
    }

    /**
     * @param string $data Data to sign
     * @return string base64 encoded signature
     * @throws Exception
     */
    private function signEvent($data)
    {
        if (!$this->isSupported(self::FEATURE_PROTECTED_EVENT)) {
            throw new Exception(__('Remote instance is not protected event aware yet (< 2.4.156), aborting.'));
        }

        if (!$this->cryptographicKey) {
            $this->cryptographicKey = $this->fetchTable('CryptographicKeys');
        }
        $signature = $this->cryptographicKey->signWithInstanceKey($data);
        if (empty($signature)) {
            throw new Exception(__("Invalid signing key. This should never happen."));
        }
        return base64_encode($signature);
    }

    /**
     * @param array $params
     * @return string
     */
    private function createParams(array $params)
    {
        $url = '';
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $url .= "/{$key}[]:$v";
                }
            } else {
                $url .= "/$key:$value";
            }
        }
        return $url;
    }

    /**
     * @param float $start Microtime when request was send
     * @param string $method HTTP method
     * @param string $url
     * @param Response $response
     */
    private function log($start, $method, $url, Response $response)
    {
        $duration = round(microtime(true) - $start, 3);
        $responseSize = strlen($response->getBody());
        $ce = '';
        if ($response->getHeader('Content-Encoding')) {
            $ce = $response->getHeader('Content-Encoding')[0];
        }
        $logEntry = '[' . date('Y-m-d H:i:s', intval($start)) . "] \"$method $url\" {$response->getStatusCode()} $responseSize $duration $ce\n";
        file_put_contents(APP . '../logs/server-sync.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}
