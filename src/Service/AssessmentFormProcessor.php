<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\Form\FormStateInterface;

/**
 * Service to dynamically build and process CFP Pathway forms from JSON schema.
 */
class AssessmentFormProcessor {
  /**
   * Constructs a new CfpFormProcessor object.
   *
   * @param \Drupal\farm_cfp\Service\AssessmentFormBuilder $formBuilder
   *   The form builder service.
   * @param \Drupal\farm_cfp\Service\AssessmentFormDataExtractor $dataExtractor
   *   The data extractor service.
   */
  public function __construct(
    protected AssessmentFormBuilder $formBuilder,
    protected AssessmentFormDataExtractor $dataExtractor
  ) {}

  /**
   * Builds a Drupal form from the CFP Pathway JSON schema.
   *
   * @param array $schema
   *   The JSON schema definition.
   * @param string $mode
   *   The operation mode (basic/advanced).
   *
   * @return array
   *   The complete Drupal form array.
   */
  public function buildFormFromSchema(array $schema, string $mode): array {
    return $this->formBuilder->buildFromSchema($schema, $mode);
  }

  /**
   * Extracts submitted data from form state using the schema.
   *
   * @param array $schema
   *   The JSON schema definition.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state containing submitted values.
   *
   * @return array
   *   The extracted data structured according to the schema.
   */
  public function extractFormData(array $schema, FormStateInterface $formState): array {
    return $this->dataExtractor->extractFromSchema($schema, $formState);
  }

}
