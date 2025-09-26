<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Form\FormStateInterface;

/**
 * Service to dynamically build and process CFP Pathway forms from JSON schema.
 */
class CfpFormProcessor {

  use StringTranslationTrait;

  /**
   * Builds a Drupal form from the CFP Pathway JSON schema.
   */
  public function buildFormFromSchema(array $schema): array {
    $form = [];
    if (!empty($schema['properties'])) {
      $requiredFields = $schema['required'] ?? [];
      $this->addPropertiesToForm($form, $schema['properties'], null, $requiredFields);
    }
    return $form;
  }

  /**
   * Extracts submitted data from form state using the schema.
   */
  public function extractFormData(array $schema, FormStateInterface $formState): array {
    if (empty($schema['properties'])) {
      return [];
    }
    return $this->extractPropertiesData($schema['properties'], $formState);
  }

  /**
   * Processes properties and adds them to the form.
   */
  protected function addPropertiesToForm(array &$form, array $properties, ?string $parentKey = null, array $requiredFields = []): void {
    foreach ($properties as $key => $property) {
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      // Mark field as required if it's in the required fields list
      $propertySchema = $property;
      if (in_array($key, $requiredFields)) {
        $propertySchema['#required'] = true;
      }

      if (isset($propertySchema['allOf'])) {
        $this->addAllOfToForm($form, $key, $propertySchema['allOf'], $propertySchema);
      }
      elseif (isset($propertySchema['oneOf'])) {
        $this->addOneOfToForm($form, $key, $propertySchema);
      }
      elseif (isset($propertySchema['properties'])) {
        // Handle objects with properties directly
        $nestedRequired = $propertySchema['required'] ?? [];
        $this->addObjectToForm($form, $fullKey, $propertySchema, $nestedRequired);
      }
      else {
        $this->addFieldToForm($form, $fullKey, $propertySchema);
      }
    }
  }

  /**
   * Processes an allOf schema element recursively.
   */
  protected function addAllOfToForm(array &$form, string $key, array $allOf, array $propertySchema): void {
    // Create a details element for the allOf container
    $title = $propertySchema['x-metadata']['name'] ?? $propertySchema['title'] ?? ucfirst($key);
    $hasRequiredFields = false;

    $form[$key] = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    // Process each item in the allOf array
    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        // Recursively process nested allOf
        $nestedKey = $subSchema['x-metadata']['slug'] ?? $key;
        if (empty($subSchema['x-metadata']['name'])) {
          $subSchema['x-metadata']['name'] = $title;
        }
        $this->addAllOfToForm($form[$key], $nestedKey, $subSchema['allOf'], $subSchema);
      }
      elseif (isset($subSchema['properties'])) {
        // Process properties within the allOf item
        $nestedRequired = $subSchema['required'] ?? [];
        if (!empty($nestedRequired)) {
          $hasRequiredFields = true;
        }
        $this->addPropertiesToForm($form[$key], $subSchema['properties'], $key, $nestedRequired);
      }
      elseif (isset($subSchema['oneOf'])) {
        // Process oneOf within allOf
        $oneOfKey = $subSchema['x-metadata']['slug'] ?? $key . '_oneof';
        $this->addOneOfToForm($form[$key], $oneOfKey, $subSchema);
      }
      else {
        // Process individual field within allOf
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $this->addFieldToForm($form[$key], $key . '__' . $fieldKey, $subSchema);
        }
      }
    }

    // Add required indicator if needed
    if ($hasRequiredFields || !empty($propertySchema['#required'])) {
      $form[$key]['#title'] .= ' *';
      $form[$key]['#attributes']['class'][] = 'required-details';
    }
  }

  /**
   * Adds an object with properties to the form.
   */
  protected function addObjectToForm(array &$form, string $key, array $objectSchema, array $requiredFields = []): void {
    $field = [
      '#type' => 'fieldset',
      '#title' => $this->t($objectSchema['title'] ?? ucfirst($this->getLastKeyPart($key))),
    ];

    // Add required indicator if it contains required fields
    if (!empty($requiredFields) || !empty($objectSchema['#required'])) {
      $field['#title'] .= ' *';
      $field['#attributes']['class'][] = 'required-fieldset';
    }

    if (isset($objectSchema['description'])) {
      $field['#description'] = $this->t($objectSchema['description']);
    }

    // Process the object's properties
    if (!empty($objectSchema['properties'])) {
      $this->addPropertiesToForm($field, $objectSchema['properties'], $key, $requiredFields);
    }

    $form[$key] = $field;
  }

  /**
   * Processes oneOf schema elements (radio buttons).
   */
  protected function addOneOfToForm(array &$form, string $key, array $oneOfSchema): void {
    $options = [];
    foreach ($oneOfSchema['oneOf'] as $index => $option) {
      $options[$index] = $this->t($option['title'] ?? 'Option ' . ($index + 1));
    }

    $form[$key] = [
      '#type' => 'radios',
      '#title' => $this->t($oneOfSchema['title'] ?? ucfirst($key)),
      '#options' => $options,
      '#default_value' => 0,
    ];

    // Add required indicator if needed
    if (!empty($oneOfSchema['#required'])) {
      $form[$key]['#required'] = true;
    }
  }

  /**
   * Adds a single field to the form based on schema.
   */
  protected function addFieldToForm(array &$form, string $key, array $fieldSchema): void {
    $field = [
      '#title' => $this->t($fieldSchema['title'] ?? ucfirst($this->getLastKeyPart($key))),
    ];

    if (isset($fieldSchema['description'])) {
      $field['#description'] = $this->t($fieldSchema['description']);
    }

    if (!empty($fieldSchema['#required'])) {
      $field['#required'] = true;
    }

    $field = $this->applyFieldTypeConfiguration($field, $fieldSchema, $key);
    $form[$key] = $field;
  }

  /**
   * Applies field type-specific configuration.
   */
  protected function applyFieldTypeConfiguration(array $field, array $schema, string $key): array {
    $type = $schema['type'] ?? 'string';

    switch ($type) {
      case 'string':
        return $this->configureStringField($field, $schema);

      case 'integer':
      case 'number':
        return $this->configureNumberField($field, $schema);

      case 'boolean':
        $field['#type'] = 'checkbox';
        return $field;

      case 'object':
        return $this->configureObjectField($field, $schema, $key);

      case 'array':
        return $this->configureArrayField($field, $schema, $key);

      default:
        $field['#type'] = 'markup';
        $field['#markup'] = $this->t('Unsupported field type: @type', ['@type' => $type]);
        return $field;
    }
  }

  /**
   * Configures string field (textfield, textarea, or select).
   */
  protected function configureStringField(array $field, array $schema): array {
    if (isset($schema['enum'])) {
      $field['#type'] = 'select';
      $field['#options'] = array_combine($schema['enum'], $schema['enum']);
    }
    elseif (isset($schema['maxLength']) && $schema['maxLength'] > 255) {
      $field['#type'] = 'textarea';
    }
    else {
      $field['#type'] = 'textfield';
    }
    return $field;
  }

  /**
   * Configures number field with validation constraints.
   */
  protected function configureNumberField(array $field, array $schema): array {
    $field['#type'] = 'number';
    $isFloat = $schema['type'] === 'number';

    if (isset($schema['minimum'])) {
      $field['#min'] = $schema['minimum'];
      $isFloat = $isFloat || is_float($schema['minimum']);
    }

    if (isset($schema['maximum'])) {
      $field['#max'] = $schema['maximum'];
      $isFloat = $isFloat || is_float($schema['maximum']);
    }

    $field['#step'] = $isFloat ? 'any' : 1;
    return $field;
  }

  /**
   * Configures object field as a fieldset with nested properties.
   */
  protected function configureObjectField(array $field, array $schema, string $key): array {
    $field['#type'] = 'fieldset';

    if (!empty($schema['properties'])) {
      $requiredFields = $schema['required'] ?? [];
      $this->addPropertiesToForm($field, $schema['properties'], $key, $requiredFields);
    }

    return $field;
  }

  /**
   * Configures array field.
   */
  protected function configureArrayField(array $field, array $schema, string $key): array {
    $field['#type'] = 'fieldset';
    $field['#title'] = $this->t($schema['title'] ?? ucfirst($this->getLastKeyPart($key)));

    // @todo: Implement array item processing
    $field['#markup'] = $this->t('Array field type not fully implemented yet');

    return $field;
  }

  /**
   * Extracts data from form state based on properties schema.
   */
  protected function extractPropertiesData(array $properties, FormStateInterface $formState, ?string $parentKey = null): array {
    $data = [];

    foreach ($properties as $key => $property) {
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      if (isset($property['allOf'])) {
        $data[$key] = $this->extractAllOfData($property['allOf'], $formState, $fullKey);
      }
      elseif (isset($property['oneOf'])) {
        $data[$key] = $this->extractOneOfData($property['oneOf'], $formState, $fullKey);
      }
      elseif (isset($property['properties'])) {
        $data[$key] = $this->extractPropertiesData($property['properties'], $formState, $fullKey);
      }
      else {
        $data[$key] = $this->extractFieldValue($fullKey, $property, $formState);
      }
    }

    return $data;
  }

  /**
   * Extracts data from allOf schema elements recursively.
   */
  protected function extractAllOfData(array $allOf, FormStateInterface $formState, string $parentKey): array {
    $data = [];

    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        // Recursively process nested allOf
        $data = array_merge($data, $this->extractAllOfData($subSchema['allOf'], $formState, $parentKey));
      }
      elseif (isset($subSchema['properties'])) {
        // Process properties within the allOf item
        $nestedData = $this->extractPropertiesData($subSchema['properties'], $formState, $parentKey);
        $data = array_merge($data, $nestedData);
      }
      else {
        // Process individual field within allOf
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $fullKey = $parentKey . '__' . $fieldKey;
          $data[$fieldKey] = $this->extractFieldValue($fullKey, $subSchema, $formState);
        }
      }
    }

    return $data;
  }

  /**
   * Extracts data from oneOf schema elements.
   */
  protected function extractOneOfData(array $oneOf, FormStateInterface $formState, string $key): mixed {
    $value = $formState->getValue($key);
    return $value !== null ? (int) $value : null;
  }

  /**
   * Extracts and processes a single field value.
   */
  protected function extractFieldValue(string $key, array $fieldSchema, FormStateInterface $formState): mixed {
    $value = $formState->getValue($key);

    if ($value === null || $value === '') {
      return null;
    }

    $type = $fieldSchema['type'] ?? 'string';

    return match ($type) {
      'object' => $this->extractPropertiesData($fieldSchema['properties'] ?? [], $formState, $key),
      'integer' => (int) $value,
      'boolean' => (bool) $value,
      'number' => (float) $value,
      'string' => (string) $value,
      'array' => is_array($value) ? $value : [],
      default => $value,
    };
  }

  /**
   * Helper method to find the field key in a subschema.
   */
  protected function findFieldKeyInSubSchema(array $subSchema): ?string {
    // Look for common field identifiers in the schema
    if (isset($subSchema['title'])) {
      return $this->convertTitleToKey($subSchema['title']);
    }

    // Check for enum fields (select fields)
    if (isset($subSchema['enum'])) {
      return 'enum_field';
    }

    return null;
  }

  /**
   * Converts a title to a valid form key.
   */
  protected function convertTitleToKey(string $title): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
  }

  /**
   * Extracts the last part of a nested key.
   */
  protected function getLastKeyPart(string $key): string {
    $parts = explode('__', $key);
    return end($parts);
  }

}
