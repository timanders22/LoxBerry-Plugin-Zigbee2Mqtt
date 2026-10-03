<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(Plugin::LOXONE);

$mqttcfg = MqttConfig::load();
$serviceCfg = ServiceConfig::load();
$availability = is_enabled($serviceCfg->availability);
$devices = zng_device_ios($mqttcfg->topic, zng_read_json($bridgeDevicesFile, array()), $availability);
$hasActions = false;
foreach ($devices as $d) {
    foreach ($d["inputs"] as $in) {
        $hasActions = $hasActions || $in["type"] === "action";
    }
}

echo $twig->render('loxone.html', array(
    "devices" => $devices,
    "hasDeviceList" => is_file($bridgeDevicesFile),
    "gateway" => zng_gateway_info(),
    "topic" => $mqttcfg->topic,
    "hausTopics" => is_enabled($mqttcfg->hausTopics),
    "availability" => $availability,
    "hasActions" => $hasActions,
    "loxberryIp" => LBSystem::get_localip(),
));

//creates the footer
LBWeb::lbfooter();
