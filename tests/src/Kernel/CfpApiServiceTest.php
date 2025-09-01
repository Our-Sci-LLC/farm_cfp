<?php

namespace Drupal\Tests\farm_cfp\Kernel;

use Drupal\farm_cfp\Service\CfpApiService;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the CfpApiService.
 *
 * @group farm_cfp
 */
class CfpApiServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'key', 'farm_cfp'];

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ClientInterface|MockObject $httpClient;

  /**
   * The CFP API service.
   *
   * @var \Drupal\farm_cfp\Service\CfpApiService
   */
  protected CfpApiService $cfpApiService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'key']);

    // Set configurations used by service
    $this->config('farm_cfp.settings')
      ->set('api_url', 'https://example.com/api')
      ->set('api_key', 'test_key')
      ->save();

    $mockKey = $this->createMock('Drupal\key\Entity\Key');
    $mockKey->method('getKeyValue')->willReturn('dummy-api-key');

    $mockKeyRepository = $this->createMock('Drupal\key\KeyRepositoryInterface');
    $mockKeyRepository->method('getKey')->willReturn($mockKey);

    $this->httpClient = $this->createMock(ClientInterface::class);

    $mockLogger = $this->createMock('Psr\Log\LoggerInterface');

    // Instantiate service with mocked dependencies.
    $this->cfpApiService = new CfpApiService(
      $this->httpClient,
      $this->container->get('config.factory'),
      $mockKeyRepository,
      $mockLogger
    );
  }

  /**
   * Tests successful listAssessments call.
   */
  public function testListAssessmentsSuccess() {
    $responseBody = ['data' => [['id' => '1'], ['id' => '2']]];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://example.com/api/assessments')
      ->willReturn($response);

    $result = $this->cfpApiService->listAssessments();
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests failed listAssessments call.
   */
  public function testListAssessmentsFailure() {
    $mockRequest = $this->createMock('GuzzleHttp\Psr7\Request');

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://example.com/api/assessments')
      ->willThrowException(new RequestException('Test error', $mockRequest));

    $result = $this->cfpApiService->listAssessments();
    $this->assertNull($result);
  }

  /**
   * Tests createAndRunAssessment.
   */
  public function testCreateAndRunAssessment() {
    $assessmentData = ['name' => 'Test Assessment'];
    $responseBody = ['id' => '123'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://example.com/api/assessment/create-and-run', [
        'json' => $assessmentData,
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->createAndRunAssessment($assessmentData);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests createAssessment.
   */
  public function testCreateAssessment() {
    $assessmentData = ['name' => 'Test'];
    $responseBody = ['id' => '456'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://example.com/api/assessment', [
        'json' => $assessmentData,
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->createAssessment($assessmentData);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests fetchAssessment.
   */
  public function testFetchAssessment() {
    $assessmentId = '789';
    $responseBody = ['id' => $assessmentId];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', "https://example.com/api/assessment/{$assessmentId}", [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->fetchAssessment($assessmentId);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests deleteAssessment.
   */
  public function testDeleteAssessment() {
    $assessmentId = 'del-123';
    $responseBody = ['status' => 'deleted'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('DELETE', "https://example.com/api/assessment/{$assessmentId}", [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->deleteAssessment($assessmentId);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests createOrEditAssessmentRun.
   */
  public function testCreateOrEditAssessmentRun() {
    $assessmentId = 'run-123';
    $runData = ['year' => 2023];
    $responseBody = ['run_id' => 'r1'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', "https://example.com/api/assessment/{$assessmentId}/run/create-or-edit", [
        'json' => $runData,
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->createOrEditAssessmentRun($assessmentId, $runData);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests copyAssessment.
   */
  public function testCopyAssessment() {
    $assessmentId = 'copy-123';
    $newName = 'Copied Assessment';
    $responseBody = ['id' => 'new-123'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', "https://example.com/api/assessment/{$assessmentId}/copy", [
        'json' => ['new_name' => $newName],
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->copyAssessment($assessmentId, $newName);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests calculateAssessment.
   */
  public function testCalculateAssessment() {
    $assessmentData = ['crop' => 'Wheat'];
    $responseBody = ['result' => 42];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://example.com/api/assessment/calculate', [
        'json' => $assessmentData,
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->calculateAssessment($assessmentData);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests fetchRun.
   */
  public function testFetchRun() {
    $runId = 'run-456';
    $responseBody = ['id' => $runId];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', "https://example.com/api/assessment/run/{$runId}", [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->fetchRun($runId);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests fetchPathway.
   */
  public function testFetchPathway() {
    $pathwayName = 'soil_health';
    $responseBody = ['schema' => ['type' => 'object']];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', "https://example.com/api/assessment/pathway/{$pathwayName}/schema", [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->fetchPathway($pathwayName);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests createApiKey.
   */
  public function testCreateApiKey() {
    $keyData = ['name' => 'New Key'];
    $responseBody = ['key' => 'secret123'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://example.com/api/api-key', [
        'json' => $keyData,
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->createApiKey($keyData);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests deleteApiKey.
   */
  public function testDeleteApiKey() {
    $apiKeyId = 'key-123';
    $responseBody = ['status' => 'deleted'];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('DELETE', "https://example.com/api/api-key/{$apiKeyId}", [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->deleteApiKey($apiKeyId);
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Tests listUserApiKeys.
   */
  public function testListUserApiKeys() {
    $responseBody = ['keys' => [['id' => 'k1'], ['id' => 'k2']]];
    $response = new Response(200, [], Utils::streamFor(json_encode($responseBody)));

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://example.com/api/api-keys', [
        'headers' => $this->getExpectedHeaders()
      ])
      ->willReturn($response);

    $result = $this->cfpApiService->listUserApiKeys();
    $this->assertEquals($responseBody, $result);
  }

  /**
   * Returns expected headers for API requests.
   */
  private function getExpectedHeaders(): array {
    return [
      'X-API-KEY' => 'dummy-api-key',
      'Accept' => 'application/json',
    ];
  }
}
