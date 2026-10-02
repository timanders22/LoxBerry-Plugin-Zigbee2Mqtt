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
     * Loads the configuration and creates a new instance of the class
     */
    public static function load()
    {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        $data = json_decode(file_get_contents($mqttconfigfile), true);
        $class = new MqttConfig();
        foreach ($data as $key => $value) $class->{$key} = $value;
        return $class;
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
