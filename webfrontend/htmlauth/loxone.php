<?php
require_once 'include/plugin.php';
require_once LBPBINDIR . '/zigbee2lox.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(4);

$mqttcfg = json_decode(file_get_contents($mqttconfigfile));
$serviceCfg = json_decode(file_get_contents($configfile));
$availability = !property_exists($serviceCfg, 'availability') || is_enabled($serviceCfg->availability);

echo $twig->render('loxone.html', array(
    "devices" => z2l_device_ios($mqttcfg->topic, z2l_read_json($bridgeDevicesFile, array()), $availability),
    "hasDeviceList" => is_file($bridgeDevicesFile),
    "gateway" => z2l_gateway_info(),
    "topic" => $mqttcfg->topic,
    "hausTopics" => property_exists($mqttcfg, 'hausTopics') && is_enabled($mqttcfg->hausTopics),
    "loxberryIp" => LBSystem::get_localip(),
));

//creates the footer
LBWeb::lbfooter();
