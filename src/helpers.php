<?php

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    throw new \Exception('Config file does not exist at ' . $configFile);
}

$config = require_once $configFile;

$database = (new MongoDB\Client)->selectDatabase($config['databaseName']);
$beaconsCollection = $database->beacons;

/**
 * Returns an authorised API client.
 *
 * @return Google_Client
 */
function getGoogleClient(): Google_Client {
    global $config;

    $client = new Google_Client();
    $client->setApplicationName('Beacon manager');
    $client->setScopes(Google_Service_Proximitybeacon::USERLOCATION_BEACON_REGISTRY);
    $client->setAuthConfig([
        'client_id' => $config['googleClientId'],
        'client_secret' => $config['googleClientSecret'],
        'redirect_uris' => ['http://localhost:10000'],
    ]);
    $client->setAccessType('offline');

    // Load previously authorised credentials from a file.
    $credentialsPath = expandHomeDirectory('~/.google-beacon-oauth.json');
    if (file_exists($credentialsPath)) {
        $accessToken = json_decode(file_get_contents($credentialsPath), true);
    } else {
        // Request authorisation from the user.
        $authUrl = $client->createAuthUrl();
        echo "Open the following link in your browser:\n$authUrl\n";
        echo 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));

        // Exchange authorisation code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        // Store the credentials to disk so the credentials aren't prompted for again.
        if (!file_exists(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0700, true);
        }
        file_put_contents($credentialsPath, json_encode($accessToken));
        echo "Credentials saved to $credentialsPath\n";
    }
    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    }

    return $client;
}

/**
 * Expands the home directory alias '~' to the full path.
 *
 * @param string $path The path to expand.
 * @return string The expanded path.
 */
function expandHomeDirectory(string $path): string {
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory)) {
        $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }
    return str_replace('~', realpath($homeDirectory), $path);
}

function doCityMunchApiRequest(string $method, string $path, array $options = []): GuzzleHttp\Psr7\Response {
    if (strpos($path, '/') !== 0) {
        throw new \Exception('path must begin with /');
    }

    global $config;

    $guzzle = new GuzzleHttp\Client();

    $defaultOptions = [
        'headers' => [
            'Authorization' => 'Partner ' . $config['citymunchApiKey'],
            'User-Agent' => 'CM beacon manager',
            'Accept' => 'application/vnd.citymunch.v12+json',
        ],
        'http_errors' => false,
    ];

    $url = $config['apiBase'] . $path;

    echo 'Making API request: ' . $method . ' ' . $url . "\n";

    return $guzzle->request(
        $method,
        $url,
        array_merge($defaultOptions, $options)
    );
}

function queueSlackMessage(string $message): void {
    global $slackMessageQueue;
    $slackMessageQueue[] = $message;

    echo "Queued message for Slack: $message\n";
}

function postSlackMessageQueue(): void {
    global $config, $slackMessageQueue;

    if (count($slackMessageQueue) === 0) {
        echo "Nothing to post to Slack\n";
        return;
    }

    $settings = [
        'username' => 'Beacon manager',
        'link_names' => false,
        'unfurl_links' => false,
        'unfurl_media' => false,
        'allow_markdown' => false,
    ];

    $slackClient = new Maknz\Slack\Client($config['slackHookUrl'], $settings);

    $message = implode("\n", $slackMessageQueue);

    $slackClient->to('#' . $config['slackChannel'])->send($message);

    echo "Posted to Slack:\n";
    echo $message . "\n";
}
