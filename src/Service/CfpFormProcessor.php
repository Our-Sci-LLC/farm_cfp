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
      $this->addPropertiesToForm($form, $schema['properties'], NULL, $requiredFields);
    }
    return $form;
  }

  /**
   * Processes properties and adds them to the form.
   */
  protected function addPropertiesToForm(array &$form, array $properties, ?string $parentKey = NULL, array $requiredFields = []): void {
    foreach ($properties as $key => $property) {
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      // Mark field as required if it's in the required fields list.
      $propertySchema = $property;
      if (in_array($key, $requiredFields)) {
        $propertySchema['#required'] = TRUE;
      }

      if (isset($propertySchema['allOf'])) {
        $this->addAllOfToForm($form, $key, $propertySchema['allOf'], $propertySchema);
      }
      elseif (isset($propertySchema['oneOf'])) {
        $this->addOneOfToForm($form, $fullKey, $propertySchema);
      }
      elseif (isset($propertySchema['properties'])) {
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
    // Create a details element for the allOf container.
    $title = $propertySchema['x-metadata']['name'] ?? $propertySchema['title'] ?? ucfirst($key);
    $hasRequiredFields = FALSE;

    $form[$key] = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        $nestedKey = $subSchema['x-metadata']['slug'] ?? $key;
        if (empty($subSchema['x-metadata']['name'])) {
          $subSchema['x-metadata']['name'] = $title;
        }
        $this->addAllOfToForm($form[$key], $nestedKey, $subSchema['allOf'], $subSchema);
      }
      elseif (isset($subSchema['properties'])) {
        // @todo Don't require these for now because the subfields should
        // only be required if the item is added via 'Add more'.
        $nestedRequired = $subSchema['required'] ?? [];
        if (!empty($nestedRequired)) {
          $hasRequiredFields = TRUE;
        }
        $this->addPropertiesToForm($form[$key], $subSchema['properties'], $key, $nestedRequired);
      }
      elseif (isset($subSchema['oneOf'])) {
        $oneOfKey = $subSchema['x-metadata']['slug'] ?? $key . '_oneof';
        $this->addOneOfToForm($form[$key], $oneOfKey, $subSchema);
      }
      else {
        // Process individual field within allOf.
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $this->addFieldToForm($form[$key], $key . '__' . $fieldKey, $subSchema);
        }
      }
    }

    // @todo don't require fields for now.
//    if ($hasRequiredFields || !empty($propertySchema['#required'])) {
//      $form[$key]['#title'] .= ' *';
//      $form[$key]['#attributes']['class'][] = 'required-details';
//    }
  }

  /**
   * Adds an object with properties to the form.
   */
  protected function addObjectToForm(array &$form, string $key, array $objectSchema, array $requiredFields = []): void {
    $field = [
      '#type' => 'fieldset',
      '#title' => $this->t($objectSchema['title'] ?? ucfirst($this->getLastKeyPart($key))),
    ];

    // @todo don't require fields for now.
//    if (!empty($requiredFields) || !empty($objectSchema['#required'])) {
//      $field['#title'] .= ' *';
//      $field['#attributes']['class'][] = 'required-fieldset';
//    }

    if (isset($objectSchema['description'])) {
      $field['#description'] = $this->t($objectSchema['description']);
    }

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

    // If each oneOf has properties, add them as conditional fields.
    foreach ($oneOfSchema['oneOf'] as $option) {
      if (!isset($option['properties'])) {
        continue;
      }
      $this->addPropertiesToForm($form, $option['properties'], $key);
    }

    // @todo don't require fields for now because they are conditionally shown.
    //if (!empty($oneOfSchema['#required'])) {
      //$form[$key]['#required'] = TRUE;
    //}
  }

  /**
   * Adds a single field to the form based on schema.
   */
  protected function addFieldToForm(array &$form, string $key, array $fieldSchema): void {
    // @todo hard-code required fields for now.
    $required = [
      'cropDetails__cropType',
      'cropDetails__assessmentYear',
      'cropDetails__area__value',
      'cropDetails__area__unit',
      'cropDetails__cropYield__value',
      'cropDetails__cropYield__unit',
      'cropDetails__farmGate__value',
      'cropDetails__farmGate__unit',
      'cropDetails__cropLifeCycleDuration',
      'cropDetails__inputLifeCycleYear',
      'cropDetails__plantsDiePercent',
      'harvestedInputYearWeight__value',
      'harvestedInputYearWeight__unit',
    ];
    $field = [
      '#title' => $this->t($fieldSchema['title'] ?? ucfirst($this->getLastKeyPart($key))),
    ];

    if (isset($fieldSchema['description'])) {
      $field['#description'] = $this->t($fieldSchema['description']);
    }

    // @todo don't require fields for now.
    if (in_array($key, $required)) {
      $field['#required'] = TRUE;
    }
    //if (!empty($fieldSchema['#required'])) {
      //$field['#required'] = TRUE;
    //}

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
   * Configures array field as a fieldset with a repeatable item structure.
   */
  protected function configureArrayField(array $field, array $schema, string $key): array {
    $field['#type'] = 'fieldset';
    $field['#title'] = $this->t($schema['title'] ?? ucfirst($this->getLastKeyPart($key)));
    $field['#tree'] = TRUE;

    // Check for a complex item definition using 'items' and its internal structure.
    $isComplexArray = isset($schema['items'])
      && (
        (isset($schema['items']['type']) && $schema['items']['type'] === 'object') ||
        isset($schema['items']['allOf']) ||
        isset($schema['items']['oneOf'])
      );

    if ($isComplexArray) {
      $itemsSchema = $schema['items'];

      $field['items_wrapper'] = [
        '#type' => 'container',
        '#prefix' => '<div id="' . $key . '-add-more-wrapper">',
        '#suffix' => '</div>',
        '#array_parents' => [$key],
      ];

      // Start with one initial item.
      $count = 1;
      for ($i = 0; $i < $count; $i++) {
        $this->addArrayItemToForm($field['items_wrapper'], $i, $key, $itemsSchema);
      }

      // @todo Add a placeholder for the AJAX 'Add more' button.
      $field['actions'] = [
        '#type' => 'actions',
        'add_item' => [
          '#type' => 'submit',
          '#value' => $this->t('Add @title Item', ['@title' => $field['#title']]),
          '#name' => $key . '_add_more',
          // Disable as AJAX isn't implemented here.
          '#attributes' => ['disabled' => 'disabled'],
        ],
      ];

      $field['#markup'] = $this->t('Array structure initialized with one item. Dynamic "Add More" functionality requires AJAX setup in the parent form.');
      // @todo don't make it required unless an item is added via 'Add more'.
      $field['#required'] = FALSE;
    }
    else {
      $field['#markup'] = $this->t('Array field type is defined, but the "items" definition is missing or unsupported.');
    }

    return $field;
  }

  /**
   * Adds a single array item (object) to the form, recursively processing its schema.
   */
  protected function addArrayItemToForm(array &$form, int $index, string $parentKey, array $itemsSchema): void {
    $itemKey = $parentKey . '_item_' . $index;

    // Create a container for the individual item.
    $form[$itemKey] = [
      '#type' => 'div',
    ];

    if (isset($itemsSchema['allOf'])) {
      $optionKey = $itemsSchema['x-metadata']['slug'] ?? $itemKey . '_allOf';
      // The parent key for nested fields inside the allOf will be the itemKey.
      if (empty($itemsSchema['x-metadata']['name'])) {
        $itemsSchema['x-metadata']['name'] = $this->getLastKeyPart($parentKey) . ' ' . $index + 1;
      }
      $this->addAllOfToForm($form[$itemKey], $optionKey, $itemsSchema['allOf'], $itemsSchema);
    }
    elseif (isset($itemsSchema['oneOf'])) {
      $oneOfKey = $itemsSchema['x-metadata']['slug'] ?? $itemKey . '_oneof';
      $this->addOneOfToForm($form[$itemKey], $oneOfKey, $itemsSchema);

      // NOTE: In a production environment, you would use AJAX to conditionally
      // render the fields associated with the selected 'oneOf' option below this radio button.

      // TEMPORARY: For static form rendering, we'll render fields for the first option only.
      if (isset($itemsSchema['oneOf'][0]['allOf'])) {
        $firstOption = $itemsSchema['oneOf'][0];
        $optionKey = $firstOption['x-metadata']['slug'] ?? $itemKey . '_option_0';
        $this->addAllOfToForm($form[$itemKey], $optionKey, $firstOption['allOf'], $firstOption);
      }
    }
    elseif (isset($itemsSchema['properties'])) {
      // Handles a simple array of objects.
      // @todo only make it required if an item is added via 'Add more'.
      //$requiredFields = $itemsSchema['required'] ?? [];
      $requiredFields = [];
      $this->addPropertiesToForm($form[$itemKey], $itemsSchema['properties'], $itemKey, $requiredFields);
    }
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
   * Extracts data from form state based on properties schema.
   */
  protected function extractPropertiesData(array $properties, FormStateInterface $formState, ?string $parentKey = NULL): ?array {
    // @todo properties to skip because they are not yet working.
    $skip = [
      'residue' => ['residueManagementPractice' => 'composting_forced_aeration'],
      'wasteWater' => ['treatments' => []],
      'pesticide' => ['applications' => []],
      'fertiliser' => ['fertilisers' => []],
      'fuelEnergy' => ['usages' => []] ,
      'irrigation' => ['events' => []],
      'transport' => ['transports' => []],
      'machinery' => ['applications' => []],
      'nonCropEstimated' => ['intercrops' => [], 'shadeTrees' => [], 'hedges' => []],
      'SOC' => ['landUseHistory' => NULL],
      'nonCropMeasured' => ['trees' => []],
      'landUseChangeBiomass' => ['forestChanges' => NULL],
      'refrigerants' => ['equipments' => []],
      'seedProduction' => ['seedBought' => NULL],
      'coProducts' => ['products' => []],
    ];
    $data = [];
    $formValues = $formState->getValues();
    $isTopLevel = ($parentKey === NULL);

    foreach ($properties as $key => $property) {
      if (array_key_exists($key, $skip)) {
        $data[$key] = $skip[$key];
        continue;
      }
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      if (isset($property['allOf'])) {
        $data[$key] = $this->extractAllOfDataFromValues($property['allOf'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['oneOf'])) {
        $data[$key] = $this->extractOneOfDataFromValues($formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['properties'])) {
        $data[$key] = $this->extractNestedProperties($property['properties'], $formValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['type']) && $property['type'] === 'array') {
        $data[$key] = $this->extractArrayData($fullKey, $property, $formState);
      }
      else {
        $data[$key] = $this->extractFieldValueFlexible($fullKey, $property, $formValues, $isTopLevel);
      }
    }

    return $data;
  }

  /**
   * Extracts array data from form state.
   */
  protected function extractArrayData(string $key, array $arraySchema, FormStateInterface $formState): array {
    $formValues = $formState->getValues();

    // For top-level arrays, use direct access
    if (isset($formValues[$key]) && is_array($formValues[$key]) && isset($formValues[$key]['items_wrapper'])) {
      $arrayValues = $formValues[$key];
    } else {
      // Try nested lookup for arrays within structures.
      $arrayValues = $this->getNestedValue($formValues, $key);
    }

    if (!is_array($arrayValues) || !isset($arrayValues['items_wrapper'])) {
      return [];
    }

    $itemsWrapper = $arrayValues['items_wrapper'];

    // Use base key for item lookup
    $baseKey = $this->getBaseKey($key);

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
   */
  protected function extractArrayItemData(array $itemValues, array $itemSchema, string $itemKey, FormStateInterface $formState): array {
    $itemData = [];

    // For array items, we're always working with nested values, so isTopLevel = FALSE
    if (isset($itemSchema['allOf'])) {
      $allOfKey = $itemKey . '_allOf';

      if (isset($itemValues[$allOfKey])) {
        foreach ($itemSchema['allOf'] as $allOfSchema) {
          if (isset($allOfSchema['properties'])) {
            $nestedData = $this->extractNestedProperties($allOfSchema['properties'], $itemValues[$allOfKey], $allOfKey, FALSE);
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
              $nestedData = $this->extractNestedProperties($allOfSchema['properties'], $itemValues[$optionKey], $optionKey, FALSE);
              $itemData = array_merge($itemData, $nestedData);
            }
          }
        }
      }
    }
    elseif (isset($itemSchema['properties'])) {
      $itemData = $this->extractNestedProperties($itemSchema['properties'], $itemValues, $itemKey, FALSE);
    }

    return $itemData;
  }

  /**
   * Extracts properties from form values, handling both nested and top-level values.
   */
  protected function extractNestedProperties(array $properties, array $nestedValues, string $parentKey, bool $isTopLevel = FALSE): ?array {
    // @todo properties to skip because they are not yet working.
    $skip = [
      'coProducts',
    ];

    $data = [];

    foreach ($properties as $key => $property) {
      if (in_array($key, $skip)) {
        return NULL;
      }
      $fullKey = $parentKey ? "{$parentKey}__{$key}" : $key;

      if (isset($property['allOf'])) {
        $data[$key] = $this->extractAllOfDataFromValues($property['allOf'], $nestedValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['oneOf'])) {
        $data[$key] = $this->extractOneOfDataFromValues($nestedValues, $fullKey, $isTopLevel);
        // @todo One of fields are radio buttons that have dependent fields.
        // We will implement full extraction logic later.
      }
      elseif (isset($property['properties'])) {
        $data[$key] = $this->extractNestedProperties($property['properties'], $nestedValues, $fullKey, $isTopLevel);
      }
      elseif (isset($property['type']) && $property['type'] === 'array') {
        $data[$key] = $this->extractArrayDataFromValues($fullKey, $property, $nestedValues);
      }
      else {
        $data[$key] = $this->extractFieldValueFlexible($fullKey, $property, $nestedValues, $isTopLevel);
      }
    }

    return $data;
  }

  /**
   * Extracts field value that can be either nested or at top level.
   */
  protected function extractFieldValueFlexible(string $key, array $fieldSchema, array $nestedValues, bool $isTopLevel = FALSE): mixed {
    // Try to get value from nested values first.
    $value = $this->getNestedValue($nestedValues, $key);

    if ($value === NULL || $value === '') {
      return NULL;
    }

    $type = $fieldSchema['type'] ?? 'string';

    return match ($type) {
      'object' => $this->extractNestedProperties($fieldSchema['properties'] ?? [], $nestedValues, $key, $isTopLevel),
      'integer' => (int) $value,
      'boolean' => (bool) $value,
      'number' => (float) $value,
      'string' => (string) $value,
      'array' => is_array($value) ? $value : [],
      default => $value,
    };
  }

  /**
   * Extracts allOf data from values, handling both nested and top-level scenarios.
   */
  protected function extractAllOfDataFromValues(array $allOf, array $nestedValues, string $parentKey, bool $isTopLevel = FALSE): array {
    $data = [];

    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        $data = array_merge($data, $this->extractAllOfDataFromValues($subSchema['allOf'], $nestedValues, $parentKey, $isTopLevel));
      }
      elseif (isset($subSchema['properties'])) {
        $nestedData = $this->extractNestedProperties($subSchema['properties'], $nestedValues, $parentKey, $isTopLevel);
        if (!empty($nestedData)) {
          $data = array_merge($data, $nestedData);
        }
      }
      else {
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $fullKey = $parentKey . '__' . $fieldKey;
          $data[$fieldKey] = $this->extractFieldValueFlexible($fullKey, $subSchema, $nestedValues, $isTopLevel);
        }
      }
    }

    return $data;
  }

  /**
   * Extracts oneOf data from values, handling both nested and top-level scenarios.
   */
  protected function extractOneOfDataFromValues(array $nestedValues, string $key, bool $isTopLevel = FALSE): mixed {
    $value = $this->getNestedValue($nestedValues, $key);

    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Extracts field value from nested array instead of using formState->getValue().
   */
  protected function extractFieldValueFromNested(string $key, array $fieldSchema, array $nestedValues): mixed {
    // Try the nested values first.
    $value = $this->getNestedValue($nestedValues, $key);

    if ($value === NULL || $value === '') {
      return NULL;
    }

    $type = $fieldSchema['type'] ?? 'string';

    return match ($type) {
      'object' => $this->extractNestedProperties($fieldSchema['properties'] ?? [], $nestedValues, $key),
      'integer' => (int) $value,
      'boolean' => (bool) $value,
      'number' => (float) $value,
      'string' => (string) $value,
      'array' => is_array($value) ? $value : [],
      default => $value,
    };
  }

  /**
   * Helper method to get a nested value from an array using key parts.
   */
  /**
   * Gets a nested value from form state using actual key structure.
   */
  protected function getNestedValue(array $nestedValues, string $fullKey) {
    // Check for direct match first (for top-level fields).
    if (array_key_exists($fullKey, $nestedValues)) {
      return $nestedValues[$fullKey];
    }

    // Handle compound nested keys by finding parent containers
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
   * Extracts array data from nested values (when already working with a subset of form values).
   */
  protected function extractArrayDataFromValues(string $key, array $arraySchema, array $nestedValues): array {
    $data = [];

    // For top-level arrays, the key might be compound like 'pesticide__applications'
    // We need to check if this exact key exists in the nestedValues
    if (isset($nestedValues[$key]) && is_array($nestedValues[$key]) && isset($nestedValues[$key]['items_wrapper'])) {
      // Direct access for top-level arrays
      $arrayValues = $nestedValues[$key];
    } else {
      // Try nested lookup for arrays that are truly nested within structures
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
   * Gets the base key from a compound key.
   * For 'pesticide__applications' returns 'applications'
   * For 'simpleKey' returns 'simpleKey'
   */
  protected function getBaseKey(string $key): string {
    $parts = explode('__', $key);
    return end($parts);
  }

  /**
   * Extracts data for a single array item from nested values.
   */
  protected function extractArrayItemDataFromValues(array $itemValues, array $itemSchema, string $itemKey): array {
    $itemData = [];

    if (isset($itemSchema['allOf'])) {
      $allOfKey = $itemKey . '_allOf';

      if (isset($itemValues[$allOfKey])) {
        foreach ($itemSchema['allOf'] as $allOfSchema) {
          if (isset($allOfSchema['properties'])) {
            $nestedData = $this->extractNestedProperties($allOfSchema['properties'], $itemValues[$allOfKey], $allOfKey, FALSE);
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
              $nestedData = $this->extractNestedProperties($allOfSchema['properties'], $itemValues[$optionKey], $optionKey, FALSE);
              $itemData = array_merge($itemData, $nestedData);
            }
          }
        }
      }
    }
    elseif (isset($itemSchema['properties'])) {
      $itemData = $this->extractNestedProperties($itemSchema['properties'], $itemValues, $itemKey, FALSE);
    }

    return $itemData;
  }

  /**
   * Extracts data from allOf schema elements recursively.
   */
  protected function extractAllOfData(array $allOf, FormStateInterface $formState, string $parentKey): array {
    $data = [];
    $formValues = $formState->getValues();

    foreach ($allOf as $subSchema) {
      if (isset($subSchema['allOf'])) {
        $data = array_merge($data, $this->extractAllOfData($subSchema['allOf'], $formState, $parentKey));
      }
      elseif (isset($subSchema['properties'])) {
        $nestedData = $this->extractNestedProperties($subSchema['properties'], $formValues, $parentKey);
        $data = array_merge($data, $nestedData);
      }
      else {
        $fieldKey = $this->findFieldKeyInSubSchema($subSchema);
        if ($fieldKey) {
          $fullKey = $parentKey . '__' . $fieldKey;
          $data[$fieldKey] = $this->extractFieldValueFromNested($fullKey, $subSchema, $formValues);
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
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Extracts and processes a single field value.
   */
  protected function extractFieldValue(string $key, array $fieldSchema, FormStateInterface $formState): mixed {
    $value = $formState->getValue($key);

    if ($value === NULL || $value === '') {
      return NULL;
    }

    $type = $fieldSchema['type'] ?? 'string';

    return match ($type) {
      'object' => $this->extractNestedProperties($fieldSchema['properties'] ?? [], $formState->getValues(), $key),
      'integer' => (int) $value,
      'boolean' => (bool) $value,
      'number' => (float) $value,
      'string' => (string) $value,
      'array' => $this->extractArrayData($key, $fieldSchema, $formState),
      default => $value,
    };
  }

  /**
   * Helper method to find the field key in a subschema.
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
   */
  protected function convertTitleToKey(string $title): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
  }

  /**
   * Extracts the last part of a nested key.
   */
  protected function getLastKeyPart(string $key): string {
    $parts = explode('__', $key);
    return ucfirst(end($parts));
  }

}
