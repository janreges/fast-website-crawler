<?php

/*
 * This file is part of the SiteOne Crawler.
 *
 * (c) Ján Regeš <jan.reges@siteone.cz>
 */

declare(strict_types=1);

namespace Crawler\Export\Utils;

use Crawler\Export\OfflineWebsiteExporter;
use Crawler\ParsedUrl;
use Crawler\Utils;

class OfflineUrlConverter
{

    private readonly ParsedUrl $initialUrl;
    private readonly ParsedUrl $baseUrl;
    private readonly ParsedUrl $targetUrl;
    private readonly ParsedUrl $relativeTargetUrl;
    private readonly ?string $targetUrlSourceAttribute;

    private readonly array $callbackIsDomainAllowedForStaticFiles;
    private readonly array $callbackIsExternalDomainAllowedForCrawling;

    private static int $exportFilePathLengthLimit = 200;
    private static array $replaceQueryString = [];
    private static string $filenameSanitization = OfflineWebsiteExporter::FILENAME_SANITIZATION_SPECIAL_CHARS_TO_UNDERSCORE;

    private TargetDomainRelation $targetDomainRelation;

    const DEBUG_URL = null; // example: '/\.\.\/page/i';

    /**
     * @param ParsedUrl $initialUrl
     * @param ParsedUrl $baseUrl
     * @param ParsedUrl $targetUrl
     * @param array $callbackIsDomainAllowedForStaticFiles
     * @param array $callbackIsExternalDomainAllowedForCrawling
     * @param string|null $attribute
     */
    public function __construct(ParsedUrl $initialUrl, ParsedUrl $baseUrl, ParsedUrl $targetUrl, array $callbackIsDomainAllowedForStaticFiles, array $callbackIsExternalDomainAllowedForCrawling, ?string $attribute)
    {
        $this->initialUrl = $initialUrl;
        $this->baseUrl = $baseUrl;
        $this->targetUrl = $targetUrl;
        $this->relativeTargetUrl = clone $targetUrl;
        $this->callbackIsDomainAllowedForStaticFiles = $callbackIsDomainAllowedForStaticFiles;
        $this->callbackIsExternalDomainAllowedForCrawling = $callbackIsExternalDomainAllowedForCrawling;
        $this->targetUrlSourceAttribute = $attribute;
        $this->targetDomainRelation = TargetDomainRelation::getByUrls($initialUrl, $baseUrl, $targetUrl);

        /** @phpstan-ignore-next-line */
        if (self::DEBUG_URL !== null && preg_match(self::DEBUG_URL, $this->targetUrl->getFullUrl()) === 1) {
            $this->relativeTargetUrl->setDebug(true);
        }
    }

    /**
     * @param bool $keepFragment
     * @return string
     */
    public function convertUrlToRelative(bool $keepFragment = true): string
    {
        $forcedUrl = $this->getForcedUrlIfNeeded();
        if ($forcedUrl) {
            return $forcedUrl;
        }

        $debug = stripos($this->targetUrl->getFullUrl(), 'windows-light.png') !== false;
        if ($debug) {
            echo "JREEE00: {$this->targetUrl->getFullUrl()} | {$this->relativeTargetUrl->getFullUrl()} | {$this->targetDomainRelation}\n";
        }

        $this->detectAndSetFileNameWithExtension();
        $this->calculateAndApplyDepth();

        $preFinalUrl = $this->relativeTargetUrl->getFullUrl(false, $keepFragment);
        return self::sanitizeFilePath($preFinalUrl, $keepFragment);
    }

    public function getRelativeTargetUrl(): ParsedUrl
    {
        return $this->relativeTargetUrl;
    }

    public function getTargetDomainRelation(): TargetDomainRelation
    {
        return $this->targetDomainRelation;
    }

    /**
     * @return string|null
     */
    private function getForcedUrlIfNeeded(): ?string
    {
        if ($this->relativeTargetUrl->isOnlyFragment()) {
            return '#' . $this->relativeTargetUrl->fragment;
        }

        // when URL is not requestable resource, it is not possible to convert it to relative URL
        if (!Utils::isHrefForRequestableResource($this->targetUrl->getFullUrl())) {
            return $this->targetUrl->getFullUrl(false);
        }

        // when is external host but this host is not allowed
        $isExternalHost = in_array($this->targetDomainRelation, [
            TargetDomainRelation::INITIAL_DIFFERENT__BASE_DIFFERENT,
            TargetDomainRelation::INITIAL_DIFFERENT__BASE_SAME
        ]);

        if ($isExternalHost && $this->targetUrl->host) {
            if ($this->isExternalDomainAllowedForCrawling($this->targetUrl->host)) {
                return null;
            } else if ($this->targetUrl->isStaticFile() && $this->isDomainAllowedForStaticFiles($this->targetUrl->host)) {
                return null;
            } else if (!$this->targetUrl->isStaticFile() && $this->targetUrlSourceAttribute === 'src' && $this->isDomainAllowedForStaticFiles($this->targetUrl->host)) {
                // if it is not static file but it is src attribute and domain is allowed for static files, do not force URL
                // example case is this SVG without extension: <img src=""https://netlify-marketing-icons.netlify.app/fa/fal/users/%232e51ed/">
                return null;
            } else {
                return $this->targetUrl->getFullUrl(true, true);
            }
        }

        return null;
    }

    /**
     * Add '*.html' or '/index.html' to path when needed
     *
     * @return void
     */
    private function detectAndSetFileNameWithExtension(): void
    {
        $queryHash = $this->relativeTargetUrl->query ? self::getQueryHashFromQueryString($this->relativeTargetUrl->query) : null;

        // when the path is empty or '/'
        if (trim($this->relativeTargetUrl->path, '/ ') === '') {
            if ($queryHash) {
                $this->relativeTargetUrl->setPath("/index.{$queryHash}.html", "Set '/index.{$queryHash}.html' because path is empty or '/' and has query string");
                $this->relativeTargetUrl->setQuery(null);
            } elseif ($this->relativeTargetUrl->path === '' && $this->relativeTargetUrl->fragment) {
                // only #fragment
                return;
            } else {
                $this->relativeTargetUrl->setPath('/index.html', "Set '/index.html' because path is empty or '/'");
            }
            return;
        }

        $isImageAttribute = in_array($this->targetUrlSourceAttribute, ['src', 'srcset']);

        // if the URL is probably icon, we use SVG extension, otherwise we use JPG (not ideal)
        $imgExtension = stripos($this->relativeTargetUrl->getFullUrl(), 'icon') !== false ? 'svg' : 'jpg';

        // when the URL is probably font from Google Fonts, we use CSS extension, otherwise we use HTML (not ideal, ready to refactor in the future)
        $otherFileExtension = ($this->targetUrlSourceAttribute === 'href' && stripos($this->relativeTargetUrl->url, 'fonts.googleapis.com/css') !== false)
            ? 'css'
            : 'html';

        $extension = $this->relativeTargetUrl->estimateExtension() ?: ($isImageAttribute ? $imgExtension : $otherFileExtension);

        if (str_ends_with($this->relativeTargetUrl->path, '/')) {
            $baseNameWithoutExtension = 'index';
            if ($queryHash) {
                $this->relativeTargetUrl->setPath(
                    $this->relativeTargetUrl->path . "{$baseNameWithoutExtension}.{$queryHash}.{$extension}",
                    "Add '{$baseNameWithoutExtension}}.{$queryHash}.{$extension}' because path ends with '/' and has query string"
                );
                $this->relativeTargetUrl->setQuery(null);
            } else {
                $this->relativeTargetUrl->setPath(
                    $this->relativeTargetUrl->path . "{$baseNameWithoutExtension}.{$extension}",
                    "Add '{$baseNameWithoutExtension}.{$extension}' because path ends with '/' and do not have query string"
                );
            }
        } else {
            $relativeTargetPathWithoutExtension = preg_replace('/\.[a-z0-9]{1,10}$/i', '', $this->relativeTargetUrl->path);
            if ($queryHash) {
                $this->relativeTargetUrl->setPath(
                    $relativeTargetPathWithoutExtension . ".{$queryHash}.{$extension}",
                    "Add '.{$queryHash}.{$extension}' because path do not ends with '/' and has query string"
                );
                $this->relativeTargetUrl->setQuery(null);
            } else {
                $this->relativeTargetUrl->setPath(
                    $relativeTargetPathWithoutExtension . ".{$extension}",
                    "Add '.{$extension}' because path do not ends with '/' and do not have query string"
                );
            }
        }
    }

    /**
     * Get depth of base path in target offline version
     * Examples:
     *  / = 0 because /index.html
     *  /foo = 0 because /foo.html
     *  /foo/ = 1 because /foo/index.html
     *  /foo/bar = 1 because /foo/bar.html
     *  /foo/bar/ = 2 because /foo/bar/index.html
     *  /?param=1 = 0 because /index.queryMd5Hash.html
     *  /foo?param=1 = 0 because /foo.queryMd5Hash.html (+1 because of query string
     *  /foo/?param=1 = 1 because /foo/index.queryMd5Hash.html
     *  /foo/bar?param=1 = 1 because /foo/bar.queryMd5Hash.html (+1 because of query string)
     *  /foo/bar/?param=1 = 2 because /foo/bar/index.queryMd5Hash.html
     *
     * @return int
     */
    public static function getOfflineBaseUrlDepth(ParsedUrl $url): int
    {
        if (trim($url->path, '/ ') === '') {
            return 0;
        }

        return substr_count(ltrim($url->path, '/ '), '/');
    }

    private function calculateAndApplyDepth(): void
    {
        $baseDepth = substr_count(ltrim($this->baseUrl->path, '/ '), '/');
        switch ($this->targetDomainRelation) {
            case TargetDomainRelation::INITIAL_SAME__BASE_SAME:
                // browsing within initial domain
            case TargetDomainRelation::INITIAL_DIFFERENT__BASE_SAME:
                // browsing within the same base domain different from the initial domain
                if (str_starts_with($this->relativeTargetUrl->path, '/')) {
                    $this->relativeTargetUrl->changeDepth(
                        $baseDepth,
                        "Increased depth for '$baseDepth' because its path starts with '/'"
                    );
                }
                break;

            case TargetDomainRelation::INITIAL_SAME__BASE_DIFFERENT:
                // backlink from the other domain back to initial domain
                $this->relativeTargetUrl->setPath(
                    str_repeat('../', $baseDepth + 1) . ltrim(preg_replace(
                        "/^(\/\/|https?:\/\/)" . preg_quote($this->relativeTargetUrl->host) . "(:[0-9]+)?/i",
                        '',
                        $this->relativeTargetUrl->path
                    ), '/ '),
                    "Backlink back to initial domain - changed depth to offline root and removed domain from path"
                );
                break;

            case TargetDomainRelation::INITIAL_DIFFERENT__BASE_DIFFERENT:
                // link outside base domain and also other than initial domain
                $extraDepthDueToGoBackToOfflineRoot = $this->baseUrl->host !== $this->initialUrl->host ? 1 : 0;
                $this->relativeTargetUrl->setPath(
                    str_repeat('../', $baseDepth + $extraDepthDueToGoBackToOfflineRoot) . "_{$this->relativeTargetUrl->host}{$this->relativeTargetUrl->path}",
                    "Link outside base domain and also other than initial domain - changed depth to offline root and added domain prefix to path"
                );
                break;
        }

        // remove first slash from path if needed
        if (str_starts_with($this->relativeTargetUrl->path, '/')) {
            $this->relativeTargetUrl->setPath(ltrim($this->relativeTargetUrl->path, '/ '), "Remove first slash from path");
        }
    }

    private function isDomainAllowedForStaticFiles(string $domain): bool
    {
        return $this->callbackIsDomainAllowedForStaticFiles
            ? call_user_func($this->callbackIsDomainAllowedForStaticFiles, $domain)
            : false;
    }

    private function isExternalDomainAllowedForCrawling(string $domain): bool
    {
        return $this->callbackIsExternalDomainAllowedForCrawling
            ? call_user_func($this->callbackIsExternalDomainAllowedForCrawling, $domain)
            : false;
    }

    /**
     * Sanitize file path and replace special chars because they are not allowed in file/dir names on some platforms (e.g. Windows)
     * When long filename and potential of OS filepath limit (~256 on Windows), we replace filename with shorter md5 and the same extension
     * If $replaceQueryString is not empty, query string hashing is deactivated and replaced by the values/regexps from this array
     *
     * @param string $filePath
     * @param bool $keepFragment
     * @return string
     */
    public static function sanitizeFilePath(string $filePath, bool $keepFragment): string
    {
        // transform query string to filename (small hash before extension)
        $parsedFilePath = parse_url($filePath);
        $pathWithExtension = preg_match('/^(.+)\.([a-z0-9]{1,10})(\?|#|$)/i', $parsedFilePath['path'] ?? '', $matches) === 1;

        $debug = stripos($filePath, 'windows-light.png') !== false;
        if ($debug) {
            echo "JREEE0A: $filePath | {$pathWithExtension}\n ";
        }

        if ($pathWithExtension) {
            $start = $matches[1];
            $extension = $matches[2];
            $queryString = $parsedFilePath['query'] ?? null;
            $fragment = $parsedFilePath['fragment'] ?? null;

            if (is_string($queryString) && trim($queryString) !== '') {
                $queryHash = self::getQueryHashFromQueryString($queryString);
                $filePath = $start . '.' . $queryHash . '.' . $extension;

                // add fragment to the end of the file path
                if ($keepFragment && $fragment) {
                    $filePath .= '#' . $fragment;
                }
            }
        }

        // sanitize filename based on selected self::$filenameSanitization
        $filePath = self::sanitizeFilename($filePath);

        // when filepath is too long and there is a long filename, we replace filename with shorter md5 and the same extension
        // filepath length is calculated from root of offline website directory for better results
        // self::$offlineFilePathLengthLimit default (200) is just a safe universal limit, because there is also directory path
        $filePathLength = strlen(preg_replace('/#.+$/', '', $filePath));
        if ($filePathLength > self::$exportFilePathLengthLimit && strlen(basename($filePath)) > 40) {
            $basename = basename($filePath);
            $extension = $extension ?? pathinfo($basename, PATHINFO_EXTENSION);
            $filePath = str_replace($basename, substr(md5($basename), 0, 10) . '.' . $extension, $filePath);
        }

        $staticFilesExtensions =
            'jpg|jpeg|png|gif|webp|svg|ico|js|css|txt|woff2|woff|ttf|eot|mp4|webm|ogg|mp3|wav|flac|pdf|doc' .
            '|docx|xls|xlsx|ppt|pptx|zip|rar|gz|bz2|7z|tar|xml|json|action|asp|aspx|cfm|cfml|cgi|do|gsp|jsp|jspx|lasso|phtml' .
            '|php|php3|php4|php5|php7|php8|php9|pl|py|rb|rbw|rhtml|shtml|srv|vm|vmdk';

        // adding "_" to the end of the folder that contains the potential file extension .. it solves the
        // situation where I may need the folder "foo/next.js/" and the file "foo/next.js" or
        // "foo/template.com/" vs file "foo/template.com" (real cases from vercel.com)
        $filePath = preg_replace('/([^.]+)\.(' . $staticFilesExtensions . ')\//i', '$1.$2_/', $filePath);

        // replace extensions of typical dynamic pages
        $filePath = preg_replace('/\.(action|asp|aspx|cfm|cfml|cgi|do|gsp|jsp|jspx|lasso|phtml|php3|php4|php5|php7|php8|php9|php|pl|py|rb|rbw|rhtml|shtml|srv|vm)$/i', '.$1.html', $filePath);

        if (!$keepFragment && str_contains($filePath, '#')) {
            $filePath = preg_replace('/#.+$/', '', $filePath);
        }

        if ($debug) {
            echo "JREEE0B: $filePath | {$pathWithExtension}\n ";
        }

        return $filePath;
    }

    /**
     * Sanitize filename in $filePath based on selecated self::$filenameSanitization
     * @param string $filePath
     * @return string
     */
    private static function sanitizeFilename(string $filePath): string
    {
        $debug = stripos($filePath, 'windows-light.png') !== false;
        if ($debug) {
            echo "JREEE1: $filePath\n";
        }

        static $specialCharacters = ['\\', ':', '%20', '%', '*', '?', '"', "'", '<', '>', '|', '+', ' '];

        if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_SPECIAL_CHARS_TO_UNDERSCORE) {
            $filePath = str_replace($specialCharacters, '_', $filePath);
            $filePath = preg_replace('/_{2,}/', '_', $filePath);
        } else if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_SPECIAL_CHARS_TO_DASH) {
            $filePath = str_replace($specialCharacters, '-', $filePath);
            $filePath = preg_replace('/-{2,}/', '-', $filePath);
        } else if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_SPECIAL_CHARS_TO_EMPTY) {
            $filePath = str_replace($specialCharacters, '', $filePath);
        } else if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_URLENCODE) {
            $filePath = preg_replace_callback('/[^\/]+$/i', function ($matches) {
                return urlencode($matches[0]);
            }, $filePath);
            $filePath = str_replace('%23', '#', $filePath); // replace %23 with '#'
        } else if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_RAWURLENCODE) {
            $filePath = preg_replace_callback('/[^\/]+$/i', function ($matches) {
                return rawurlencode($matches[0]);
            }, $filePath);
            $filePath = str_replace('%23', '#', $filePath); // replace %23 with '#'
        } else if (self::$filenameSanitization === OfflineWebsiteExporter::FILENAME_SANITIZATION_MD5) {
            $extension = pathinfo(parse_url($filePath, PHP_URL_PATH), PATHINFO_EXTENSION);

            $fragment = parse_url($filePath, PHP_URL_FRAGMENT);
            if ($fragment !== null) {
                $filePath = str_replace('#' . $fragment, '', $filePath);
            }

            $filePath = preg_replace_callback('/[^\/]+$/i', function ($matches) use ($extension) {
                return md5($matches[0]) . '.' . $extension;
            }, $filePath);


            if ($fragment !== null) {
                $filePath .= '#' . $fragment;
            }
        } else {
            throw new \InvalidArgumentException('Unknown filename sanitization method: ' . self::$filenameSanitization);
        }

        if ($debug) {
            echo "JREEE2: $filePath\n";
        }

        return $filePath;
    }

    /**
     * @param string[] $replaceQueryString
     * @return void
     */
    public static function setReplaceQueryString(array $replaceQueryString): void
    {
        self::$replaceQueryString = $replaceQueryString;
    }

    /**
     * @param int $exportFilePathLengthLimit
     * @return void
     */
    public static function setExportFilePathLengthLimit(int $exportFilePathLengthLimit): void
    {
        self::$exportFilePathLengthLimit = $exportFilePathLengthLimit;
    }

    public static function setFilenameSanitization(string $filenameSanitization): void
    {
        self::$filenameSanitization = $filenameSanitization;
    }

    /**
     * @param string $queryString
     * @return string
     */
    private static function getQueryHashFromQueryString(string $queryString): string
    {
        if (self::$replaceQueryString) {
            foreach (self::$replaceQueryString as $replace) {
                $parts = explode('->', $replace);
                $replaceFrom = trim($parts[0]);
                $replaceTo = trim($parts[1] ?? '');
                $isRegex = preg_match('/^([\/#~%]).*\1[a-z]*$/i', $replaceFrom);
                if ($isRegex) {
                    $queryString = preg_replace($replaceFrom, $replaceTo, $queryString);
                } else {
                    $queryString = str_replace($replaceFrom, $replaceTo, $queryString);
                }
            }

            // replace slashes with '~'
            return str_replace('/', '~', $queryString);
        } else {
            return substr(md5(htmlspecialchars_decode(urldecode($queryString))), 0, 10);
        }
    }

}