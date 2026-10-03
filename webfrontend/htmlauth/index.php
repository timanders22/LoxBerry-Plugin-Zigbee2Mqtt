<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(Plugin::SETTINGS);

echo $twig->render('index.html', array(
    "predecessors" => zng_predecessor_plugins(),
    "adapters" => ServiceConfig::ADAPTERS,
    "service" => ServiceConfig::load(),
));

//creates the footer
LBWeb::lbfooter();
