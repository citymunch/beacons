#!/usr/bin/php
<?php

require_once __DIR__ . '/helpers.php';

use MongoDB\BSON\ObjectID;

$beaconsCollection->deleteMany([]);

$beaconsCollection->insertOne([
    // This is the test beacon's ID.
    'name' => 'beacons/3!abcaabcacabcacaabcacaabcacacabca',
    // This is PizzaBuzz.
    'restaurantId' => new ObjectID('5802bbd09db45bb8718b5197'),
    'status' => 'ACTIVE',
    'friendlyName' => 'PizzaBuzz test',
    'useDebugNotifications' => true,
]);
