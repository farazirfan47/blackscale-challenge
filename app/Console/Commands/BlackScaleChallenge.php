<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use DOMDocument;
use DOMXPath;

class BlackScaleChallenge extends Command
{
    protected $signature = 'blackscale:challenge';
    protected $description = 'Complete the BlackScale Media Challenge';

    public function handle()
    {
        $client = $this->getGuzzleClient();
        $mailbox = $this->generateMailBox();
        $email = $mailbox["name"] . "@developermail.com";

        $this->info("Generated email: $email");

        $jar = new CookieJar();

        // Set necessary cookies
        $this->initializeCookies($client, $jar);
        $this->info("Initialized cookies");

        // Get hidden form fields
        $hiddenFields = $this->getHiddenFields($client, $jar);
        $this->info("Retrieved hidden form fields");

        // Prepare form data
        $data = $this->prepareFormData($hiddenFields, $email);
        $this->info("Prepared form data for registration");

        // Send registration request
        $this->sendRegistrationRequest($client, $jar, $data);
        $this->info("Sent registration request");

        // Wait for the verification email
        $this->info("Waiting for the verification email...");
        sleep(3);

        // Retrieve and send verification code
        $latestEmailMsgId = $this->getLatestEmailMsg($mailbox["token"], $mailbox["name"]);
        $code = $this->getMsgById($mailbox["token"], $mailbox["name"], $latestEmailMsgId);
        $this->info("Retrieved verification code: $code");

        $this->sendVerificationCode($code, $jar);
        $this->info("Sent verification code");
    }

    /**
     * Initialize cookies by making an initial request.
     *
     * @param Client $client
     * @param CookieJar $jar
     */
    private function initializeCookies(Client $client, CookieJar $jar)
    {
        $client->request('GET', 'https://challenge.blackscale.media', ['cookies' => $jar]);
    }

    /**
     * Get hidden form fields from the registration page.
     *
     * @param Client $client
     * @param CookieJar $jar
     * @return array
     */
    private function getHiddenFields(Client $client, CookieJar $jar)
    {
        $response = $client->request('GET', 'https://challenge.blackscale.media/register.php', ['cookies' => $jar]);
        $html = (string)$response->getBody();

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html);
        libxml_clear_errors();

        $hiddenFields = [];
        foreach ($doc->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('type') === 'hidden') {
                $hiddenFields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        return $hiddenFields;
    }

    /**
     * Prepare form data for the registration request.
     *
     * @param array $hiddenFields
     * @param string $email
     * @return array
     */
    private function prepareFormData(array $hiddenFields, string $email)
    {
        return array_merge($hiddenFields, [
            'fullname' => 'test',
            'email' => $email,
            'password' => '12345678',
            'email_signature' => base64_encode($email)
        ]);
    }

    /**
     * Send the registration request to the server.
     *
     * @param Client $client
     * @param CookieJar $jar
     * @param array $data
     */
    private function sendRegistrationRequest(Client $client, CookieJar $jar, array $data)
    {
        $client->request('POST', 'https://challenge.blackscale.media/verify.php', [
            'form_params' => $data,
            'cookies' => $jar,
            'headers' => [
                'Origin' => 'https://challenge.blackscale.media',
                'Referer' => 'https://challenge.blackscale.media/register.php'
            ]
        ]);
    }

    /**
     * Send the verification code to the server.
     *
     * @param string $code
     * @param CookieJar $jar
     */
    private function sendVerificationCode(string $code, CookieJar $jar)
    {
        $client = $this->getGuzzleClient();
        $url = "https://challenge.blackscale.media/captcha.php";
        $response = $client->request('POST', $url, [
            'form_params' => ['code' => $code],
            'headers' => [
                'Origin' => 'https://challenge.blackscale.media',
                'Referer' => 'https://challenge.blackscale.media/verify.php'
            ],
            'cookies' => $jar
        ]);

        if ($response->getStatusCode() == 200) {
            $html = (string)$response->getBody();
            $sitekey = $this->extractSiteKey($html);

            if ($sitekey) {
                $captchaKey = $this->getCaptchaKey($sitekey);
                $this->completeChallenge($captchaKey, $jar);
            }
        }
    }

    /**
     * Extract the site key for the captcha from the HTML response.
     *
     * @param string $html
     * @return string|null
     */
    private function extractSiteKey(string $html)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $node = $xpath->query('//div[@class="g-recaptcha"]')->item(0);
        return $node ? $node->getAttribute('data-sitekey') : null;
    }

    /**
     * Complete the challenge by sending the captcha key.
     *
     * @param string $captchaKey
     * @param CookieJar $jar
     */
    private function completeChallenge(string $captchaKey, CookieJar $jar)
    {
        $client = $this->getGuzzleClient();
        $response = $client->request('POST', 'https://challenge.blackscale.media/complete.php', [
            'form_params' => ['g-recaptcha-response' => $captchaKey],
            'cookies' => $jar,
            'headers' => [
                'Origin' => 'https://challenge.blackscale.media',
                'Referer' => 'https://challenge.blackscale.media/captcha.php'
            ]
        ]);

        if ($response->getStatusCode() == 200) {
            $html = (string)$response->getBody();
            $this->info("Challenge completed successfully!");
            $this->info($html);
        }
    }

    /**
     * Retrieve the captcha key using the 2Captcha service.
     *
     * @param string $siteKey
     * @return string
     */
    private function getCaptchaKey($siteKey)
    {
        try {
            $solver = new \TwoCaptcha\TwoCaptcha('8e4d4bde7dda19b43976d7177f389f27');
            $result = $solver->recaptcha([
                'sitekey' => $siteKey,
                'url' => 'https://challenge.blackscale.media/captcha.php',
            ]);
            return $result->code;
        } catch (\Exception $e) {
            $this->error("You do not have enough balance to solve the captcha");
            exit;
        }
    }

    /**
     * Get a Guzzle client with the necessary headers.
     *
     * @return Client
     */
    private function getGuzzleClient()
    {
        return new Client([
            'cookies' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'
            ]
        ]);
    }

    /**
     * Generate a new email mailbox.
     *
     * @return array|null
     */
    private function generateMailBox()
    {
        $client = $this->getGuzzleClient();
        $response = $client->request('PUT', 'https://www.developermail.com/api/v1/mailbox', [
            'Accept' => 'application/json'
        ]);
        $data = json_decode($response->getBody(), true);
        return $data["success"] ? $data["result"] : null;
    }

    /**
     * Get the latest email message ID from the mailbox.
     *
     * @param string $mailboxToken
     * @param string $mailBoxName
     * @return mixed
     */
    private function getLatestEmailMsg($mailboxToken, $mailBoxName)
    {
        $client = $this->getGuzzleClient();
        $response = $client->get('https://www.developermail.com/api/v1/mailbox/' . $mailBoxName, [
            'headers' => [
                'accept' => 'application/json',
                'X-MailboxToken' => $mailboxToken
            ]
        ]);
        $data = json_decode($response->getBody(), true);
        return $data["success"] ? $data["result"][0] : null;
    }

    /**
     * Get the email message by its ID.
     *
     * @param string $mailboxToken
     * @param string $mailBoxName
     * @param string $msgId
     * @return string|null
     */
    private function getMsgById($mailboxToken, $mailBoxName, $msgId)
    {
        $client = $this->getGuzzleClient();
        $response = $client->get('https://www.developermail.com/api/v1/mailbox/' . $mailBoxName . '/messages/' . $msgId, [
            'headers' => [
                'accept' => 'application/json',
                'X-MailboxToken' => $mailboxToken
            ]
        ]);
        $data = json_decode($response->getBody(), true);
        if ($data["success"]) {
            preg_match('/Your verification code is: (\w+)/', $data["result"], $matches);
            return $matches[1];
        }
        return null;
    }
}