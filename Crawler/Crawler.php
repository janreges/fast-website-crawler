<?php

namespace Crawler;

use Crawler\Output\Output;
use Exception;
use Swoole\Process;
use Swoole\Table;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\ExitException;

class Crawler
{
    private Options $options;
    private Output $output;

    private Table $statusTable;
    private Table $queue;
    private Table $visited;

    private ParsedUrl $initialParsedUrl;
    private string $finalUserAgent;

    const URL_TYPE_HTML = 1;
    const URL_TYPE_SCRIPT = 2;
    const URL_TYPE_STYLESHEET = 3;
    const URL_TYPE_IMAGE = 4;
    const URL_TYPE_FONT = 5;
    const URL_TYPE_DOCUMENT = 6;
    const URL_TYPE_JSON = 7;
    const URL_TYPE_OTHER_FILE = 9;

    /**
     * @param Options $options
     * @param Output $output
     * @throws Exception
     */
    public function __construct(Options $options, Output $output)
    {
        $this->options = $options;
        $this->output = $output;
        $this->init();
    }

    /**
     * @return void
     * @throws Exception
     */
    private function init(): void
    {
        $this->statusTable = new Table(1);
        $this->statusTable->column('workers', Table::TYPE_INT, 2);
        $this->statusTable->column('doneUrls', Table::TYPE_INT, 8);
        $this->statusTable->create();
        $this->statusTable->set('1', ['workers' => 0, 'doneUrls' => 0]);

        $this->queue = new Table($this->options->maxQueueLength);
        $this->queue->column('url', Table::TYPE_STRING, $this->options->maxUrlLength);
        $this->queue->create();

        $this->visited = new Table($this->options->maxVisitedUrls);
        $this->visited->column('url', Table::TYPE_STRING, $this->options->maxUrlLength);
        $this->visited->column('time', Table::TYPE_FLOAT, 8);
        $this->visited->column('status', Table::TYPE_INT, 8);
        $this->visited->column('size', Table::TYPE_INT, 8);
        $this->visited->column('type', Table::TYPE_INT, 1); // @see self::URL_TYPE_*
        $this->visited->create();

        $this->finalUserAgent = $this->getFinalUserAgent();
        $this->initialParsedUrl = ParsedUrl::parse($this->options->url);
    }

    /**
     * @return void
     */
    public function run(): void
    {
        // add initial URL to queue
        $this->addUrlToQueue($this->options->url);

        // print table header
        $this->output->addTableHeader();

        // start recursive coroutine to process URLs
        Coroutine\run(function () {

            // catch SIGINT (Ctrl+C), print statistics and stop crawler
            Process::signal(SIGINT, function () {
                $this->output->addTotalStats($this->visited);
                Coroutine::cancel(Coroutine::getCid());
                throw new ExitException(Utils::getColorText('I caught the manual stop of the script. Therefore, the statistics only contain processed URLs until the script stops.', 'red', true));
            });

            while ($this->getActiveWorkersNumber() < $this->options->maxWorkers && $this->queue->count() > 0) {
                Coroutine::create([$this, 'processNextUrl']);
            }
        });
    }

    /**
     * @param string $body
     * @param string $url
     * @return array
     */
    private function parseHtmlBodyAndFillQueue(string $body, string $url): array
    {
        $result = [];

        preg_match_all('/<a[^>]*\shref=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
        $foundUrls = $matches[1];

        if ($this->options->crawlAssets) {
            foreach ($this->options->crawlAssets as $assetType) {
                if ($assetType === AssetType::FONTS) {
                    preg_match_all("/url\s*\(\s*['\"]([^'\"]+\.(eot|ttf|woff|woff2))/i", $body, $matches);
                    $foundUrls = array_merge($foundUrls, $matches[1]);
                } elseif ($assetType === AssetType::IMAGES) {
                    preg_match_all('/<img\s+.*?src=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
                    $foundUrls = array_merge($foundUrls, $matches[1]);
                } elseif ($assetType === AssetType::STYLES) {
                    preg_match_all('/<link\s+.*?href=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
                    $foundUrls = array_merge($foundUrls, $matches[1]);
                } elseif ($assetType === AssetType::SCRIPTS) {
                    preg_match_all('/<script\s+.*?src=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
                    $foundUrls = array_merge($foundUrls, $matches[1]);
                }
            }
        }

        foreach ($foundUrls as $urlForQueue) {
            $parsedUrlForQueue = ParsedUrl::parse(trim($urlForQueue));

            // skip URLs that are not on the same host or are not real HTML URLs
            $isUrlOnSameHost = !$parsedUrlForQueue->host || $parsedUrlForQueue->host === $this->initialParsedUrl->host;
            $isRealHtmlUrl = preg_match('/(mailto|phone|tel|javascript):/', $urlForQueue) === 0;
            if (!$isUrlOnSameHost || !$isRealHtmlUrl) {
                continue;
            }

            // build URl for queue
            if (isset($parsedUrlForQueue->host) && !$parsedUrlForQueue->scheme) {
                $urlForQueue = "{$this->initialParsedUrl->scheme}://$urlForQueue";
            } elseif (!isset($parsedUrlForQueue->host) && !str_starts_with($urlForQueue, '/') && !str_starts_with($urlForQueue, '#') && preg_match('/^https?:\/\//i', $urlForQueue) === 0) {
                $urlForQueue = Utils::relativeToAbsoluteUrl($urlForQueue, $url);
            } elseif (!isset($parsedUrlForQueue->host)) {
                $urlForQueue = "{$this->initialParsedUrl->scheme}://{$this->initialParsedUrl->host}$urlForQueue";
            }

            if (!$urlForQueue) {
                continue;
            }

            // remove hash from URL
            $urlForQueue = preg_replace('/#.*$/', '', $urlForQueue);

            // remove query params from URL if needed
            if ($this->options->removeQueryParams) {
                $urlForQueue = preg_replace('/\?.*$/', '', $urlForQueue);
            }

            // add URL to queue if it's not already there
            if ($this->isUrlSuitableForQueue($urlForQueue)) {
                $this->addUrlToQueue($urlForQueue);
            }
        }

        // add extra parsed content to result (Title, Keywords, Description) if needed
        if ($this->options->hasHeaderToTable('Title')) {
            preg_match_all('/<title>(.*?)<\/title>/i', $body, $matches);
            $result['Title'] = trim($matches[1][0] ?? '');
        }

        if ($this->options->hasHeaderToTable('Description')) {
            preg_match_all('/<meta\s+.*?name=["\']description["\']\s+content=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
            $result['Description'] = trim($matches[1][0] ?? '');
        }

        if ($this->options->hasHeaderToTable('Keywords')) {
            preg_match_all('/<meta\s+.*?name=["\']keywords["\']\s+content=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
            $result['Keywords'] = trim($matches[1][0] ?? '');
        }

        if ($this->options->hasHeaderToTable('DOM')) {
            @preg_match_all('/<\w+/', $body, $matches);
            $dom = count($matches[0] ?? []);
            $result['DOM'] = $dom;
        }

        return $result;
    }

    /**
     * @return void
     * @throws Exception
     */
    private function processNextUrl(): void
    {
        if ($this->queue->count() === 0) {
            return;
        }

        // take first URL from queue, remove it from queue and add it to visited
        $url = null;
        foreach ($this->queue as $urlKey => $queuedUrl) {
            $url = $queuedUrl['url'];
            $this->addUrlToVisited($url);
            $this->queue->del($urlKey);
            break;
        }

        // end if queue is empty
        if (!$url) {
            return;
        }

        // increment workers count
        $this->statusTable->incr('1', 'workers');

        $start = microtime(true);
        $parsedUrl = ParsedUrl::parse($url);
        $isAssetUrl = $parsedUrl->extension && stripos($parsedUrl->extension, 'html') === false;

        $absoluteUrl = $this->initialParsedUrl->scheme . '://' . $this->initialParsedUrl->host . ($this->initialParsedUrl->port !== 80 && $this->initialParsedUrl->port !== 443 ? ':' . $this->initialParsedUrl->port : '') . $parsedUrl->path;
        $finalUrlForHttpClient = $this->options->addRandomQueryParams ? Utils::addRandomQueryParams($parsedUrl->path) : $parsedUrl->path;

        // setup HTTP client, send request and get response
        $client = new Client($parsedUrl->host, $this->initialParsedUrl->port, $this->initialParsedUrl->scheme === 'https');
        $client->setHeaders(['User-Agent' => $this->finalUserAgent]);
        $client->setHeaders(['Accept-Encoding' => $this->options->acceptEncoding]);
        $client->set(['timeout' => $this->options->timeout]);
        $client->setMethod($isAssetUrl ? 'HEAD' : 'GET');
        $client->execute($finalUrlForHttpClient);

        $body = $client->body;
        $status = $client->statusCode;
        $elapsedTime = microtime(true) - $start;

        if ($isAssetUrl && isset($client->headers['content-length'])) {
            $bodySize = (int)$client->headers['content-length'];
        } else {
            $bodySize = $body ? strlen($body) : 0;
        }

        // decrement workers count after request is done
        $this->statusTable->decr('1', 'workers');

        // parse HTML body and fill queue with new URLs
        $isHtmlBody = isset($client->headers['content-type']) && stripos($client->headers['content-type'], 'text/html') !== false;
        $extraParsedContent = [];
        if ($body && $isHtmlBody) {
            $extraParsedContent = $this->parseHtmlBodyAndFillQueue($body, $url);
        }

        // get type self::URL_TYPE_* based on content-type header
        $contentType = $client->headers['content-type'] ?? '';
        $type = self::URL_TYPE_OTHER_FILE;
        if (str_contains($contentType, 'text/html')) {
            $type = self::URL_TYPE_HTML;
        } elseif (str_contains($contentType, 'text/javascript')) {
            $type = self::URL_TYPE_SCRIPT;
        } elseif (str_contains($contentType, 'text/css')) {
            $type = self::URL_TYPE_STYLESHEET;
        } elseif (str_contains($contentType, 'image/')) {
            $type = self::URL_TYPE_IMAGE;
        } elseif (str_contains($contentType, 'font/')) {
            $type = self::URL_TYPE_FONT;
        } elseif (str_contains($contentType, 'application/json')) {
            $type = self::URL_TYPE_JSON;
        } elseif (str_contains($contentType, 'application/pdf') || str_contains($type, 'application/msword') || str_contains($type, 'application/vnd.ms-excel') || str_contains($type, 'application/vnd.ms-powerpoint')) {
            $type = self::URL_TYPE_DOCUMENT;
        }

        // update info about visited URL
        $this->updateVisitedUrl($url, $elapsedTime, $status, $bodySize, $type);

        // print table row to output
        $progressStatus = $this->statusTable->get('1', 'doneUrls') . '/' . ($this->queue->count() + $this->visited->count());
        $this->output->addTableRow($client, $absoluteUrl, $status, $elapsedTime, $bodySize, $type, $extraParsedContent, $progressStatus);

        // check if crawler is done and exit or start new coroutine to process next URL
        if ($this->queue->count() === 0 && $this->getActiveWorkersNumber() === 0) {
            $this->output->addTotalStats($this->visited);
            Coroutine::cancel(Coroutine::getCid());
        } else {
            while ($this->getActiveWorkersNumber() < $this->options->maxWorkers && $this->queue->count() > 0) {
                Coroutine::create([$this, 'processNextUrl']);
                Coroutine::sleep(0.001);
            }
        }
    }

    private function isUrlSuitableForQueue(string $url): bool
    {
        if (!$this->isUrlAllowedByRegexs($url)) {
            return false;
        }

        $urlKey = $this->getUrlKeyForSwooleTable($url);

        $isInQueue = $this->queue->exist($urlKey);
        $isAlreadyVisited = $this->visited->exist($urlKey);
        $isParsable = @parse_url($url) !== false;
        $isUrlWithHtml = preg_match('/\.[a-z0-9]{2,4}$/i', $url) === 0 || preg_match('/\.(html|shtml|phtml)/i', $url) === 1;
        $parseableAreOnlyHtmlFiles = empty($this->options->crawlAssets);

        return !$isInQueue && !$isAlreadyVisited && $isParsable && ($isUrlWithHtml || !$parseableAreOnlyHtmlFiles);
    }

    private function isUrlAllowedByRegexs(string $url): bool
    {
        $isAllowed = $this->options->includeRegex === [];
        foreach ($this->options->includeRegex as $includeRegex) {
            if (preg_match($includeRegex, $url) === 1) {
                $isAllowed = true;
                break;
            }
        }
        foreach ($this->options->ignoreRegex as $ignoreRegex) {
            if (preg_match($ignoreRegex, $url) === 1) {
                $isAllowed = false;
                break;
            }
        }
        return $isAllowed;
    }

    private function addUrlToQueue(string $url): void
    {
        if (!$this->queue->set($this->getUrlKeyForSwooleTable($url), ['url' => $url])) {
            $error = "ERROR: Unable to queue URL '{$url}'. Set higher --max-queue-length.";
            $this->output->addError($error);
            throw new Exception($error);
        }
    }

    private function addUrlToVisited(string $url): void
    {
        if (!$this->visited->set($this->getUrlKeyForSwooleTable($url), ['url' => $url])) {
            $error = "ERROR: Unable to add visited URL '{$url}'. Set higher --max-visited-urls or --max-url-length.";
            $this->output->addError($error);
            throw new Exception($error);
        }
    }

    /**
     * @param string $url
     * @param float $elapsedTime
     * @param int $status
     * @param int $size
     * @param int $type @see self::URL_TYPE_*
     * @return void
     * @throws Exception
     */
    private function updateVisitedUrl(string $url, float $elapsedTime, int $status, int $size, int $type): void
    {
        $urlKey = $this->getUrlKeyForSwooleTable($url);
        $visitedUrl = $this->visited->get($urlKey);
        if (!$visitedUrl) {
            throw new Exception("ERROR: Unable to handle visited URL '{$url}'. Set higher --max-visited-urls or --max-url-length.");
        }
        $visitedUrl['time'] = $elapsedTime;
        $visitedUrl['status'] = $status;
        $visitedUrl['size'] = $size;
        $visitedUrl['type'] = $type;
        $this->visited->set($urlKey, $visitedUrl);

        $this->statusTable->incr('1', 'doneUrls');
    }

    private function getUrlKeyForSwooleTable(string $url): string
    {
        $parsedUrl = parse_url($url);
        $relevantParts = ($parsedUrl['host'] ?? '') . ($parsedUrl['path'] ?? '/');
        return md5($relevantParts);
    }

    private function getActiveWorkersNumber(): int
    {
        return $this->statusTable->get('1', 'workers');
    }

    private function getDoneUrlsNumber(): int
    {
        return $this->statusTable->get('1', 'doneUrls');
    }

    public function getFinalUserAgent(): string
    {
        if ($this->options->userAgent) {
            return $this->options->userAgent;
        }

        switch ($this->options->device) {
            case DeviceType::DESKTOP:
                return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . date('y') . '.0.0.0 Safari/537.36';
            case DeviceType::MOBILE:
                return 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15A5370a Safari/604.1';
            case DeviceType::TABLET:
                return 'Mozilla/5.0 (Linux; Android 11; SAMSUNG SM-T875) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/14.0 Chrome/87.0.4280.141 Safari/537.36';
            default:
                throw new Exception("Unsupported device '{$this->options->device}'");
        }
    }

}