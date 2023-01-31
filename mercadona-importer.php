#!/usr/bin/php
<?php
ini_set('max_execution_time', '0');

use Josantonius\MercadonaImporter\MercadonaImporter;

require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() !== 'cli') {
    exit;
}

new MercadonaImporter(
    warehouse: 'svq1',
    timezone: 'Europe/Madrid',
    delayForError: 300000000,
    delayForRequests: 1300000,
    includeFullProduct: false,
    reimportFullProduct: false,
    logDirectory: __DIR__ . '/logs/',
    outputDirectory: __DIR__ . '/data/',
);
