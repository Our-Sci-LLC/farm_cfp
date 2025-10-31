<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_cfp\Constants;

/**
 * Service for looking up CFP related allowed values from farmOS entities.
 */
class LookupService {

  /**
   * Constructs a new CfpLookupService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory
  ) {}

  /**
   * Allowed values callback for CFP Pathway Type field.
   *
   * @return array
   *   An array of pathway options, keyed by term ID, valued by term label.
   */
  public function pathwayAllowedValues(): array {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'cfp_pathway']);

    foreach ($terms as $term) {
      $options[$term->label()] = $term->label();
    }

    return $options;
  }

  /**
   * Allowed values callback for CFP Climate field.
   *
   * @return array
   *   An array of climate options, keyed by term ID, valued by term label.
   */
  public function climateAllowedValues(): array {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'cfp_climate']);

    foreach ($terms as $term) {
      $options[$term->label()] = $term->label();
    }

    return $options;
  }

  /**
   * Allowed values callback for CFP Country field.
   *
   * @return array
   *   An array of country options, keyed by term ID, valued by term label.
   */
  public function countryAllowedValues(): array {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'cfp_country']);

    foreach ($terms as $term) {
      $options[$term->label()] = $term->label();
    }

    return $options;
  }

  /**
   * Loads the default CFP Pathway term id from configuration.
   *
   * @return string|null
   * The default CFP Pathway taxonomy term label, or NULL if not set.
   */
  public function getDefaultPathway(): ?string {
    $config = $this->configFactory->get('farm_cfp.settings');
    return $config->get('pathway_default');
  }

  /**
   * Loads the default CFP Climate term id from configuration.
   *
   * @return string|null
   * The default CFP Climate taxonomy term label, or NULL if not set.
   */
  public function getDefaultClimate(): ?string {
    $config = $this->configFactory->get('farm_cfp.settings');
    return $config->get('climate_default');
  }

  /**
   * Loads the default CFP Country term id from configuration.
   *
   * @return string|null
   * The default CFP Country taxonomy term label, or NULL if not set.
   */
  public function getDefaultCountry(): ?string {
    $config = $this->configFactory->get('farm_cfp.settings');
    return $config->get('country_default');
  }

  /**
   * Loads the default Annual average temperature value from configuration.
   *
   * @return array|null
   *   An associative array with 'temp' and 'unit' keys.
   */
  public function getDefaultAverageTemperature(): array {
    $config = $this->configFactory->get('farm_cfp.settings');
    return [
      'value' => $config->get('annual_avg_temp'),
      'unit' => $config->get('annual_avg_temp_unit'),
    ];
  }

  /**
   * Loads the operation mode from configuration.
   *
   * @return string|null
   * The operation mode, or OPERATION_MODE_BASIC if not set.
   */
  public function getOperationMode(): string {
    $mode = $this->configFactory->get('farm_cfp.settings')->get('operation_mode');
    return $mode ?? Constants::OPERATION_MODE_BASIC;
  }
}
