<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;

class StorefrontThemeResolver
{
    private const MAX_CSS_BYTES = 250000;
    private const CSS_PATTERNS = [
        '/--(?:color|theme|brand|button)-primary(?:-[a-z0-9_-]+)?\s*:\s*(#[0-9a-fA-F]{3,6})/i',
        '/\.action\.primary\s*,\s*\.action-primary\s*\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
        '/\.action\.primary\s*\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
        '/\.action-primary\s*\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
        '/\.btn-primary(?:\s*,[^{]+)?\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
    ];

    private DirectoryList $directoryList;
    private ScopeConfigInterface $scopeConfig;
    private ThemeProviderInterface $themeProvider;

    public function __construct(
        DirectoryList $directoryList,
        ScopeConfigInterface $scopeConfig,
        ThemeProviderInterface $themeProvider
    ) {
        $this->directoryList = $directoryList;
        $this->scopeConfig = $scopeConfig;
        $this->themeProvider = $themeProvider;
    }

    public function resolvePrimaryColor(int $storeId, ?string $locale = null): ?string
    {
        foreach ($this->resolveCssCandidates($storeId, $locale) as $path) {
            $primaryColor = $this->extractPrimaryColorFromCss($this->readCssFile($path));
            if ($primaryColor !== null) {
                return $primaryColor;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveCssCandidates(int $storeId, ?string $locale = null): array
    {
        $root = rtrim($this->directoryList->getRoot(), DIRECTORY_SEPARATOR);
        $candidates = [
            $root . '/pub/media/styles.css',
        ];

        $themePaths = $this->resolveThemePaths($storeId);
        $localeCandidates = $this->resolveLocaleCandidates($storeId, $locale);

        foreach ($localeCandidates as $candidateLocale) {
            foreach ($themePaths as $themePath) {
                $candidates[] = $root . '/pub/static/' . $themePath . '/' . $candidateLocale . '/css/styles-m.css';
                $candidates[] = $root . '/pub/static/' . $themePath . '/' . $candidateLocale . '/css/styles-l.css';
                $candidates[] = $root . '/var/view_preprocessed/pub/static/' . $themePath . '/' . $candidateLocale . '/css/styles-m.css';
                $candidates[] = $root . '/var/view_preprocessed/pub/static/' . $themePath . '/' . $candidateLocale . '/css/styles-l.css';
            }

            $wildcards = [
                $root . '/pub/static/frontend/*/*/' . $candidateLocale . '/css/styles-m.css',
                $root . '/pub/static/frontend/*/*/' . $candidateLocale . '/css/styles-l.css',
                $root . '/var/view_preprocessed/pub/static/frontend/*/*/' . $candidateLocale . '/css/styles-m.css',
                $root . '/var/view_preprocessed/pub/static/frontend/*/*/' . $candidateLocale . '/css/styles-l.css',
            ];
            foreach ($wildcards as $pattern) {
                $matches = glob($pattern) ?: [];
                foreach ($matches as $match) {
                    $candidates[] = $match;
                }
            }
        }

        $resolved = [];
        foreach ($candidates as $candidate) {
            $path = str_replace('\\', '/', (string) $candidate);
            if ($path === '' || isset($resolved[$path]) || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $resolved[$path] = true;
        }

        return array_keys($resolved);
    }

    /**
     * @return list<string>
     */
    private function resolveThemePaths(int $storeId): array
    {
        $paths = [];
        $themeId = (int) $this->scopeConfig->getValue(
            DesignInterface::XML_PATH_THEME_ID,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        if ($themeId > 0) {
            try {
                $theme = $this->themeProvider->getThemeById($themeId);
                $fullPath = trim((string) ($theme ? $theme->getFullPath() : ''));
                if ($fullPath !== '') {
                    $paths[] = $fullPath;
                }
            } catch (\Throwable $exception) {
            }
        }

        $paths[] = 'frontend/Magento/luma';

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * @return list<string>
     */
    private function resolveLocaleCandidates(int $storeId, ?string $locale = null): array
    {
        $resolvedLocale = trim((string) $locale);
        if ($resolvedLocale === '') {
            $resolvedLocale = (string) $this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORES,
                $storeId
            );
        }

        $normalizedLocale = str_replace('-', '_', $resolvedLocale);
        $language = strtolower((string) strtok($normalizedLocale, '_'));
        $candidates = [];

        if ($normalizedLocale !== '') {
            $candidates[] = $normalizedLocale;
        }
        if ($language === 'fr') {
            $candidates[] = 'fr_FR';
        }
        $candidates[] = 'en_US';

        return array_values(array_unique(array_filter($candidates)));
    }

    private function readCssFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_CSS_BYTES);
        return is_string($contents) ? $contents : '';
    }

    private function extractPrimaryColorFromCss(string $css): ?string
    {
        if ($css === '') {
            return null;
        }

        foreach (self::CSS_PATTERNS as $pattern) {
            if (!preg_match($pattern, $css, $matches)) {
                continue;
            }

            $normalized = $this->normalizeHexColor($matches[1] ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeHexColor(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] !== '#') {
            $raw = '#' . $raw;
        }
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $raw)) {
            $raw = '#' . str_repeat($raw[1], 2) . str_repeat($raw[2], 2) . str_repeat($raw[3], 2);
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
            return null;
        }

        return strtoupper($raw);
    }
}
