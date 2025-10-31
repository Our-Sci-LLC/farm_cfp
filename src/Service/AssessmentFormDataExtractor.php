<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\Form\FormStateInterface;

/**
 * Extracts and validates form data according to JSON Schema structure.
 *
 * This class processes form submissions and reconstructs data structures
 * that match the original JSON Schema, handling nested objects, arrays,
 * and conditional fields.
 */
class AssessmentFormDataExtractor {

  /**
   * Extracts structured data from form submission matching schema.
   *
   * @param array $schema
   *   The JSON schema defining the expected data structure.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state containing submitted values.
   *
   * @return array
   *   The extracted data structured according to the schema.
   */
  public function extractFromSchema(array $schema, FormStateInterface $formState): array {
    if (empty($schema['properties'])) {
      return [];
    }

    return $this->extractPropertiesData($schema['properties'], $formState);
  }

  /**
   * Extracts data for all properties in a schema section.
   *
   * @param array $properties
   *   The schema properties to extract.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state containing submitted values.
   * @param string|null $parentKey
   *   The parent key for nested properties, or NULL for top-level.
   *
   * @return array
   *   The extracted data for the properties.
   */
  protected function extractPropertiesData(array $properties, FormStateInterface $formState, ?string $parentKey = NULL): array {
    $data = [];
    $formValues = $formState->getValues();
    $isTopLevel = ($parentKey === NULL);

    foreach ($properties as $key => $property) {
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      if (isset($property['allOf'])) {
        $data[$key] = $this->extractAllOfData($property['allOf'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['oneOf'])) {
        $data[$key] = $this->extractOneOfData($property['oneOf'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['properties'])) {
        $data[$key] = $this->extractNestedObjectData($property['properties'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['type']) && $property['type'] === 'array') {
        $data[$key] = $this->extractArrayData($fullKey, $property, $formState);
      }
      else {
        $data[$key] = $this->extractSimpleFieldValue($fullKey, $property, $formValues, $isTopLevel);
      }
    }

    return $data;
  }

  /**
   * Extracts data from allOf schema elements.
   *
   * @param array $allOf
   *   The allOf schema definitions.
   * @param array $formValues
   *   The form values to extract from.
   * @param string $parentKey
   *   The parent key for nested values.
   * @param bool $isTopLevel
   *   Whether this is a top-level element.
   *
   * @return array
   *   The combined data from all allOf schemas.
   */
  protected function extractAllOfData(array $allOf, array $formValues, string $parentKey, bool $isTopLevel): array {
    $data = [];

    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        $nestedData = $this->extractAllOfData($subSchema['allOf'], $formValues, $parentKey, $isTopLevel);
        $data = array_merge($data, $nestedData);
      }
      elseif (isset($subSchema['properties'])) {
        $nestedData = $this->extractNestedObjectData($subSchema['properties'], $formValues, $parentKey, $isTopLevel);
        if (!empty($nestedData)) {
          $data = array_merge($data, $nestedData);
        }
      }
      else {
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $fullKey = $parentKey . '__' . $fieldKey;
          $data[$fieldKey] = $this->extractSimpleFieldValue($fullKey, $subSchema, $formValues, $isTopLevel);
        }
      }
    }

    return $data;
  }

  /**
   * Extracts data from oneOf schema elements.
   *
   * @param array $oneOf
   *   The oneOf schema options.
   * @param array $formValues
   *   The form values to extract from.
   * @param string $key
   *   The base key for the oneOf element.
   * @param bool $isTopLevel
   *   Whether this is a top-level element.
   *
   * @return array
   *   The data from the selected oneOf option.
   */
  protected function extractOneOfData(array $oneOf, array $formValues, string $key, bool $isTopLevel): ?array {
    $data = [];

    foreach ($oneOf as $option => $subSchema) {
      if (isset($subSchema['type']) && $subSchema['type'] === 'null') {
        $value = $this->getNestedValue($formValues, $key);
        if (empty($value)) {
          return NULL;
        }
      }
      elseif (isset($subSchema['properties'])) {
        $parentKey = $key . '_option_' . $option;
        $nestedData = $this->extractNestedObjectData($subSchema['properties'], $formValues, $parentKey, $isTopLevel);
        if (!empty($nestedData)) {
          $data = array_merge($data, $nestedData);
        }
      }
    }

    return $data;
  }

  /**
   * Extracts data for nested object properties.
   *
   * @param array $properties
   *   The nested properties schema.
   * @param array $formValues
   *   The form values to extract from.
   * @param string $parentKey
   *   The parent key for the nested object.
   * @param bool $isTopLevel
   *   Whether the parent is a top-level element.
   *
   * @return array
   *   The extracted nested object data.
   */
  protected function extractNestedObjectData(array $properties, array $formValues, string $parentKey, bool $isTopLevel): array {
    $data = [];

    foreach ($properties as $key => $property) {
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      if (isset($property['allOf'])) {
        $data[$key] = $this->extractAllOfData($property['allOf'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['oneOf'])) {
        $data[$key] = $this->extractOneOfData($property['oneOf'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['properties'])) {
        $data[$key] = $this->extractNestedObjectData($property['properties'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['type']) && $property['type'] === 'array') {
        $data[$key] = $this->extractArrayDataFromValues($fullKey, $property, $formValues);
      }
      else {
        $data[$key] = $this->extractSimpleFieldValue($fullKey, $property, $formValues, $isTopLevel);
      }
    }

    return $data;
  }

  /**
   * Extracts array data from form state.
   *
   * @param string $key
   *   The array field key.
   * @param array $arraySchema
   *   The array schema definition.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state containing submitted values.
   *
   * @return array
   *   The extracted array data.
   */
  protected function extractArrayData(string $key, array $arraySchema, FormStateInterface $formState): array {
    $formValues = $formState->getValues();

    // For top-level arrays, use direct access.
    if (isset($formValues[$key]) && is_array($formValues[$key]) && isset($formValues[$key]['items_wrapper'])) {
      $arrayValues = $formValues[$key];
    }
    else {
      // Try nested lookup for arrays within structures.
      $arrayValues = $this->getNestedValue($formValues, $key);
    }

    if (!is_array($arrayValues) || !isset($arrayValues['items_wrapper'])) {
      return [];
    }

    return $this->processArrayItems($arrayValues['items_wrapper'], $arraySchema, $key, $formState);
  }

  /**
   * Processes all items in an array field.
   *
   * @param array $itemsWrapper
   *   The items wrapper array from form values.
   * @param array $arraySchema
   *   The array schema definition.
   * @param string $parentKey
   *   The parent key for the array.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state for context.
   *
   * @return array
   *   The processed array items.
   */
  protected function processArrayItems(array $itemsWrapper, array $arraySchema, string $parentKey, FormStateInterface $formState): array {
    $data = [];
    $baseKey = $this->getBaseKey($parentKey);

    $index = 0;
    while (isset($itemsWrapper[$baseKey . '_item_' . $index])) {
      $itemKey = $baseKey . '_item_' . $index;
      $itemValues = $itemsWrapper[$itemKey];

      $itemData = $this->extractArrayItemData($itemValues, $arraySchema['items'] ?? [], $itemKey, $formState);

      if (!empty($itemData)) {
        $data[] = $itemData;
      }
      $index++;
    }

    return $data;
  }

  /**
   * Extracts data for a single array item.
   *
   * @param array $itemValues
   *   The form values for this array item.
   * @param array $itemSchema
   *   The schema for array items.
   * @param string $itemKey
   *   The key for this array item.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state for context.
   *
   * @return array
   *   The extracted array item data.
   */
  protected function extractArrayItemData(array $itemValues, array $itemSchema, string $itemKey, FormStateInterface $formState): array {
    $itemData = [];

    // For array items, we're always working with nested values, so isTopLevel = FALSE.
    if (isset($itemSchema['allOf'])) {
      $allOfKey = $itemKey . '_allOf';

      if (isset($itemValues[$allOfKey])) {
        foreach ($itemSchema['allOf'] as $allOfSchema) {
          if (isset($allOfSchema['properties'])) {
            $nestedData = $this->extractNestedObjectData($allOfSchema['properties'], $itemValues[$allOfKey], $allOfKey, FALSE);
            $itemData = array_merge($itemData, $nestedData);
          }
        }
      }
    }
    elseif (isset($itemSchema['oneOf'])) {
      $oneOfKey = $itemKey . '_oneof';

      if (isset($itemValues[$oneOfKey]) && isset($itemSchema['oneOf'][0]['allOf'])) {
        $firstOption = $itemSchema['oneOf'][0];
        $optionKey = $itemKey . '_option_0';

        if (isset($itemValues[$optionKey])) {
          foreach ($firstOption['allOf'] as $allOfSchema) {
            if (isset($allOfSchema['properties'])) {
              $nestedData = $this->extractNestedObjectData($allOfSchema['properties'], $itemValues[$optionKey], $optionKey, FALSE);
              $itemData = array_merge($itemData, $nestedData);
            }
          }
        }
      }
    }
    elseif (isset($itemSchema['properties'])) {
      $itemData = $this->extractNestedObjectData($itemSchema['properties'], $itemValues, $itemKey, FALSE);
    }

    return $itemData;
  }

  /**
   * Extracts array data from nested values.
   *
   * @param string $key
   *   The array field key.
   * @param array $arraySchema
   *   The array schema definition.
   * @param array $nestedValues
   *   The nested form values to extract from.
   *
   * @return array
   *   The extracted array data.
   */
  protected function extractArrayDataFromValues(string $key, array $arraySchema, array $nestedValues): array {
    $data = [];

    // For top-level arrays, the key might be compound like 'pesticide__applications'.
    if (isset($nestedValues[$key]) && is_array($nestedValues[$key]) && isset($nestedValues[$key]['items_wrapper'])) {
      // Direct access for top-level arrays.
      $arrayValues = $nestedValues[$key];
    }
    else {
      // Try nested lookup for arrays that are truly nested within structures.
      $arrayValues = $this->getNestedValue($nestedValues, $key);
    }

    if (!is_array($arrayValues) || !isset($arrayValues['items_wrapper'])) {
      return [];
    }

    $itemsWrapper = $arrayValues['items_wrapper'];

    $index = 0;
    while (isset($itemsWrapper[$key . '_item_' . $index])) {
      $itemKey = $key . '_item_' . $index;
      $itemValues = $itemsWrapper[$itemKey];

      $itemData = $this->extractArrayItemDataFromValues($itemValues, $arraySchema['items'] ?? [], $itemKey);

      if (!empty($itemData)) {
        $data[] = $itemData;
      }
      $index++;
    }

    return $data;
  }

  /**
   * Extracts data for a single array item from nested values.
   *
   * @param array $itemValues
   *   The form values for this array item.
   * @param array $itemSchema
   *   The schema for array items.
   * @param string $itemKey
   *   The key for this array item.
   *
   * @return array
   *   The extracted array item data.
   */
  protected function extractArrayItemDataFromValues(array $itemValues, array $itemSchema, string $itemKey): array {
    $itemData = [];

    if (isset($itemSchema['allOf'])) {
      $allOfKey = $itemKey . '_allOf';

      if (isset($itemValues[$allOfKey])) {
        foreach ($itemSchema['allOf'] as $allOfSchema) {
          if (isset($allOfSchema['properties'])) {
            $nestedData = $this->extractNestedObjectData($allOfSchema['properties'], $itemValues[$allOfKey], $allOfKey, FALSE);
            if (!empty($nestedData)) {
              $itemData = array_merge($itemData, $nestedData);
            }
          }
        }
      }
    }
    elseif (isset($itemSchema['oneOf'])) {
      $oneOfKey = $itemKey . '_oneof';

      if (isset($itemValues[$oneOfKey]) && isset($itemSchema['oneOf'][0]['allOf'])) {
        $firstOption = $itemSchema['oneOf'][0];
        $optionKey = $itemKey . '_option_0';

        if (isset($itemValues[$optionKey])) {
          foreach ($firstOption['allOf'] as $allOfSchema) {
            if (isset($allOfSchema['properties'])) {
              $nestedData = $this->extractNestedObjectData($allOfSchema['properties'], $itemValues[$optionKey], $optionKey, FALSE);
              $itemData = array_merge($itemData, $nestedData);
            }
          }
        }
      }
    }
    elseif (isset($itemSchema['properties'])) {
      $itemData = $this->extractNestedObjectData($itemSchema['properties'], $itemValues, $itemKey, FALSE);
    }

    return $itemData;
  }

  /**
   * Extracts and type-casts a simple field value.
   *
   * @param string $key
   *   The field key.
   * @param array $fieldSchema
   *   The field schema definition.
   * @param array $formValues
   *   The form values to extract from.
   * @param bool $isTopLevel
   *   Whether this is a top-level field.
   *
   * @return mixed
   *   The extracted and type-cast value.
   */
  protected function extractSimpleFieldValue(string $key, array $fieldSchema, array $formValues, bool $isTopLevel): mixed {
    // Try to get value from nested values first.
    $value = $this->getNestedValue($formValues, $key);

    if ($value === NULL || $value === '') {
      return NULL;
    }

    $type = $fieldSchema['type'] ?? 'string';

    return match ($type) {
      'object' => $this->extractNestedObjectData($fieldSchema['properties'] ?? [], $formValues, $key, $isTopLevel),
      'integer' => (int) $value,
      'boolean' => (bool) $value,
      'number' => (float) $value,
      'string' => (string) $value,
      'array' => is_array($value) ? $value : [],
      default => $value,
    };
  }

  /**
   * Helper method to get a nested value from form values.
   *
   * @param array $nestedValues
   *   The form values array.
   * @param string $fullKey
   *   The full key using double underscore notation.
   *
   * @return mixed
   *   The value if found, NULL otherwise.
   */
  protected function getNestedValue(array $nestedValues, string $fullKey) {
    // Check for direct match first (for top-level fields).
    if (array_key_exists($fullKey, $nestedValues)) {
      return $nestedValues[$fullKey];
    }

    // Handle compound nested keys by finding parent containers.
    // For keys like 'prefix__nested__field', look for 'prefix__nested'.
    $keyParts = explode('__', $fullKey);

    // Build potential parent keys by joining parts.
    for ($i = count($keyParts) - 1; $i > 0; $i--) {
      $parentKey = implode('__', array_slice($keyParts, 0, $i));
      $childKey = implode('__', array_slice($keyParts, $i));

      if (isset($nestedValues[$parentKey]) && is_array($nestedValues[$parentKey])) {
        if (array_key_exists($fullKey, $nestedValues[$parentKey])) {
          // Direct key exists in parent.
          return $nestedValues[$parentKey][$fullKey];
        }
        elseif (array_key_exists($childKey, $nestedValues[$parentKey])) {
          // Child key exists in parent.
          return $nestedValues[$parentKey][$childKey];
        }
      }
    }

    return NULL;
  }

  /**
   * Gets the base key from a compound key.
   *
   * For 'pesticide__applications' returns 'applications'.
   * For 'simpleKey' returns 'simpleKey'.
   *
   * @param string $key
   *   The compound key.
   *
   * @return string
   *   The base key.
   */
  protected function getBaseKey(string $key): string {
    $parts = explode('__', $key);
    return end($parts);
  }

  /**
   * Helper method to find the field key in a subschema.
   *
   * @param array $subSchema
   *   The subschema to search.
   *
   * @return string|null
   *   The field key if found, NULL otherwise.
   */
  protected function findFieldKeyInSubSchema(array $subSchema): ?string {
    if (isset($subSchema['title'])) {
      return $this->convertTitleToKey($subSchema['title']);
    }

    // Check for enum fields (select fields).
    if (isset($subSchema['enum'])) {
      return 'enum_field';
    }

    return NULL;
  }

  /**
   * Converts a title to a valid form key.
   *
   * @param string $title
   *   The human-readable title.
   *
   * @return string
   *   The form-friendly key.
   */
  protected function convertTitleToKey(string $title): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
  }

}
