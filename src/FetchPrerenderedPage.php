<?php

namespace Nutsweb\LaravelPrerender;

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
    protected $ip;

    /**
     * Create a new job instance.
     *
     * @param  Podcast  $podcast
     * @return void
     */
    public function __construct($returnSoftHttpCodes, $url, $headers, $ip)
    {
        $this->returnSoftHttpCodes = $returnSoftHttpCodes;
        $this->url = $url;
        $this->headers = $headers;
        $this->ip = $ip;
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
        } catch (\Exception $e) {
            \EmailDispatcher::sendEmail('server_error', [
                'message' => $e->getMessage(), 
                'exception' => $e->getTraceAsString(), 
                'error_url' => $this->url, 
                'user_ip' => $this->ip,
                'details' => 'This error was caused by the FetchPrerenderedPage job dispatched by nutsweb/laravel-prerender'
            ]);
        } finally {
            $this->delete();
        }
    }
}
