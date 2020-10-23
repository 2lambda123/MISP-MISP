<?php
App::uses('AppModel', 'Model');

class Module extends AppModel
{
    public $useTable = false;

    private $__validTypes = array(
        'Enrichment' => array('hover', 'expansion'),
        'Import' => array('import'),
        'Export' => array('export'),
        'Cortex' => array('cortex')
    );

    private $__typeToFamily = array(
        'Import' => 'Import',
        'Export' => 'Export',
        'hover' => 'Enrichment',
        'expansion' => 'Enrichment',
        'Cortex' => 'Cortex'
    );

    public $configTypes = array(
        'IP' => array(
            'validation' => 'validateIPField',
            'field' => 'text',
            'class' => 'input-xxlarge'
        ),
        'String' => array(
            'validation' => 'validateStringField',
            'field' => 'text',
            'class' => 'input-xxlarge'
        ),
        'Integer' => array(
            'validation' => 'validateIntegerField',
            'field' => 'number',
        ),
        'Boolean' => array(
            'validation' => 'validateBooleanField',
            'field' => 'checkbox'
        ),
        'Select' => array(
            'validation' => 'validateSelectField',
            'field' => 'select'
        )
    );

    public function validateIPField($value)
    {
        if (!filter_var($value, FILTER_VALIDATE_IP) === false) {
            return 'Value is not a valid IP.';
        }
        return true;
    }

    public function validateStringField($value)
    {
        if (!empty($value)) {
            return true;
        }
        return 'Field cannot be empty.';
    }

    public function validateIntegerField($value)
    {
        if (is_numeric($value) && is_int(intval($value))) {
            return true;
        }
        return 'Value is not an integer.';
    }

    public function validateBooleanField($value)
    {
        if ($value == true || $value == false) {
            return true;
        }
        return 'Value has to be a boolean.';
    }

    public function validateSelectField($value)
    {
        return true;
    }

    /**
     * @param string $moduleFamily
     * @param bool $throwException
     * @return array[]|string
     * @throws JsonException
     */
    public function getModules($moduleFamily = 'Enrichment', $throwException = false)
    {
        $modules = $this->queryModuleServer('/modules', false, false, $moduleFamily, $throwException);
        if ($modules === false) { // not possible when $throwException is true
            return 'Module service not reachable.';
        }
        return $modules;
    }

    public function getEnabledModules($user, $type = false, $moduleFamily = 'Enrichment')
    {
        $modules = $this->getModules($moduleFamily);
        if (is_array($modules)) {
            foreach ($modules as $k => $module) {
                if (!Configure::read('Plugin.' . $moduleFamily . '_' . $module['name'] . '_enabled') || ($type && !in_array(strtolower($type), $module['meta']['module-type']))) {
                    unset($modules[$k]);
                    continue;
                }
                if (
                    !$user['Role']['perm_site_admin'] &&
                    Configure::read('Plugin.' . $moduleFamily . '_' . $module['name'] . '_restrict') &&
                    Configure::read('Plugin.' . $moduleFamily . '_' . $module['name'] . '_restrict') != $user['org_id']
                ) {
                    unset($modules[$k]);
                }
            }
        } else {
            return 'The modules system reports that it found no suitable modules.';
        }
        if (empty($modules)) {
            return [];
        }
        $output = ['modules' => array_values($modules)];
        foreach ($modules as $temp) {
            if (isset($temp['meta']['module-type']) && in_array('import', $temp['meta']['module-type'])) {
                $output['Import'] = $temp['name'];
            } elseif (isset($temp['meta']['module-type']) && in_array('export', $temp['meta']['module-type'])) {
                $output['Export'] = $temp['name'];
            } else {
                foreach ($temp['mispattributes']['input'] as $input) {
                    if (!isset($temp['meta']['module-type']) || (in_array('expansion', $temp['meta']['module-type']) || in_array('cortex', $temp['meta']['module-type']))) {
                        $output['types'][$input][] = $temp['name'];
                    }
                    if (isset($temp['meta']['module-type']) && in_array('hover', $temp['meta']['module-type'])) {
                        $output['hover_type'][$input][] = $temp['name'];
                    }
                }
            }
        }
        return $output;
    }

    /**
     * @param string $name
     * @param string $type
     * @return array|string
     */
    public function getEnabledModule($name, $type)
    {
        if (!isset($this->__typeToFamily[$type])) {
            throw new InvalidArgumentException("Invalid type '$type'.");
        }
        $moduleFamily = $this->__typeToFamily[$type];
        $modules = $this->getModules($moduleFamily);
        if (!Configure::read('Plugin.' . $moduleFamily . '_' . $name . '_enabled')) {
            return 'The requested module is not enabled.';
        }
        if (is_array($modules)) {
            foreach ($modules['modules'] as $module) {
                if ($module['name'] == $name) {
                    if ($type && in_array(strtolower($type), $module['meta']['module-type'])) {
                        return $module;
                    } else {
                        return 'The requested module is not available for the requested action.';
                    }
                }
            }
        } else {
            return $modules;
        }
        return 'The modules system reports that it found no suitable modules.';
    }

    private function __getModuleServer($moduleFamily = 'Enrichment')
    {
        if (!Configure::read('Plugin.' . $moduleFamily . '_services_enable')) {
            return false;
        }

        $url = Configure::read('Plugin.' . $moduleFamily . '_services_url');
        $port = Configure::read('Plugin.' . $moduleFamily . '_services_port');

        if (empty($url) || empty($port)) {
            // Load default values
            $this->Server = ClassRegistry::init('Server');
            if (empty($url)) {
                $url = $this->Server->serverSettings['Plugin'][$moduleFamily . '_services_url']['value'];
            }
            if (empty($port)) {
                $port = $this->Server->serverSettings['Plugin'][$moduleFamily . '_services_port']['value'];
            }
        }

        return "$url:$port";
    }

    /**
     * @param string $uri
     * @param array|false $post
     * @param bool $hover
     * @param string $moduleFamily
     * @param bool $throwException
     * @return array|false
     * @throws JsonException
     */
    public function queryModuleServer($uri, $post = false, $hover = false, $moduleFamily = 'Enrichment', $throwException = false)
    {
        $url = $this->__getModuleServer($moduleFamily);
        if (!$url) {
            if ($throwException) {
                throw new Exception("Module type $moduleFamily is not enabled.");
            }
            return false;
        }
        App::uses('HttpSocket', 'Network/Http');
        if ($hover) {
            $settings = array(
                'timeout' => Configure::read('Plugin.' . $moduleFamily . '_hover_timeout') ?: 5
            );
        } else {
            $settings = array(
                'timeout' => Configure::read('Plugin.' . $moduleFamily . '_timeout') ?: 10
            );
        }
        $sslSettings = array('ssl_verify_peer', 'ssl_verify_host', 'ssl_allow_self_signed', 'ssl_verify_peer', 'ssl_cafile');
        foreach ($sslSettings as $sslSetting) {
            if (Configure::check('Plugin.' . $moduleFamily . '_' . $sslSetting) && Configure::read('Plugin.' . $moduleFamily . '_' . $sslSetting) !== '') {
                $settings[$sslSetting] = Configure::read('Plugin.' . $moduleFamily . '_' . $sslSetting);
            }
        }
        // let's set a low timeout for the introspection so that we don't block the loading of pages due to a misconfigured modules
        if ($uri == '/modules') {
            $settings['timeout'] = 1;
        }
        $httpSocket = new HttpSocket($settings);
        $request = array(
            'header' => array(
                'Content-Type' => 'application/json',
            )
        );
        if ($moduleFamily == 'Cortex') {
            if (!empty(Configure::read('Plugin.' . $moduleFamily . '_authkey'))) {
                $request['header']['Authorization'] = 'Bearer ' . Configure::read('Plugin.' . $moduleFamily . '_authkey');
            }
        }
        try {
            if ($post) {
                if (!is_array($post)) {
                    throw new InvalidArgumentException("Post data must be array, " . gettype($post) . " given.");
                }
                $post = json_encode($post);
                $response = $httpSocket->post($url . $uri, $post, $request);
            } else {
                if ($moduleFamily == 'Cortex') {
                    unset($request['header']['Content-Type']);
                }
                $response = $httpSocket->get($url . $uri, false, $request);
            }
            if (!$response->isOk()) {
                if ($httpSocket->lastError()) {
                    throw new Exception("Failed to get response from $moduleFamily module " . $httpSocket->lastError());
                }
                throw new Exception("Failed to get response from $moduleFamily module: HTTP $response->reasonPhrase", (int)$response->code);
            }
            return $this->jsonDecode($response->body);
        } catch (Exception $e) {
            if ($throwException) {
                throw $e;
            }
            $this->logException('Failed to query module ' . $moduleFamily, $e);
            return false;
        }
    }

    /**
     * @param string $moduleFamily
     * @return array
     */
    public function getModuleSettings($moduleFamily = 'Enrichment')
    {
        $modules = $this->getModules($moduleFamily);
        $result = array();
        if (!empty($modules)) {
            foreach ($modules as $module) {
                if (array_intersect($this->__validTypes[$moduleFamily], $module['meta']['module-type'])) {
                    $moduleSettings = [
                        array('name' => 'enabled', 'type' => 'boolean'),
                        array('name' => 'restrict', 'type' => 'orgs')
                    ];
                    if (isset($module['meta']['config'])) {
                        foreach ($module['meta']['config'] as $key => $value) {
                            if (is_string($key)) {
                                $moduleSettings[] = array('name' => $key, 'type' => 'string', 'description' => $value);
                            } else {
                                $moduleSettings[] = array('name' => $value, 'type' => 'string');
                            }
                        }
                    }
                    $result[$module['name']] = $moduleSettings;
                }
            }
        }
        return $result;
    }
}
