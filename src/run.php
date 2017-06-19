#!/usr/bin/php
<?php

require_once __DIR__ . '/helpers.php';

use MongoDB\Model\BSONDocument;

$googleService = new Google_Service_Proximitybeacon(getGoogleClient());
$beacons = $beaconsCollection->find([]);
$slackMessageQueue = [];

$lookAheadUntilDate = new DateTime();
$lookAheadUntilDate->add(new DateInterval('P2D'));

foreach ($beacons as $beacon) {
    try {
        updateBeacon($beacon);
    } catch (Exception $e) {
        queueSlackMessage($e->getMessage());
    }

    queueSlackMessage('Beacon manager finished running');
    postSlackMessageQueue();
}

function updateBeacon(BSONDocument $beacon): void {
    global $googleService;

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

        if ($event['isActiveOnDate'] !== true) {
            continue;
        }
        if ($event['hasEnded']) {
            continue;
        }
        if ($event['coversRemaining'] === 0) {
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
                'targeting' => [
                    'startDate' => $event['date'],
                    'endDate' => $event['date'],
                    'startTimeOfDay' => $event['startTime'],
                    'endTimeOfDay' => $event['endTime'],
                    'anyOfDaysOfWeek' => [(new DateTime($event['date']))->format('N')],
                ],
            ],
            'restaurant' => $restaurant,
            'discount' => $event['discount'],
        ];

        $beaconAttachment['base64Data'] = base64_encode(json_encode($beaconAttachment['data']));

        $beaconAttachmentsToCreateOrKeep[] = $beaconAttachment;
    }

    $existingBeaconAttachments = $googleService->beacons_attachments->listBeaconsAttachments($beacon['name']);

    foreach ($beaconAttachmentsToCreateOrKeep as $toCreateOrKeep) {
        $alreadyExists = false;
        foreach ($existingBeaconAttachments as $existing) {
            if ($existing['data'] === $toCreateOrKeep['base64Data']) {
                queueSlackMessage(
                    'Notification already exists for ' . $toCreateOrKeep['discount'] . '% off'
                        . ' at ' . $toCreateOrKeep['restaurant']['name']
                        . ' on ' . $toCreateOrKeep['data']['targeting']['startDate']
                        . ' at ' . $toCreateOrKeep['data']['targeting']['startTimeOfDay']
                        . '-' . $toCreateOrKeep['data']['targeting']['endTimeOfDay']
                        . ' for beacon "' . $beacon['friendlyName'] . '"'
                );
                $alreadyExists = true;
                break;
            }
        }

        if ($alreadyExists) {
            continue;
        }

        $googleService->beacons_attachments->create(
            $beacon['name'],
            new Google_Service_Proximitybeacon_BeaconAttachment([
                'namespacedType' => $toCreateOrKeep['namespacedType'],
                'data' => base64_encode(json_encode($toCreateOrKeep['data'])),
            ])
        );
        queueSlackMessage(
            'Created notification for ' . $toCreateOrKeep['discount'] . '% off'
                . ' at ' . $toCreateOrKeep['restaurant']['name']
                . ' on ' . $toCreateOrKeep['data']['targeting']['startDate']
                . ' at ' . $toCreateOrKeep['data']['targeting']['startTimeOfDay']
                . '-' . $toCreateOrKeep['data']['targeting']['endTimeOfDay']
                . ' for beacon "' . $beacon['friendlyName'] . '"'
        );
    }

    // Check if any existing beacons need to be deleted.
    foreach ($existingBeaconAttachments as $existing) {
        $isToBeKept = false;
        foreach ($beaconAttachmentsToCreateOrKeep as $toCreateOrKeep) {
            if ($toCreateOrKeep['base64Data'] === $existing['data']) {
                $isToBeKept = true;
                break;
            }
        }

        if ($isToBeKept) {
            continue;
        }

        $googleService->beacons_attachments->delete($existing['attachmentName']);
        queueSlackMessage(
            'Deleted old notification for ' . $toCreateOrKeep['discount'] . '% off'
                . ' at ' . $toCreateOrKeep['restaurant']['name']
                . ' on ' . $toCreateOrKeep['data']['targeting']['startDate']
                . ' at ' . $toCreateOrKeep['data']['targeting']['startTimeOfDay']
                . '-' . $toCreateOrKeep['data']['targeting']['endTimeOfDay']
                . ' for beacon "' . $beacon['friendlyName'] . '"'
        );
    }
}
