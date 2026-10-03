<?php
/**
 * Writes configuration.yaml of zigbee2mqtt and the files of the MQTT gateway
 * from the plugin settings. Runs on every save, on install and - through
 * ExecStartPre of the service - before every start of zigbee2mqtt, so
 * changed broker credentials of the MQTT gateway are picked up.
 */
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";
require_once LBPHTMLAUTHDIR . "/model/ServiceConfig.php";
require_once LBPHTMLAUTHDIR . "/model/MqttConfig.php";


$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update configuration");

$rawMqtt = zng_read_json($mqttconfigfile, array());
$mqttcfg = MqttConfig::load();
$serviceCfg = ServiceConfig::load();

$zigbee2mqttConfig = is_file($serviceConfigFile) ? @yaml_parse_file($serviceConfigFile) : array();
if (!is_array($zigbee2mqttConfig)) {
    if (is_file($serviceConfigFile)) {
        LOGWARN("configuration.yaml could not be read - it is written anew");
    }
    $zigbee2mqttConfig = array();
}

############ handle upgrade from previous version  ##################

//registerMqttTopic added in 0.8.0 ==> defaults to true to be backwards compatible
if (!array_key_exists('registerMqttTopic', $rawMqtt)) {
    $mqttcfg->registerMqttTopic = true;
}
$mqttcfg->save();

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"]["enabled"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt.log";
$zigbee2mqttConfig["advanced"]["log_output"] = array("console", "file");
$zigbee2mqttConfig["advanced"]["output"] = "json";
// The bridge extension of the plugin is an external extension
$zigbee2mqttConfig["advanced"]["enable_external_js"] = true;
$zigbee2mqttConfig["device_options"]["empty"] = false;
$zigbee2mqttConfig["devices"] = "devices.yaml";
$zigbee2mqttConfig["groups"] = "groups.yaml";
// zigbee2mqtt 2.x no longer knows permit_join in its configuration - pairing
// is opened with the button on the Devices tab (bridge/request/permit_join)
unset($zigbee2mqttConfig["permit_join"]);

$availability = (bool) is_enabled($serviceCfg->availability);
$haus = (bool) is_enabled($mqttcfg->hausTopics);

//MQTT parameter
$registerTopics = false;
if (is_enabled($mqttcfg->usemqttgateway)) {
    $registerTopics = (bool) is_enabled($mqttcfg->registerMqttTopic);
}
$creds = zng_broker_credentials($mqttcfg);

// Gateway subscriptions. "devices" registers only the state topics of the
// devices and groups, so the big bridge/* messages (device list, logging)
// never reach the Miniserver. The list is kept up to date by the extension
// whenever devices join, leave or are renamed.
$devices = zng_read_json($bridgeDevicesFile, array());
if (!$registerTopics) {
    zng_write_if_changed($mqttGatewaySubscriptionFile, "");
    zng_write_if_changed($mqttGatewayResetFile, "");
} else {
    if ($mqttcfg->forwardMode == "all" || !is_file($bridgeDevicesFile)) {
        // without a device list yet (first start) everything is forwarded once
        zng_write_if_changed($mqttGatewaySubscriptionFile, $mqttcfg->topic . "/#\n" . ($haus ? "haus/tuer/#\n" : ""));
    } else {
        zng_write_if_changed($mqttGatewaySubscriptionFile, zng_subscription_lines(
            $mqttcfg->topic,
            $devices,
            zng_read_json($bridgeGroupsFile, array()),
            $availability,
            $haus
        ));
    }
    zng_write_if_changed($mqttGatewayResetFile, zng_reset_lines($mqttcfg->topic, $devices));
}

// Up to 4.0.0 online/offline was converted by mqtt_conversions.cfg. The
// gateway applies such conversions to the values of ALL plugins, so the
// extension now publishes <topic>/<device>/erreichbar as 1/0 instead.
zng_write_if_changed($mqttGatewayConversionFile, "");

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

$frontendPort = (int) $serviceCfg->frontendPort;
if ($frontendPort < 1 || $frontendPort > 65535) {
    $frontendPort = 8881;
}
if (is_enabled($serviceCfg->enableUI)) {
    $zigbee2mqttConfig["frontend"]["enabled"] = true;
    $zigbee2mqttConfig["frontend"]["port"] = $frontendPort;
    if (is_enabled($serviceCfg->frontendAuth)) {
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', (string) $serviceCfg->frontendToken)) {
            $serviceCfg->frontendToken = bin2hex(random_bytes(12));
        }
        $zigbee2mqttConfig["frontend"]["auth_token"] = $serviceCfg->frontendToken;
    } else {
        unset($zigbee2mqttConfig["frontend"]["auth_token"]);
    }
} else {
    $zigbee2mqttConfig["frontend"]["enabled"] = false;
}
// The onboarding page of zigbee2mqtt (shown when configuration.yaml is
// invalid) listens on the same port as the frontend
zng_write_if_changed($serviceEnvFile, "Z2M_ONBOARD_URL=http://0.0.0.0:" . $frontendPort . "\n");

if ($serviceCfg->adapter != "") {
    $zigbee2mqttConfig["serial"]["adapter"] = $serviceCfg->adapter;
}

// Baudrate and rtscts are only written when the user set them. An empty value
// means "leave configuration.yaml alone", so a hand-edited value survives and
// zigbee2mqtt keeps choosing the default for everyone who does not care.
if ($serviceCfg->baudrate !== "" && $serviceCfg->baudrate !== null) {
    $zigbee2mqttConfig["serial"]["baudrate"] = (int) $serviceCfg->baudrate;
}

if ($serviceCfg->rtscts !== "" && $serviceCfg->rtscts !== null) {
    $zigbee2mqttConfig["serial"]["rtscts"] = ($serviceCfg->rtscts === true || $serviceCfg->rtscts === "true");
}

// Zigbee channel - only written when set, an empty field leaves the
// configuration alone. Changing it means pairing all devices again.
if ($serviceCfg->channel !== "" && $serviceCfg->channel !== null) {
    $channel = (int) $serviceCfg->channel;
    if ($channel >= 11 && $channel <= 26) {
        $zigbee2mqttConfig["advanced"]["channel"] = $channel;
    }
}

// Availability: <topic>/<device>/erreichbar tells Loxone if a device is
// reachable - the same information Matter2Lox gives for Matter devices
$zigbee2mqttConfig["availability"]["enabled"] = $availability;

//save zigbee2mqtt config
yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);

// if the adapter is empty, use the current value from the zigbee2mqtt config
if ($serviceCfg->adapter == "" && !empty($zigbee2mqttConfig["serial"]["adapter"])) {
    $serviceCfg->adapter = $zigbee2mqttConfig["serial"]["adapter"];
}
$serviceCfg->save();

// Bridge extension: keeps the gateway files and the device list up to date,
// publishes erreichbar, button pulses and the house topics, sends
// notifications
if (!is_dir(dirname($extensionTargetFile))) {
    mkdir(dirname($extensionTargetFile), 0755, true);
}
if (is_file($extensionSourceFile)) {
    zng_write_if_changed($extensionTargetFile, file_get_contents($extensionSourceFile));
}
zng_write_if_changed($bridgeConfigFile, json_encode(array(
    "registerTopics" => $registerTopics,
    "forwardMode" => $mqttcfg->forwardMode,
    "subscriptionFile" => $mqttGatewaySubscriptionFile,
    "resetFile" => $mqttGatewayResetFile,
    "availability" => $availability,
    "hausTopics" => $haus,
    "devicesFile" => $bridgeDevicesFile,
    "groupsFile" => $bridgeGroupsFile,
    "infoFile" => $bridgeInfoFile,
    "hausFile" => $hausTopicsFile,
    "hausNamesFile" => $hausNamesFile,
    "statusFile" => $bridgeStatusFile,
    "availabilityFile" => $availabilityFile,
    "notifyOffline" => $availability && is_enabled($serviceCfg->notifyOffline),
    "notifyBattery" => (bool) is_enabled($serviceCfg->notifyBattery),
    "batteryThreshold" => (int) $serviceCfg->batteryThreshold,
    "notifyFile" => $notifyStateFile,
    "notifyScript" => $notifyScript,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

LOGOK("Update successful");
LOGEND("Update configuration finished");
