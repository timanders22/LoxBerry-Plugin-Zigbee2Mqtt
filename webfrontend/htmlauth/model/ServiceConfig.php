<?php
/**
 * Zigbee2Mqtt service configuration
 */
class ServiceConfig {
    
    /**
     * Allow to add new devices
     *  @var bool */
    public $permitJoin = false;

    /**
     * Path to the zigbee device 
     * @var string */
    public $port = '';
    
    /**
     * Adapter type
     * @var string
     */
    public $adapter = '';
    /**
     * Serial baudrate. Empty string means: leave the value in
     * configuration.yaml untouched.
     * @var string
     */
    public $baudrate = '';

    /**
     * Serial hardware flow control. Empty string means: leave the value in
     * configuration.yaml untouched; "true" / "false" set it explicitly.
     * @var string
     */
    public $rtscts = '';

    /**
     * Enable zigbee2mqtt ui
     * @var bool
     */
    public $enableUI = false;

    /**
     * Port of the zigbee2mqtt ui
     * @var string
     */
    public $frontendPort = '8881';

    /**
     * Zigbee channel (11-26). Empty string means: leave the value in
     * configuration.yaml untouched.
     * @var string
     */
    public $channel = '';

    /**
     * Publish the availability (online/offline) of every device
     * @var bool
     */
    public $availability = true;
    /**
     * Creates a new instance
     */
    public function __construct() {
    }


    /**
     * Loads the config
     */
    public static function load() {
        $configfile = LBPCONFIGDIR . "/service.json";
        $data = json_decode(file_get_contents($configfile), true);
        $class = new ServiceConfig();
        foreach ($data as $key => $value) $class->{$key} = $value;
        return $class;
    }

    /**
     * Saves the config
     */
    public function save() {
        $configfile = LBPCONFIGDIR . "/service.json";
        file_put_contents($configfile, $this->toJson());
    }

    /**
     * Creates a json string out of the config
     */
     public function toJson() {
         return json_encode($this,JSON_PRETTY_PRINT);
     }

    

}