<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016-2020 David Cole <david.cole1340@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Http;

use Carbon\Carbon;
use Discord\Discord;
use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Socket\ConnectorInterface;

/**
 * react/http driver.
 *
 * @author David Cole <david.cole1340@gmail.com>
 */
class ReactDriver extends Browser implements HttpDriver
{
    /**
     * The react event loop.
     *
     * @var LoopInterface
     */
    protected $loop;
    /**
     * Whether the HTTP client has been rate limited.
     *
     * @var bool Rate limited.
     */
    protected $rateLimited = false;

    /**
     * Array of rate limit promises.
     *
     * @var array Rate Limits.
     */
    protected $rateLimits = [];

    /**
     * {@inheritdoc}
     */
    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        $this->loop = $loop;
        parent::__construct($loop, $connector);
    }

    /**
     * {@inheritdoc}
     */
    public function runRequest($method, $url, $headers, $body, array $options = [])
    {
        $deferred = new Deferred();
        $count = 0;

        $sendRequest = function () use ($method, $url, $headers, $body, $options, $deferred, &$sendRequest, &$count) {
            $this->{$method}($this->makeUrl($url), $headers, $body)->then(function (ResponseInterface $response) use ($deferred, &$sendRequest, &$count) {
                if ($response->getStatusCode() !== 429 && $response->getHeader('X-RateLimit-Remaining') == 0) {
                    $this->rateLimited = true;

                    $limitEnd = Carbon::createFromTimestamp($response->getHeader('X-RateLimit-Reset'));
                    
                    $this->loop->addTimer(Carbon::now()->diffInSeconds($limitEnd), function () {
                        foreach ($this->rateLimits as $i => $d) {
                            $d->resolve();
                            unset($this->rateLimits[$i]);
                        }

                        $this->rateLimited = false;
                    });
                }

                // Discord Rate-Limiting
                if ($response->getStatusCode() == 429) {
                    $tts = (int) $response->getHeader('Retry-After')[0] / 1000;
                    $this->rateLimited = true;

                    $deferred = new Deferred();
                    $deferred->promise()->then($sendRequest);

                    $this->rateLimits[] = $deferred;

                    $this->loop->addTimer($tts, function () {
                        foreach ($this->rateLimits as $i => $d) {
                            $d->resolve();
                            unset($this->rateLimits[$i]);
                        }

                        $this->rateLimited = false;
                    });
                }
                // Bad Gateway
                // Cloudflare SSL Handshake Error
                //
                // We just retry since this is a weird error and only happens every now and then.
                elseif ($response->getStatusCode() == 502 || $response->getStatusCode() == 525) {
                    if ($count > 3) {
                        $deferred->reject($response);

                        return;
                    }

                    // Slight delay of 0.1s to satisfy Andrei and Jake
                    $this->loop->addTimer(0.1, $sendRequest);
                }
                // Handle any other codes that are not successful.
                elseif ($response->getStatusCode() < 200 || $response->getStatusCode() > 226) {
                    $deferred->reject($response);
                }
                // All is good!
                else {
                    $deferred->resolve($response);
                }
            })->otherwise(function (Exception $e) use ($deferred) {
                $deferred->reject($e);
            });
        };

        $sendRequest();

        return $deferred->promise();
    }

    /**
     * Makes a FSDN from a given endpoint.
     *
     * @param string $endpoint
     *
     * @return string
     */
    private function makeUrl($endpoint)
    {
        return Http::BASE_URL.'/v'.Discord::HTTP_API_VERSION.'/'.$endpoint;
    }
}
