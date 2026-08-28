<?php

namespace App\Tests\Panther;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\Panther\PantherTestCase;

abstract class BasePantherTestCase extends PantherTestCase
{
    protected static function createPantherClient(array $options = [], array $kernelOptions = [], array $managerOptions = []): PantherClient
    {
        $options['browser'] = $options['browser'] ?? self::SELENIUM;
        $options['external_base_uri'] = $options['external_base_uri'] ?? $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? 'http://aerie-app';
        $managerOptions['host'] = $managerOptions['host'] ?? $_SERVER['PANTHER_WEBDRIVER_URL'] ?? 'http://aerie-selenium:4444/wd/hub';

        if (!isset($managerOptions['capabilities'])) {
            $capabilities = DesiredCapabilities::chrome();
            $chromeOptions = new ChromeOptions();
            $chromeOptions->addArguments([
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--window-size=1920,1080',
                '--ignore-certificate-errors',
                '--allow-insecure-localhost',
            ]);
            $capabilities->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);
            $capabilities->setCapability('acceptInsecureCerts', true);
            $managerOptions['capabilities'] = $capabilities;
        }

        return parent::createPantherClient($options, $kernelOptions, $managerOptions);
    }

    protected function loginUser(PantherClient $client, string $email = 'test.test@test.se', string $password = 'test'): void
    {
        $crawler = $client->request('GET', '/login');
        if ($crawler->filter('input[name="_username"]')->count() > 0) {
            $client->findElement(WebDriverBy::name('_username'))->sendKeys($email);
            $client->findElement(WebDriverBy::name('_password'))->sendKeys($password);
            $client->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();
            // Wait for redirect to finish and navigation/logout link to appear
            $client->waitFor('a[href="/logout"], a[href="/logout"] span, body', 4);
            usleep(500000); // 500ms safety for session propagation
        }
    }
}
