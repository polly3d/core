<?php
/**
 * @author Sergio Bertolin <sbertolin@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use TestHelpers\OcsApiHelper;

require __DIR__ . '/../../../../lib/composer/autoload.php';

/**
 * Basic functions needed by mostly everything
 */
trait BasicStructure {

	use AppConfiguration;
	use Auth;
	use Checksums;
	use Comments;
	use MailTool;
	use Provisioning;
	use Sharing;
	use Tags;
	use Trashbin;
	use WebDav;
	use CommandLine;

	/**
	 * @var array 
	 */
	private $adminUsername = '';

	/**
	 * @var array
	 */
	private $adminPassword = '';

	/**
	 * @var string
	 */
	private $regularUserPassword = '';

	/**
	 * @var string 
	 */
	private $currentUser = '';

	/**
	 * @var string 
	 */
	private $currentServer = '';

	/**
	 * @var string 
	 */
	private $baseUrl = '';

	/**
	 * @var string
	 */
	private $localBaseUrl = '';

	/**
	 * @var string
	 */
	private $remoteBaseUrl = '';

	/**
	 * @var int 
	 */
	private $apiVersion = 1;

	/**
	 * @var ResponseInterface 
	 */
	private $response = null;

	/**
	 * @var \GuzzleHttp\Cookie\CookieJar 
	 */
	private $cookieJar;

	/**
	 * @var string 
	 */
	private $requestToken;

	/**
	 * BasicStructure constructor.
	 *
	 * @param string $baseUrl
	 * @param string $adminUsername
	 * @param string $adminPassword
	 * @param string $regularUserPassword
	 * @param string $mailhogUrl
	 * @param string $ocPath
	 *
	 */
	public function __construct(
		$baseUrl, $adminUsername, $adminPassword, $regularUserPassword, $mailhogUrl, $ocPath
	) {

		// Initialize your context here
		$this->baseUrl = $baseUrl;
		$this->adminUsername = $adminUsername;
		$this->adminPassword = $adminPassword;
		$this->regularUserPassword = $regularUserPassword;
		$this->mailhogUrl = $mailhogUrl;
		$this->localBaseUrl = $this->baseUrl;
		$this->remoteBaseUrl = $this->baseUrl;
		$this->currentServer = 'LOCAL';
		$this->cookieJar = new \GuzzleHttp\Cookie\CookieJar();
		$this->ocPath = $ocPath;

		// in case of CI deployment we take the server url from the environment
		$testServerUrl = getenv('TEST_SERVER_URL');
		if ($testServerUrl !== false) {
			$this->baseUrl = $testServerUrl;
			$this->localBaseUrl = $testServerUrl;
		}

		// federated server url from the environment
		$testRemoteServerUrl = getenv('TEST_SERVER_FED_URL');
		if ($testRemoteServerUrl !== false) {
			$this->remoteBaseUrl = $testRemoteServerUrl;
		}
	}

	/**
	 * Override the baseUrl that came via the behat.yml and context constructor.
	 * Use this when running in an environment that passes the baseUrl from some
	 * external script. For example, the webUI acceptance tests build up the
	 * baseUrl from environment variables and the script passes the value in as
	 * a Mink parameter.
	 *
	 * @param string $newBaseUrl in the format that the webUI tests use
	 *
	 * @return void
	 */
	public function overrideBaseUrlWithWebUIValue($newBaseUrl) {
		// baseUrl in the API tests featureContext uses a form with '/ocs/'
		// on the end so add that.
		if (substr($newBaseUrl, -1) !== '/') {
			$newBaseUrl .= '/';
		}

		$newBaseUrl .= 'ocs/';
		$this->baseUrl = $newBaseUrl;
		$this->localBaseUrl = $this->baseUrl;
		$this->remoteBaseUrl = $this->baseUrl;
	}

	/**
	 * returns the base URL without the /ocs part
	 *
	 * @return string
	 */
	public function baseUrlWithoutOCSAppendix() {
		return substr($this->baseUrl, 0, -4);
	}

	/**
	 * @Given /^using (?:api|API) version "([^"]*)"$/
	 *
	 * @param string $version
	 *
	 * @return void
	 */
	public function usingApiVersion($version) {
		$this->apiVersion = $version;
	}

	/**
	 * @Given /^as user "([^"]*)"$/
	 *
	 * @param string $user
	 *
	 * @return void
	 */
	public function asUser($user) {
		$this->currentUser = $user;
	}

	/**
	 * @return string
	 */
	public function getCurrentUser() {
		return $this->currentUser;
	}

	/**
	 * @Given /^using server "(LOCAL|REMOTE)"$/
	 *
	 * @param string $server
	 *
	 * @return string Previous used server
	 */
	public function usingServer($server) {
		$previousServer = $this->currentServer;
		if ($server === 'LOCAL') {
			$this->baseUrl = $this->localBaseUrl;
			$this->currentServer = 'LOCAL';
		} else {
			$this->baseUrl = $this->remoteBaseUrl;
			$this->currentServer = 'REMOTE';
		}
		return $previousServer;
	}

	/**
	 * @When /^the user sends HTTP method "([^"]*)" to API endpoint "([^"]*)"$/
	 * @Given /^the user has sent HTTP method "([^"]*)" to API endpoint "([^"]*)"$/
	 *
	 * @param string $verb
	 * @param string $url
	 *
	 * @return void
	 */
	public function sendingTo($verb, $url) {
		$this->sendingToWith($verb, $url, null);
	}

	/**
	 * @When /^user "([^"]*)" sends HTTP method "([^"]*)" to API endpoint "([^"]*)"$/
	 * @Given /^user "([^"]*)" has sent HTTP method "([^"]*)" to API endpoint "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $verb
	 * @param string $url
	 *
	 * @return void
	 */
	public function userSendingTo($user, $verb, $url) {
		$this->userSendsHTTPMethodToAPIEndpointWithBody(
			$user,
			$verb,
			$url,
			null
		);
	}

	/**
	 * Parses the xml answer to get ocs response which doesn't match with
	 * http one in v1 of the api.
	 *
	 * @param ResponseInterface $response
	 *
	 * @return string
	 */
	public function getOCSResponseStatusCode($response) {
		return (string) $this->getResponseXml($response)->meta[0]->statuscode;
	}

	/**
	 * Parses the response as XML
	 *
	 * @param ResponseInterface $response
	 * 
	 * @return SimpleXMLElement
	 */
	public function getResponseXml($response = null) {
		if ($response === null) {
			$response = $this->response;
		}
		// rewind just to make sure we can re-parse it in case it was parsed already...
		$response->getBody()->rewind();
		return new SimpleXMLElement($response->getBody()->getContents());
	}

	/**
	 * Parses the xml answer to get the requested key and sub-key
	 *
	 * @param ResponseInterface $response
	 * @param string $key1
	 * @param string $key2
	 *
	 * @return string
	 */
	public function getXMLKey1Key2Value($response, $key1, $key2) {
		return $this->getResponseXml($response)->$key1->$key2;
	}

	/**
	 * Parses the xml answer to get the requested key sequence
	 *
	 * @param ResponseInterface $response
	 * @param string $key1
	 * @param string $key2
	 * @param string $key3
	 *
	 * @return string
	 */
	public function getXMLKey1Key2Key3Value($response, $key1, $key2, $key3) {
		return $this->getResponseXml($response)->$key1->$key2->$key3;
	}

	/**
	 * Parses the xml answer to get the requested attribute value
	 *
	 * @param ResponseInterface $response
	 * @param string $key1
	 * @param string $key2
	 * @param string $key3
	 * @param string $attribute
	 *
	 * @return string
	 */
	public function getXMLKey1Key2Key3AttributeValue(
		$response, $key1, $key2, $key3, $attribute
	) {
		return (string) $this->getResponseXml($response)->$key1->$key2->$key3->attributes()->$attribute;
	}

	/**
	 * This function is needed to use a vertical fashion in the gherkin tables.
	 *
	 * @param array $arrayOfArrays
	 *
	 * @return array
	 */
	public function simplifyArray($arrayOfArrays) {
		$a = array_map(
			function ($subArray) {
				return $subArray[0]; 
			}, $arrayOfArrays
		);
		return $a;
	}

	/**
	 * @When /^the user sends HTTP method "([^"]*)" to API endpoint "([^"]*)" with body$/
	 * @Given /^the user has sent HTTP method "([^"]*)" to API endpoint "([^"]*)" with body$/
	 *
	 * @param string $verb
	 * @param string $url
	 * @param TableNode $body
	 *
	 * @return void
	 */
	public function sendingToWith($verb, $url, $body) {
		$this->userSendsHTTPMethodToAPIEndpointWithBody(
			$this->currentUser,
			$verb,
			$url,
			$body
		);
	}

	/**
	 * @When /^user "([^"]*)" sends HTTP method "([^"]*)" to API endpoint "([^"]*)" with body$/
	 * @Given /^user "([^"]*)" has sent HTTP method "([^"]*)" to API endpoint "([^"]*)" with body$/
	 *
	 * @param string $user
	 * @param string $verb
	 * @param string $url
	 * @param TableNode $body
	 *
	 * @return void
	 */
	public function userSendsHTTPMethodToAPIEndpointWithBody(
		$user, $verb, $url, $body
	) {

		/**
		 * array of the data to be sent in the body.
		 * contains $body data converted to an array
		 *
		 * @var array $bodyArray
		 */
		$bodyArray = [];
		if ($body instanceof TableNode) {
			$bodyArray = $body->getRowsHash();
		}

		if ($user !== 'UNAUTHORIZED_USER') {
			$password = $this->getPasswordForUser($user);
		} else {
			$user = null;
			$password = null;
		}

		$this->response = OcsApiHelper::sendRequest(
			$this->baseUrlWithoutOCSAppendix(),
			$user, $password, $verb, $url, $bodyArray, $this->apiVersion
		);

	}

	/**
	 * @When /^user "([^"]*)" sends HTTP method "([^"]*)" to URL "([^"]*)"$/
	 * @Given /^user "([^"]*)" has sent HTTP method "([^"]*)" to URL "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $verb
	 * @param string $url
	 *
	 * @return void
	 */
	public function userSendsHTTPMethodToUrl($user, $verb, $url) {
		$this->sendingToWithDirectUrl($user, $verb, $url, null);
	}

	/**
	 * @param string $user
	 * @param string $verb
	 * @param string $url
	 * @param TableNode $body
	 *
	 * @return void
	 */
	public function sendingToWithDirectUrl($user, $verb, $url, $body) {
		$fullUrl = substr($this->baseUrl, 0, -5) . $url;
		$client = new Client();
		$options = [];
		$options['auth'] = $this->getAuthOptionForUser($user);

		if (!empty($this->cookieJar->toArray())) {
			$options['cookies'] = $this->cookieJar;
		}

		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			$options['form_params'] = $fd;
		}

		try {
			$headers = [];
			if (isset($this->requestToken)) {
				$headers['requesttoken'] = $this->requestToken;
			}
			$request = new Request($verb, $fullUrl, $headers);
			$this->response = $client->send($request, $options);
		} catch (BadResponseException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	/**
	 * @param string $possibleUrl
	 * @param string $finalPart
	 *
	 * @return bool
	 */
	public function isExpectedUrl($possibleUrl, $finalPart) {
		$baseUrlChopped = $this->baseUrlWithoutOCSAppendix();
		$endCharacter = strlen($baseUrlChopped) + strlen($finalPart);
		return (substr($possibleUrl, 0, $endCharacter) == "$baseUrlChopped" . "$finalPart");
	}

	/**
	 * @Then /^the OCS status code should be "([^"]*)"$/
	 *
	 * @param int $statusCode
	 *
	 * @return void
	 */
	public function theOCSStatusCodeShouldBe($statusCode) {
		PHPUnit_Framework_Assert::assertEquals(
			$statusCode, $this->getOCSResponseStatusCode($this->response)
		);
	}

	/**
	 * @Then /^the HTTP status code should be "([^"]*)"$/
	 *
	 * @param int $statusCode
	 *
	 * @return void
	 */
	public function theHTTPStatusCodeShouldBe($statusCode) {
		PHPUnit_Framework_Assert::assertEquals(
			$statusCode, $this->response->getStatusCode()
		);
	}

	/**
	 * @Then /^the XML "([^"]*)" "([^"]*)" value should be "([^"]*)"$/
	 *
	 * @param string $key1
	 * @param string $key2
	 * @param string $idText
	 *
	 * @return void
	 */
	public function theXMLKey1Key2ValueShouldBe($key1, $key2, $idText) {
		PHPUnit_Framework_Assert::assertEquals(
			$idText,
			$this->getXMLKey1Key2Value($this->response, $key1, $key2)
		);
	}

	/**
	 * @Then /^the XML "([^"]*)" "([^"]*)" "([^"]*)" value should be "([^"]*)"$/
	 *
	 * @param string $key1
	 * @param string $key2
	 * @param string $key3
	 * @param string $idText
	 *
	 * @return void
	 */
	public function theXMLKey1Key2Key3ValueShouldBe($key1, $key2, $key3, $idText) {
		PHPUnit_Framework_Assert::assertEquals(
			$idText,
			$this->getXMLKey1Key2Key3Value($this->response, $key1, $key2, $key3)
		);
	}

	/**
	 * @Then /^the XML "([^"]*)" "([^"]*)" "([^"]*)" "([^"]*)" attribute value should be a valid version string$/
	 *
	 * @param string $key1
	 * @param string $key2
	 * @param string $key3
	 * @param string $attribute
	 *
	 * @return void
	 */
	public function theXMLKey1Key2AttributeValueShouldBe(
		$key1, $key2, $key3, $attribute
	) {
		$value = $this->getXMLKey1Key2Key3AttributeValue(
			$this->response, $key1, $key2, $key3, $attribute
		);
		PHPUnit_Framework_Assert::assertTrue(
			version_compare($value, '0.0.1') >= 0,
			'attribute ' . $attribute . ' value ' . $value . ' is not a valid version string'
		);
	}

	/**
	 * @param ResponseInterface $response
	 *
	 * @return void
	 */
	private function extracRequestTokenFromResponse(ResponseInterface $response) {
		$this->requestToken = substr(
			preg_replace(
				'/(.*)data-requesttoken="(.*)">(.*)/sm', '\2',
				$response->getBody()->getContents()
			),
			0,
			89
		);
	}

	/**
	 * @When /^user "([^"]*)" logs in to a web-style session using the API$/
	 * @Given /^user "([^"]*)" has logged in to a web-style session using the API$/
	 *
	 * @param string $user
	 *
	 * @return void
	 */
	public function userHasLoggedInToAWebStyleSessionUsingTheAPI($user) {
		$loginUrl = substr($this->baseUrl, 0, -5) . '/login';
		// Request a new session and extract CSRF token
		$client = new Client();
		$response = $client->get(
			$loginUrl,
			[
				'cookies' => $this->cookieJar,
			]
		);
		$this->extracRequestTokenFromResponse($response);

		// Login and extract new token
		$password = $this->getPasswordForUser($user);
		$client = new Client();
		$response = $client->post(
			$loginUrl,
			[
				'form_params' => [
					'user' => $user,
					'password' => $password,
					'requesttoken' => $this->requestToken,
				],
				'cookies' => $this->cookieJar,
			]
		);
		$this->extracRequestTokenFromResponse($response);
	}

	/**
	 * @When the client sends a :method to :url with requesttoken using the API
	 * @Given the client has sent a :method to :url with requesttoken
	 *
	 * @param string $method
	 * @param string $url
	 *
	 * @return void
	 */
	public function sendingAToWithRequesttoken($method, $url) {
		$baseUrl = substr($this->baseUrl, 0, -5);

		$client = new Client();
		$request = new Request(
			$method,
			$baseUrl . $url,
			['requesttoken' => $this->requestToken]
		);
		$options = [
				'cookies' => $this->cookieJar,
		];
		try {
			$this->response = $client->send($request, $options);
		} catch (BadResponseException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When the client sends a :method to :url without requesttoken using the API
	 * @Given the client has sent a :method to :url without requesttoken
	 *
	 * @param string $method
	 * @param string $url
	 *
	 * @return void
	 */
	public function sendingAToWithoutRequesttoken($method, $url) {
		$baseUrl = substr($this->baseUrl, 0, -5);

		$client = new Client();
		$request = new Request($method, $baseUrl . $url);
		$options = [
			'cookies' => $this->cookieJar,
		];
		try {
			$this->response = $client->send($request, $options);
		} catch (BadResponseException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @param string $path
	 * @param string $filename
	 *
	 * @return void
	 */
	public static function removeFile($path, $filename) {
		if (file_exists("$path" . "$filename")) {
			unlink("$path" . "$filename");
		}
	}

	/**
	 * @When user :user modifies text of :filename with text :text using the API
	 * @Given user :user has modified text of :filename with text :text
	 *
	 * @param string $user
	 * @param string $filename
	 * @param string $text
	 *
	 * @return void
	 */
	public function modifyTextOfFile($user, $filename, $text) {
		self::removeFile($this->getUserHome($user) . "/files", "$filename");
		file_put_contents(
			$this->getUserHome($user) . "/files" . "$filename", "$text"
		);
	}

	/**
	 * @param string $name
	 * @param string $size
	 *
	 * @return void
	 */
	public function createFileSpecificSize($name, $size) {
		$file = fopen("work/" . "$name", 'w');
		fseek($file, $size - 1, SEEK_CUR);
		fwrite($file, 'a'); // write a dummy char at SIZE position
		fclose($file);
	}

	/**
	 * @param string $name
	 * @param string $text
	 *
	 * @return void
	 */
	public function createFileWithText($name, $text) {
		$file = fopen("work/" . "$name", 'w');
		fwrite($file, $text);
		fclose($file);
	}

	/**
	 * @Given file :filename of size :size has been created in local storage
	 *
	 * @param string $filename
	 * @param string $size
	 *
	 * @return void
	 */
	public function fileHasBeenCreatedInLocalStorageWithSize($filename, $size) {
		$this->createFileSpecificSize("local_storage/$filename", $size);
	}

	/**
	 * @Given file :filename with text :text has been created in local storage
	 *
	 * @param string $filename
	 * @param string $text
	 *
	 * @return void
	 */
	public function fileHasBeenCreatedInLocalStorageWithText($filename, $text) {
		$this->createFileWithText("local_storage/$filename", $text);
	}

	/**
	 * @Given file :filename has been deleted in local storage
	 *
	 * @param string $filename
	 *
	 * @return void
	 */
	public function fileHasBeenDeletedInLocalStorage($filename) {
		unlink("work/local_storage/$filename");
	}

	/**
	 * @return string
	 */
	public function getAdminUsername() {
		return (string) $this->adminUsername;
	}

	/**
	 * @return string
	 */
	public function getAdminPassword() {
		return (string) $this->adminPassword;
	}

	/**
	 * @param string $userName
	 *
	 * @return string
	 */
	public function getPasswordForUser($userName) {
		if ($userName === $this->getAdminUsername()) {
			return (string) $this->getAdminPassword();
		} else {
			return (string) $this->regularUserPassword;
		}
	}

	/**
	 * @param string $userName
	 *
	 * @return array
	 */
	public function getAuthOptionForUser($userName) {
		return [$userName, $this->getPasswordForUser($userName)];
	}

	/**
	 * @return array
	 */
	public function getAuthOptionForAdmin() {
		return $this->getAuthOptionForUser($this->getAdminUsername());
	}

	/**
	 * @When the admin requests status.php using the API
	 *
	 * @return void
	 */
	public function getStatusPhp() {
		$fullUrl = $this->baseUrlWithoutOCSAppendix() . "status.php";
		$client = new Client();
		$options = [];
		$options['auth'] = $this->getAuthOptionForUser('admin');
		try {
			$this->response = $client->send(
				new Request('GET', $fullUrl), $options
			);
		} catch (BadResponseException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	/**
	 * @Then the json responded should match with
	 *
	 * @param PyStringNode $jsonExpected
	 *
	 * @return void
	 */
	public function jsonRespondedShouldMatch(PyStringNode $jsonExpected) {
		$jsonExpectedEncoded = json_encode($jsonExpected->getRaw());
		$jsonRespondedEncoded = json_encode((string) $this->response->getBody());
		PHPUnit\Framework\Assert::assertEquals(
			$jsonExpectedEncoded, $jsonRespondedEncoded
		);
	}

	/**
	 * @Then the status.php response should match with
	 *
	 * @param PyStringNode $jsonExpected
	 *
	 * @return void
	 */
	public function statusPhpRespondedShouldMatch(PyStringNode $jsonExpected) {
		$jsonExpectedDecoded = json_decode($jsonExpected->getRaw(), true);
		$jsonRespondedEncoded = json_encode(json_decode($this->response->getBody(), true));
		if ($this->runOcc(['status']) === 0) {
			$output = explode("- ", $this->lastStdOut);
			$version = explode(": ", $output[2]);
			PHPUnit_Framework_Assert::assertEquals("version", $version[0]);
			$versionString = explode(": ", $output[3]);
			PHPUnit_Framework_Assert::assertEquals("versionstring", $versionString[0]);
			$jsonExpectedDecoded['version'] = trim($version[1]);
			$jsonExpectedDecoded['versionstring'] = trim($versionString[1]);
			$jsonExpectedEncoded = json_encode($jsonExpectedDecoded);
		} else {
			PHPUnit_Framework_Assert::fail('Cannot get version variables from occ');
		}
		PHPUnit\Framework\Assert::assertEquals($jsonExpectedEncoded, $jsonRespondedEncoded);
	}

	/**
	 * @BeforeScenario @local_storage
	 *
	 * @return void
	 */
	public static function removeFilesFromLocalStorageBefore() {
		$dir = "./work/local_storage/";
		$di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
		$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
		foreach ( $ri as $file ) {
			$file->isDir() ?  rmdir($file) : unlink($file);
		}
	}

	/**
	 * @AfterScenario @local_storage
	 *
	 * @return void
	 */
	public static function removeFilesFromLocalStorageAfter() {
		$dir = "./work/local_storage/";
		$di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
		$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
		foreach ( $ri as $file ) {
			$file->isDir() ?  rmdir($file) : unlink($file);
		}
	}

	/**
	 * @BeforeSuite
	 *
	 * @param BeforeSuiteScope $scope
	 *
	 * @return void
	 */
	public static function useBigFileIDs(BeforeSuiteScope $scope) {
		$fullUrl = getenv('TEST_SERVER_URL') . "/v1.php/apps/testing/api/v1/increasefileid";
		$client = new Client();
		$options = [];
		$adminUsername = $scope->getSuite()->getSettings()['contexts'][0][__CLASS__]['adminUsername'];
		$adminPassword = $scope->getSuite()->getSettings()['contexts'][0][__CLASS__]['adminPassword'];
		$options['auth'] = [$adminUsername, $adminPassword];
		$client->send(new Request('POST', $fullUrl), $options);
	}
}

