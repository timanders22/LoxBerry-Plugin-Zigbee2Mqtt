<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPDATADIR . "/configuration.yaml";
$deviceDataFile = LBPDATADIR . "/devices.yaml";
$mqttGatewaySubscriptionFile = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";

// Zigbee2MqttNG runs its own service and installation folder, so it never
// collides with the original Zigbee2Mqtt plugin or its former name Zigbee2Lox
$serviceName = "zigbee2mqttng";
$installDir = "/opt/zigbee2mqttng";
// Plugins whose Zigbee network is taken over on a fresh install (see
// postroot.sh). Plugin folder => array(title, service).
$predecessorPlugins = array(
    "zigbee2lox" => array("title" => "Zigbee2Lox", "service" => "zigbee2lox"),
    "zigbee2mqtt" => array("title" => "Zigbee2Mqtt", "service" => "zigbee2mqtt"),
);

// Bridge between zigbee2mqtt and the plugin (see bin/zigbee2mqttng_extension.mjs)
$extensionSourceFile = LBPBINDIR . "/zigbee2mqttng_extension.mjs";
$extensionTargetFile = LBPDATADIR . "/external_extensions/zigbee2mqttng.mjs";
$bridgeConfigFile = LBPDATADIR . "/zigbee2mqttng.json";
$bridgeDevicesFile = LBPDATADIR . "/zigbee2mqttng_devices.json";
$bridgeGroupsFile = LBPDATADIR . "/zigbee2mqttng_groups.json";
$bridgeInfoFile = LBPDATADIR . "/zigbee2mqttng_info.json";
$hausTopicsFile = LBPDATADIR . "/zigbee2mqttng_haus.json";

// Matter2Lox keeps the Thread dataset of its border router here
$matter2loxConfigFile = LBHOMEDIR . "/config/plugins/matter2lox/matter2lox.json";

$L = LBSystem::readlanguage("language.ini");
$navbar = array();
$htmlhead = 'htmlhead';
