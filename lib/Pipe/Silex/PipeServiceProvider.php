<?php

namespace Pipe\Silex;

use Silex\Application;
use Silex\ServiceProviderInterface;

use Pipe\Environment;
use Pipe\Config;

use CHH\Silex\CacheServiceProvider\CacheNamespace;
use Symfony\Component\HttpFoundation\Response;

class PipeServiceProvider implements ServiceProviderInterface
{
    const ROUTE_ASSET = 'pipe.asset';

    function register(Application $app)
    {
        $app->register(new \Silex\Provider\UrlGeneratorServiceProvider());

        if (isset($app['caches'])) {
            $app['caches'] = $app->share($app->extend('caches', function($caches) use ($app) {
                $caches['pipe'] = new CacheNamespace('pipe', $caches['default']);
                return $caches;
            }));
        }

        $app['pipe.precompile'] = new \ArrayObject(array(
            'application.js',
            'application.css'
        ));

        $app['pipe.load_path'] = $app->share(function() use ($app) {
            $loadPath = new \SplDoublyLinkedList;

            if (isset($app['pipe.root'])) {
                $root = $app['pipe.root'];

                foreach (array(
                    "$root/images",
                    "$root/javascripts",
                    "$root/vendor/javascripts",
                    "$root/stylesheets",
                    "$root/vendor/stylesheets",
                ) as $path) {
                    $loadPath->push($path);
                }
            }

            return $loadPath;
        });

        $app["pipe"] = $app->share(function($app) {
            return new PipeService($app);
        });

        $app['pipe.environment'] = $app->share(function() use ($app) {
            $config = new Config;
            $config->debug = isset($app['pipe.debug']) ? $app['pipe.debug'] : false;

            if (isset($app['pipe.css_compressor'])) {
                $config->cssCompressor = $app['pipe.css_compressor'];
            }

            if (isset($app['pipe.js_compressor'])) {
                $config->jsCompressor = $app['pipe.js_compressor'];
            }

            $environment = $config->createEnvironment();

            foreach ($app["pipe.load_path"] as $path) {
                $environment->appendPath($path);
            }

            return $environment;
        });

        if (isset($app["twig"])) {
            $app['twig'] = $app->share($app->extend('twig', function($twig) {
                $twig->addExtension(new PipeTwigExtension);

                return $twig;
            }));
        }

        $app->get("/_pipe/asset/{logicalPath}", function($logicalPath) use ($app) {
            $asset = $app["pipe.environment"]->find($logicalPath, array('bundled' => true));

            if (!$asset) {
                return $app->abort(404, "Asset '$logicalPath' not found");
            }

            $lastModified = new \DateTime;
            $lastModified->setTimestamp($asset->getLastModified());

            $res = new Response();
            $res->setPublic();
            $res->setLastModified($lastModified);

            if ($res->isNotModified($app['request'])) {
                return $res;
            }

            $time = microtime(true);

            $res->headers->set("Content-Type", $asset->getContentType());
            $res->headers->set("Content-Length", strlen($asset->getBody()));
            $res->setContent($asset->getBody());

            if (isset($app["monolog"]) and $app["monolog"] !== null) {
                $d = microtime(true) - $time;

                $app["monolog"]->addInfo(
                    sprintf('pipe: Generated "%s" in %f seconds', $logicalPath, $d), array(
                        'time' => $d, 'path' => $logicalPath, 'realpath' => $asset->path
                    )
                );
            }

            return $res;
        })
        ->assert("logicalPath", ".+")
        ->bind(self::ROUTE_ASSET);
    }

    function boot(Application $app)
    {
    }
}
