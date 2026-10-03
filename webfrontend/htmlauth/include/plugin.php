<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/defines.php";

require __DIR__ . '/vendor/autoload.php';

/**
 * Plugin helper class
 */
class Plugin
{
    // Tabs of the house standard (Einstellungen, MQTT, Einbindung in Loxone,
    // Test, Logdateien) plus Geraete - like "Geraete anlernen" in Matter2Lox
    const SETTINGS = 1;
    const DEVICES = 2;
    const MQTT = 3;
    const LOXONE = 4;
    const TEST = 5;
    const LOG = 99;

    /**
     * Creates the page header
     * $L is globally available from defines.php
     * $navbar is globally available from loxberry
     */
    static function createHeader($activePage)
    {
        $template_title = "Zigbee2MqttNG";
        $helplink = "https://github.com/timanders22/LoxBerry-Plugin-Zigbee2MqttNG#readme";
        $helptemplate = "help.html";

        global $navbar;
        global $htmlhead;
        global $L;

        $navbar[self::SETTINGS]['Name'] = $L["Navbar.Settings"];
        $navbar[self::SETTINGS]['URL'] = 'index.php';
        $navbar[self::SETTINGS]['Script'] = array('common.js', 'index.js');

        $navbar[self::DEVICES]['Name'] = $L["Navbar.Devices"];
        $navbar[self::DEVICES]['URL'] = 'devices.php';
        $navbar[self::DEVICES]['Script'] = array('vendor/ace.js', 'vendor/vis-network.min.js', 'common.js', 'devices.js');

        $navbar[self::MQTT]['Name'] = $L["Navbar.Mqtt"];
        $navbar[self::MQTT]['URL'] = 'mqtt.php';
        $navbar[self::MQTT]['Script'] = array('common.js', 'mqtt.js');

        $navbar[self::LOXONE]['Name'] = $L["Navbar.Loxone"];
        $navbar[self::LOXONE]['URL'] = 'loxone.php';

        $navbar[self::TEST]['Name'] = $L["Navbar.Test"];
        $navbar[self::TEST]['URL'] = 'test.php';

        $navbar[self::LOG]['Name'] = $L["Navbar.Logfiles"];
        $navbar[self::LOG]['URL'] = 'log.php';

        foreach (array_keys($navbar) as $key) {
            $navbar[$key]['active'] = null;
        }
        $navbar[$activePage]['active'] = true;

        // scripts and styles of the active page go into the LoxBerry header
        $page = $navbar[$activePage];
        foreach (isset($page['Script']) ? (array) $page['Script'] : array() as $value) {
            $htmlhead .= '<script src="js/' . $value . '"></script>';
        }
        foreach (isset($page['CSS']) ? (array) $page['CSS'] : array() as $value) {
            $htmlhead .= '<link rel="stylesheet" href="css/' . $value . '">';
        }
        $htmlhead .= '<link rel="stylesheet" href="css/plugin.css">';

        // Creates the loxberry header
        LBWeb::lbheader($template_title, $helplink, $helptemplate);
    }

    /**
     * Initializes the plugin environment
     */
    static function initializeTwig()
    {
        global $lbptemplatedir;
        global $L;
        $loader = new \Twig\Loader\FilesystemLoader($lbptemplatedir);
        $twig = new \Twig\Environment($loader, [
            'cache' => "$lbptemplatedir/cache",
        ]);

        $filter = new \Twig\TwigFilter('trans', function ($string) use ($L) {
            return isset($L[$string]) ? $L[$string] : $string;
        });
        $twig->addFilter($filter);
        return $twig;
    }
}
