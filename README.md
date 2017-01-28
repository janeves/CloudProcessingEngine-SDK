[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/sportarchive/CloudProcessingEngine-SDK/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/sportarchive/CloudProcessingEngine-SDK/?branch=master) [![Build Status](https://travis-ci.org/sportarchive/CloudProcessingEngine-SDK.svg?branch=master)](https://travis-ci.org/sportarchive/CloudProcessingEngine-SDK)

## What for?

Use this SDK if you want to develop custom Activities for the Cloud Transcode or your own project.

**This SDK offers classes for your custom activities:**
   - Base Activity class to extend for your own activities
   - Client Interface to extends to create your own interface between your Activity and client application
   - Logger
   - Custom exception class

## Usage Example: Cloud Transcode

Cloud Transcode (CT) is an example of project using this SDK.

CT transcodes media files at scale using the CPE stack. It uses this SDK to implement its Activities and to report back to client applications that submitted jobs to the CPE stack.

**See:** https://github.com/sportarchive/CloudProcessingEngine

## Install

Composer package:<br>
https://packagist.org/packages/sportarchive/cloud-processing-engine-sdk

## SDK Documentation

http://sportarchive.github.io/CloudProcessingEngine-SDK/
