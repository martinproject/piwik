<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

namespace Piwik;

use Exception;

/**
 * Singleton that provides read & write access to Piwik's INI configuration.
 * 
 * This class reads and writes to the `config/config.ini.php` file. If config
 * options are missing from that file, this class will look for their default
 * values in `config/global.ini.php`.
 * 
 * ### Examples
 * 
 * **Getting a value:**
 *
 *     // read the minimum_memory_limit option under the [General] section
 *     $minValue = Config::getInstance()->General['minimum_memory_limit'];
 *
 * **Setting a value:**
 *
 *     // set the minimum_memory_limit option
 *     Config::getInstance()->General['minimum_memory_limit'] = 256;
 *     Config::getInstance()->forceSave();
 * 
 * **Setting an entire section:**
 * 
 *     Config::getInstance()->MySection = array('myoption' => 1);
 *     Config::getInstance()->forceSave();
 * 
 * @package Piwik
 * @subpackage Piwik_Config
 * @method static \Piwik\Config getInstance()
 */
class Config extends Singleton
{
    /**
     * Contains configuration files values
     *
     * @var array
     */
    protected $initialized = false;
    protected $configGlobal = array();
    protected $configLocal = array();
    protected $configCache = array();
    protected $pathGlobal = null;
    protected $pathLocal = null;

    /**
     * Constructor
     */
    protected function __construct()
    {
        $this->clear();
    }

    /**
     * @var boolean
     */
    protected $isTest = false;

    /**
     * Enable test environment
     *
     * @param string $pathLocal
     * @param string $pathGlobal
     */
    public function setTestEnvironment($pathLocal = null, $pathGlobal = null)
    {
        $this->isTest = true;

        $this->clear();

        if ($pathLocal) {
            $this->pathLocal = $pathLocal;
        }

        if ($pathGlobal) {
            $this->pathGlobal = $pathGlobal;
        }

        $this->init();

        // this proxy will not record any data in the production database.
        // this provides security for Piwik installs and tests were setup.
        if (isset($this->configGlobal['database_tests'])
            || isset($this->configLocal['database_tests'])
        ) {
            $this->__get('database_tests');
            $this->configCache['database'] = $this->configCache['database_tests'];
        }

        // Ensure local mods do not affect tests
        if (is_null($pathGlobal)) {
            $this->configCache['Debug'] = $this->configGlobal['Debug'];
            $this->configCache['branding'] = $this->configGlobal['branding'];
            $this->configCache['mail'] = $this->configGlobal['mail'];
            $this->configCache['General'] = $this->configGlobal['General'];
            $this->configCache['Segments'] = $this->configGlobal['Segments'];
            $this->configCache['Tracker'] = $this->configGlobal['Tracker'];
            $this->configCache['Deletelogs'] = $this->configGlobal['Deletelogs'];
        }

        // for unit tests, we set that no plugin is installed. This will force
        // the test initialization to create the plugins tables, execute ALTER queries, etc.
        $this->configCache['PluginsInstalled'] = array('PluginsInstalled' => array());

        // DevicesDetection plugin is not yet enabled by default
        if (isset($configGlobal['Plugins'])) {
            $this->configCache['Plugins'] = $this->configGlobal['Plugins'];
            $this->configCache['Plugins']['Plugins'][] = 'DevicesDetection';
        }
        if (isset($configGlobal['Plugins_Tracker'])) {
            $this->configCache['Plugins_Tracker'] = $this->configGlobal['Plugins_Tracker'];
            $this->configCache['Plugins_Tracker']['Plugins_Tracker'][] = 'DevicesDetection';
        }

        // to avoid weird session error in travis
        $this->configCache['General']['session_save_handler'] = 'dbtables';

    }

    /**
     * Returns absolute path to the global configuration file
     *
     * @return string
     */
    protected static function getGlobalConfigPath()
    {
        return PIWIK_USER_PATH . '/config/global.ini.php';
    }

    /**
     * Returns absolute path to the local configuration file
     *
     * @return string
     */
    public static function getLocalConfigPath()
    {
        $path = self::getByDomainConfigPath();
        if ($path) {
            return $path;
        }

        return PIWIK_USER_PATH . '/config/config.ini.php';
    }

    private static function getLocalConfigInfoForHostname($hostname)
    {
        $perHostFilename  = $hostname . '.config.ini.php';
        $pathDomainConfig = PIWIK_USER_PATH . '/config/' . $perHostFilename;

        return array('file' => $perHostFilename, 'path' => $pathDomainConfig);
    }

    public function getConfigHostnameIfSet()
    {
        if ($this->getByDomainConfigPath() === false) {
            return false;
        }
        return $this->getHostname();
    }

    protected static function getByDomainConfigPath()
    {
        $host       = self::getHostname();
        $hostConfig = self::getLocalConfigInfoForHostname($host);

        if (Filesystem::isValidFilename($hostConfig['file'])
            && file_exists($hostConfig['path'])
        ) {
            return $hostConfig['path'];
        }
        return false;
    }

    protected static function getHostname()
    {
        $host = Url::getHost($checkIfTrusted = false); // Check trusted requires config file which is not ready yet
        return $host;
    }

    /**
     * If set, Piwik will use the hostname config no matter if it exists or not. Useful for instance if you want to
     * create a new hostname config:
     *
     *     $config = Config::getInstance();
     *     $config->forceUsageOfHostnameConfig('piwik.example.com');
     *     $config->save();
     *
     * @param string $hostname eg piwik.example.com
     *
     * @throws \Exception In case the domain contains not allowed characters
     */
    public function forceUsageOfLocalHostnameConfig($hostname)
    {
        $hostConfig = static::getLocalConfigInfoForHostname($hostname);

        if (!Filesystem::isValidFilename($hostConfig['file'])) {
            throw new Exception('Hostname is not valid');
        }

        $this->pathLocal   = $hostConfig['path'];
        $this->configLocal = array();
        $this->initialized = false;
        return $this->pathLocal;
    }

    /**
     * Returns `true` if the local configuration file is writable.
     *
     * @return bool
     */
    public function isFileWritable()
    {
        return is_writable($this->pathLocal);
    }

    /**
     * Clear in-memory configuration so it can be reloaded
     */
    public function clear()
    {
        $this->configGlobal = array();
        $this->configLocal = array();
        $this->configCache = array();
        $this->initialized = false;

        $this->pathGlobal = self::getGlobalConfigPath();
        $this->pathLocal = self::getLocalConfigPath();
    }

    /**
     * Read configuration from files into memory
     *
     * @throws Exception if local config file is not readable; exits for other errors
     */
    public function init()
    {
        $this->initialized = true;
        $reportError = !empty($GLOBALS['PIWIK_TRACKER_MODE']);

        // read defaults from global.ini.php
        if (!is_readable($this->pathGlobal) && $reportError) {
            Piwik_ExitWithMessage(Piwik::translate('General_ExceptionConfigurationFileNotFound', array($this->pathGlobal)));
        }

        $this->configGlobal = _parse_ini_file($this->pathGlobal, true);
        if (empty($this->configGlobal) && $reportError) {
            Piwik_ExitWithMessage(Piwik::translate('General_ExceptionUnreadableFileDisabledMethod', array($this->pathGlobal, "parse_ini_file()")));
        }

        if ($reportError) {
            $this->checkLocalConfigFound();
        }
        $this->configLocal = _parse_ini_file($this->pathLocal, true);
        if (empty($this->configLocal) && $reportError) {
            Piwik_ExitWithMessage(Piwik::translate('General_ExceptionUnreadableFileDisabledMethod', array($this->pathLocal, "parse_ini_file()")));
        }
    }

    public function checkLocalConfigFound()
    {
        if (!is_readable($this->pathLocal)) {
            throw new Exception(Piwik::translate('General_ExceptionConfigurationFileNotFound', array($this->pathLocal)));
        }
    }

    /**
     * Decode HTML entities
     *
     * @param mixed $values
     * @return mixed
     */
    protected function decodeValues($values)
    {
        if (is_array($values)) {
            foreach ($values as &$value) {
                $value = $this->decodeValues($value);
            }
            return $values;
        }
        return html_entity_decode($values, ENT_COMPAT, 'UTF-8');
    }

    /**
     * Encode HTML entities
     *
     * @param mixed $values
     * @return mixed
     */
    protected function encodeValues($values)
    {
        if (is_array($values)) {
            foreach ($values as &$value) {
                $value = $this->encodeValues($value);
            }
        } else {
            $values = htmlentities($values, ENT_COMPAT, 'UTF-8');
        }
        return $values;
    }

    /**
     * Returns a configuration value or section by name.
     *
     * @param string $name The value or section name.
     * @return string|array The requested value requested. Returned by reference.
     * @throws Exception If the value requested not found in either `config.ini.php` or
     *                   `global.ini.php`.
     * @api
     */
    public function &__get($name)
    {
        if (!$this->initialized) {
            $this->init();

            // must be called here, not in init(), since setTestEnvironment() calls init(). (this avoids
            // infinite recursion)
            Piwik::postTestEvent('Config.createConfigSingleton', array( $this ));
        }

        // check cache for merged section
        if (isset($this->configCache[$name])) {
            $tmp =& $this->configCache[$name];
            return $tmp;
        }

        $section = $this->getFromDefaultConfig($name);

        if (isset($this->configLocal[$name])) {
            // local settings override the global defaults
            $section = $section
                ? array_merge($section, $this->configLocal[$name])
                : $this->configLocal[$name];
        }

        if ($section === null) {
            throw new Exception("Error while trying to read a specific config file entry <strong>'$name'</strong> from your configuration files.</b>If you just completed a Piwik upgrade, please check that the file config/global.ini.php was overwritten by the latest Piwik version.");
        }

        // cache merged section for later
        $this->configCache[$name] = $this->decodeValues($section);
        $tmp =& $this->configCache[$name];

        return $tmp;
    }

    public function getFromDefaultConfig($name)
    {
        if (isset($this->configGlobal[$name])) {
            return $this->configGlobal[$name];
        }
        return null;
    }

    /**
     * Sets a configuration value or section.
     *
     * @param string $name This section name or value name to set.
     * @param mixed $value
     * @api
     */
    public function __set($name, $value)
    {
        $this->configCache[$name] = $value;
    }

    /**
     * Comparison function
     *
     * @param mixed $elem1
     * @param mixed $elem2
     * @return int;
     */
    public static function compareElements($elem1, $elem2)
    {
        if (is_array($elem1)) {
            if (is_array($elem2)) {
                return strcmp(serialize($elem1), serialize($elem2));
            }

            return 1;
        }

        if (is_array($elem2)) {
            return -1;
        }

        if ((string)$elem1 === (string)$elem2) {
            return 0;
        }

        return ((string)$elem1 > (string)$elem2) ? 1 : -1;
    }

    /**
     * Compare arrays and return difference, such that:
     *
     *     $modified = array_merge($original, $difference);
     *
     * @param array $original original array
     * @param array $modified modified array
     * @return array differences between original and modified
     */
    public function array_unmerge($original, $modified)
    {
        // return key/value pairs for keys in $modified but not in $original
        // return key/value pairs for keys in both $modified and $original, but values differ
        // ignore keys that are in $original but not in $modified

        return array_udiff_assoc($modified, $original, array(__CLASS__, 'compareElements'));
    }

    /**
     * Dump config
     *
     * @param array $configLocal
     * @param array $configGlobal
     * @param array $configCache
     * @return string
     */
    public function dumpConfig($configLocal, $configGlobal, $configCache)
    {
        $dirty = false;

        $output = "; <?php exit; ?> DO NOT REMOVE THIS LINE\n";
        $output .= "; file automatically generated or modified by Piwik; you can manually override the default values in global.ini.php by redefining them in this file.\n";

        if (!$configCache) {
            return false;
        }
        if ($configLocal) {
            foreach ($configLocal as $name => $section) {
                if (!isset($configCache[$name])) {
                    $configCache[$name] = $this->decodeValues($section);
                }
            }
        }

        $sectionNames = array_unique(array_merge(array_keys($configGlobal), array_keys($configCache)));

        foreach ($sectionNames as $section) {
            if (!isset($configCache[$section])) {
                continue;
            }

            // Only merge if the section exists in global.ini.php (in case a section only lives in config.ini.php)

            // get local and cached config
            $local = isset($configLocal[$section]) ? $configLocal[$section] : array();
            $config = $configCache[$section];

            // remove default values from both (they should not get written to local)
            if (isset($configGlobal[$section])) {
                $config = $this->array_unmerge($configGlobal[$section], $configCache[$section]);
                $local = $this->array_unmerge($configGlobal[$section], $local);
            }

            // if either local/config have non-default values and the other doesn't,
            // OR both have values, but different values, we must write to config.ini.php
            if (empty($local) xor empty($config)
                || (!empty($local)
                    && !empty($config)
                    && self::compareElements($config, $configLocal[$section]))
            ) {
                $dirty = true;
            }

            // no point in writing empty sections, so skip if the cached section is empty
            if (empty($config)) {
                continue;
            }

            $output .= "[$section]\n";

            foreach ($config as $name => $value) {
                $value = $this->encodeValues($value);

                if (is_numeric($name)) {
                    $name = $section;
                    $value = array($value);
                }

                if (is_array($value)) {
                    foreach ($value as $currentValue) {
                        $output .= $name . "[] = \"$currentValue\"\n";
                    }
                } else {
                    if (!is_numeric($value)) {
                        $value = "\"$value\"";
                    }
                    $output .= $name . ' = ' . $value . "\n";
                }
            }

            $output .= "\n";
        }

        if ($dirty) {
            return $output;
        }
        return false;
    }


    /**
     * Write user configuration file
     *
     * @param array $configLocal
     * @param array $configGlobal
     * @param array $configCache
     * @param string $pathLocal
     *
     * @throws Exception if config file not writable
     */
    protected function writeConfig($configLocal, $configGlobal, $configCache, $pathLocal)
    {
        if ($this->isTest) {
            return;
        }

        $output = $this->dumpConfig($configLocal, $configGlobal, $configCache);
        if ($output !== false) {
            $success = @file_put_contents($pathLocal, $output);
            if (!$success) {
                throw $this->getConfigNotWritableException();
            }
        }

        $this->clear();
    }

    /**
     * Writes the current configuration to the **config.ini.php** file. Only writes options whose
     * values are different from the default.
     * 
     * @api
     */
    public function forceSave()
    {
        $this->writeConfig($this->configLocal, $this->configGlobal, $this->configCache, $this->pathLocal);
    }

    /**
     * @throws \Exception
     */
    public function getConfigNotWritableException()
    {
        $path = "config/" . basename($this->pathLocal);
        return new Exception(Piwik::translate('General_ConfigFileIsNotWritable', array("(" . $path . ")", "")));
    }
}