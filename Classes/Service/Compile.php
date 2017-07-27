<?php
namespace CM\Neos\ThemeModule\Service;

use Leafo\ScssPhp\Compiler;
use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility;
use Neos\Neos\Domain\Model\Site;
use Neos\Utility\Unicode\Functions;
use CM\Neos\ThemeModule\Domain\Model\Font;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\SystemLoggerInterface;
use CM\Neos\ThemeModule\Domain\Model\Settings;
use CM\Neos\ThemeModule\FileUtility;
use Neos\Flow\ResourceManagement\ResourceManager;


class Compile
{

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\InjectConfiguration(package="CM.Neos.ThemeModule")
     * @var array
     */
    protected $configuration;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var Build
     */
    protected $buildService;

    /**
     * @Flow\Inject
     * @var CompilingEvaluator
     */
    protected $eelEvaluator;

    /**
     * Compile scss to css and add custom scss/css
     *
     * @param Settings $settings current settings
     * @param array $customSettings current custom settings
     * @param Site $site allows specifying a site to modify options in multi-site setups
     */
    public function compileScss(Settings $settings, $customSettings, Site $site = null)
    {

        $scssVars = array();
        $mainScssContent = '';
        $fonts = $this->buildService->buildFontOptions();
        $fontFaceCss = '';

        foreach ($customSettings as $group) {
            foreach ($group['type'] as $typeKey => $typeValue) {
                foreach ($typeValue as $element) {
                    if ($typeKey === 'font') {
                        // Render font name for scss variable
                        $scssVars[$element['scssVariableName']] = '"' . $element['value']['family'] . '", ' . $element['fontFallbackValue'];

                        $font = $this->findFontByName($element['value']['family'], $fonts);

                        $variantsArray = json_decode($element['value']['variants']);

                        // Render only local fonts into css, google/cdn fonts are added via fusion object and
                        // check if at least one font variant is available
                        if ($font->getFontSource() === Font::FONT_SOURCE_LOCAL && isset($variantsArray) && is_array($variantsArray) && count($variantsArray) > 0) {
                            $fontFaceCss .= $this->scssFontFace($font, $variantsArray);
                        }

                    } else {
                        $scssVars[$element['scssVariableName']] = $element['value'];
                    }
                }
            }
        }

        try {

            // get absolute path to scss folder
            $pathParts = Functions::parse_url($this->configuration['scss']['importPaths']);
            $scssAbsolutePath = FLOW_PATH_ROOT . 'Packages/Sites/' . $pathParts['host'] . '/Resources' . $pathParts['path'];
            $scssAbsolutePath = FileUtility::getUnixStylePath($scssAbsolutePath);

            $scss = new Compiler();
            $scss->setImportPaths($scssAbsolutePath);

            $scss->setFormatter($this->configuration['scss']['formatter']);
            $scss->setVariables($scssVars);

            $mainScssFileAndPath = FileUtility::concatenatePaths(array(
                $this->configuration['scss']['importPaths'],
                $this->configuration['scss']['mainScssFile']
            ));

            $mainScssContent .= FileUtility::getFileContents($mainScssFileAndPath);

            if ($settings->getCustomScss()) {
                // add custom scss code to the end of the file
                $mainScssContent = $mainScssContent . "\n" . $settings->getCustomScss();
            }

            // add fonts as @import rule to css
            if ($fontFaceCss) {
                $compiledCss = $fontFaceCss . "\n";
            } else {
                $compiledCss = '';
            }

            // compile scss to css
            $compiledCss .= $scss->compile($mainScssContent);

            if ($settings->getCustomCss()) {
                // add custom css code to the end of the file
                $compiledCss = $compiledCss . "\n" . $settings->getCustomCss();
            }

            FileUtility::writeStaticFile(
                $this->getParsedConfigurationParameter($this->configuration['scss']['outputPath'], $site),
                $this->getParsedConfigurationParameter($this->configuration['scss']['outputFilename'], $site),
                $compiledCss);

            $this->systemLogger->log('Scss successfully compiled');

        } catch (\Exception $e) {
            $this->systemLogger->logException($e, array('message' => 'Compiling scss was not successful'));
        }
    }

    /**
     * Evaluates input as eel expressions and returns the result
     *
     * @param $value
     * @param Site|null $site
     * @return mixed
     */
    protected function getParsedConfigurationParameter($value, Site $site = null)
    {
        if (is_string($value)) {
            $parameters = array_merge([], ['site' => $site]);
            try {
                return Utility::evaluateEelExpression($value, $this->eelEvaluator, $parameters);
            } catch (\Exception $e) {

            }
        }
        return $value;
    }

    /**
     * Find font by given Name
     *
     * @param string $fontfamily
     * @param array $fonts
     *
     * @return Font $font
     */
    public function findFontByName($fontfamily, $fonts)
    {
        $font = null;

        foreach ($fonts['options'] as $categoryFonts) {
            /** @var Font $categoryFont */
            foreach ($categoryFonts as $categoryFont) {
                if ($categoryFont->getFamily() === $fontfamily) {
                    return $categoryFont;
                }
            }
        }

        return $font;
    }

    /**
     * Build css font-face string
     *
     * @param Font $font The font to render a font-face
     * @param array $variants The font variants to render
     *
     * @return string
     */
    public function scssFontFace(Font $font, $variants)
    {
        $fontface = '';

        foreach ($variants as $variant) {
            $fontstyle = 'normal';

            switch ($variant) {
                case is_numeric($variant):
                    $fontweight = $variant;
                    break;

                case $variant === 'regular':
                    $fontweight = 'normal';
                    break;

                case (strpos($variant, 'italic') !== false):
                    $fontweight = substr($variant, 0, 3);
                    $fontstyle = "italic";
                    break;

                default:
                    $fontweight = 400;
            }

            $fontface .= '@font-face {';
            $fontface .= "font-family: '" . $font->getFamily() . "';";
            $fontface .= "font-style: " . $fontstyle . ";";
            $fontface .= "font-weight: " . $fontweight . ";";
            $fontface .= "src: local('" . $font->getFamily() . "'),";

            if (strpos($font->getFamily(), ' ') !== false) {
                $fontface .= " local('" . str_replace(' ', '', $font->getFamily()) . "'),";
            }

            foreach ($font->getFiles() as $fileKey => $file) {

                if ($fileKey === $variant) {
                    if (is_array($file)) {
                        $i = 0;
                        foreach ($file as $source) {
                            if ($i > 0) {
                                $fontface .= ',';
                            }
                            $fontface .= "url(" . $this->resourceManager->getPublicPackageResourceUriByPath($source) . ") format('" . pathinfo($this->resourceManager->getPublicPackageResourceUriByPath($source),
                                    PATHINFO_EXTENSION) . "')";
                            $i++;
                        }
                    } else {
                        $fontface .= "url(" . $this->resourceManager->getPublicPackageResourceUriByPath($file) . ") format('" . pathinfo($this->resourceManager->getPublicPackageResourceUriByPath($file),
                                PATHINFO_EXTENSION) . "')";
                    }
                }

            }

            $fontface .= ";}\n";
        }

        return $fontface;
    }

}
