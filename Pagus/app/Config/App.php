<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{
    public string $baseURL = 'http://pagus.test/';
    public string $indexPage = '';
    public string $uriProtocol = 'REQUEST_URI';
    public string $permittedURIChars = 'a-z 0-9~%.:_\\-';
    public string $proxyIPs = '';
    public string $allowedHostnames = '';
    public bool $forceGlobalSecureRequests = false;
    public bool $negotiateLocale = false;
    public array $supportedLocales = ['ko'];
    public bool $CSPEnabled = false;
    public string $defaultLocale = 'ko';
    public string $appTimezone = 'Asia/Seoul';
    public string $encryptionKey = '';
}
