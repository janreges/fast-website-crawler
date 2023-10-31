<?php

namespace Crawler\Result;

use Crawler\Crawler;
use Crawler\Utils;

class VisitedUrl
{
    const ERROR_CONNECTION_FAIL = -1;
    const ERROR_TIMEOUT = -2;
    const ERROR_SERVER_RESET = -3;
    const ERROR_SEND_ERROR = -4;

    /**
     * @var string Unique ID hash of this URL
     */
    public readonly string $uqId;

    /**
     * @var string Unique ID hash of the source URL where this URL was found
     */
    public readonly string $sourceUqId;

    /**
     * Full URL with scheme, domain, path and query
     * @var string URL
     */
    public readonly string $url;

    /**
     * HTTP status code of the request
     * Negative values are errors - see self:ERROR_* constants
     * @var int
     */
    public readonly int $statusCode;

    /**
     * Request time in seconds
     * @var float
     */
    public readonly float $requestTime;

    /**
     * Request time formatted as "32 ms" or "7.4 s"
     * @var string
     */
    public readonly string $requestTimeFormatted;

    /**
     * Size of the response in bytes
     * @var int|null
     */
    public readonly ?int $size;

    /**
     * Size of the response formatted as "1.23 MB"
     * @var string|null
     */
    public readonly ?string $sizeFormatted;

    /**
     * Content-Encoding header value (br, gzip, ...)
     * @var string|null
     */
    public readonly ?string $contentEncoding;

    /**
     * Content type ID
     * @see Crawler::CONTENT_TYPE_ID_*
     * @var int
     */
    public readonly int $contentType;

    /**
     * Extra data from the response required by --extra-columns (headers, Title, DOM, etc.
     * @var array|null
     */
    public readonly ?array $extras;

    /**
     * Is this URL external (not from the same domain as the initial URL)
     * @var bool
     */
    public readonly bool $isExternal;

    /**
     * Is this URL allowed for crawling (based on --allowed-domain-for-crawling)
     * @var bool
     */
    public readonly bool $isAllowedForCrawling;

    /**
     * @param string $uqId
     * @param string $sourceUqId
     * @param string $url
     * @param int $statusCode
     * @param float $requestTime
     * @param int|null $size
     * @param int $contentType
     * @param string|null $contentEncoding
     * @param array|null $extras
     * @param bool $isExternal
     * @param bool $isAllowedForCrawling
     */
    public function __construct(string $uqId, string $sourceUqId, string $url, int $statusCode, float $requestTime, ?int $size, int $contentType, ?string $contentEncoding, ?array $extras, bool $isExternal, bool $isAllowedForCrawling)
    {
        $this->uqId = $uqId;
        $this->sourceUqId = $sourceUqId;
        $this->url = $url;
        $this->statusCode = $statusCode;
        $this->requestTime = $requestTime;
        $this->requestTimeFormatted = Utils::getFormattedDuration($this->requestTime);
        $this->size = $size;
        $this->sizeFormatted = $size !== null ? Utils::getFormattedSize($size) : null;
        $this->contentType = $contentType;
        $this->contentEncoding = $contentEncoding;
        $this->extras = $extras;
        $this->isExternal = $isExternal;
        $this->isAllowedForCrawling = $isAllowedForCrawling;
    }

    public function isStaticFile(): bool
    {
        static $staticTypes = [
            Crawler::CONTENT_TYPE_ID_IMAGE,
            Crawler::CONTENT_TYPE_ID_SCRIPT,
            Crawler::CONTENT_TYPE_ID_STYLESHEET,
            Crawler::CONTENT_TYPE_ID_VIDEO,
            Crawler::CONTENT_TYPE_ID_AUDIO,
            Crawler::CONTENT_TYPE_ID_DOCUMENT,
            Crawler::CONTENT_TYPE_ID_FONT,
            Crawler::CONTENT_TYPE_ID_JSON,
            Crawler::CONTENT_TYPE_ID_XML,
        ];

        return in_array($this->contentType, $staticTypes);
    }

}