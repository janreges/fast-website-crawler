# SiteOne Website Crawler

SiteOne Website Crawler **is the best, most powerful and most functional website crawler you will love ♥**.

Crawler has beautiful formatted and colored console output and advanced features like static assets crawling, custom
columns, exports to HTML/JSON/TXT and sending report to email addresses.

It works on all major platforms, but most easily on Linux. Windows, macOS or arm64 are also supported with little
effort, see below.

## Motivation to create this tool

At [SiteOne](https://www.siteone.io/) we have been creating web applications and web presentations for our clients for
more than 20 years. We have implemented hundreds of projects, and we have one need all around.

We need to check that the whole website is working great. Check that all pages respond quickly, that the title and other
SEO criteria are well-designed, that there are no non-existent pages (invalid links or missing files), that the cache or
security headers are set correctly, that we do not have unnecessary redirects. Last but not least, we need to perform
stress tests or test protections against DoS/DDoS attacks on our infrastructure.

There are GUI tools like [Xenu's Link Sleuth](https://home.snafu.de/tilman/xenulink.html)
or [Screaming Frog SEO Spider](https://www.screamingfrog.co.uk/seo-spider/), or some poor quality CLI tools. None of
these tools covered all our needs. That's why we decided to create our own tool.

Ehmmmm... Enough of the marketing bullshit! What was really the most real reason? The author, head of development and
infrastructure at [SiteOne](https://www.siteone.io/), wanted to prove that he could develop a great tool in 16 hours of
pure working time and take a break from caring for his extremely prematurely born son. And he did it! :-) The tool is
great, and his son is doing great too! ♥

## Features

* No external dependencies, no complicated installation. It works well with **any modern Linux (x64) distribution** and
  with little effort also on Windows, macOS or arm64 architecture.
* Efficiently crawls the website starting from the provided URL.
* Supports **public domains** and also **local domains** (e.g. `http://localhost:3000`).
* Dynamic **User-Agent** string based on selected `--device` type with the option to override by `--user-agent`.
* Option `--output=json` for better integration with CI/CD pipelines and other tools. The JSON output also has a nice
  visual progress reporting, if interactive output is detected.
* Option `--mail-to=<email>` and `--mail-smtp-*` for sending nice HTML report to email addresses. For now only SMTP
  without encryption is supported.
* Option `--max-workers=<num>` to specify the maximum number of workers for concurrent URL processing. You can use full
  power of your multicore CPU, but be careful and do not overload the target server.
* Option `--crawl-assets=<values>` to crawl also selected assets (images, fonts, styles, scripts, files).
* Option `--headers-to-table=<values>` to specify which extra headers from the HTTP response to display in the output
  table.
* Option `--include-regex=<regexp>` and `--ignore-regex=<regexp>` to include or ignore URLs based on regular expression.
  Both arguments can be specified multiple times and can be combined.
* Option `--add-random-query-params` to add random query params to test cache/anti-cache behavior.
* Option `--remove-query-params` to remove all query parameters from the URLs to skip unwanted dynamic URLs.
* Option `--hide-scheme-and-host` to hide the scheme and host from the URLs in the output table for more compact output.
* Option `--do-not-truncate-url` to truncate the URLs to the specified `--url-column-size=<size>` in the output table.
* Option `--hide-progress-bar` to hide progress bar visible in text and JSON output for more compact view.
* Option `--output-html-file`, `--output-json-file` and `--output-text-file` to save formatted outputs to a file. You
  can use `--add-timestamp-to-output-file` and `--add-host-to-output-file` options to add timestamp and host from URL as
  suffix to output file name.
* Beautifully **formatted and colored console output** with highlighted status codes and slow response times.
* Displays **execution statistics** at the end, including AVG, MIN, and MAX response times and breakdown by HTTP status
  codes.
* Supports **HTTP/1.1** and **HTTP/2**. Supports **HTTP** and **HTTPS**.
* Sophisticated **error handling** and input parameters validation.
* Table output - the script provides a detailed table-like output which includes:
    * Crawled URLs
    * Status code of the HTTP response (colored based on the status)
    * Time taken for the HTTP request (colored based on the time)
    * Response size
    * Specified headers from the HTTP response (based on `--headers-to-table` argument)
    * `Title`, `Keywords` and `Description` extracted from the HTML response (based on `--headers-to-table` argument)

## Installation

Most easily installation on most Linux (x64) distributions:

```bash
git clone https://github.com/janreges/siteone-website-crawler.git
cd siteone-website-crawler
chmod +x ./swoole-cli
````

> For Windows, macOS or arm64, see below. You have to download precompiled `swoole-cli` binary for your platform.

## Usage

To run the crawler, execute the `crawler.php` file from the command line with precompiled `swoole-cli` and provide the
required arguments:

**Basic example**

```bash
./swoole-cli crawler.php --url=https://mydomain.tld/ --device=mobile
```

**Fully-featured example**

```bash
./swoole-cli crawler.php --url=https://mydomain.tld/ \
  --output=text \
  --max-workers=2 \
  --timeout=5 \
  --user-agent="My User-Agent String" \
  --headers-to-table="X-Cache(10),Title,Keywords,Description" \
  --accept-encoding="gzip, deflate" \
  --url-column-size=100 \
  --max-queue-length=3000 \
  --max-visited-urls=10000 \
  --max-url-length=5000 \
  --crawl-assets="fonts,images,styles,scripts,files" \
  --include-regex="/^.*\/technologies.*/" \
  --include-regex="/^.*\/fashion.*/" \
  --ignore-regex="/^.*\/downloads\/.*\.pdf$/i" \
  --remove-query-params \
  --add-random-query-params \
  --hide-scheme-and-host \
  --do-not-truncate-url \
  --output-html-file=/dir/report.html \
  --output-json-file=/dir/report.json \
  --output-text-file=/dir/report.txt \
  --add-timestamp-to-output-file \
  --add-host-to-output-file \
  --mail-to=your.name@ymy-mail.tld \
  --mail-from=crawler@ymy-mail.tld \
  --mail-smtp-host=smtp.my-mail.tld \
  --mail-smtp-port=25 \
  --mail-smtp-user=smtp.user \
  --mail-smtp-pass=secretPassword123
```

### Arguments

#### Required:

* `--url=<value>`: The starting URL to begin crawling from.

#### Optional:

* `--device=<string>`: Specify the device type. Options: `desktop`, `mobile`, `tablet`. Defaults to `desktop` if not
  specified.
* `--output=<string>`: Specify the output type. Options: `text` or `json`. Defaults to `text` if not specified.
* `--max-workers=<num>`: The maximum number of workers for concurrent URL processing. Defaults to `3` if not specified.
* `--user-agent="<string>"`: The User-Agent string to use for the HTTP requests. If not provided, it defaults based on
  the `device` argument.
* `--headers-to-table=<string>`: Specify which extra headers from the HTTP response to display in the table output.
  Comma delimited. A specialty is the possibility to use `Title`, `Keywords` and `Description`. These are extracted from
  the HTML response and displayed in the table output. You can also set the expected length of the column in parentheses
  for better output - for example 'X-Cache(10)'
* `--crawl-assets=<string>`: Optional. Comma delimited list of assets you want to crawl too. Supported
  values: `fonts`, `images`, `styles`, `scripts` and `files` (pdf, etc.). Defaults to empty if not specified so no
  assets are crawled.
* `--accept-encoding=<string>`: Accept-Encoding header value. Defaults to `gzip, deflate, br` if not specified.
* `--timeout=<seconds>`: Timeout duration in seconds for the HTTP requests. Defaults to `3` seconds if not specified.
* `--url-column-size=<num>`: Basic URL column size in chars. Defaults to `80` chars if not specified.
* `--max-queue-length=<num>`: The maximum length of the waiting URL queue. Increase in case of large websites, but
  expect higher memory requirements. Defaults to `2000` if not specified.
* `--max-visited-urls=<num>`: The maximum number of the visited URLs. Increase in case of large websites, but expect
  higher memory requirements. Defaults to `5000` if not specified.
* `--max-url-length=<num>`: The maximum supported URL length in chars. Increase in case of very long URLs with query
  params, but expect higher memory requirements. Defaults to `2000` if not specified.
* `--include-regex=<regex>`: Optional. Regular expression compatible with PHP preg_match() for URLs that should be
  included. Argument can be specified multiple times. Example: `--include-regex='/^.*\/public\//'`. If at least one
  argument is specified, then any URL (except the initial URL) must match at least one regex. Otherwise, the URL is
  ignored.
* `--ignore-regex=<regex>`: Optional. Regular expression compatible with PHP preg_match() for URLs that should be
  ignored. Argument can be specified multiple times. Example: `--ignore-regex='/^.*\/downloads\/.*\.pdf$/i'`.
* `--add-random-query-params`: Whether to add random query parameters to the URL. This can help in testing cache
  behavior.
* `--remove-query-params`: Whether to remove all query parameters from the parsed URLs.
* `--hide-scheme-and-host`: If set, URLs displayed in the output table will not include the domain.
* `--do-not-truncate-url`: Long URLs are truncated by default to column size so that the table does not wrap. With this
  option, you can turn off the truncation.
* `--hide-progress-bar`: Hide progress bar visible in text and JSON output for more compact view.

#### Output files options:

* `--output-html-file=<file>`: Optional. Save formatted HTML output to a file. Extension `.html` is automatically added
  if not specified.
* `--output-json-file=<file>`: Optional. Save formatted JSON output to a file. Extension `.json` is automatically added
  if not specified.
* `--output-text-file=<file>`: Optional. Save formatted text output to a file. Extension `.txt` is automatically added
  if not specified.
* `--add-timestamp-to-output-file`:  Optional. Add timestamp as suffix to output file name. Example: you
  set `--output-json-file=/dir/report.json` and target filename will be `/dir/report.2023-10-06.14-33-12.json`.
* `--add-host-to-output-file`: Optional. Add host from URL as suffix to output file name. Example: you
  set `--output-html-file=/dir/report.html` and target filename will be `/dir/report.www.mydomain.tld.html`. You can
  combine this option with `--add-timestamp-to-output-file` option as well.

#### Mailer options:

**NOTICE**: For now, only SMTP without encryption is supported, typically running on port 25. If you are interested in
this tool, we can also implement secure SMTP support, or simply send me a merge request with lightweight implementation.

* `--mail-to=<email>`: Optional but required for mailer activation. Send report to email addresses. You can specify
  multiple emails separated by comma.
* `--mail-from=<email>`: Optional. Sender email address. Default is `siteone-website-crawler@your-hostname`.
* `--mail-smtp-host=<host>`: Optional. SMTP host for sending email. Default is `localhost`.
* `--mail-smtp-port=<port>`: Optional. SMTP port for sending email. Default is `25`.
* `--mail-smtp-user=<user>`: Optional. SMTP user, if your SMTP server requires authentication.
* `--mail-smtp-pass=<pass>`: Optional. SMTP password, if your SMTP server requires authentication.

## Other platforms

### Windows (x64)

If using Windows, the best choice is to use [Ubuntu](https://ubuntu.com/wsl)
or [Debian](https://www.linuxfordevices.com/tutorials/linux/install-debian-on-windows-wsl)
in [WSL](https://learn.microsoft.com/en-us/windows/wsl/install).

Otherwise, you can
download [swoole-cli-v4.8.13-cygwin-x64.zip](https://github.com/swoole/swoole-src/releases/download/v4.8.13/swoole-cli-v4.8.13-cygwin-x64.zip)
from [Swoole releases](https://github.com/swoole/swoole-src/releases) and use precompiled `bin/swoole-cli.exe`.

A really functional and tested Windows command looks like this (modify path to your `swoole-cli.exe` and `crawler.php`):

```bash
c:\Work\swoole-cli-v4.8.13-cygwin-x64\bin\swoole-cli.exe C:\Work\siteone-website-crawler\crawler.php --url=https://www.siteone.io/ --output=json
```

**NOTICE**: Cygwin does not support STDERR with rewritable lines in the console. Therefore, the output is not as
beautiful as on Linux or macOS.

### macOS (arm64, x64)

If using macOS with latest arm64 M1/M2 CPU, download arm64
version [swoole-cli-v4.8.13-macos-arm64.tar.xz](https://github.com/swoole/swoole-src/releases/download/v4.8.13/swoole-cli-v4.8.13-macos-arm64.tar.xz),
unpack and use its precompiled `swoole-cli`.

If using macOS with Intel CPU (x64), download x64
version  [swoole-cli-v4.8.13-macos-x64.tar.xz](https://github.com/swoole/swoole-src/releases/download/v4.8.13/swoole-cli-v4.8.13-macos-x64.tar.xz),
unpack and use its precompiled `swoole-cli`.

### Linux (arm64)

If using arm64 Linux, you can download
precompiled [swoole-cli-v4.8.13-linux-arm64.tar.xz](https://github.com/swoole/swoole-src/releases/download/v4.8.13/swoole-cli-v4.8.13-linux-arm64.tar.xz)
and use its precompiled `swoole-cli`.

## Roadmap

* Well tested Docker images for easy usage in CI/CD pipelines on hub.docker.com (for all platforms).
* Better static assets processing - now are assets processed immediately, same as other URLs. This can cause
  problems with large websites. We will implement a better solution with a separate queue for static assets and separate
  visualization in the output.
* Support for configurable thresholds for response times, status codes, etc. to exit with a non-zero code.
* Support for secure SMTP.
* Support for HTTP authentication.

If you have any suggestions or feature requests, please open an issue on GitHub. We'd love to hear from you!

Your contributions with realized improvements, bug fixes, and new features are welcome. Please open a pull request :-)

## Disclaimer

Please use responsibly and ensure that you have the necessary permissions when crawling websites. Some sites may have
rules against automated access detailed in their robots.txt.

**The author is not responsible for any consequences caused by inappropriate use or deliberate misuse of this tool.**

## License

The script is provided as-is without any guarantees. You're free to modify, distribute, and use it as per your
requirements.

## Output examples

### Text output

![SiteOne Website Crawler](./docs/siteone-website-crawler.gif)

### JSON output

Output is truncated (only 3 URLs in results) for better readability.

```json
{
  "crawler": {
    "name": "SiteOne Website Crawler",
    "version": "2023.10.2",
    "executedAt": "2023-10-05 16:50:27",
    "command": "crawler.php --url=https:\/\/www.siteone.io\/ --headers-to-table=Title --max-workers=2 --do-not-truncate-url --url-column-size=72 --output=json"
  },
  "options": {
    "url": "https:\/\/www.siteone.io\/",
    "device": "desktop",
    "outputType": "json",
    "maxWorkers": 2,
    "timeout": 10,
    "urlColumnSize": 72,
    "acceptEncoding": "gzip, deflate, br",
    "userAgent": null,
    "headersToTable": [
      "Title"
    ],
    "maxQueueLength": 1000,
    "maxVisitedUrls": 5000,
    "maxUrlLength": 2000,
    "crawlAssets": [],
    "addRandomQueryParams": false,
    "removeQueryParams": false,
    "hideSchemeAndHost": false,
    "doNotTruncateUrl": true
  },
  "results": [
    {
      "url": "https:\/\/www.siteone.io\/",
      "status": 200,
      "elapsedTime": 0.086,
      "size": 159815,
      "extras": {
        "Title": "SiteOne | Design. Development. Digital Transformation."
      }
    },
    {
      "url": "https:\/\/www.siteone.io\/our-projects",
      "status": 200,
      "elapsedTime": 0.099,
      "size": 132439,
      "extras": {
        "Title": "SiteOne | Our Projects &amp; Successful Solutions"
      }
    },
    {
      "url": "https:\/\/www.siteone.io\/case-study\/new-webdesign-for-e.on-energy",
      "status": 200,
      "elapsedTime": 0.099,
      "size": 156471,
      "extras": {
        "Title": "SiteOne | E.ON: Security and Stability in the Energy Market"
      }
    }
  ],
  "stats": {
    "totalExecutionTime": 0.464,
    "totalUrls": 9,
    "totalSize": 1358863,
    "totalSizeFormatted": "1.3 MB",
    "totalRequestsTimes": 0.826,
    "totalRequestsTimesAvg": 0.092,
    "totalRequestsTimesMin": 0.074,
    "totalRequestsTimesMax": 0.099,
    "countByStatus": {
      "200": 9
    }
  }
}
```