<?php
namespace CM\Neos\ThemeModule\Controller;

use CM\Neos\ThemeModule\Service\Build;
use CM\Neos\ThemeModule\Service\Compile;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Mvc\Controller\ActionController;
use CM\Neos\ThemeModule\Domain\Model\Settings;
use CM\Neos\ThemeModule\Domain\Repository\SettingsRepository;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;

class BackendController extends ActionController
{
    /**
     * @Flow\Inject
     * @var SettingsRepository
     */
    protected $settingsRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\InjectConfiguration(package="CM.Neos.ThemeModule")
     * @var array
     */
    protected $configuration;

    /**
     * @Flow\Inject
     * @var Build
     */
    protected $buildService;

    /**
     * @Flow\Inject
     * @var Compile
     */
    protected $compile;

    /**
     * @Flow\Inject
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * Default index action
     */
    public function indexAction()
    {
        /** @var Settings $dbSettings */
        $activeSettings = $this->settingsRepository->findActive();

        if (!$activeSettings) {
            $activeSettings = new Settings();
        }

        $sites = $this->siteRepository->findOnline();

        $themeSettings = $this->buildService->buildThemeSettings();

        $fonts = $this->buildService->buildFontOptions();

        $this->view->assignMultiple([
            'sites' => $sites,
            'configuration' => $this->configuration,
            'settings' => $activeSettings,
            'themeSettings' => $themeSettings,
            'fonts' => $fonts
        ]);
    }

    /**
     * Update theme settings
     *
     * @param Settings $settings Custom theme setting object
     * @param array $customSettings Custom settings for the theme
     * @param Site $site for which the theme should be configured
     */
    public function updateAction(Settings $settings, $customSettings = [], Site $site = null)
    {
        $settings->setCustomSettings(json_encode($customSettings));

        if ($settings instanceof Settings && $this->persistenceManager->isNewObject($settings)) {
            $this->settingsRepository->add($settings);
        } elseif ($settings instanceof Settings) {
            $this->settingsRepository->update($settings);
        }

        $this->compile->compileScss($settings, $customSettings, $site);

        // Make sure all page caches get flushed
        $this->cacheManager->flushCachesByTag('DescendantOf_' . strtr('Neos.Neos:Page', '.:', '_-'), true);
        $this->cacheManager->flushCachesByTag('DescendantOf_' . strtr('Neos.NodeTypes:Page', '.:', '_-'), true);
        $this->cacheManager->flushCachesByTag('DescendantOf_' . strtr('Neos.Neos:Document', '.:', '_-'), true);

        $this->cacheManager->flushCachesByTag('NodeType_' . strtr('Neos.Neos:Page', '.:', '_-'), true);
        $this->cacheManager->flushCachesByTag('NodeType_' . strtr('Neos.NodeTypes:Page', '.:', '_-'), true);
        $this->cacheManager->flushCachesByTag('NodeType_' . strtr('Neos.Neos:Document', '.:', '_-'), true);

        $this->redirect('index');
    }

}
