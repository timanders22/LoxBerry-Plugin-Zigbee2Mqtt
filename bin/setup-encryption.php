<?php
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";


$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update encryption");

$zigbee2mqttConfig = yaml_parse_file($serviceConfigFile);

//autogenerate KEY
$zigbee2mqttConfig["advanced"]["network_key"] = "GENERATE";
// Own PAN ID and extended PAN ID instead of the zigbee2mqtt defaults, which
// every installation in the neighbourhood would share
$zigbee2mqttConfig["advanced"]["pan_id"] = "GENERATE";
$zigbee2mqttConfig["advanced"]["ext_pan_id"] = "GENERATE";


yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);
LOGOK("Update encryption successful");
LOGEND("Update encryption finished");;
