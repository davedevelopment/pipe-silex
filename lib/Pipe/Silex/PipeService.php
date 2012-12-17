<?php

namespace Pipe\Silex;

use Pipe\AssetDumper;
use Silex\Application;
use Jazz;
use CHH\Silex\CacheServiceProvider\CacheNamespace;

class PipeService
{
    protected $app;
    protected $manifest;

    function __construct(Application $app)
    {
        $this->app = $app;
    }

    function precompile()
    {
        $dir = $this->app['pipe.precompile_directory'];
        $dumper = new AssetDumper($dir);

        foreach ($this->app['pipe.precompile'] as $logicalPath) {
            $asset = $this->app['pipe.environment']->find($logicalPath, array('bundled' => true));

            if (!$asset) {
                throw new \UnexpectedValueException("Asset '$logicalPath' not found.");
            }

            $dumper->add($asset);
        }

        $dumper->dump();
    }

    function assetLink($logicalPath)
    {
        if (!isset($this->app["pipe.use_precompiled"]) or false == $this->app["pipe.use_precompiled"]) {
            return $this->app["url_generator"]->generate(
                PipeServiceProvider::ROUTE_ASSET, array("logicalPath" => $logicalPath)
            );
        }

        $manifest = $this->manifest();

        if (isset($manifest->$logicalPath)) {
            $path = "{$this->app["pipe.prefix"]}/{$manifest->$logicalPath}";

            $acceptedEncodings = $this->app['request']->headers->get('Accept-Encoding');

            if (strpos($acceptedEncodings, 'gzip') !== false and php_sapi_name() !== 'cli-server') {
                $path .= '.gz';
            }

            return $path;
        }
    }

    function assetLinkTag($logicalPath)
    {
        $asset = $this->app['pipe.environment']->find($logicalPath);
        $html = '';

        if (!$asset) {
            return "";
        }

        $contentType = $asset->getContentType();

        switch ($contentType) {
            case "application/javascript":
                $html = Jazz::render(array(
                    '#script', array('src' => $this->assetLink($logicalPath), 'type' => $contentType)
                ));
                break;
            case "text/css":
                $html = Jazz::render(array(
                    '#link', array('rel' => 'stylesheet', 'href' => $this->assetLink($logicalPath), 'type' => $contentType)
                ));
                break;
            default:
                if (stripos($contentType, 'image/') === 0) {
                    $html = Jazz::render(array(
                        '#img', array('src' => $this->assetLink($logicalPath), 'alt' => '')
                    ));
                }
                break;
        }

        return $html;
    }

    protected function manifest()
    {
        if (null === $this->manifest) {
            if (isset($this->app['caches'])) {
                $cache = $this->app['caches']['pipe'];

                if ($cache->contains('manifest')) {
                    $this->manifest = $cache->fetch('manifest');
                } else {
                    $this->manifest = $this->fetchManifest();
                    $cache->save('manifest', $this->manifest);
                }
            } else {
                $this->manifest = $this->fetchManifest();
            }
        }

        return $this->manifest;
    }

    private function fetchManifest()
    {
        return json_decode(@file_get_contents($this->app["pipe.manifest"]));
    }
}
