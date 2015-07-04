## What for?

Use this SDK if you want to develop custom Activities for the Cloud Processing Engine (CPE) project.

This SDK offers classes for your custom activities to send messages to your client via SQS (send updates, progress, etc). It provides a Logger class that writes logs to Syslog, a custom CpeException class, and a SQS and SQF handler.

## Example: Cloud Transcode

Cloud Transcode (CT) is an example of project using this SDK.

CT transcodes media files at scale using the CPE stack. It uses this SDK to send correctly formatted SQS messages back to the clients using the CPE stack.

## CPE Project

See: https://github.com/sportarchive/CloudProcessingEngine
