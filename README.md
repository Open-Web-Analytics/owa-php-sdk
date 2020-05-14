# Open Web Analytics SDK for PHP
The OWA Software Development Kit for PHP makes it easy to perform web analytics tracking and administrative tasks from within PHP code. You can get started by installing The SDK via composer or downloading a tarball of our latest release. The SDK is licensed under GLP v2.0.

## Resource

- [User Guide](https://github.com/Open-Web-Analytics/owa-php-sdk/wiki)
- [Issues](https://github.com/Open-Web-Analytics/owa-php-sdk/wiki)

## Getting Help

We use Github for managing bugs and feature requests and have limited bandwidth to provide general support. If you do require support [please consider sponsoring the project](https://github.com/sponsors/padams).


- If you have found a bug please [open a new issue](https://github.com/Open-Web-Analytics/owa-php-sdk/wiki).

## Opening Issues

If you find a bug in the SDK, please let us know about it. However, before you create the ticket please search through the existing issues to make sure you it's not someting we already know nabout or have encountered in the past. When creating a new issue be sure to include the version of SDK, the PHP version, and operating system you are using. Also include a stack trace and detailed steps to reproduce the bug when appropriate.

## Getting Started

## Quick Example

### Create a Tracker ###

```php
<?php require_once('owa-php-sdk/owa-autoloader.php');

$config = [
    'instance_url' => 'http://standalone-php5-test.openwebanalytics.com/owa/'
];

$sdk = new OwaSdk\sdk($config);
$tracker = $sdk->createTracker();
$tracker->setSiteId('9ceefbab8a804bc03cb0be196abe12f8');
$tracker->setPageTitle('Standalone PHP Test Page3');
$tracker->trackPageView();

?>

```

## Realted OWA Products

- [Open Web Analytics Core](https://github.com/Open-Web-Analytics/open-web-analytics)
- [WordPress Integration Plugin](https://github.com/Open-Web-Analytics/owa-wordpress-plugin)
- [MediaWiki Integration Extension](https://github.com/Open-Web-Analytics/owa-mediawiki-extension/)
