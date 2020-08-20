<?php
class GpgTool
{
    /**
     * @return CryptGpgExtended
     * @throws Exception
     */
    public function initializeGpg()
    {
        if (!class_exists('Crypt_GPG')) {
            // 'Crypt_GPG' class cannot be autoloaded, try to require from include_path.
            if (!stream_resolve_include_path('Crypt/GPG.php')) {
                throw new Exception("Crypt_GPG is not installed.");
            }
            require_once 'Crypt/GPG.php';
        }

        require_once __DIR__ . '/CryptGpgExtended.php';

        $homedir = Configure::read('GnuPG.homedir');
        if ($homedir === null) {
            throw new Exception("Configuration option 'GnuPG.homedir' is not set, Crypt_GPG cannot be initialized.");
        }

        $options = array(
            'homedir' => $homedir,
            'gpgconf' => Configure::read('GnuPG.gpgconf'),
            'binary' => Configure::read('GnuPG.binary') ?: '/usr/bin/gpg',
        );

        return new CryptGpgExtended($options);
    }

    /**
     * @param string $search
     * @return array
     * @throws Exception
     */
    public function searchGpgKey($search)
    {
        $uri = 'https://pgp.circl.lu/pks/lookup?search=' . urlencode($search) . '&op=index&fingerprint=on&options=mr';
        $response = $this->keyServerLookup($uri);
        if ($response->code == 404) {
            return array(); // no keys found
        } else if ($response->code != 200) {
            throw new Exception("Fetching the '$uri' failed with HTTP error {$response->code}: {$response->reasonPhrase}");
        }
        return $this->extractKeySearch($response->body);
    }

    /**
     * @param string $fingerprint
     * @return string|null
     * @throws Exception
     */
    public function fetchGpgKey($fingerprint)
    {
        $uri = 'https://pgp.circl.lu/pks/lookup?search=0x' . urlencode($fingerprint) . '&op=get&options=mr';
        $response = $this->keyServerLookup($uri);
        if ($response->code == 404) {
            return null; // key with given fingerprint not found
        } else if ($response->code != 200) {
            throw new Exception("Fetching the '$uri' failed with HTTP error {$response->code}: {$response->reasonPhrase}");
        }

        $key = $response->body;

        return $key;
    }

    /**
     * @param string $body
     * @return array
     */
    private function extractKeySearch($body)
    {
        $final = array();
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $parts = explode(":", $line);

            if ($parts[0] === 'pub') {
                if (!empty($temp)) {
                    $final[] = $temp;
                    $temp = array();
                }

                if (strpos($parts[6], 'r') !== false || strpos($parts[6], 'd') !== false || strpos($parts[6], 'e') !== false) {
                    continue; // skip if key is expired, revoked or disabled
                }

                $temp = array(
                    'fingerprint' => $parts[1],
                    'key_id' => substr($parts[1], -8),
                    'date' => date('Y-m-d', $parts[4]),
                );

            } else if ($parts[0] === 'uid' && !empty($temp)) {
                $temp['address'] = urldecode($parts[1]);
            }
        }

        if (!empty($temp)) {
            $final[] = $temp;
        }

        return $final;
    }

    /**
     * @param string $uri
     * @return HttpSocketResponse
     * @throws Exception
     */
    private function keyServerLookup($uri)
    {
        App::uses('SyncTool', 'Tools');
        $syncTool = new SyncTool();
        $HttpSocket = $syncTool->setupHttpSocket();
        $response = $HttpSocket->get($uri);
        if ($response === false) {
            throw new Exception("Could not fetch '$uri'.");
        }
        return $response;
    }
}
