<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';

$twig = Plugin::initializeTwig();

// The zigbee2mqtt UI belongs to the Devices tab
Plugin::createHeader(Plugin::DEVICES);
$serviceCfg = ServiceConfig::load();
$port = (int) $serviceCfg->frontendPort > 0 ? (int) $serviceCfg->frontendPort : 8881;
echo $twig->render('ui.html', array("port" => $port, "service" => $serviceCfg));
//creates the footer
LBWeb::lbfooter();
