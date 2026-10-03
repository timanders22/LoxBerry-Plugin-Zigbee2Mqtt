<?php
/**
 * Sends one LoxBerry notification for the events the bridge extension
 * collected within a minute (see bin/zigbee2mqttng_extension.mjs).
 *
 * Usage: php notify.php offline:<device> battery:<device>:<level> ...
 */
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once LBPBINDIR . "/defines.php";

$offline = array();
$battery = array();
foreach (array_slice($argv, 1) as $event) {
    if (strpos($event, "offline:") === 0) {
        $offline[] = substr($event, 8);
    } elseif (strpos($event, "battery:") === 0) {
        $pos = strrpos($event, ":");
        $battery[] = substr($event, 8, $pos - 8) . " (" . (int) substr($event, $pos + 1) . " %)";
    }
}

$lines = array();
if ($offline) {
    $lines[] = sprintf($L["Notify.Offline"], implode(", ", $offline));
}
if ($battery) {
    $lines[] = sprintf($L["Notify.Battery"], implode(", ", $battery));
}
if ($lines) {
    notify(LBPPLUGINDIR, "zigbee", "Zigbee2MqttNG: " . implode(" ", $lines), count($offline) > 0);
}
