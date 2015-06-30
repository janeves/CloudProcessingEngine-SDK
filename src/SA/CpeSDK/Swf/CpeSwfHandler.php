<?php

namespace SA\CpeSdk\Swf;

use Aws\Swf\SwfClient;

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
            exit("Set 'AWS_DEFAULT_REGION' environment variable!");

        if (!getenv('AWS_ACCESS_KEY_ID'))
            exit("Set 'AWS_ACCESS_KEY_ID' environment variable!");
        
        if (!getenv('AWS_SECRET_ACCESS_KEY'))
            exit("Set 'AWS_SECRET_ACCESS_KEY' environment variable!");
        
        // SWF client
        $this->swf = SwfClient::factory(array(
                'region'  => $region
            ));
    }
}