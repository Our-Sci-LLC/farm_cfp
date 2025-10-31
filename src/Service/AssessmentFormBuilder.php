<?php

namespace Drupal\farm_cfp\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\farm_cfp\Constants;

/**
 * Builds Drupal form elements from JSON Schema definitions.
 *
 * Handles complex schema structures including:
 * - Nested objects and properties
 * - Conditional selections (oneOf)
 * - Combined schemas (allOf)
 * - Arrays with complex item types
 * - Basic field types
 */
class AssessmentFormBuilder {
  use StringTranslationTrait;

  // Schema type constants.
  private const SCHEMA_TYPE_OBJECT = 'object';
  private const SCHEMA_TYPE_ARRAY = 'array';
  private const SCHEMA_TYPE_STRING = 'string';
  private const SCHEMA_TYPE_NUMBER = 'number';
  private const SCHEMA_TYPE_INTEGER = 'integer';
  private const SCHEMA_TYPE_BOOLEAN = 'boolean';

  // Form element types.
  private const FORM_DETAILS = 'details';
  private const FORM_FIELDSET = 'fieldset';
  private const FORM_RADIOS = 'radios';
  private const FORM_CHECKBOX = 'checkbox';
  private const FORM_TEXTFIELD = 'textfield';
  private const FORM_TEXTAREA = 'textarea';
  private const FORM_SELECT = 'select';
  private const FORM_NUMBER = 'number';
  private const FORM_CONTAINER = 'container';

  public function __construct(
    protected LookupService $cfpLookupService) {
  }

  /**
   * Builds complete form structure from JSON schema.
   *
   * @param array $schema
   *   The JSON schema with properties to build form elements from.
   * @param string $mode
   *   The operation mode (basic/advanced).
   *
   * @return array
   *   Drupal form array with all elements built from schema.
   */
  public function buildFromSchema(array $schema): array {
    $form = [];

    if (empty($schema['properties'])) {
      return $form;
    }

    $requiredFields = $schema['required'] ?? [];
    $ignoredProperties = $schema['ignored'] ?? [];

    $this->buildFormElementsFromProperties(
      $form,
      $schema['properties'],
      NULL,
      $requiredFields,
      $ignoredProperties
    );

    return $form;
  }

  /**
   * Recursively processes schema properties into form elements.
   *
   * @param array &$form
   *   The parent form array to add elements to.
   * @param array $properties
   *   Schema properties to process.
   * @param string|NULL $parentKey
   *   Parent key for nested elements, NULL for root level.
   * @param array $requiredFields
   *   List of required field keys.
   * @param array $ignoredProperties
   *   Properties to skip in basic mode.
   */
  protected function buildFormElementsFromProperties(
    array &$form,
    array $properties,
    ?string $parentKey = NULL,
    array $requiredFields = [],
    array $ignoredProperties = []
  ): void {
    foreach ($properties as $propertyKey => $propertySchema) {
      $fullKey = $this->buildFullKey($parentKey, $propertyKey);

      if ($this->shouldSkipProperty($propertyKey, $ignoredProperties)) {
        $form[$fullKey] = [];
        continue;
      }

      $this->buildPropertyElement($form, $fullKey, $propertySchema, $requiredFields);
    }
  }

  /**
   * Determines if a property should be skipped based on operation mode.
   */
  private function shouldSkipProperty(string $propertyKey, array $ignoredProperties): bool {
    $mode = $this->cfpLookupService->getOperationMode();
    return $mode === Constants::OPERATION_MODE_BASIC && in_array($propertyKey, $ignoredProperties);
  }

  /**
   * Routes property to appropriate form element builder based on schema structure.
   */
  protected function buildPropertyElement(
    array &$form,
    string $fullKey,
    array $propertySchema,
    array $requiredFields
  ): void {
    // Mark required fields.
    $baseKey = $this->getBaseKey($fullKey);
    if (in_array($baseKey, $requiredFields)) {
      $propertySchema['#required'] = TRUE;
    }

    // Route to appropriate builder based on schema structure.
    if (isset($propertySchema['allOf'])) {
      $this->buildAllOfElement($form, $fullKey, $propertySchema);
    }
    elseif (isset($propertySchema['oneOf'])) {
      $this->buildConditionalSelectionElement($form, $fullKey, $propertySchema);
    }
    elseif (isset($propertySchema['properties'])) {
      $this->buildObjectElement($form, $fullKey, $propertySchema);
    }
    else {
      $this->buildSimpleFieldElement($form, $fullKey, $propertySchema);
    }
  }

  /**
   * Builds form element for allOf schema (combined schemas).
   *
   * allOf represents a combination of multiple schemas where all must be satisfied.
   * This is rendered as a collapsible details element containing all combined fields.
   */
  protected function buildAllOfElement(array &$form, string $key, array $schema): void {
    $title = $this->getElementTitle($schema, $key);
    $hasRequiredFields = FALSE;

    $form[$key] = [
      '#type' => self::FORM_DETAILS,
      '#title' => $this->t($title),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    foreach ($schema['allOf'] as $subSchema) {
      if (isset($subSchema['allOf'])) {
        $this->buildNestedAllOfElement($form[$key], $key, $subSchema, $title);
      }
      elseif (isset($subSchema['properties'])) {
        $hasRequiredFields = $this->processNestedProperties(
          $form[$key],
          $key,
          $subSchema,
          $hasRequiredFields
        );
      }
      elseif (isset($subSchema['oneOf'])) {
        $this->buildNestedConditionalElement($form[$key], $key, $subSchema);
      }
    }

    $this->markElementAsRequired($form[$key], $hasRequiredFields, $schema);
  }

  /**
   * Builds nested allOf element within a parent allOf.
   */
  protected function buildNestedAllOfElement(
    array &$parentForm,
    string $parentKey,
    array $subSchema,
    string $fallbackTitle
  ): void {
    $nestedKey = $subSchema['x-metadata']['slug'] ?? $parentKey;

    if (empty($subSchema['x-metadata']['name'])) {
      $subSchema['x-metadata']['name'] = $fallbackTitle;
    }

    $this->buildAllOfElement($parentForm, $nestedKey, $subSchema);
  }

  /**
   * Processes nested properties within an allOf element.
   */
  protected function processNestedProperties(
    array &$parentForm,
    string $parentKey,
    array $subSchema,
    bool $currentRequiredStatus
  ): bool {
    $nestedRequired = $subSchema['required'] ?? [];
    $hasRequiredFields = $currentRequiredStatus || !empty($nestedRequired);

    $this->buildFormElementsFromProperties(
      $parentForm,
      $subSchema['properties'],
      $parentKey,
      $nestedRequired
    );

    return $hasRequiredFields;
  }

  /**
   * Builds nested conditional (oneOf) element within allOf.
   */
  protected function buildNestedConditionalElement(
    array &$parentForm,
    string $parentKey,
    array $subSchema
  ): void {
    $oneOfKey = $subSchema['x-metadata']['slug'] ?? $parentKey . '_oneof';
    $this->buildConditionalSelectionElement($parentForm, $oneOfKey, $subSchema);
  }

  /**
   * Builds conditional selection element (oneOf) with radio options.
   *
   * Creates radio buttons where each option reveals different form sections.
   * Used for mutually exclusive schema options with different structures.
   */
  protected function buildConditionalSelectionElement(array &$form, string $key, array $conditionalSchema): void {
    $options = $this->buildConditionalOptions($conditionalSchema['oneOf']);

    $form[$key] = [
      '#type' => self::FORM_RADIOS,
      '#title' => $this->getElementTitle($conditionalSchema, $key),
      '#options' => $options,
      '#default_value' => 0,
    ];

    $this->addConditionalSections($form, $key, $conditionalSchema['oneOf']);
  }

  /**
   * Creates option labels for conditional selection.
   */
  private function buildConditionalOptions(array $options): array {
    $formOptions = [];

    foreach ($options as $index => $option) {
      $formOptions[$index] = $this->t($option['title'] ?? 'Option ' . ($index + 1));
    }

    return $formOptions;
  }

  /**
   * Adds conditional form sections that appear based on radio selection.
   */
  private function addConditionalSections(array &$form, string $key, array $options): void {
    foreach ($options as $index => $option) {
      $optionKey = $key . '_option_' . $index;

      $form[$optionKey] = [
        '#type' => self::FORM_CONTAINER,
        '#states' => [
          'visible' => [
            ':input[name="' . $key . '"]' => ['value' => (string) $index],
          ],
        ],
      ];

      $this->buildConditionalOptionContent($form[$optionKey], $optionKey, $option);
    }
  }

  /**
   * Builds content for a conditional option section.
   */
  private function buildConditionalOptionContent(array &$container, string $optionKey, array $optionSchema): void {
    if (isset($optionSchema['properties'])) {
      $this->buildFormElementsFromProperties($container, $optionSchema['properties'], $optionKey);
    }

    if (isset($optionSchema['allOf'])) {
      $allOfKey = $optionKey . '_allof';
      $this->buildAllOfElement($container, $allOfKey, $optionSchema);
    }

    if (isset($optionSchema['oneOf'])) {
      $nestedOneOfKey = $optionKey . '_oneof';
      $this->buildConditionalSelectionElement($container, $nestedOneOfKey, $optionSchema);
    }
  }

  /**
   * Builds object element as a fieldset with nested properties.
   */
  protected function buildObjectElement(array &$form, string $key, array $objectSchema): void {
    $field = [
      '#type' => self::FORM_FIELDSET,
      '#title' => $this->getElementTitle($objectSchema, $key),
    ];

    if (isset($objectSchema['description'])) {
      $field['#description'] = $this->t($objectSchema['description']);
    }

    $this->markElementAsRequired($field, !empty($objectSchema['required']), $objectSchema);

    if (!empty($objectSchema['properties'])) {
      // @todo we don't pass required fields if the object is conditionally
      // shown. We should fix this later.
      if (isset($form['#states'])) {
        $nestedRequired = [];
      }
      else {
        $nestedRequired = $objectSchema['required'] ?? [];
      }
      $this->buildFormElementsFromProperties($field, $objectSchema['properties'], $key, $nestedRequired);
    }

    $form[$key] = $field;
  }

  /**
   * Builds simple field element based on schema type.
   */
  protected function buildSimpleFieldElement(array &$form, string $key, array $fieldSchema): void {
    $mode = $this->cfpLookupService->getOperationMode();

    // Skip array fields in basic mode.
    if ($mode === Constants::OPERATION_MODE_BASIC &&
      isset($fieldSchema['type']) &&
      $fieldSchema['type'] === self::SCHEMA_TYPE_ARRAY) {
      return;
    }

    $field = [
      '#title' => $this->getElementTitle($fieldSchema, $key),
    ];

    if (isset($fieldSchema['description'])) {
      $field['#description'] = $this->t($fieldSchema['description']);
    }

    if (!empty($fieldSchema['#required'])) {
      $field['#required'] = TRUE;
    }

    $field = $this->applyFieldTypeConfiguration($field, $fieldSchema, $key);
    $form[$key] = $field;
  }

  /**
   * Applies field type-specific configuration to form element.
   */
  protected function applyFieldTypeConfiguration(array $field, array $schema, string $key): array {
    $type = $schema['type'] ?? self::SCHEMA_TYPE_STRING;

    switch ($type) {
      case self::SCHEMA_TYPE_STRING:
        return $this->configureStringField($field, $schema);

      case self::SCHEMA_TYPE_INTEGER:
      case self::SCHEMA_TYPE_NUMBER:
        return $this->configureNumberField($field, $schema);

      case self::SCHEMA_TYPE_BOOLEAN:
        $field['#type'] = self::FORM_CHECKBOX;
        // Don't force required on boolean fields because they can be unset.
        $field['#required'] = FALSE;
        return $field;

      case self::SCHEMA_TYPE_OBJECT:
        return $this->configureObjectField($field, $schema, $key);

      case self::SCHEMA_TYPE_ARRAY:
        return $this->configureArrayField($field, $schema, $key);

      default:
        $field['#type'] = 'markup';
        $field['#markup'] = $this->t('Unsupported field type: @type', ['@type' => $type]);
        return $field;
    }
  }

  /**
   * Configures string field as textfield, textarea, or select.
   */
  protected function configureStringField(array $field, array $schema): array {
    if (isset($schema['enum'])) {
      $field['#type'] = self::FORM_SELECT;
      $field['#options'] = array_combine($schema['enum'], $schema['enum']);
    }
    elseif (isset($schema['maxLength']) && $schema['maxLength'] > 255) {
      $field['#type'] = self::FORM_TEXTAREA;
    }
    else {
      $field['#type'] = self::FORM_TEXTFIELD;
    }

    return $field;
  }

  /**
   * Configures number field with validation constraints.
   */
  protected function configureNumberField(array $field, array $schema): array {
    $field['#type'] = self::FORM_NUMBER;
    $isFloat = $schema['type'] === self::SCHEMA_TYPE_NUMBER;

    if (isset($schema['minimum'])) {
      $field['#min'] = $schema['minimum'];
      $isFloat = $isFloat || is_float($schema['minimum']);
    }

    if (isset($schema['maximum'])) {
      $field['#max'] = $schema['maximum'];
      $isFloat = $isFloat || is_float($schema['maximum']);
    }

    if (isset($schema['x-unit'])) {
      $field['#field_suffix'] = ' ' . $this->t($schema['x-unit']);
    }

    $field['#step'] = $isFloat ? 'any' : 1;
    return $field;
  }

  /**
   * Configures object field as a fieldset with nested properties.
   */
  protected function configureObjectField(array $field, array $schema, string $key): array {
    $field['#type'] = self::FORM_FIELDSET;

    if (!empty($schema['properties'])) {
      $requiredFields = $schema['required'] ?? [];
      $this->buildFormElementsFromProperties($field, $schema['properties'], $key, $requiredFields);
    }

    return $field;
  }

  /**
   * Configures array field with repeatable item structure.
   *
   * Currently supports complex arrays with object items. Simple arrays
   * may need additional implementation based on specific use cases.
   */
  protected function configureArrayField(array $field, array $schema, string $key): array {
    $field['#type'] = self::FORM_FIELDSET;
    $field['#title'] = $this->getElementTitle($schema, $key);
    $field['#tree'] = TRUE;

    $isComplexArray = $this->isComplexArraySchema($schema);

    if ($isComplexArray) {
      $this->buildComplexArrayStructure($field, $key, $schema);
    }
    else {
      $field['#markup'] = $this->t('Array field type is defined, but the "items" definition is missing or unsupported.');
    }

    return $field;
  }

  /**
   * Determines if array schema represents a complex structure.
   */
  private function isComplexArraySchema(array $schema): bool {
    return isset($schema['items']) &&
      (
        (isset($schema['items']['type']) && $schema['items']['type'] === self::SCHEMA_TYPE_OBJECT) ||
        isset($schema['items']['allOf']) ||
        isset($schema['items']['oneOf'])
      );
  }

  /**
   * Builds complex array structure with item wrapper and add more functionality.
   */
  private function buildComplexArrayStructure(array &$field, string $key, array $schema): void {
    $itemsSchema = $schema['items'];

    $field['items_wrapper'] = [
      '#type' => self::FORM_CONTAINER,
      '#prefix' => '<div id="' . $key . '-add-more-wrapper">',
      '#suffix' => '</div>',
      '#array_parents' => [$key],
    ];

    // Start with one initial item.
    $this->addArrayItemToForm($field['items_wrapper'], 0, $key, $itemsSchema);

    $this->addArrayActions($field, $key);
  }

  /**
   * Adds action buttons for array management (Add More, Remove).
   */
  private function addArrayActions(array &$field, string $key): void {
    $field['actions'] = [
      '#type' => 'actions',
      'add_item' => [
        '#type' => 'submit',
        '#value' => $this->t('Add @title Item', ['@title' => $field['#title']]),
        '#name' => $key . '_add_more',
        // TODO: Implement AJAX functionality for dynamic adding
        '#attributes' => ['disabled' => 'disabled'],
      ],
    ];

    $field['#markup'] = $this->t('Array structure initialized with one item. Dynamic "Add More" functionality requires AJAX setup in the parent form.');
    $field['#required'] = FALSE;
  }

  /**
   * Adds a single array item to the form.
   */
  protected function addArrayItemToForm(array &$form, int $index, string $parentKey, array $itemsSchema): void {
    $itemKey = $parentKey . '_item_' . $index;

    $form[$itemKey] = [
      '#type' => 'div',
    ];

    if (isset($itemsSchema['allOf'])) {
      $this->buildArrayItemAllOf($form[$itemKey], $itemKey, $itemsSchema, $parentKey, $index);
    }
    elseif (isset($itemsSchema['oneOf'])) {
      $this->buildArrayItemOneOf($form[$itemKey], $itemKey, $itemsSchema);
    }
    elseif (isset($itemsSchema['properties'])) {
      $this->buildArrayItemProperties($form[$itemKey], $itemKey, $itemsSchema);
    }
  }

  /**
   * Builds array item with allOf schema.
   */
  private function buildArrayItemAllOf(array &$itemForm, string $itemKey, array $itemsSchema, string $parentKey, int $index): void {
    $optionKey = $itemsSchema['x-metadata']['slug'] ?? $itemKey . '_allOf';

    if (empty($itemsSchema['x-metadata']['name'])) {
      $itemsSchema['x-metadata']['name'] = $this->getBaseKey($parentKey) . ' ' . ($index + 1);
    }

    $this->buildAllOfElement($itemForm, $optionKey, $itemsSchema);
  }

  /**
   * Builds array item with oneOf schema.
   */
  private function buildArrayItemOneOf(array &$itemForm, string $itemKey, array $itemsSchema): void {
    $oneOfKey = $itemsSchema['x-metadata']['slug'] ?? $itemKey . '_oneof';
    $this->buildConditionalSelectionElement($itemForm, $oneOfKey, $itemsSchema);
  }

  /**
   * Builds array item with simple properties.
   */
  private function buildArrayItemProperties(array &$itemForm, string $itemKey, array $itemsSchema): void {
    $requiredFields = $itemsSchema['required'] ?? [];
    $this->buildFormElementsFromProperties($itemForm, $itemsSchema['properties'], $itemKey, $requiredFields);
  }

  /**
   * Helper Methods
   */

  /**
   * Builds full key for nested elements using double underscore separator.
   */
  private function buildFullKey(?string $parentKey, string $currentKey): string {
    return $parentKey ? "{$parentKey}__{$currentKey}" : $currentKey;
  }

  /**
   * Extracts the last part of a nested key for display purposes.
   */
  private function getBaseKey(string $key): string {
    $parts = explode('__', $key);
    return end($parts);
  }

  /**
   * Gets display title for form element from schema or key.
   */
  private function getElementTitle(array $schema, string $key): string {
    if (isset($schema['x-metadata']['name'])) {
      return $schema['x-metadata']['name'];
    }

    if (isset($schema['title'])) {
      return $schema['title'];
    }

    return $this->convertKeyToTitle($this->getBaseKey($key));
  }

  /**
   * Marks form element as required with visual indicator.
   */
  private function markElementAsRequired(array &$element, bool $hasRequiredFields, array $schema): void {
    if ($hasRequiredFields || !empty($schema['#required'])) {
      $element['#title'] .= ' *';
      $elementClass = $element['#type'] === self::FORM_DETAILS ? 'required-details' : 'required-fieldset';
      $element['#attributes']['class'][] = $elementClass;
    }
  }

  /**
   * Converts camelCase or snake_case key to human-readable title.
   */
  private function convertKeyToTitle(string $key): string {
    $title = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $key);
    $title = str_replace('_', ' ', $title);
    return ucwords($title);
  }
}
