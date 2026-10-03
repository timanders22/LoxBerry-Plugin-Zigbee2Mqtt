<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(Plugin::DEVICES);

$serviceCfg = ServiceConfig::load();
$list = deviceList(dirname($serviceConfigFile) . "/state.json");
echo $twig->render('devices.html', array(
    "deviceData" => is_file($deviceDataFile) ? file_get_contents($deviceDataFile) : "",
    "deviceList" => $list["devices"],
    "hasList" => is_file($bridgeDevicesFile),
    "stateTime" => $list["stateTime"],
    "showLastSeen" => $list["showLastSeen"],
    "service" => $serviceCfg,
    "pairing" => zng_read_json($bridgeInfoFile, array()),
));

//creates the footer
LBWeb::lbfooter();

/**
 * All paired devices from the device list the bridge extension keeps
 * (bridge/devices), with the last values from the state cache of zigbee2mqtt
 * (state.json) and the availability. Read only.
 */
function deviceList($stateFile)
{
    global $bridgeDevicesFile, $availabilityFile;
    $result = array("devices" => array(), "stateTime" => "", "showLastSeen" => false);

    $cache = array();
    if (is_readable($stateFile)) {
        $cache = json_decode((string) file_get_contents($stateFile), true);
        $cache = is_array($cache) ? $cache : array();
        $result["stateTime"] = date("d.m.Y H:i", filemtime($stateFile));
    }
    $availability = zng_read_json($availabilityFile, array());

    foreach ((array) zng_read_json($bridgeDevicesFile, array()) as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator") {
            continue;
        }
        $definition = isset($device["definition"]) && is_array($device["definition"]) ? $device["definition"] : array();
        $units = array();
        foreach (zng_flatten_exposes(isset($definition["exposes"]) ? $definition["exposes"] : array()) as $feature) {
            $units[$feature["property"]] = isset($feature["unit"]) ? $feature["unit"] : "";
        }
        $state = isset($cache[$device["ieee_address"]]) && is_array($cache[$device["ieee_address"]]) ? $cache[$device["ieee_address"]] : array();
        $values = array();
        foreach ($state as $property => $value) {
            if ($property === "last_seen" || is_array($value) || $value === "" || $value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? "true" : "false";
            }
            $values[] = $property . " " . $value . (isset($units[$property]) && $units[$property] !== "" ? " " . $units[$property] : "");
        }
        if (isset($state["last_seen"])) {
            $result["showLastSeen"] = true;
        }
        $name = isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"];
        $result["devices"][] = array(
            "name" => $name,
            "ieee" => $device["ieee_address"],
            "model" => trim((isset($definition["vendor"]) ? $definition["vendor"] . " " : "") . (isset($definition["model"]) ? $definition["model"] : (isset($device["model_id"]) ? $device["model_id"] : ""))),
            "description" => isset($definition["description"]) ? $definition["description"] : "",
            "type" => $device["type"],
            "power" => isset($device["power_source"]) ? $device["power_source"] : "",
            "interview" => isset($device["interview_state"]) ? $device["interview_state"] : (!empty($device["interview_completed"]) ? "SUCCESSFUL" : ""),
            "supported" => !isset($device["supported"]) || $device["supported"],
            "disabled" => !empty($device["disabled"]),
            "reachable" => isset($availability[$name]) ? (bool) $availability[$name] : null,
            "values" => implode(", ", $values),
            "lastSeen" => isset($state["last_seen"]) ? (is_numeric($state["last_seen"]) ? date("d.m.Y H:i", (int) ($state["last_seen"] / 1000)) : (string) $state["last_seen"]) : ""
        );
    }
    usort($result["devices"], function ($a, $b) {
        return strcasecmp($a["name"], $b["name"]);
    });
    return $result;
}
