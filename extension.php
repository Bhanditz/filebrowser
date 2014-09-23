<?php
/**
 * A file browser extension that exposes Bolt's /files directory to the front-end,
 * implementing a file-manager-like UI in the front-end.
 *
 * @author Tobias Dammers <tobias@twokings.nl>
 */

namespace FileBrowser;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Finder\Finder;

class Extension extends \Bolt\BaseExtension
{
    public function info()
    {
        return array(
            'name' => "FileBrowser",
            'description' => "Exposes /files on the front-end",
            'author' => "Tobias Dammers",
            'link' => "http://bolt.cm",
            'version' => "0.1",
            'required_bolt_version' => "1.6.0",
            'type' => "General",
            'first_releasedate' => null,
            'latest_releasedate' => null,
            'priority' => 10
        );
    }

    public function initialize()
    {
        $this->addJquery();
        $scriptPath = $this->app['paths']['app'] . "extensions/FileBrowser/assets/file_browser.js";
        $this->app['extensions']->addJavascript($scriptPath, false);
        $this->app->get("/async/file_browser", array($this, "asyncGetFiles"))->bind("file_browser_get");
        $this->addTwigFunction('file_browser', 'twigFileBrowser');
    }

    private function validateMode($mode) {
        $allowedModes = array('list', 'icons');
        if (!in_array($mode, $allowedModes)) {
            $imploded = implode(', ', $allowedModes);
            throw new \Exception("Invalid mode: $mode, allowed modes are: $imploded");
        }
    }

    private function listFiles($rootPath, $currentPath) {
        $path = '';
        if (!empty($rootPath)) {
            $path .= "$rootPath/";
        }
        if (!empty($currentPath)) {
            $path .= "$currentPath/";
        }
        $finder = new Finder();
        $extensionRegex = '/^[^\.]*$|\\.' . implode('|',
            array_map('preg_quote',
                $this->app['config']->get('general/accept_file_types'))) . '$/';
        error_log($extensionRegex);
        $files =
            $finder
                ->depth('== 0')
                ->name($extensionRegex)
                ->notName('*.exe')
                ->notName('*.php')
                ->notName('*.html')
                ->notName('*.js')
                ->sortByName()
                ->sortByType()
                ->in($this->app['paths']['filespath'] . "/$path");
        return iterator_to_array($files);
    }

    private function splitPath($path) {
        $f = function($part) {
            // No whitespace around components
            $part = trim($part);
            // No empty components
            if (empty($part)) {
                return false;
            }
            // No hidden components
            if ($part[0] === '.') {
                return false;
            }
            return true;
        };
        return array_filter(explode('/', $path), $f);
    }

    private function getContext($mode, $rootPath, $currentPath) {
        $paths = $this->sanitizePaths($rootPath, $currentPath);
        list($rootPath, $currentPath, $upPath) = array_values($paths);
        $iconsPath = $this->app['paths']['app'] . "extensions/FileBrowser/assets/icons";
        return array(
            'mode' => $mode,
            'paths' => $paths,
            'icons' => $iconsPath,
            'files' => $this->listFiles($rootPath, $currentPath));
    }

    private function sanitizePaths($rootPath = '', $currentPath = '') {
        $rootPP = $this->splitPath($rootPath);
        $currentPP = $this->splitPath($currentPath);
        if (count($currentPP)) {
            $upPP = $currentPP;
            array_pop($upPP);
        }
        else {
            $upPP = null;
        }

        return array(
            'root' => implode('/', $rootPP),
            'current' => implode('/', $currentPP),
            'up' => ($upPP === null) ? null : implode('/', $upPP),
        );
    }

    public function asyncGetFiles(Request $request) {
        $mode = ($request->get('fb_mode') ? $request->get('fb_mode') : 'list');
        $this->validateMode($mode);
        $rootPath = ($request->get('fb_root') ? $request->get('fb_root') : '');
        $currentPath = ($request->get('fb_cp') ? $request->get('fb_cp') : '');

        $context = $this->getContext($mode, $rootPath, $currentPath);
        return $this->render("list.twig", $context);
    }

    public function twigFileBrowser($mode = 'list', $rootPath = '', $currentPath = '')
    {
        $fbmode = $this->app['request']->get('fb_mode');
        if (!empty($fbmode)) {
            $mode = $fbmode;
        }
        $this->validateMode($mode);
        $fbcp = $this->app['request']->get('fb_cp');
        if (!empty($fbcp)) {
            $currentPath = $fbcp;
        }
        $context = $this->getContext($mode, $rootPath, $currentPath);
        $inner = $this->render("list.twig", $context);
        $context['inner'] = $inner;
        return new \Twig_Markup($this->render("container.twig", $context), 'UTF-8');
    }

    private function render($template, $data) {
        $this->app['twig.loader.filesystem']->addPath(dirname(__FILE__) . '/templates');
        return new \Twig_Markup($this->app['render']->render($template, $data), 'UTF-8');
    }

}

