<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Service for interacting with the Cool Farm Platform API.
 */
class CfpApiService {
  use StringTranslationTrait;
  use LoggerTrait;

  /**
   * The base URL for the Cool Farm Platform API.
   *
   * @var string
   */
  protected $apiUrl;

  /**
   * Constructs a new CfpApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
 */
  public function __construct(protected ClientInterface $httpClient, protected ConfigFactoryInterface $configFactory, protected LoggerInterface $logger) {
    $this->apiUrl = $this->configFactory->get('farm_cfp.settings')->get('api_url');
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) : void {
    $this->logger->log($level, $message, $context);
  }

  /**
   * Retrieves the API key from configuration.
   *
   * @return string|null
   * The API key, or null if not found.
   */
  protected function getApiKey() {
    $config = $this->configFactory->get('farm_cfp.settings');
    return $config->get('api_key');
  }

  /**
   * Helper function to send a request to the API.
   *
   * @param string $method
   * The HTTP method (e.g., 'GET', 'POST').
   * @param string $endpoint
   * The API endpoint path.
   * @param array $options
   * An array of request options.
   *
   * @return array|null
   * The decoded response data or null on failure.
   */
  protected function sendRequest(string $method, string $endpoint, array $options = []) {
    $url = $this->apiUrl . $endpoint;
    $apiKey = $this->getApiKey();

    if (empty($apiKey)) {
      $this->error('CFP API key is not configured.');
      return NULL;
    }

    // Use API key as Bearer token (temporary hack) until we have a proper auth flow.
    $options['headers']['Authorization'] = 'Bearer ' . $apiKey;
    $options['headers']['Accept'] = 'application/json';

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $data = Json::decode((string) $response->getBody());
      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        $this->error('CFP API request to @url failed with status @status. @data', ['@url' => $url, '@status' => $response->getStatusCode(), '@data' => print_r($data, TRUE)]);
        return NULL;
      }
      return $data;
    }
    catch (RequestException $e) {
      $this->error('Error calling the CFP API: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /*
   * Assessment API Endpoint Stubs
   */

  /**
   * GET /assessments
   *
   * Fetch assessments belonging to your user.
   *
   * @return array|null
   * A list of assessments or null on failure.
   */
  public function listAssessments() {
    return $this->sendRequest('GET', '/assessments');
  }

  /**
   * POST /assessment/create-and-run
   *
   * Create and run an assessment.
   *
   * @param array $assessment_data
   * The assessment data to send.
   *
   * @return array|null
   * The created assessment data or null on failure.
   */
  public function createAndRunAssessment(array $assessment_data) {
    return $this->sendRequest('POST', '/assessment/create-and-run', ['json' => $assessment_data]);
  }

  /**
   * POST /assessment
   *
   * Create an assessment.
   *
   * @param array $assessment_data
   * The assessment data to send.
   *
   * @return array|null
   * The created assessment data or null on failure.
   */
  public function createAssessment(array $assessment_data) {
    return $this->sendRequest('POST', '/assessment', ['json' => $assessment_data]);
  }

  /**
   * GET /assessment/{assessmentId}
   *
   * Fetch an assessment.
   *
   * @param string $assessment_id
   * The ID of the assessment to fetch.
   *
   * @return array|null
   * The assessment data or null on failure.
   */
  public function fetchAssessment(string $assessment_id) {
    return $this->sendRequest('GET', "/assessment/{$assessment_id}");
  }

  /**
   * DELETE /assessment/{assessmentId}
   *
   * Delete an assessment.
   *
   * @param string $assessment_id
   * The ID of the assessment to delete.
   *
   * @return array|null
   * The API response or null on failure.
   */
  public function deleteAssessment(string $assessment_id) {
    return $this->sendRequest('DELETE', "/assessment/{$assessment_id}");
  }

  /**
   * POST /assessment/{assessmentId}/run/create-or-edit
   *
   * Create or edit an assessment run.
   *
   * @param string $assessment_id
   * The ID of the assessment.
   * @param array $run_data
   * The run data to send.
   *
   * @return array|null
   * The created or edited run data or null on failure.
   */
  public function createOrEditAssessmentRun(string $assessment_id, array $run_data) {
    return $this->sendRequest('POST', "/assessment/{$assessment_id}/run/create-or-edit", ['json' => $run_data]);
  }

  /**
   * POST /assessment/{assessmentId}/copy
   *
   * Copy an assessment with a new name.
   *
   * @param string $assessment_id
   * The ID of the assessment to copy.
   * @param string $new_name
   * The new name for the copied assessment.
   *
   * @return array|null
   * The new assessment data or null on failure.
   */
  public function copyAssessment(string $assessment_id, string $new_name) {
    return $this->sendRequest('POST', "/assessment/{$assessment_id}/copy", ['json' => ['new_name' => $new_name]]);
  }

  /**
   * POST /assessment/calculate
   *
   * Run an assessment without saving the result.
   *
   * @param array $assessment_data
   * The assessment data to send.
   *
   * @return array|null
   * The calculation result or null on failure.
   */
  public function calculateAssessment(array $assessment_data) {
    return $this->sendRequest('POST', '/assessment/calculate', ['json' => $assessment_data]);
  }

  /**
   * GET /assessment/run/{runId}
   *
   * Fetch an assessment run.
   *
   * @param string $run_id
   * The ID of the assessment run.
   *
   * @return array|null
   * The run data or null on failure.
   */
  public function fetchRun(string $run_id) {
    return $this->sendRequest('GET', "/assessment/run/{$run_id}");
  }

  /**
   * GET /assessment/pathway/{pathwayName}/schema
   *
   * Fetch JSON schema for a given pathway.
   *
   * @param string $pathway_name
   * The name of the pathway.
   *
   * @return array|null
   * The pathway schema or null on failure.
   */
  public function fetchPathway(string $pathway_name) {
    return $this->sendRequest('GET', "/assessment/pathway/{$pathway_name}/schema");
  }

  /*
   * API Key Endpoint Stubs
   */

  /**
   * POST /api-key
   *
   *
   * Create an API Key.
   *
   * @param array $key_data
   * The data for the new API key.
   *
   * @return array|null
   * The created API key data or null on failure.
   */
  public function createApiKey(array $key_data) {
    return $this->sendRequest('POST', '/api-key', ['json' => $key_data]);
  }

  /**
   * DELETE /api-key/{apiKeyId}
   *
   * Delete an API Key.
   *
   * @param string $api_key_id
   * The ID of the API key to delete.
   *
   * @return array|null
   * The API response or null on failure.
   */
  public function deleteApiKey(string $api_key_id) {
    return $this->sendRequest('DELETE', "/api-key/{$api_key_id}");
  }

  /**
   * GET /api-keys
   *
   * Fetch all API Keys for the current user.
   *
   * @return array|null
   * A list of API keys or null on failure.
   */
  public function listUserApiKeys() {
    return $this->sendRequest('GET', '/api-keys');
  }

}
