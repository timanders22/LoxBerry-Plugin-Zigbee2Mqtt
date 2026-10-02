<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "model/ServiceConfig.php";
require_once "model/MqttConfig.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/zigbee2lox.php";

$log = LBLog::newLog(["name" => "Service"]);

if (isset($_GET["action"])) {
    $action = $_GET["action"];
    if ($action == "getFormData") {
        if (isset($_GET["form"])) {
            sendresponse(200, "application/json", getFormData($_GET["form"]));
        }
    } else if ($action == "setFormData") {
        if (isset($_GET["form"])) {
            sendresponse(200, "application/json", setFormData($_GET["form"], $_POST));
        }
    } else if ($action == "setDevices") {
        sendresponse(200, "application/json", setDevices($_POST));
    } else if ($action == "applyChanges") {
        sendresponse(200, "application/json", applyChanges());
    } else if ($action == "getPid") {
        sendresponse(200, "application/json", getPid());
    } else if ($action == "getSerialPorts") {
        sendresponse(200, "application/json", json_encode(z2l_serial_ports()));
    } else if ($action == "getRadioInfo") {
        sendresponse(200, "application/json", getRadioInfo());
    } else if ($action == "getTemplate") {
        getTemplate(isset($_GET["kind"]) ? $_GET["kind"] : "", isset($_GET["device"]) ? $_GET["device"] : "");
    }
}

/**
 * apply the changes
 */
function applyChanges()
{

    LOGSTART("Restart zigbee2mqtt service");
    shell_exec("php " . LBPBINDIR . "/update-config.php");
    global $serviceName;
    shell_exec("sudo systemctl restart " . escapeshellarg($serviceName) . " -q");
    LOGOK("Restart ok");
    LOGEND("Restarted zigbee2mqtt service");


    return '{"result":true}';
}

/**
 * Retrievs the form data for the given form
 */
function getFormData($form)
{

    switch ($form) {
        case "ServiceConfig":
            $data = ServiceConfig::load();
            return $data->toJson();
            break;
        case "MqttConfig":
            $data = MqttConfig::load();
            return $data->toJson();
            break;
    }
    return "{}";
}

/**
 * Sets the form data
 */
function setFormData($form, $formData)
{
    $class = null;
    switch ($form) {
        case "ServiceConfig":
            $class = new ReflectionClass(ServiceConfig::class);
            break;
        case "MqttConfig":
            $class = new ReflectionClass(MqttConfig::class);
            break;

        default:
            sendresponse(400, "application/json", '{"result":false}');
            exit(1);
    }

    foreach ($formData[$class->getName()] as $name => $value) {
        if ($value == "on" || $value == "true")
            $formData[$class->getName()][$name] = true;
        if ($value == "off" || $value == "false")
            $formData[$class->getName()][$name] = false;
    }

    $data = MakeObjectFromArray($class, $formData[$class->getName()]);
    $data->save();
    return '{"result": true}';
}

/**
 * Sets the device data
 */
function setDevices($formData)
{
    global $deviceDataFile;

    LOGSTART("Update device configuration");

    $data = file_get_contents('php://input');
    if (yaml_parse($data) == FALSE) {
        LOGERR("Sent device configuration invalid was invalid");
        LOGEND("Update failed");
        sendresponse(400, "application/json", '{ "error" : "Configuration not valid." }');
        exit(1);
    }

    $file = fopen($deviceDataFile, "w");
    fwrite($file, $data);
    fclose($file);
    LOGOK("Update OK");
    LOGEND("Update finished");
    sendresponse(200, "text/plain", $data);
}

/**
 * Gets the pid of the zigbee2mqtt service
 */
function getPid()
{
    //fetches the pid or 0 if not running
    global $serviceName;
    $pid = (int) shell_exec("systemctl show --property MainPID --value " . escapeshellarg($serviceName));
    return json_encode(array("pid" => $pid));
}

/**
 * Zigbee and Thread channel. Both use the same 2.4 GHz channels (IEEE 802.15.4,
 * 11-26), so a Thread network of Matter2Lox on the same channel disturbs Zigbee.
 */
function getRadioInfo()
{
    list($zigbee, $zigbeeSource) = z2l_zigbee_channel();
    list($thread, $threadSource) = z2l_thread_channel();
    $level = "ok";
    if ($thread && $thread == $zigbee) {
        $level = "conflict";
    } elseif ($thread && abs($thread - $zigbee) == 1) {
        $level = "adjacent";
    }
    return json_encode(array(
        "zigbee" => $zigbee,
        "zigbeeSource" => $zigbeeSource,
        "thread" => $thread,
        "threadSource" => $threadSource,
        "level" => $level,
        "original" => z2l_original_plugin(),
    ));
}

/**
 * Loxone template as download: kind "in" (virtual UDP input) or "out"
 * (virtual output), for one device or all devices
 */
function getTemplate($kind, $device)
{
    global $mqttconfigfile, $configfile, $bridgeDevicesFile;
    $mqttcfg = json_decode(file_get_contents($mqttconfigfile));
    $serviceCfg = json_decode(file_get_contents($configfile));
    $availability = !property_exists($serviceCfg, 'availability') || is_enabled($serviceCfg->availability);
    $ios = z2l_device_ios($mqttcfg->topic, z2l_read_json($bridgeDevicesFile, array()), $availability);
    if ($device !== "") {
        $ios = array_values(array_filter($ios, function ($d) use ($device) {
            return $d["name"] === $device;
        }));
        if (count($ios) == 0) {
            sendresponse(404, "application/json", '{"error":"device not found"}');
        }
    }
    $gateway = z2l_gateway_info();
    $title = $device !== "" ? "Zigbee " . $device : "Zigbee2Lox";
    $inputs = array();
    $outputs = array();
    foreach ($ios as $d) {
        $inputs = array_merge($inputs, $d["inputs"]);
        $outputs = array_merge($outputs, $d["outputs"]);
    }
    $file = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title);
    if ($kind == "in") {
        $xml = z2l_xml_virtual_in_udp($title, $gateway["udpport"], $inputs);
        $file = "VIU_" . $file . ".xml";
    } else if ($kind == "out") {
        $address = "/dev/udp/" . LBSystem::get_localip() . "/" . $gateway["udpinport"];
        $xml = z2l_xml_virtual_out($title, $address, $outputs);
        $file = "VO_" . $file . ".xml";
    } else {
        sendresponse(400, "application/json", '{"error":"unknown kind"}');
    }
    header("Content-Type: application/xml; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $file . '"');
    echo $xml;
    exit(0);
}

function sendresponse($httpstatus, $contenttype, $response = null)
{

    $codes = array(
        200 => "OK",
        204 => "NO CONTENT",
        304 => "NOT MODIFIED",
        400 => "BAD REQUEST",
        404 => "NOT FOUND",
        405 => "METHOD NOT ALLOWED",
        500 => "INTERNAL SERVER ERROR",
        501 => "NOT IMPLEMENTED"
    );
    if (isset($_SERVER["SERVER_PROTOCOL"])) {
        header($_SERVER["SERVER_PROTOCOL"] . " $httpstatus " . $codes[$httpstatus]);
        header("Content-Type: $contenttype");
    }

    if ($response) {
        echo $response . "\n";
    }
    exit(0);
}
