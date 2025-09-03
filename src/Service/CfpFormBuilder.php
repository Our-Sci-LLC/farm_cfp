<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service to dynamically build a Drupal form from the CFP Pathway JSON schema.
 */
class CfpFormBuilder {

  use StringTranslationTrait;

  /**
   * Builds a Drupal form from the CFP Pathway JSON schema.
   *
   * @param array $schema The JSON schema array.
   *
   * @return array The form array.
   */
  public function buildFormFromSchema(array $schema) {
    $form = [];
    if (!empty($schema['properties'])) {
      $this->processProperties($form, $schema['properties']);
    }
    return $form;
  }

  /**
   * Processes the properties section of the schema.
   *
   * @param array $form The form array reference.
   * @param array $properties The properties section of the schema.
   */
  protected function processProperties(array &$form, array $properties) {
    foreach ($properties as $key => $property) {
      if (!empty($property['allOf'])) {
        $this->processAllOf($form, $key, $property['allOf'], $property['x-metadata']['name'] ?? $key);
      }
      elseif (!empty($property['oneOf'])) {
        $this->processOneOf($form, $key, $property);
      }
      else {
        $this->processField($form, $key, $property);
      }
    }
  }

  /**
   * Processes an 'allOf' schema.
   *
   * @param array $form The form array reference.
   * @param string $key The key for the element.
   * @param array $allOf The 'allOf' array.
   * @param string $title The title for the fieldset.
   */
  protected function processAllOf(array &$form, string $key, array $allOf, string $title) {
    $form[$key] = [
      '#type' => 'fieldset',
      '#title' => $this->t($title),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    foreach ($allOf as $subSchema) {
      if (!empty($subSchema['properties'])) {
        $this->processProperties($form[$key], $subSchema['properties']);
      }
      if (!empty($subSchema['allOf'])) {
        $this->processAllOf($form[$key], $subSchema['x-metadata']['slug'] ?? 'nested_' . $key, $subSchema['allOf'], $subSchema['x-metadata']['name'] ?? $title);
      }
    }
  }

  /**
   * Processes a 'oneOf' schema.
   *
   * @param array $form The form array reference.
   * @param string $key The key for the element.
   * @param array $oneOf The 'oneOf' schema.
   */
  protected function processOneOf(array &$form, string $key, array $oneOf) {
    $options = [];
    foreach ($oneOf['oneOf'] as $index => $option) {
      $title = $option['title'] ?? 'Option ' . ($index + 1);
      $options[$index] = $this->t($title);
    }

    $form[$key] = [
      '#type' => 'radios',
      '#title' => $this->t($oneOf['title']),
      '#options' => $options,
      '#default_value' => '0',
      '#id' => $key . '-wrapper',
    ];
  }

  /**
   * Processes a single field based on its schema.
   *
   * @param array $form The form array reference.
   * @param string $key The key for the element.
   * @param array $field_schema The schema for the field.
   */
  protected function processField(array &$form, string $key, array $field_schema) {
    $field = [];
    $field['#title'] = $this->t($field_schema['title'] ?? ucfirst($key));

    // Handle different JSON types.
    switch ($field_schema['type']) {
      case 'string':
        if (isset($field_schema['enum'])) {
          $field['#type'] = 'select';
          $options = array_combine($field_schema['enum'], $field_schema['enum']);
          $field['#options'] = $options;
        }
        elseif (isset($field_schema['maxLength']) && $field_schema['maxLength'] > 255) {
          $field['#type'] = 'textarea';
        }
        else {
          $field['#type'] = 'textfield';
        }
        break;

      case 'integer':
      case 'number':
        $field['#type'] = 'number';
        if (isset($field_schema['minimum'])) {
          $field['#min'] = $field_schema['minimum'];
        }
        if (isset($field_schema['maximum'])) {
          $field['#max'] = $field_schema['maximum'];
        }
        break;

      case 'boolean':
        $field['#type'] = 'checkbox';
        break;

      case 'object':
        $field['#type'] = 'fieldset';
        $field['#title'] = $this->t($field_schema['title'] ?? ucfirst($key));
        if (!empty($field_schema['properties'])) {
          $this->processProperties($field, $field_schema['properties']);
        }
        break;

      default:
        $field['#type'] = 'markup';
        $field['#markup'] = $this->t('Unsupported field type: @type', ['@type' => $field_schema['type']]);
    }

    // Add common properties like description.
    if (!empty($field_schema['description'])) {
      $field['#description'] = $this->t($field_schema['description']);
    }

    $form[$key] = $field;
  }

  /**
   * Builds a sample submission JSON for testing purposes.
   *
   * @return array The sample submission JSON.
   */
  public function buildSubmissionData(FormStateInterface $form_state) : array {
    // Placeholder for submission JSON building logic.
    return [
      "name" => "Test assessment",
      "farmId" => "091299f7-0595-408c-85f7-5eb05ec3dcff",
      "purposes" => [
        "Testing"
      ],
      "pathway" => "Paddy Rice v3"
    ];

  }
}
