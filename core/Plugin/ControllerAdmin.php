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
namespace Piwik\Plugin;

use Piwik\Config as PiwikConfig;
use Piwik\Menu\MenuAdmin;
use Piwik\Menu\MenuTop;
use Piwik\Notification;
use Piwik\Notification\Manager as NotificationManager;
use Piwik\Piwik;
use Piwik\Url;
use Piwik\Version;
use Piwik\View;

/**
 * Base class of plugin controllers that provide administrative functionality.
 * 
 * See {@link Controller} to learn more about Piwik controllers.
 * 
 * @package Piwik
 */
abstract class ControllerAdmin extends Controller
{
    private static function notifyWhenTrackingStatisticsDisabled()
    {
        $statsEnabled = PiwikConfig::getInstance()->Tracker['record_statistics'];
        if ($statsEnabled == "0") {
            $notification = new Notification(Piwik::translate('General_StatisticsAreNotRecorded'));
            $notification->context = Notification::CONTEXT_INFO;
            Notification\Manager::notify('ControllerAdmin_StatsAreNotRecorded', $notification);
        }
    }

    private static function notifyAnyInvalidPlugin()
    {
        $missingPlugins = \Piwik\Plugin\Manager::getInstance()->getMissingPlugins();
        if (empty($missingPlugins)) {
            return;
        }

        if (!Piwik::isUserIsSuperUser()) {
            return;
        }
        $pluginsLink = Url::getCurrentQueryStringWithParametersModified(array(
            'module' => 'CorePluginsAdmin', 'action' => 'plugins'
        ));
        $invalidPluginsWarning = Piwik::translate('CoreAdminHome_InvalidPluginsWarning', array(
                self::getPiwikVersion(),
                '<strong>' . implode('</strong>,&nbsp;<strong>', $missingPlugins) . '</strong>'))
            . Piwik::translate('CoreAdminHome_InvalidPluginsYouCanUninstall', array(
                '<a href="' . $pluginsLink . '"/>',
                '</a>'
        ));

        $notification = new Notification($invalidPluginsWarning);
        $notification->raw = true;
        $notification->context = Notification::CONTEXT_WARNING;
        $notification->title = Piwik::translate('General_Warning') . ':';
        Notification\Manager::notify('ControllerAdmin_InvalidPluginsWarning', $notification);
    }

    /**
     * Calls {@link setBasicVariablesView()} and {@link setBasicVariablesAdminView()}
     * using the supplied view.
     *
     * @param View $view
     * @api
     */
    protected function setBasicVariablesView($view)
    {
        parent::setBasicVariablesView($view);

        self::setBasicVariablesAdminView($view);
    }

    /**
     * @ignore
     */
    static public function displayWarningIfConfigFileNotWritable()
    {
        $isConfigFileWritable = PiwikConfig::getInstance()->isFileWritable();

        if (!$isConfigFileWritable) {
            $exception = PiwikConfig::getInstance()->getConfigNotWritableException();
            $message = $exception->getMessage();

            $notification = new Notification($message);
            $notification->raw     = true;
            $notification->context = Notification::CONTEXT_WARNING;
            Notification\Manager::notify('ControllerAdmin_ConfigNotWriteable', $notification);
        }
    }

    /**
     * Assigns view properties that would be useful to views that render admin pages.
     *
     * Assigns the following variables:
     *
     * - **statisticsNotRecorded** - Set to true if the `[Tracker] record_statistics` INI
     *                               config is `0`. If not `0`, this variable will not be defined.
     * - **topMenu** - The result of `MenuTop::getInstance()->getMenu()`.
     * - **currentAdminMenuName** - The currently selected admin menu name.
     * - **enableFrames** - The value of the `[General] enable_framed_pages` INI config option. If
     *                    true, {@link Piwik\View::setXFrameOptions()} is called on the view.
     * - **isSuperUser** - Whether the current user is a superuser or not.
     * - **usingOldGeoIPPlugin** - Whether this Piwik install is currently using the old GeoIP
     *                             plugin or not.
     * - **invalidPluginsWarning** - Set if some of the plugins to load (determined by INI configuration)
     *                               are invalid or missing.
     * - **phpVersion** - The current PHP version.
     * - **phpIsNewEnough** - Whether the current PHP version is new enough to run Piwik.
     * - **adminMenu** - The result of `MenuAdmin::getInstance()->getMenu()`.
     *
     * @param View $view
     * @api
     */
    static public function setBasicVariablesAdminView(View $view)
    {
        self::notifyWhenTrackingStatisticsDisabled();

        $view->topMenu = MenuTop::getInstance()->getMenu();
        $view->currentAdminMenuName = MenuAdmin::getInstance()->getCurrentAdminMenuName();

        $view->enableFrames = PiwikConfig::getInstance()->General['enable_framed_settings'];
        if (!$view->enableFrames) {
            $view->setXFrameOptions('sameorigin');
        }

        $view->isSuperUser = Piwik::isUserIsSuperUser();

        self::notifyAnyInvalidPlugin();

        self::checkPhpVersion($view);

        $adminMenu = MenuAdmin::getInstance()->getMenu();
        $view->adminMenu = $adminMenu;

        $view->notifications = NotificationManager::getAllNotificationsToDisplay();
        NotificationManager::cancelAllNonPersistent();
    }


    static protected function getPiwikVersion()
    {
        return "Piwik " . Version::VERSION;
    }

    /**
     * Check if the current PHP version is >= 5.3. If not, a warning is displayed
     * to the user.
     */
    private static function checkPhpVersion($view)
    {
        $view->phpVersion = PHP_VERSION;
        $view->phpIsNewEnough = version_compare($view->phpVersion, '5.3.0', '>=');
    }

    protected function getDefaultWebsiteId()
    {
        $sitesId = \Piwik\Plugins\SitesManager\API::getInstance()->getSitesIdWithAdminAccess();
        if (!empty($sitesId)) {
            return $sitesId[0];
        }
        return parent::getDefaultWebsiteId();
    }
}