#!/usr/bin/php
<?php

require_once __DIR__ . '/helpers.php';

use MongoDB\Model\BSONDocument;

$googleService = new Google_Service_Proximitybeacon(getGoogleClient());
$beacons = $beaconsCollection->find([])->toArray();
$slackMessageQueue = [];

$lookAheadUntilDate = new DateTime();
$lookAheadUntilDate->add(new DateInterval('P2D'));

foreach ($beacons as $beacon) {
    try {
        updateBeacon($beacon);
    } catch (Exception $e) {
        queueSlackMessage($e->getMessage());
    }
}
postSlackMessageQueue();

function updateBeacon(BSONDocument $beacon): void {
    global $googleService;

    echo 'Updating beacon ' . $beacon['friendlyName'] . "\n";

    $googleObject = $googleService->beacons->get($beacon['name']);

    if ($googleObject['modelData']['advertisedId']['type'] !== 'EDDYSTONE') {
        throw new Exception('Beacon "' . $beacon['friendlyName'] . '" advertisement is not Eddystone');
    }

    if ($beacon['status'] === 'ACTIVE') {
        if ($googleObject['status'] !== 'ACTIVE') {
            $googleService->beacons->activate($beacon['name']);
            queueSlackMessage('Activated beacon "' . $beacon['friendlyName'] . '"');
        }

        createAttachments($beacon);
    } else if ($beacon['status'] === 'INACTIVE') {
        if ($googleObject['status'] !== 'INACTIVE') {
            $googleService->beacons->deactivate($beacon['name']);
            queueSlackMessage('De-activated beacon "' . $beacon['friendlyName'] . '"');
            deleteAllAttachments($beacon);
        }
    } else {
        throw new Exception('Unexpected status "' . $googleObject['status'] . '" for beacon "' . $beacon['friendlyName'] . '"');
    }
}

function deleteAllAttachments(BSONDocument $beacon): void {
    global $googleService;
    $googleService->beacons_attachments->batchDelete($beacon['name']);
    queueSlackMessage('Deleted all notifications for beacon "' . $beacon['name'] . '"');
}

function toBase64(array $data): string {
    return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
}

function fromBase64(string $data): array {
    return json_decode(base64_decode($data), true);
}

function createAttachments(BSONDocument $beacon): void {
    global $lookAheadUntilDate, $config, $googleService;

    $response = doCityMunchApiRequest(
        'GET',
        '/offers/search/active-events-by-restaurant-ids?ids=' . $beacon['restaurantId']
            . '&startDate=' . date('Y-m-d')
            . '&endDate=' . $lookAheadUntilDate->format('Y-m-d')
            . '&includeEnded=false'
    );

    if ($response->getStatusCode() !== 200) {
        throw new Exception('Couldn\'t get offers for restaurant ' . $beacon['restaurantId']);
    }

    $body = json_decode($response->getBody(), true);
    $beaconAttachmentsToCreateOrKeep = [];

    foreach ($body['events'] as $row) {
        $event = $row['event'];
        $restaurant = $row['restaurant'];
        $offer = $row['offer'];

        if (isset($beacon['limitedToOffers']) && is_iterable($beacon['limitedToOffers'])) {
            $isAllowed = false;
            foreach ($beacon['limitedToOffers'] as $limitedOffer) {
                if ((string) $limitedOffer === $offer['id']) {
                    $isAllowed = true;
                }
            }
            if (!$isAllowed) {
                echo 'Offer ' . $offer['id'] . ' is not allowed' . "\n";
                continue;
            }
        }

        echo 'Looking at event for ' . $restaurant['name'] . ': '
            . $event['discount'] . '% off'
            . ' on ' . $event['date']
            . ' at ' . $event['startTime']
            . '-' . $event['endTime']
            . ' - offer is ' . $offer['id']
            . "\n";

        if ($event['isActiveOnDate'] !== true) {
            echo "Offer is not active\n";
            continue;
        }
        if ($event['hasEnded']) {
            echo "Offer has ended\n";
            continue;
        }
        if ($event['coversRemaining'] === 0) {
            echo "Offer has no covers remaining\n";
            continue;
        }
        if ($offer['type'] !== 'PERCENT_OFF_ANY_FOOD') {
            echo "Offer is not PERCENT_OFF_ANY_FOOD\n";
            continue;
        }

        $namespacedType = 'com.google.nearby/en';
        if ($beacon['useDebugNotifications']) {
            $namespacedType .= '-debug';
        }

        $beaconAttachment = [
            'namespacedType' => $namespacedType,
            'data' => [
                'title' => $event['discount'] . '% off at ' . $restaurant['name'] . '!',
                'url' => $config['urlShortenerBase'] . '/b/' . $restaurant['id'],
                'targeting' => [[
                    'startDate' => $event['date'],
                    'endDate' => $event['date'],
                    'startTimeOfDay' => $event['startTime'],
                    'endTimeOfDay' => $event['endTime'],
                    'anyOfDaysOfWeek' => [(int) (new DateTime($event['date']))->format('N')],
                ]],
            ],
            'restaurant' => $restaurant,
            'discount' => $event['discount'],
            'offer' => $offer,
        ];

        $beaconAttachment['base64Data'] = toBase64($beaconAttachment['data']);

        $beaconAttachmentsToCreateOrKeep[] = $beaconAttachment;
    }

    $existingBeaconAttachments = $googleService->beacons_attachments->listBeaconsAttachments($beacon['name']);

    foreach ($beaconAttachmentsToCreateOrKeep as $toCreateOrKeep) {
        $alreadyExists = false;
        foreach ($existingBeaconAttachments as $existing) {
            if ($existing['data'] === $toCreateOrKeep['base64Data']) {
                $alreadyExists = true;
                break;
            }
        }

        if ($alreadyExists) {
            echo 'Notification already exists for ' . $toCreateOrKeep['discount'] . '% off'
                . ' at ' . $toCreateOrKeep['restaurant']['name']
                . ' on ' . $toCreateOrKeep['data']['targeting'][0]['startDate']
                . ' at ' . $toCreateOrKeep['data']['targeting'][0]['startTimeOfDay']
                . '-' . $toCreateOrKeep['data']['targeting'][0]['endTimeOfDay']
                . ' for beacon "' . $beacon['friendlyName'] . '"'
                . "\n";
            continue;
        }

        $googleService->beacons_attachments->create(
            $beacon['name'],
            new Google_Service_Proximitybeacon_BeaconAttachment([
                'namespacedType' => $toCreateOrKeep['namespacedType'],
                'data' => toBase64($toCreateOrKeep['data']),
            ])
        );
        queueSlackMessage(
            'Created notification for ' . $toCreateOrKeep['discount'] . '% off'
                . ' at ' . $toCreateOrKeep['restaurant']['name']
                . ' on ' . $toCreateOrKeep['data']['targeting'][0]['startDate']
                . ' at ' . $toCreateOrKeep['data']['targeting'][0]['startTimeOfDay']
                . '-' . $toCreateOrKeep['data']['targeting'][0]['endTimeOfDay']
                . ' for beacon "' . $beacon['friendlyName'] . '"'
                . ' - offer is ' . $toCreateOrKeep['offer']['id']
        );
    }

    // Check if any existing beacon attachments need to be deleted.
    foreach ($existingBeaconAttachments as $existing) {
        $isToBeKept = false;
        foreach ($beaconAttachmentsToCreateOrKeep as $toCreateOrKeep) {
            if ($toCreateOrKeep['base64Data'] === $existing['data']) {
                $isToBeKept = true;
                break;
            }
        }

        if ($isToBeKept) {
            echo 'Notification is to be kept: ' . $existing['attachmentName'] . "\n";
            continue;
        }

        $googleService->beacons_attachments->delete($existing['attachmentName']);

        $existingDecoded = fromBase64($existing['data']);
        queueSlackMessage(
            'Deleted old notification "' . $existingDecoded['title'] . '"'
                . ' on ' . $existingDecoded['targeting'][0]['startDate']
                . ' at ' . $existingDecoded['targeting'][0]['startTimeOfDay']
                . '-' . $existingDecoded['targeting'][0]['endTimeOfDay']
                . ' for beacon "' . $beacon['friendlyName'] . '"'
        );
    }
}
