<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPDATADIR . "/configuration.yaml";
$deviceDataFile = LBPDATADIR . "/devices.yaml";
$mqttGatewaySubscriptionFile = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";

// Zigbee2Lox runs its own service and installation folder, so it never
// collides with the original Zigbee2Mqtt plugin
$serviceName = "zigbee2lox";
$installDir = "/opt/zigbee2lox";
$originalPluginFolder = "zigbee2mqtt";
$originalServiceName = "zigbee2mqtt";

// Bridge between zigbee2mqtt and the plugin (see bin/zigbee2lox_extension.mjs)
$extensionSourceFile = LBPBINDIR . "/zigbee2lox_extension.mjs";
$extensionTargetFile = LBPDATADIR . "/external_extensions/zigbee2lox.mjs";
$bridgeConfigFile = LBPDATADIR . "/zigbee2lox.json";
$bridgeDevicesFile = LBPDATADIR . "/zigbee2lox_devices.json";
$bridgeGroupsFile = LBPDATADIR . "/zigbee2lox_groups.json";
$bridgeInfoFile = LBPDATADIR . "/zigbee2lox_info.json";
$hausTopicsFile = LBPDATADIR . "/zigbee2lox_haus.json";

// Matter2Lox keeps the Thread dataset of its border router here
$matter2loxConfigFile = LBHOMEDIR . "/config/plugins/matter2lox/matter2lox.json";

$L = LBSystem::readlanguage("language.ini");
$navbar = array();
$htmlhead = 'htmlhead';
