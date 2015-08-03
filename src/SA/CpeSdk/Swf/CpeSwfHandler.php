<?php

namespace SA\CpeSdk\Swf;

use Aws\Swf\SwfClient;

// SA Cpe SDK
use SA\CpeSdk;

/**
 * Create the AWS SWF connection
 * Check for AWS environment variables
 */
class CpeSwfHandler
{
    public $swf;

    public function __construct()
    {
        # Check if preper env vars are setup
        if (!($region = getenv("AWS_DEFAULT_REGION")))
            throw new CpeSdk\CpeException("Set 'AWS_DEFAULT_REGION' environment variable!");

        // SWF client
        $this->swf = SwfClient::factory(array(
            'region'  => $region
        ));
    }
}