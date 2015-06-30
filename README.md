## What for?

Use this SDK if you want to develop custom Activities for the Cloud Processing Engine (CPE) project.

This SDK offers classes to communicate to the CPE stack through AWS SQS. It provides a Logger class that writes logs to Syslog and also a custom CpeException class.

Let's say you want to create activities to process huge amount of trading data. You could create a project, use this SDK and drop your activities in the CPE stack.

Then clients could use the activities you created at scale using the CPE stack capabilities.

## Example: Cloud Transcode

Cloud Transcode (CT) is an example of project using this SDK.

CT transcodes media files at scale using the CPE stack. It uses this SDK to send correctly formatted SQS messages back to the clients using the the CPE stack.

## CPE Project

The CPE project is a stack that allows you to process anything at scale in AWS cloud. It uses SWF for workflow execution and SQS services for communication between the stack and the clients.

See: https://github.com/sportarchive/CloudProcessingEngine
