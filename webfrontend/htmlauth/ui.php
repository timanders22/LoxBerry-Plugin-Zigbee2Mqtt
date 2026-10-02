<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(3);
$serviceCfg = json_decode(file_get_contents($configfile));
$port = isset($serviceCfg->frontendPort) && (int) $serviceCfg->frontendPort > 0 ? (int) $serviceCfg->frontendPort : 8881;
echo $twig->render('ui.html', array("port" => $port));
//creates the footer
LBWeb::lbfooter();
