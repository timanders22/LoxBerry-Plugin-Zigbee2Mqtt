<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(1);

$mqtt_installed = false;

//mqtt is not a plugin anymore in lb >=3
$major_version=number_format(substr(LBSystem::lbversion(),0,1));
if($major_version > 2)
{
    $mqtt_installed = true;
}
else
{
   $mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;
}
echo $twig->render('index.html', array(
    "mqtt_installed" => $mqtt_installed,
    "predecessors" => zng_predecessor_plugins(),
    "gateway" => zng_gateway_info(),
));

//creates the footer
LBWeb::lbfooter();
