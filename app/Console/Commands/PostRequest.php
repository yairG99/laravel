<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class PostRequest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'post:request
    {url=https://atomic.incfile.com/fakepost : Destination URL};
    {--t|title=POST Request : Title parameter of the POST request.}
    {--b|body=This is a POST request : Body parameter of the POST request.}
    {--c|concurrency=10 : Maximum number of requests to be handled simultaneously.}
    {--a|attempts=5 : Maximum number of attempts when a request fails.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a POST request to the specified URL';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $url = $this->argument('url');
        $maxAttempts = $this->option('attempts');
        $concurrencyLimit = $this->option('concurrency');

        // Configuration of staic values
        $nRequests = 5;
        $retryErrorCodes = [429, 503, 504];
        /**
         * Only retry on status code 429 (Too many requests),
         * 503 (Service Unavailable) and 504 (Gateway Timeout)
         * which imply responses that can be accepted after an
         * unknown amount of time.
         */

        /**
         * Is important to keep in mind that Network Connection
         * errors may be handled properly with non idempotent
         * methods such as POST, where is important to know whether
         * the connection failed before or after server reception.
         * This can be handled by making first a request to an
         * endpoint where an ID will be assigned to the request and
         * returned to the client, where the client will make the
         * deserved request with the provided ID and that way the
         * server will be able to check if the request has already
         * been processed.
         */

        function retryDecider($nAttempts, $errorPolicy)
        {
            return function (
                $retries,
                Request $request,
                Response $response = null,
                RequestException $exception = null
            ) use ($nAttempts, $errorPolicy) {
                // Limit the number of retries to especified number
                if ($retries >= $nAttempts) {
                    return false;
                }

                // Retry connection exceptions
                if ($exception instanceof ConnectException) {
                    return true;
                }

                if ($response) {
                    // Retry on server errors
                    $status = $response->getStatusCode();
                    if (in_array($status, $errorPolicy)) {
                        return true;
                    }
                }

                return false;
            };
        }

        function retryDelay()
        {
            return fn ($numberOfRetries) => 1000 * $numberOfRetries;
        }

        $handlerStack = HandlerStack::create(new CurlHandler());
        $handlerStack->push(Middleware::retry(
            retryDecider($maxAttempts, $retryErrorCodes),
            retryDelay()
        ));
        $client = new Client(['handler' => $handlerStack]);

        $requests = function ($total) use ($url) {
            for ($i = 0; $i < $total; $i++) {
                yield new Request(
                    'POST',
                    $url,
                    [],
                    json_encode([
                        'title' => $this->option('title'),
                        'body' => $this->option('body')
                    ])
                );
            }
        };

        /**
         * By using the pool Object we can make our requests
         * asynchronously and stablish the desired concurrency.
         * This will decrease the time to finish the requests,
         * as well as limiting the number of requests and avoid
         * server overloading.
         */
        $pool = new Pool($client, $requests($nRequests), [
            'concurrency' => $concurrencyLimit,
            'fulfilled' => function (Response $response, $index) {
                // Here you can handle succesful requests
                $this->info("Request succesful: {$response->getBody()}");
            },
            'rejected' => function (RequestException $reason, $index) {
                // Here you can handle failed requests
                $this->warn("Request #$index with error: {$reason->getResponse()->getStatusCode()}");
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->wait();

        return Command::SUCCESS;
    }
}
