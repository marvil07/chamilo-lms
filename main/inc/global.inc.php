<?php
/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Framework\Container;
use Patchwork\Utf8\Bootup;
use Symfony\Component\Dotenv\Dotenv;

/**
 * All legacy Chamilo scripts should include this important file.
 */

// Specification for user names:
// 1. ASCII-letters, digits, "." (dot), "_" (underscore) are acceptable, 40 characters maximum length.
// 2. Empty username is formally valid, but it is reserved for the anonymous user.
// 3. Checking the login_is_email portal setting in order to accept 100 chars maximum
define('USERNAME_MAX_LENGTH', 100);

require_once __DIR__.'/../../vendor/autoload.php';

try {
    // Check the PHP version
    api_check_php_version();

    // Get settings from .env.local file created.
    $envFile = __DIR__.'/../../.env.local';
    if (file_exists($envFile)) {
        (new Dotenv())->load($envFile);
    } else {
        throw new \RuntimeException('APP_ENV environment variable is not defined.
        You need to define environment variables for configuration to load variables from a .env.local file.');
    }

    $env = $_SERVER['APP_ENV'] ?? 'dev';
    $append = $_SERVER['APP_URL_APPEND'] ?? '';

    $kernel = new Chamilo\Kernel($env, true);
    // Loading Request from Sonata. In order to use Sonata Pages Bundle.
    $request = Sonata\PageBundle\Request\RequestFactory::createFromGlobals('host_with_path_by_locale');

    // This 'load_legacy' variable is needed to know that symfony is loaded using old style legacy mode,
    // and not called from a symfony controller from public/
    $request->request->set('load_legacy', true);

    // @todo fix URL loading
    $request->setBaseUrl($request->getRequestUri());
    $kernel->boot();
    if (!empty($append)) {
        if (substr($append, 0, 1) !== '/') {
            echo 'APP_URL_APPEND must start with "/"';
            exit;
        }
        $append = "$append/";
        $append .= 'public';
    } else {
        $append .= '/public';
    }

    $container = $kernel->getContainer();
    $router = $container->get('router');
    $context = $router->getContext();

    $context->setBaseUrl($append);
    $router->setContext($context);
    $response = $kernel->handle($request);
    $context = Container::getRouter()->getContext();
    $context->setBaseUrl($append);
    $container = $kernel->getContainer();

    if ($kernel->isInstalled()) {
        require_once $kernel->getConfigurationFile();
    } else {
        throw new Exception('Chamilo is not installed');
    }

    //$kernel->setApi($_configuration);
    if (!isset($GLOBALS['_configuration'])) {
        $GLOBALS['_configuration'] = $_configuration;
    }

    // Do not over-use this variable. It is only for this script's local use.
    $libraryPath = __DIR__.'/lib/';
    $container = $kernel->getContainer();

    // Symfony uses request_stack now
    $container->get('request_stack')->push($request);

    // Connect Chamilo with the Symfony container
    // Container::setContainer($container);
    // Container::setLegacyServices($container);

    // The code below is not needed. The connections is now made in the file:
    // src/CoreBundle/EventListener/LegacyListener.php
    // This is called when when doing the $kernel->handle
    $charset = 'UTF-8';
    // Enables the portability layer and configures PHP for UTF-8
    Bootup::initAll();
    ini_set('log_errors', '1');
    $this_section = SECTION_GLOBAL;
    //Default quota for the course documents folder
    /*$default_quota = api_get_setting('default_document_quotum');
    //Just in case the setting is not correctly set
    if (empty($default_quota)) {
        $default_quota = 100000000;
    }
    define('DEFAULT_DOCUMENT_QUOTA', $default_quota);*/
} catch (Exception $e) {
    var_dump($e->getMessage());
    var_dump($e->getCode());
    var_dump($e->getLine());
    /*echo $e->getMessage();    exit;
    var_dump($e->getMessage());
    var_dump($e->getCode());
    var_dump($e->getLine());
    echo $e->getTraceAsString();
    exit;*/
}
