<?php
/**
 * Helpers shared by update-config.php and the web frontend.
 *
 * Expects defines.php to be loaded.
 */

/**
 * Reads a json file, returns $default when it is missing or broken
 */
function z2l_read_json($file, $default = null)
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
function z2l_write_if_changed($file, $content)
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
function z2l_topic_ok($name)
{
    return is_string($name) && $name !== "" && strpbrk($name, "+#") === false;
}

/**
 * Subscription lines for the MQTT gateway: only the state topics of devices
 * and groups - the large bridge/* messages (device list, logging, ...) stay
 * away from the Miniserver.
 * Must give the same result as subscriptionLines() in zigbee2lox_extension.mjs.
 */
function z2l_subscription_lines($base, $devices, $groups, $availability)
{
    $lines = array($base . "/bridge/state");
    foreach ((array) $devices as $device) {
        if (!isset($device["friendly_name"]) || (isset($device["type"]) && $device["type"] == "Coordinator")) {
            continue;
        }
        if (!z2l_topic_ok($device["friendly_name"])) {
            continue;
        }
        $lines[] = $base . "/" . $device["friendly_name"];
        if ($availability) {
            $lines[] = $base . "/" . $device["friendly_name"] . "/availability";
        }
    }
    foreach ((array) $groups as $group) {
        if (isset($group["friendly_name"]) && z2l_topic_ok($group["friendly_name"])) {
            $lines[] = $base . "/" . $group["friendly_name"];
        }
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Lists serial devices. Paths below /dev/serial/by-id never change, whereas
 * /dev/ttyACM0 and /dev/ttyACM1 may swap on every boot as soon as a second
 * stick (e.g. a Thread radio for Matter) is plugged in.
 */
function z2l_serial_ports()
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
function z2l_port_unstable($port)
{
    return (bool) preg_match('#^/dev/tty(ACM|USB)[0-9]+$#', (string) $port);
}

/**
 * Channel of a Thread operational dataset (hex TLVs). TLV type 0 is the
 * channel: one byte channel page, two bytes channel. Returns 0 if not found.
 */
function z2l_dataset_channel($hex)
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
function z2l_thread_channel()
{
    global $matter2loxConfigFile;
    $files = array_unique(array_merge(array($matter2loxConfigFile), glob(LBHOMEDIR . "/config/plugins/matter2lox*/matter2lox.json") ?: array()));
    foreach ($files as $file) {
        $cfg = z2l_read_json($file, array());
        if (!empty($cfg["thread_dataset"])) {
            $channel = z2l_dataset_channel($cfg["thread_dataset"]);
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
        $channel = z2l_dataset_channel(trim($body, " \t\r\n\"'"));
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
function z2l_zigbee_channel()
{
    global $bridgeInfoFile, $serviceConfigFile;
    $info = z2l_read_json($bridgeInfoFile, array());
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
 * State of the original Zigbee2Mqtt plugin. Both use the same adapter, so
 * only one of them may run.
 */
function z2l_original_plugin()
{
    global $originalPluginFolder, $originalServiceName;
    $installed = is_dir(LBHOMEDIR . "/config/plugins/" . $originalPluginFolder);
    $active = trim((string) shell_exec("systemctl is-active " . escapeshellarg($originalServiceName) . " 2>/dev/null")) === "active";
    return array("installed" => $installed, "active" => $active);
}

/**
 * Settings of the LoxBerry MQTT gateway that matter for the Loxone templates
 */
function z2l_gateway_info()
{
    $general = z2l_read_json(LBSCONFIGDIR . "/general.json", array());
    $mqtt = isset($general["Mqtt"]) ? $general["Mqtt"] : array();
    $gateway = z2l_read_json(LBSCONFIGDIR . "/mqttgateway.json", array());
    return array(
        "udpinport" => isset($mqtt["Udpinport"]) ? (int) $mqtt["Udpinport"] : 11884,
        "version" => isset($mqtt["Gatewayversion"]) ? (int) $mqtt["Gatewayversion"] : 0,
        "udpport" => isset($gateway["Main"]["udpport"]) ? (int) $gateway["Main"]["udpport"] : 0,
        "use_udp" => isset($gateway["Main"]["use_udp"]) && is_enabled($gateway["Main"]["use_udp"]),
        "use_http" => !isset($gateway["Main"]["use_http"]) || is_enabled($gateway["Main"]["use_http"]),
        "convert_booleans" => !isset($gateway["Main"]["convert_booleans"]) || is_enabled($gateway["Main"]["convert_booleans"]),
    );
}

/* ==================================================================
 * Loxone templates
 * ================================================================== */

/**
 * Name of the virtual input the MQTT gateway sends to via HTTP:
 * "/", " " and "%" become "_" (same rule as the gateway)
 */
function z2l_vi_name($topic)
{
    return str_replace(array("/", " ", "%"), "_", $topic);
}

/**
 * Name used in templates: ZIGBEE_<NAME>_<PROPERTY>, the counterpart of
 * MATTER_<N>_<E>_<TOPIC> in Matter2Lox
 */
function z2l_title($device, $property)
{
    $text = strtr($device . "_" . $property, array("ä" => "ae", "ö" => "oe", "ü" => "ue", "ß" => "ss", "Ä" => "Ae", "Ö" => "Oe", "Ü" => "Ue"));
    $name = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $text));
    return "ZIGBEE_" . trim($name, "_");
}

/**
 * Flattens the exposes of a device into a list of single values
 */
function z2l_flatten_exposes($exposes)
{
    $result = array();
    foreach ((array) $exposes as $expose) {
        if (isset($expose["features"]) && is_array($expose["features"]) && (!isset($expose["type"]) || $expose["type"] != "composite")) {
            $result = array_merge($result, z2l_flatten_exposes($expose["features"]));
        } elseif (isset($expose["property"]) && isset($expose["type"]) && in_array($expose["type"], array("binary", "numeric", "enum", "text"))) {
            $result[] = $expose;
        }
    }
    return $result;
}

/**
 * Inputs and outputs of all devices, ready for the frontend and the templates
 */
function z2l_device_ios($base, $devices, $availability)
{
    $list = array();
    foreach ((array) $devices as $device) {
        if (!isset($device["friendly_name"]) || (isset($device["type"]) && $device["type"] == "Coordinator")) {
            continue;
        }
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
        foreach (z2l_flatten_exposes(isset($device["definition"]["exposes"]) ? $device["definition"]["exposes"] : array()) as $e) {
            $property = $e["property"];
            if (isset($seen[$property])) {
                continue;
            }
            $seen[$property] = true;
            $access = isset($e["access"]) ? (int) $e["access"] : 1;
            $unit = isset($e["unit"]) ? $e["unit"] : "";
            if ($access & 1) {
                $input = array(
                    "title" => z2l_title($name, $property),
                    "property" => $property,
                    "type" => $e["type"],
                    "vi" => z2l_vi_name($topic . "_" . $property),
                    "udp" => $topic . "/" . $property,
                    "unit" => $unit,
                );
                if ($e["type"] == "binary") {
                    $input["analog"] = false;
                    $input["min"] = 0;
                    $input["max"] = 1;
                } elseif ($e["type"] == "numeric") {
                    $input["analog"] = true;
                    $input["min"] = isset($e["value_min"]) ? $e["value_min"] : -2147483647;
                    $input["max"] = isset($e["value_max"]) ? $e["value_max"] : 2147483647;
                } else {
                    // enum and text arrive as text - Loxone cannot read them from a UDP input
                    $input["text"] = true;
                }
                $entry["inputs"][] = $input;
            }
            if ($access & 2) {
                $set = "publish " . $topic . "/set/" . $property . " ";
                $title = z2l_title($name, $property);
                if ($e["type"] == "binary") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => false,
                        "on" => $set . z2l_payload(isset($e["value_on"]) ? $e["value_on"] : "ON"),
                        "off" => $set . z2l_payload(isset($e["value_off"]) ? $e["value_off"] : "OFF"));
                } elseif ($e["type"] == "numeric") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => true,
                        "on" => $set . "<v>", "off" => "");
                } elseif ($e["type"] == "enum" && isset($e["values"]) && count($e["values"]) <= 12) {
                    foreach ($e["values"] as $value) {
                        $entry["outputs"][] = array("title" => z2l_title($name, $property . "_" . $value), "property" => $property,
                            "analog" => false, "on" => $set . z2l_payload($value), "off" => "");
                    }
                }
            }
        }
        if ($availability) {
            $entry["inputs"][] = array("title" => z2l_title($name, "availability"), "property" => "availability",
                "type" => "binary", "vi" => z2l_vi_name($topic . "/availability_state"),
                "udp" => $topic . "/availability/state", "unit" => "", "analog" => false, "min" => 0, "max" => 1);
        }
        $list[] = $entry;
    }
    return $list;
}

/**
 * Payload as zigbee2mqtt expects it on .../set/<property>
 */
function z2l_payload($value)
{
    if (is_bool($value)) {
        return $value ? "true" : "false";
    }
    return (string) $value;
}

function z2l_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Virtual UDP input for the values the MQTT gateway sends via UDP.
 * The gateway bundles "MQTT: topic/property=value " pairs into one packet,
 * so every command looks for "<topic>/<property>=".
 */
function z2l_xml_virtual_in_udp($title, $port, $inputs)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp Title="' . z2l_x($title) . '" Comment="Zigbee2Lox" Address="" Port="' . (int) $port . '">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    foreach ($inputs as $in) {
        if (!empty($in["text"])) {
            continue;
        }
        $min = $in["min"];
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . z2l_x($in["title"]) . '" ';
        $o .= 'Comment="' . z2l_x($in["udp"]) . '" ';
        $o .= 'Address="" ';
        $o .= 'Check="' . z2l_x($in["udp"] . '=\v') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($in["analog"] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" ';
        $o .= 'MinVal="' . z2l_x($min) . '" ';
        $o .= 'MaxVal="' . z2l_x($in["max"]) . '" ';
        $o .= 'Unit="' . z2l_x('<v.1>' . ($in["unit"] !== "" ? ' ' . $in["unit"] : '')) . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return $o;
}

/**
 * Virtual output: commands go as "publish <topic>/set/<property> <value>"
 * to the UDP input of the MQTT gateway on the LoxBerry.
 */
function z2l_xml_virtual_out($title, $address, $outputs)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut Title="' . z2l_x($title) . '" Comment="Zigbee2Lox" Address="' . z2l_x($address) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($outputs as $out) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . z2l_x($out["title"]) . '" ';
        $o .= 'Comment="" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . z2l_x($out["on"]) . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . z2l_x($out["off"]) . '" CmdOffHTTP="" CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($out["analog"] ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}
