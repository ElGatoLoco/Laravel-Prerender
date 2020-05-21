<?php

namespace Nutsweb\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nutsweb\LaravelPrerender\PrerenderMiddleware;

class FetchPrerenderedPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $returnSoftHttpCodes;
    protected $url;
    protected $headers;

    /**
     * Create a new job instance.
     *
     * @param  Podcast  $podcast
     * @return void
     */
    public function __construct($returnSoftHttpCodes, $url, $headers)
    {
        $this->returnSoftHttpCodes = $returnSoftHttpCodes;
        $this->url = $url;
        $this->headers = $headers;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            PrerenderMiddleware::fetchPrerenderedPage($this->returnSoftHttpCodes, $this->url, $this->headers);
        } catch (HttpException $e) {
            //
        } finally {
            $this->delete();
        }
    }
}
