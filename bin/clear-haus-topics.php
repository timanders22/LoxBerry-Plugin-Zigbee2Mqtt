<?php
/**
 * Removes the retained topics below haus/tuer/ that the bridge extension
 * published (used on uninstall). The empty "retain <topic> " goes through
 * the UDP input of the MQTT gateway, which deletes the retained message.
 *
 * Usage: php clear-haus-topics.php <path to zigbee2mqttng_haus.json>
 */
$file = isset($argv[1]) ? $argv[1] : "";
$topics = is_file($file) ? json_decode(file_get_contents($file), true) : null;
if (!is_array($topics) || count($topics) == 0) {
    exit(0);
}
$general = json_decode(@file_get_contents(getenv("LBHOMEDIR") . "/config/system/general.json"), true);
$port = isset($general["Mqtt"]["Udpinport"]) ? (int) $general["Mqtt"]["Udpinport"] : 11884;
$socket = @stream_socket_client("udp://127.0.0.1:" . $port, $errno, $errstr, 2);
if (!$socket) {
    echo "<WARNING> Could not remove the haus/tuer topics: $errstr\n";
    exit(0);
}
$count = 0;
foreach (array_keys($topics) as $topic) {
    if (preg_match('#^haus/tuer/[a-z0-9_\-]{1,60}/(offen|verriegelt)$#', $topic)) {
        fwrite($socket, "retain " . $topic . " ");
        $count++;
        usleep(20000);
    }
}
fclose($socket);
echo "<INFO> Removed $count topic(s) below haus/tuer\n";
