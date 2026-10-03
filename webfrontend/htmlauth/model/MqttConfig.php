<?php

/**
 * MQTT Configuration class
 */
class MqttConfig
{

    /**
     * Use the mqtt-gateway mqtt server instead of a custom mqtt server
     * @var bool
     */

    public $usemqttgateway = false;
    /**
     * The mqtt topic
     * @var string
     */
    public $topic = '';

    /**
     * The mqtt server username
     *  @var string */
    public $username = '';

    /**
     * The mqtt server password
     * @var string */
    public $password = '';

    /**
     * The mqtt server url
     *  @var string */
    public $server = '';

    /**
     * The mqtt server port
     * @var string */
    public $port = '';

    /**
     * Register mqtt topic on mqtt gateway
     */
    public $registerMqttTopic = false;

    /**
     * What is registered on the mqtt gateway: "devices" (only the state
     * topics of devices and groups) or "all" (<topic>/#)
     * @var string
     */
    public $forwardMode = 'devices';

    /**
     * Publish doors and locks under haus/tuer/<name>/offen|verriegelt
     * (house convention shared with Matter2Lox)
     * @var bool
     */
    public $hausTopics = false;

    /**
     * Creats a new instance
     */
    public function __construct()
    {
    }

    /***
     * Loads the configuration and creates a new instance of the class.
     * Only known settings are taken over.
     */
    public static function load()
    {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        $data = json_decode(@file_get_contents($mqttconfigfile), true);
        $class = new MqttConfig();
        foreach ((is_array($data) ? $data : array()) as $key => $value) {
            if (property_exists($class, $key)) {
                $class->{$key} = $value;
            }
        }
        return $class;
    }

    /**
     * Checks the values. Returns a list of language keys of the errors.
     */
    public function validate()
    {
        $errors = array();
        $topic = (string) $this->topic;
        if ($topic === "" || strpbrk($topic, "+#") !== false || strpos($topic, "//") !== false
            || $topic[0] === "/" || substr($topic, -1) === "/" || preg_match('/\s/', $topic)) {
            $errors[] = "Mqtt.ValInvalidTopic";
        }
        if (!is_enabled($this->usemqttgateway)) {
            if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', (string) $this->server)) {
                $errors[] = "Mqtt.ValInvalidServer";
            }
            if (!preg_match('/^[0-9]{1,5}$/', (string) $this->port) || (int) $this->port < 1 || (int) $this->port > 65535) {
                $errors[] = "Mqtt.ValInvalidPort";
            }
        }
        if (!in_array($this->forwardMode, array("devices", "all"), true)) {
            $errors[] = "Mqtt.ValForwardMode";
        }
        return $errors;
    }

    /**
     * The password is never sent to the browser. An empty password field
     * keeps the saved password, unless the user name was emptied as well.
     */
    public function keepFrom(MqttConfig $saved)
    {
        if ((string) $this->password === "" && (string) $this->username !== "") {
            $this->password = $saved->password;
        }
    }

    /**
     * Data for the form: without the password
     */
    public function toFormJson()
    {
        $data = get_object_vars($this);
        $data["password"] = "";
        $data["passwordSet"] = (string) $this->password !== "";
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Saves the instance to the configuration file
     */
    public function save()
    {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        file_put_contents($mqttconfigfile, $this->toJson());
    }

    /**
     * Creates a json string out of the class
     */
    public function toJson()
    {
        return json_encode($this, JSON_PRETTY_PRINT);
    }
}
