<?php
/**
 * Helpers shared by update-config.php and the web frontend.
 *
 * Expects defines.php to be loaded.
 */

/**
 * Reads a json file, returns $default when it is missing or broken
 */
function zng_read_json($file, $default = null)
{
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode(file_get_contents($file), true);
    return $data === null ? $default : $data;
}

/**
 * Writes a file only when its content changed. The MQTT gateway watches the
 * subscription file and reloads on every write, so unchanged content is kept.
 */
function zng_write_if_changed($file, $content)
{
    if (is_file($file) && file_get_contents($file) === $content) {
        return false;
    }
    file_put_contents($file, $content);
    return true;
}

/**
 * True if a topic part can be used in a subscription (no wildcards)
 */
function zng_topic_ok($name)
{
    return is_string($name) && $name !== "" && strpbrk($name, "+#") === false;
}

/**
 * Name of a value as the MQTT gateway forwards it (virtual HTTP input,
 * mqtt_resetaftersend.cfg): "/" and "%" become "_", nothing else changes.
 */
function zng_gateway_name($topic)
{
    return str_replace(array("/", "%"), "_", (string) $topic);
}

/**
 * True if an action value can be used as topic level
 */
function zng_action_ok($value)
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $value);
}

/**
 * Action values a device can send (expose "action" of type enum)
 * Must give the same result as actionValues() in zigbee2mqttng_extension.mjs.
 */
function zng_action_values($device)
{
    $values = array();
    $walk = function ($exposes) use (&$walk, &$values) {
        foreach ((array) $exposes as $e) {
            if (isset($e["features"]) && is_array($e["features"])) {
                $walk($e["features"]);
            } elseif (isset($e["property"]) && $e["property"] === "action" && isset($e["type"]) && $e["type"] === "enum" && isset($e["values"]) && is_array($e["values"])) {
                foreach ($e["values"] as $v) {
                    if (zng_action_ok($v) && !in_array((string) $v, $values, true)) {
                        $values[] = (string) $v;
                    }
                }
            }
        }
    };
    $walk(isset($device["definition"]["exposes"]) ? $device["definition"]["exposes"] : array());
    return $values;
}

/**
 * Devices that have state topics (no coordinator, usable name)
 */
function zng_state_devices($devices)
{
    $list = array();
    foreach ((array) $devices as $device) {
        if (!isset($device["friendly_name"]) || (isset($device["type"]) && $device["type"] == "Coordinator")) {
            continue;
        }
        if (zng_topic_ok($device["friendly_name"])) {
            $list[] = $device;
        }
    }
    return $list;
}

/**
 * Subscription lines for the MQTT gateway: only the state topics of devices
 * and groups - the large bridge/* messages (device list, logging, ...) stay
 * away from the Miniserver.
 * Must give the same result as subscriptionLines() in zigbee2mqttng_extension.mjs.
 */
function zng_subscription_lines($base, $devices, $groups, $availability, $haus)
{
    $lines = array($base . "/bridge/state");
    foreach (zng_state_devices($devices) as $device) {
        $lines[] = $base . "/" . $device["friendly_name"];
        if ($availability) {
            $lines[] = $base . "/" . $device["friendly_name"] . "/erreichbar";
        }
        if (count(zng_action_values($device)) > 0) {
            $lines[] = $base . "/" . $device["friendly_name"] . "/aktion/+";
        }
    }
    foreach ((array) $groups as $group) {
        if (isset($group["friendly_name"]) && zng_topic_ok($group["friendly_name"])) {
            $lines[] = $base . "/" . $group["friendly_name"];
        }
    }
    if ($haus) {
        $lines[] = "haus/tuer/#";
    }
    return implode("\n", $lines) . "\n";
}

/**
 * mqtt_resetaftersend.cfg: the gateway sends 0 after every button counter,
 * so Loxone sees one pulse per press.
 * Must give the same result as resetLines() in zigbee2mqttng_extension.mjs.
 */
function zng_reset_lines($base, $devices)
{
    $lines = array();
    foreach (zng_state_devices($devices) as $device) {
        foreach (zng_action_values($device) as $value) {
            $lines[] = zng_gateway_name($base . "/" . $device["friendly_name"] . "/aktion/" . $value);
        }
    }
    return count($lines) > 0 ? implode("\n", $lines) . "\n" : "";
}

/**
 * Lists serial devices. Paths below /dev/serial/by-id never change, whereas
 * /dev/ttyACM0 and /dev/ttyACM1 may swap on every boot as soon as a second
 * stick (e.g. a Thread radio for Matter) is plugged in.
 */
function zng_serial_ports()
{
    $ports = array();
    foreach (glob("/dev/serial/by-id/*") ?: array() as $link) {
        $target = realpath($link);
        $ports[] = array("path" => $link, "target" => $target ? $target : "", "stable" => true);
    }
    foreach (array("/dev/ttyACM*", "/dev/ttyUSB*", "/dev/ttyAMA*") as $pattern) {
        foreach (glob($pattern) ?: array() as $dev) {
            $ports[] = array("path" => $dev, "target" => $dev, "stable" => false);
        }
    }
    return $ports;
}

/**
 * True if the given port is a kernel name that may change between boots
 */
function zng_port_unstable($port)
{
    return (bool) preg_match('#^/dev/tty(ACM|USB)[0-9]+$#', (string) $port);
}

/**
 * Checks a coordinator port without touching the service:
 *   tcp://host:port  - resolves the name and opens a TCP connection
 *   /dev/...         - checks that the device exists and is readable
 * Only these two forms are accepted; nothing is passed to a shell.
 * Returns array("result" => bool, "message" => key[, "ip" => ...]).
 */
function zng_test_port($port, $timeout = 3)
{
    $port = trim((string) $port);
    if ($port === "") {
        return array("result" => false, "message" => "empty");
    }
    if (preg_match('#^tcp://([A-Za-z0-9.\-]+):([0-9]{1,5})$#', $port, $m)) {
        $tcpPort = (int) $m[2];
        if ($tcpPort < 1 || $tcpPort > 65535) {
            return array("result" => false, "message" => "invalid");
        }
        $ip = gethostbyname($m[1]);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return array("result" => false, "message" => "unresolved");
        }
        $fp = @fsockopen($ip, $tcpPort, $errno, $errstr, $timeout);
        if ($fp === false) {
            return array("result" => false, "message" => "unreachable", "ip" => $ip);
        }
        fclose($fp);
        return array("result" => true, "message" => "reachable", "ip" => $ip);
    }
    if (preg_match('#^/dev/[A-Za-z0-9/_.:\-]+$#', $port)) {
        if (!file_exists($port)) {
            return array("result" => false, "message" => "missing");
        }
        return is_readable($port)
            ? array("result" => true, "message" => "present")
            : array("result" => false, "message" => "noaccess");
    }
    return array("result" => false, "message" => "invalid");
}

/**
 * Processes that have the serial device open, found through /proc/<pid>/fd.
 * Only processes of the same user (loxberry) are visible - zigbee2mqtt of
 * this plugin and of its predecessors run as loxberry.
 * Returns array of array(pid, command).
 */
function zng_port_users($port)
{
    $target = realpath((string) $port);
    if ($target === false || strpos($target, "/dev/") !== 0) {
        return array();
    }
    $users = array();
    foreach (glob("/proc/[0-9]*/fd/*") ?: array() as $fd) {
        if (@readlink($fd) === $target) {
            $pid = (int) explode("/", $fd)[2];
            if (!isset($users[$pid])) {
                $cmd = trim(str_replace("\0", " ", (string) @file_get_contents("/proc/$pid/cmdline")));
                $unit = "";
                if (preg_match('#/system\.slice/([^/\s]+)\.service#', (string) @file_get_contents("/proc/$pid/cgroup"), $m)) {
                    $unit = $m[1];
                }
                $users[$pid] = array("pid" => $pid, "command" => $cmd, "unit" => $unit);
            }
        }
    }
    return array_values($users);
}

/**
 * True if something listens on the TCP port of this machine
 */
function zng_port_listening($port)
{
    $fp = @fsockopen("127.0.0.1", (int) $port, $errno, $errstr, 1);
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

/**
 * Channel of a Thread operational dataset (hex TLVs). TLV type 0 is the
 * channel: one byte channel page, two bytes channel. Returns 0 if not found.
 */
function zng_dataset_channel($hex)
{
    $hex = trim((string) $hex);
    if (!preg_match('/^([0-9A-Fa-f]{2})+$/', $hex)) {
        return 0;
    }
    $bin = hex2bin($hex);
    $i = 0;
    $len = strlen($bin);
    while ($i + 2 <= $len) {
        $type = ord($bin[$i]);
        $l = ord($bin[$i + 1]);
        if ($i + 2 + $l > $len) {
            break;
        }
        if ($type == 0 && $l == 3) {
            return (ord($bin[$i + 3]) << 8) | ord($bin[$i + 4]);
        }
        $i += 2 + $l;
    }
    return 0;
}

/**
 * Thread channel: from the dataset stored by Matter2Lox, otherwise from an
 * OpenThread border router on this LoxBerry (REST port 8081).
 * Returns array(channel, source) or array(0, "").
 */
function zng_thread_channel()
{
    global $matter2loxConfigFile;
    $files = array_unique(array_merge(array($matter2loxConfigFile), glob(LBHOMEDIR . "/config/plugins/matter2lox*/matter2lox.json") ?: array()));
    foreach ($files as $file) {
        $cfg = zng_read_json($file, array());
        if (!empty($cfg["thread_dataset"])) {
            $channel = zng_dataset_channel($cfg["thread_dataset"]);
            if ($channel) {
                return array($channel, "Matter2Lox");
            }
        }
    }
    $ctx = stream_context_create(array("http" => array(
        "method" => "GET", "timeout" => 1, "ignore_errors" => true,
        "header" => "Accept: text/plain")));
    $body = @file_get_contents("http://127.0.0.1:8081/node/dataset/active", false, $ctx);
    if ($body !== false) {
        $channel = zng_dataset_channel(trim($body, " \t\r\n\"'"));
        if ($channel) {
            return array($channel, "OpenThread Border Router");
        }
    }
    return array(0, "");
}

/**
 * Zigbee channel actually used by the coordinator (bridge/info), otherwise
 * the configured one, otherwise the zigbee2mqtt default 11.
 * Returns array(channel, source).
 */
function zng_zigbee_channel()
{
    global $bridgeInfoFile, $serviceConfigFile;
    $info = zng_read_json($bridgeInfoFile, array());
    if (!empty($info["network"]["channel"])) {
        return array((int) $info["network"]["channel"], "coordinator");
    }
    $cfg = is_file($serviceConfigFile) ? @yaml_parse_file($serviceConfigFile) : false;
    if (is_array($cfg) && !empty($cfg["advanced"]["channel"])) {
        return array((int) $cfg["advanced"]["channel"], "configuration");
    }
    return array(11, "default");
}

/**
 * 2.4 GHz WLAN channel of this LoxBerry (only if it is connected by WLAN),
 * from "iw dev". Returns array(channel, interface) or array(0, "").
 */
function zng_wifi_channel()
{
    $out = (string) @shell_exec("iw dev 2>/dev/null");
    $iface = "";
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^\s*Interface\s+(\S+)/', $line, $m)) {
            $iface = $m[1];
        } elseif (preg_match('/^\s*channel\s+([0-9]+)\s+\(([0-9]+) MHz/', $line, $m) && (int) $m[2] < 2500) {
            return array((int) $m[1], $iface);
        }
    }
    return array(0, "");
}

/**
 * Do a Zigbee channel (2 MHz wide, 2405 + 5 * (ch - 11) MHz) and a 2.4 GHz
 * WLAN channel (about 20 MHz wide, 2412 + 5 * (ch - 1) MHz, channel 14 at
 * 2484 MHz) overlap? Returns "conflict", "near" or "ok".
 */
function zng_wifi_overlap($zigbee, $wifi)
{
    if (!$zigbee || !$wifi) {
        return "ok";
    }
    $fz = 2405 + 5 * ($zigbee - 11);
    $fw = $wifi == 14 ? 2484 : 2412 + 5 * ($wifi - 1);
    $distance = abs($fz - $fw);
    if ($distance <= 11) {
        return "conflict";
    }
    if ($distance <= 16) {
        return "near";
    }
    return "ok";
}

/**
 * Predecessor plugins (the original Zigbee2Mqtt and Zigbee2Lox, the former
 * name of this plugin) that are still installed. All use the same adapter, so
 * only one of them may run.
 */
function zng_predecessor_plugins()
{
    global $predecessorPlugins;
    $list = array();
    foreach ($predecessorPlugins as $folder => $plugin) {
        if (!is_dir(LBHOMEDIR . "/config/plugins/" . $folder)) {
            continue;
        }
        $active = trim((string) shell_exec("systemctl is-active " . escapeshellarg($plugin["service"]) . " 2>/dev/null")) === "active";
        $list[] = array("title" => $plugin["title"], "installed" => true, "active" => $active);
    }
    return $list;
}

/**
 * Settings of the LoxBerry MQTT gateway that matter for the Loxone templates
 */
function zng_gateway_info()
{
    $general = zng_read_json(LBSCONFIGDIR . "/general.json", array());
    $mqtt = isset($general["Mqtt"]) ? $general["Mqtt"] : array();
    $gateway = zng_read_json(LBSCONFIGDIR . "/mqttgateway.json", array());
    return array(
        "udpinport" => isset($mqtt["Udpinport"]) ? (int) $mqtt["Udpinport"] : 11884,
        "version" => isset($mqtt["Gatewayversion"]) ? (int) $mqtt["Gatewayversion"] : 0,
        "autostart" => !isset($mqtt["Gatewayautostart"]) || is_enabled($mqtt["Gatewayautostart"]),
        "udpport" => isset($gateway["Main"]["udpport"]) ? (int) $gateway["Main"]["udpport"] : 0,
        "use_udp" => isset($gateway["Main"]["use_udp"]) && is_enabled($gateway["Main"]["use_udp"]),
        "use_http" => !isset($gateway["Main"]["use_http"]) || is_enabled($gateway["Main"]["use_http"]),
        "convert_booleans" => !isset($gateway["Main"]["convert_booleans"]) || is_enabled($gateway["Main"]["convert_booleans"]),
    );
}

/**
 * State of the systemd service: pid (0 if stopped) and start time
 */
function zng_service_state($service)
{
    $pid = (int) trim((string) shell_exec("systemctl show --property MainPID --value " . escapeshellarg($service) . " 2>/dev/null"));
    $since = "";
    $sinceTs = 0;
    if ($pid > 0) {
        $since = trim((string) shell_exec("systemctl show --property ActiveEnterTimestamp --value " . escapeshellarg($service) . " 2>/dev/null"));
        $sinceTs = $since !== "" ? (int) strtotime($since) : 0;
    }
    return array("pid" => $pid, "since" => $since, "sinceTs" => $sinceTs);
}

/**
 * Broker credentials: those of the MQTT gateway or of the own broker
 */
function zng_broker_credentials($mqttcfg)
{
    if (is_enabled($mqttcfg->usemqttgateway)) {
        return mqtt_connectiondetails();
    }
    return array(
        "brokerhost" => $mqttcfg->server,
        "brokerport" => $mqttcfg->port,
        "brokeruser" => $mqttcfg->username,
        "brokerpass" => $mqttcfg->password,
    );
}

/* ==================================================================
 * Loxone templates
 * ================================================================== */

/**
 * Name used in templates: ZIGBEE_<NAME>_<PROPERTY>, the counterpart of
 * MATTER_<N>_<E>_<TOPIC> in Matter2Lox
 */
function zng_title($device, $property)
{
    $text = strtr($device . "_" . $property, array("ä" => "ae", "ö" => "oe", "ü" => "ue", "ß" => "ss", "Ä" => "Ae", "Ö" => "Oe", "Ü" => "Ue"));
    $name = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $text));
    return "ZIGBEE_" . trim($name, "_");
}

/**
 * Flattens the exposes of a device into a list of single values
 */
function zng_flatten_exposes($exposes)
{
    $result = array();
    foreach ((array) $exposes as $expose) {
        if (isset($expose["features"]) && is_array($expose["features"]) && (!isset($expose["type"]) || $expose["type"] != "composite")) {
            $result = array_merge($result, zng_flatten_exposes($expose["features"]));
        } elseif (isset($expose["property"]) && isset($expose["type"]) && in_array($expose["type"], array("binary", "numeric", "enum", "text"))) {
            $result[] = $expose;
        }
    }
    return $result;
}

/**
 * True if the MQTT gateway turns the value into 1/0 when "convert booleans"
 * is on (LoxBerry is_enabled() / is_disabled()). Other binary values like
 * LOCK/UNLOCK or OPEN/CLOSE arrive as text.
 */
function zng_gateway_boolean($value)
{
    if (is_bool($value)) {
        return true;
    }
    return in_array(strtolower((string) $value), array("true", "false", "on", "off", "yes", "no", "1", "0", "enabled", "disabled", "enable", "disable"), true);
}

/**
 * Inputs and outputs of all devices, ready for the frontend and the templates
 */
function zng_device_ios($base, $devices, $availability)
{
    $list = array();
    foreach (zng_state_devices($devices) as $device) {
        $name = $device["friendly_name"];
        $topic = $base . "/" . $name;
        $entry = array(
            "name" => $name,
            "model" => isset($device["definition"]["model"]) ? $device["definition"]["model"] : "",
            "vendor" => isset($device["definition"]["vendor"]) ? $device["definition"]["vendor"] : "",
            "inputs" => array(),
            "outputs" => array(),
        );
        $seen = array();
        foreach (zng_flatten_exposes(isset($device["definition"]["exposes"]) ? $device["definition"]["exposes"] : array()) as $e) {
            $property = $e["property"];
            if (isset($seen[$property]) || $property === "action") {
                continue;
            }
            $seen[$property] = true;
            $access = isset($e["access"]) ? (int) $e["access"] : 1;
            $unit = isset($e["unit"]) ? $e["unit"] : "";
            if ($access & 1) {
                $input = array(
                    "title" => zng_title($name, $property),
                    "property" => $property,
                    "type" => $e["type"],
                    "vi" => zng_gateway_name($topic . "/" . $property),
                    "udp" => $topic . "/" . $property,
                    "unit" => $unit,
                );
                $on = isset($e["value_on"]) ? $e["value_on"] : true;
                $off = isset($e["value_off"]) ? $e["value_off"] : false;
                if ($e["type"] == "binary" && zng_gateway_boolean($on) && zng_gateway_boolean($off)) {
                    $input["analog"] = false;
                    $input["min"] = 0;
                    $input["max"] = 1;
                } elseif ($e["type"] == "numeric") {
                    $input["analog"] = true;
                    $input["min"] = isset($e["value_min"]) ? $e["value_min"] : -2147483647;
                    $input["max"] = isset($e["value_max"]) ? $e["value_max"] : 2147483647;
                } else {
                    // enum, text and binary values like LOCK/UNLOCK arrive as
                    // text - Loxone cannot read them from a UDP input
                    $input["text"] = true;
                }
                $entry["inputs"][] = $input;
            }
            if ($access & 2) {
                $set = $topic . "/set/" . $property;
                $title = zng_title($name, $property);
                if ($e["type"] == "binary") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => false,
                        "on" => zng_udp_command($set, zng_payload(isset($e["value_on"]) ? $e["value_on"] : "ON")),
                        "off" => zng_udp_command($set, zng_payload(isset($e["value_off"]) ? $e["value_off"] : "OFF")));
                } elseif ($e["type"] == "numeric") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => true,
                        "on" => zng_udp_command($set, "<v>"), "off" => "");
                } elseif ($e["type"] == "enum" && isset($e["values"]) && count($e["values"]) <= 12) {
                    foreach ($e["values"] as $value) {
                        $entry["outputs"][] = array("title" => zng_title($name, $property . "_" . $value), "property" => $property,
                            "analog" => false, "on" => zng_udp_command($set, zng_payload($value)), "off" => "");
                    }
                }
            }
        }
        // button presses: one pulse per press (counter, reset by the gateway)
        foreach (zng_action_values($device) as $value) {
            $entry["inputs"][] = array("title" => zng_title($name, "aktion_" . $value), "property" => "aktion/" . $value,
                "type" => "action", "vi" => zng_gateway_name($topic . "/aktion/" . $value),
                "udp" => $topic . "/aktion/" . $value, "unit" => "", "analog" => false, "min" => 0, "max" => 1);
        }
        if ($availability) {
            $entry["inputs"][] = array("title" => zng_title($name, "erreichbar"), "property" => "erreichbar",
                "type" => "binary", "vi" => zng_gateway_name($topic . "/erreichbar"),
                "udp" => $topic . "/erreichbar", "unit" => "", "analog" => false, "min" => 0, "max" => 1);
        }
        $list[] = $entry;
    }
    return $list;
}

/**
 * Payload as zigbee2mqtt expects it on .../set/<property>
 */
function zng_payload($value)
{
    if (is_bool($value)) {
        return $value ? "true" : "false";
    }
    return (string) $value;
}

/**
 * Command for the UDP input of the MQTT gateway. The JSON form keeps topics
 * with spaces intact - the plain form "publish <topic> <value>" is split at
 * every space by the gateway.
 */
function zng_udp_command($topic, $value)
{
    return json_encode(array("topic" => $topic, "value" => $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function zng_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Virtual UDP input for the values the MQTT gateway sends via UDP.
 * The gateway bundles "MQTT: topic/property=value " pairs into one packet,
 * so every command looks for "<topic>/<property>=".
 */
function zng_xml_virtual_in_udp($title, $port, $inputs)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp Title="' . zng_x($title) . '" Comment="Zigbee2MqttNG" Address="" Port="' . (int) $port . '">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    foreach ($inputs as $in) {
        if (!empty($in["text"])) {
            continue;
        }
        $min = $in["min"];
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . zng_x($in["title"]) . '" ';
        $o .= 'Comment="' . zng_x($in["udp"]) . '" ';
        $o .= 'Address="" ';
        $o .= 'Check="' . zng_x($in["udp"] . '=\v') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($in["analog"] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" ';
        $o .= 'MinVal="' . zng_x($min) . '" ';
        $o .= 'MaxVal="' . zng_x($in["max"]) . '" ';
        $o .= 'Unit="' . zng_x('<v.1>' . ($in["unit"] !== "" ? ' ' . $in["unit"] : '')) . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return $o;
}

/**
 * Virtual output: commands go as {"topic":...,"value":...} to the UDP input
 * of the MQTT gateway on the LoxBerry.
 */
function zng_xml_virtual_out($title, $address, $outputs)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut Title="' . zng_x($title) . '" Comment="Zigbee2MqttNG" Address="' . zng_x($address) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($outputs as $out) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . zng_x($out["title"]) . '" ';
        $o .= 'Comment="" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . zng_x($out["on"]) . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . zng_x($out["off"]) . '" CmdOffHTTP="" CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($out["analog"] ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}
