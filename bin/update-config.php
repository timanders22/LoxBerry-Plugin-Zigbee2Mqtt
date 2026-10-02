<?php
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2lox.php";


$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update configuration");


$mqttcfg = json_decode(file_get_contents($mqttconfigfile));
$serviceCfg = json_decode(file_get_contents($configfile));

$zigbee2mqttConfig = is_file($serviceConfigFile) ? yaml_parse_file($serviceConfigFile) : array();
if (!is_array($zigbee2mqttConfig)) {
    $zigbee2mqttConfig = array();
}

############ handle upgrade from previous version  ##################

//registerMqttTopic added in 0.8.0 ==> defaults to true to be backwards compatible
if (!property_exists($mqttcfg, 'registerMqttTopic')) {
    $mqttcfg->registerMqttTopic = true;
    file_put_contents($mqttconfigfile, json_encode($mqttcfg));
}

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"]["enabled"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt.log";
$zigbee2mqttConfig["advanced"]["log_output"][0] = "console";
$zigbee2mqttConfig["advanced"]["log_output"][1] = "file";
$zigbee2mqttConfig["advanced"]["output"] = "json";
$zigbee2mqttConfig["device_options"]["empty"] = false;
$zigbee2mqttConfig["devices"] = "devices.yaml";
$zigbee2mqttConfig["groups"] = "groups.yaml";



//defaults for settings added in Zigbee2Lox
if (!property_exists($mqttcfg, 'forwardMode')) {
    $mqttcfg->forwardMode = "devices";
}
if (!property_exists($mqttcfg, 'hausTopics')) {
    $mqttcfg->hausTopics = false;
}
$availability = !property_exists($serviceCfg, 'availability') || is_enabled($serviceCfg->availability);

//MQTT parameter
$registerTopics = false;
if (is_enabled($mqttcfg->usemqttgateway)) {
    $creds = mqtt_connectiondetails();
    $registerTopics = is_enabled($mqttcfg->registerMqttTopic);
} else {
    $creds['brokerhost'] = $mqttcfg->server;
    $creds['brokerport'] = $mqttcfg->port;
    $creds['brokeruser'] = $mqttcfg->username;
    $creds['brokerpass'] = $mqttcfg->password;
}

// Gateway subscriptions. "devices" registers only the state topics of the
// devices and groups, so the big bridge/* messages (device list, logging)
// never reach the Miniserver. The list is kept up to date by the extension
// whenever devices join, leave or are renamed.
if (!$registerTopics) {
    z2l_write_if_changed($mqttGatewaySubscriptionFile, "");
} elseif ($mqttcfg->forwardMode == "all" || !is_file($bridgeDevicesFile)) {
    // without a device list yet (first start) everything is forwarded once
    z2l_write_if_changed($mqttGatewaySubscriptionFile, $mqttcfg->topic . "/#");
} else {
    z2l_write_if_changed($mqttGatewaySubscriptionFile, z2l_subscription_lines(
        $mqttcfg->topic,
        z2l_read_json($bridgeDevicesFile, array()),
        z2l_read_json($bridgeGroupsFile, array()),
        $availability
    ));
}

// Availability arrives as {"state":"online"} - the gateway turns it into 0/1
z2l_write_if_changed(LBPCONFIGDIR . "/mqtt_conversions.cfg", $registerTopics && $availability ? "online=1\noffline=0\n" : "");

$zigbee2mqttConfig["mqtt"]["base_topic"] = $mqttcfg->topic;
$zigbee2mqttConfig["mqtt"]["server"] = "mqtt://" . $creds['brokerhost'] . ":" . $creds['brokerport'];
$zigbee2mqttConfig["mqtt"]["user"] = $creds['brokeruser'];
$zigbee2mqttConfig["mqtt"]["password"] = $creds['brokerpass'];


//customizable parameters
if ($serviceCfg->port != "") {
    $zigbee2mqttConfig["serial"]["port"] = $serviceCfg->port;
} else {
    $zigbee2mqttConfig["serial"]["port"] = null;
}
$zigbee2mqttConfig["permit_join"] = $serviceCfg->permitJoin;

if (is_enabled($serviceCfg->enableUI)) {
    $frontendPort = property_exists($serviceCfg, 'frontendPort') ? (int) $serviceCfg->frontendPort : 0;
    $zigbee2mqttConfig["frontend"]["enabled"] = true;
    $zigbee2mqttConfig["frontend"]["port"] = ($frontendPort > 0 && $frontendPort < 65536) ? $frontendPort : 8881;
} else {
    $zigbee2mqttConfig["frontend"]["enabled"] = false;
}

if ($serviceCfg->adapter != "") {
    $zigbee2mqttConfig["serial"]["adapter"] = $serviceCfg->adapter;
}

// Baudrate and rtscts are only written when the user set them. An empty value
// means "leave configuration.yaml alone", so a hand-edited value survives and
// zigbee2mqtt keeps choosing the default for everyone who does not care.
if (property_exists($serviceCfg, 'baudrate') && $serviceCfg->baudrate !== "" && $serviceCfg->baudrate !== null) {
    $zigbee2mqttConfig["serial"]["baudrate"] = (int) $serviceCfg->baudrate;
}

if (property_exists($serviceCfg, 'rtscts') && $serviceCfg->rtscts !== "" && $serviceCfg->rtscts !== null) {
    $zigbee2mqttConfig["serial"]["rtscts"] = ($serviceCfg->rtscts === true || $serviceCfg->rtscts === "true");
}

// Zigbee channel - only written when set, an empty field leaves the
// configuration alone. Changing it means pairing all devices again.
if (property_exists($serviceCfg, 'channel') && $serviceCfg->channel !== "" && $serviceCfg->channel !== null) {
    $channel = (int) $serviceCfg->channel;
    if ($channel >= 11 && $channel <= 26) {
        $zigbee2mqttConfig["advanced"]["channel"] = $channel;
    }
}

// Availability: <topic>/<device>/availability tells Loxone if a device is
// reachable - the same information Matter2Lox gives for Matter devices
$zigbee2mqttConfig["availability"]["enabled"] = $availability;

//save zigbee2mqtt config
yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);

// if the adapter is empty, use the current value from the zigbee2mqtt config
if ($serviceCfg->adapter == "") {
    $serviceCfg->adapter = $zigbee2mqttConfig["serial"]["adapter"];
    file_put_contents($configfile, json_encode($serviceCfg,JSON_PRETTY_PRINT));
}

// Bridge extension: keeps the gateway subscriptions and the device list for
// the Loxone templates up to date and publishes doors and locks under
// haus/tuer/<name>/... like Matter2Lox
if (!is_dir(dirname($extensionTargetFile))) {
    mkdir(dirname($extensionTargetFile), 0755, true);
}
if (is_file($extensionSourceFile)) {
    z2l_write_if_changed($extensionTargetFile, file_get_contents($extensionSourceFile));
}
z2l_write_if_changed($bridgeConfigFile, json_encode(array(
    "registerTopics" => $registerTopics,
    "forwardMode" => $mqttcfg->forwardMode,
    "subscriptionFile" => $mqttGatewaySubscriptionFile,
    "availability" => $availability,
    "hausTopics" => is_enabled($mqttcfg->hausTopics),
    "devicesFile" => $bridgeDevicesFile,
    "groupsFile" => $bridgeGroupsFile,
    "infoFile" => $bridgeInfoFile,
    "hausFile" => $hausTopicsFile,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

LOGOK("Update successful");
LOGEND("Update configuration finished");;
