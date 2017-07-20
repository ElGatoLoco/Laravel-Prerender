<?php


namespace Nutsweb\LaravelPrerender;


use Closure;
use Redirect;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Foundation\Application;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Redis;
use QueuePusher\Queue;

class PrerenderMiddleware
{
    /**
     * The application instance
     *
     * @var Application
     */
    private $app;

    /**
     * The Guzzle Client that sends GET requests to the prerender server
     *
     * @var Guzzle
     */
    private $client;

    /**
     * This token will be provided via the X-Prerender-Token header.
     *
     * @var string
     */
    private $prerenderToken;

    /**
     * List of crawler user agents that will be
     *
     * @var array
     */
    private $crawlerUserAgents;

    /**
     * URI whitelist for prerendering pages only on this list
     *
     * @var array
     */
    private $whitelist;

    /**
     * URI blacklist for prerendering pages that are not on the list
     *
     * @var array
     */
    private $blacklist;


    /**
     * URI whitelist for prerendering pages regardless of the user agent
     *
     * @var array
     */
    private $whitelistForAllUsers;

    /**
     * Base URI to make the prerender requests
     *
     * @var string
     */
    private $prerenderUri;

    /**
     * Return soft 3xx and 404 HTTP codes
     *
     * @var string
     */
    private $returnSoftHttpCodes;

    /**
     * Creates a new PrerenderMiddleware instance
     *
     * @param Application $app
     * @param Guzzle $client
     */
    public function __construct(Application $app, Guzzle $client)
    {
        $this->app = $app;
        $this->returnSoftHttpCodes = $app['config']->get('prerender')['prerender_soft_http_codes'];

        if ($this->returnSoftHttpCodes) {
            $this->client = $client;
        } else {
            // Workaround to avoid following redirects
            $config = $client->getConfig();
            $config['allow_redirects'] = false;
            $this->client = new Guzzle($config);
        }

        $config = $app['config']->get('prerender');

        $this->prerenderUri = $config['prerender_url'];
        $this->crawlerUserAgents = $config['crawler_user_agents'];
        $this->prerenderToken = $config['prerender_token'];
        $this->whitelist = $config['whitelist'];
        $this->blacklist = $config['blacklist'];
        $this->whitelistForAllUsers = !empty($config['whitelist_for_all_users']) ? $config['whitelist_for_all_users'] : [];
    }

    /**
     * Handles a request and prerender if it should, otherwise call the next middleware.
     *
     * @param $request
     * @param Closure $next
     * @return Response
     * @internal param int $type
     * @internal param bool $catch
     */
    public function handle($request, Closure $next)
    {
        if ($this->shouldShowPrerenderedPage($request)) {
            $key = ($request->isSecure() ? 'https' : 'http') . '://' . $request->getHost() . '/' . $request->Path();
            if (Redis::exists($key)) {
                return Response::create(Redis::get($key));
            }
            else {
                $this->getPrerenderedPageResponse($request);
            }
        }

        return $next($request);
    }

    /**
     * Returns whether the request must be prerendered.
     *
     * @param $request
     * @return bool
     */
    private function shouldShowPrerenderedPage($request)
    {
        $userAgent = strtolower($request->server->get('HTTP_USER_AGENT'));
        $bufferAgent = $request->server->get('X-BUFFERBOT');
        $requestUri = $request->getRequestUri();
        $referer = $request->headers->get('Referer');

        $isRequestingPrerenderedPage = false;

        if (!$userAgent) return false;
        if (!$request->isMethod('GET')) return false;

        // prerender if _escaped_fragment_ is in the query string
        if ($request->query->has('_escaped_fragment_')) $isRequestingPrerenderedPage = true;

        // prerender if a crawler is detected
        foreach ($this->crawlerUserAgents as $crawlerUserAgent) {
            if (str_contains($userAgent, strtolower($crawlerUserAgent))) {
                $isRequestingPrerenderedPage = true;
            }
        }

        if ($bufferAgent) $isRequestingPrerenderedPage = true;

        if ($this->whitelistForAllUsers
            && $this->isListed($requestUri, $this->whitelistForAllUsers)
            && (!$this->blacklist || !$this->isListed($requestUri, $this->blacklist))) {
            return !str_contains($userAgent, 'https://github.com/prerender/prerender');
        }

        if (!$isRequestingPrerenderedPage) return false;

        // only check whitelist if it is not empty
        if ($this->whitelist) {
            if (!$this->isListed($requestUri, $this->whitelist)) {
                return false;
            }
        }

        // only check blacklist if it is not empty
        if ($this->blacklist) {
            $uris[] = $requestUri;
            // we also check for a blacklisted referer
            if ($referer) $uris[] = $referer;
            if ($this->isListed($uris, $this->blacklist)) {
                return false;
            }
        }

        // Okay! Prerender please.
        return true;
    }

    /**
     * Prerender the page and return the Guzzle Response
     *
     * @param $request
     * @return null|void
     */
    private function getPrerenderedPageResponse($request)
    {
        $headers = [
            'User-Agent' => $request->server->get('HTTP_USER_AGENT'),
        ];
        if ($this->prerenderToken) {
            $headers['X-Prerender-Token'] = $this->prerenderToken;
        }
        $headers = compact('headers');

        $protocol = $request->isSecure() ? 'https' : 'http';
        $host = $request->getHost();
        $path = $request->Path();
        $url = $this->prerenderUri . '/' . urlencode($protocol.'://'.$host.'/'.$path);

        $returnSoftHttpCodes = $this->returnSoftHttpCodes;

        Queue::push(function($job) use ($returnSoftHttpCodes, $url, $headers) {
            $client = new Guzzle();
            if (!$returnSoftHttpCodes) {
                $clientConfig = $client->getConfig();
                $clientConfig['allow_redirects'] = false;
                $client = new Guzzle($clientConfig);
            }
            $client->get($url, $headers);
        });
    }

    /**
     * Convert a Guzzle Response to a Symfony Response
     *
     * @param ResponseInterface $prerenderedResponse
     * @return Response
     */
    private function buildSymfonyResponseFromGuzzleResponse(ResponseInterface $prerenderedResponse)
    {
        return (new HttpFoundationFactory)->createResponse($prerenderedResponse);
    }

    /**
     * Check whether one or more needles are in the given list
     *
     * @param $needles
     * @param $list
     * @return bool
     */
    private function isListed($needles, $list)
    {
        $needles = is_array($needles) ? $needles : [$needles];

        foreach ($list as $pattern) {
            foreach ($needles as $needle) {
                if (str_is($pattern, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

}
