<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\farm_cfp\Constants;
use Drupal\farm_cfp\Service\CfpApiService;
use Drupal\farm_cfp\Service\AssessmentFormProcessor;
use Drupal\farm_cfp\Service\LookupService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating and submitting a CFP assessment.
 */
class AssessmentForm extends FormBase {

  /**
   * Constructs a new AssessmentLogForm.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CfpApiService $cfpApiService,
    protected AssessmentFormProcessor $formProcessor,
    protected LookupService $cfpLookupService
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('farm_cfp.api'),
      $container->get('farm_cfp.form_processor'),
      $container->get('farm_cfp.lookup_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'assessment_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $step = $form_state->get('step') ?? 1;
    $form_state->set('step', $step);

    switch ($step) {
      case 1:
        return $this->buildStep1($form, $form_state);
      case 2:
        return $this->buildStep2($form, $form_state);
    }

    return $form;
  }

  /**
   * Build step 1: Plant and pathway selection.
   */
  private function buildStep1(array $form, FormStateInterface $form_state) {

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Assessment name'),
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('name'),
    ];

    $form['plant'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select a Planting'),
      '#target_type' => 'asset',
      '#selection_settings' => ['target_bundles' => ['plant']],
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('plant'),
    ];

    $form['cfp_pathway'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Pathway'),
      '#options' => $this->cfpLookupService->pathwayAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_pathway') ?? $this->cfpLookupService->getDefaultPathway(),
    ];

    $form['farm_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Farm details'),
      '#open' => FALSE,
    ];

    $form['farm_details']['cfp_country'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Country'),
      '#options' => $this->cfpLookupService->countryAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_country') ?? $this->cfpLookupService->getDefaultCountry(),
    ];

    $form['farm_details']['cfp_climate'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Climate'),
      '#options' => $this->cfpLookupService->climateAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_climate') ?? $this->cfpLookupService->getDefaultClimate(),
    ];

    $form['farm_details']['temperature_defaults'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Annual Average Temperature'),
    ];

    $average_temp = $this->cfpLookupService->getDefaultAverageTemperature();
    $form['farm_details']['temperature_defaults']['annual_avg_temp'] = [
      '#type' => 'number',
      '#title' => $this->t('Annual average temperature'),
      '#title_display' => 'invisible',
      '#default_value' => $form_state->getValue('annual_avg_temp') ?? $average_temp['value'],
      '#field_suffix' => ' ',
      '#min' => -150,
      '#max' => 150,
      '#step' => 1,
      '#size' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Average annual temperature in the selected unit.'),
    ];

    $form['farm_details']['temperature_defaults']['annual_avg_temp_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Unit'),
      '#title_display' => 'invisible',
      '#options' => [
        '°C' => $this->t('ºC (Celsius)'),
        '°F' => $this->t('ºF (Fahrenheit)'),
      ],
      '#default_value' => $form_state->getValue('annual_avg_temp_unit') ?? $average_temp['unit'],
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
      '#submit' => ['::submitStep1'],
    ];

    return $form;
  }

  /**
   * Build step 2: Dynamic form based on pathway.
   */
  private function buildStep2(array $form, FormStateInterface $form_state) {
    $form['assessment_name'] = [
      '#markup' => '<h3>' . $form_state->get('name') . '</h3>',
    ];

    $schema = $this->cfpApiService->fetchPathway($form_state->get('cfp_pathway'));

    if (!$schema) {
      $settings_url = Url::fromRoute('farm_cfp.settings');
      $settings_link = Link::fromTextAndUrl($this->t('CFP API key'), $settings_url)->toString();

      $this->messenger()->addError($this->t('Failed to load schema for the selected CFP pathway. Please confirm that the @settings_link is correct.', [
        '@settings_link' => $settings_link,
      ]));
      return $form;
    }

    // If we're in basic operation mode, list the inputs to be ignored.
    $mode = $this->cfpLookupService->getOperationMode();
    if ($mode === Constants::OPERATION_MODE_BASIC) {
      $schema['ignored'] = Constants::SCHEMA_IGNORE;
    }

    // Store schema in form state for use on form submit.
    $form_state->set('schema', $schema);

    $form += $this->formProcessor->buildFormFromSchema($schema, $mode);

    $form['actions']['#type'] = 'actions';
    $form['actions']['prev'] = [
      '#type' => 'submit',
      '#value' => $this->t('Previous'),
      '#submit' => ['::submitStepBack'],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Assessment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Step 1 submission handler.
   */
  public function submitStep1(array &$form, FormStateInterface $form_state) {
    $values = [
      'name',
      'plant',
      'cfp_pathway',
      'cfp_country',
      'cfp_climate',
      'annual_avg_temp',
      'annual_avg_temp_unit',
    ];

    foreach ($values as $key) {
      $form_state->set($key, $form_state->getValue($key));
    }
    $form_state
      ->set('step', 2)
      ->setRebuild(TRUE);
  }

  /**
   * Step back handler.
   */
  public function submitStepBack(array &$form, FormStateInterface $form_state) {
    $step = $form_state->get('step') - 1;
    $form_state
      ->set('step', $step)
      ->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $is_going_back = isset($triggering_element['#submit']) && in_array('::submitStepBack', $triggering_element['#submit']);

    // Skip validation if the user is going back to the previous step.
    if ($is_going_back) {
      return;
    }

    $step = $form_state->get('step');

    if ($step == 1) {
      $this->validateStep1($form, $form_state);
    }
    // Step 2 validation is handled by the CFP API on submission.
  }

  /**
   * Validate step 1.
   */
  private function validateStep1(array &$form, FormStateInterface $form_state) {
    $plant_id = $form_state->getValue('plant');
    if (!$plant_id) {
      return;
    }

    $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);

    if (!$plant || $plant->bundle() !== 'plant') {
      $form_state->setErrorByName('plant', $this->t('The selected entity is not a valid Plant asset.'));
      return;
    }

    // Store the plant's longitude and latitude in form state for submission.
    $geometry = $plant->get('geometry')->getValue();
    if (empty($geometry) || !isset($geometry[0]['lon']) || !isset($geometry[0]['lat'])) {
      $form_state->setErrorByName('plant', $this->t('The selected Plant does not have valid longitude and latitude.'));
      return;
    }

    $form_state->set('longitude', $geometry[0]['lon']);
    $form_state->set('latitude', $geometry[0]['lat']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cfp_data = $this->buildSubmissionData($form_state);
    $api_response = $this->cfpApiService->calculateAssessment($cfp_data);

    if (!empty($api_response['resultSummary'])) {
      $log_storage = $this->entityTypeManager->getStorage('log');
      $quantity_storage = $this->entityTypeManager->getStorage('quantity');
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

      $term = $term_storage->loadByProperties(['vid' => 'unit', 'name' => Constants::GHG_UNIT_KGCO2E]);
      $unit = reset($term);
      if (!$unit) {
        $this->getLogger('Farm CFP')->critical('Missing required taxonomy term for unit: @unit_name', ['@unit_name' => Constants::GHG_UNIT_KGCO2E]);
        $this->messenger()->addError($this->t('A required unit of measure is missing. Contact the site administrator.'));
        return;
      }

      $quantity_references = [];
      $co2e_results = $api_response['resultSummary']['assessmentYear']['CO2eq'] ?? [];

      // Create and save all quantity entities first.
      if (!empty($co2e_results)) {
        foreach ($co2e_results as $key => $value) {
          $quantity = $quantity_storage->create([
            'type' => 'standard',
            'measure' => 'weight',
            'value' => (float) $value,
            'units' => ['target_id' => $unit->id()],
            'label' => ucfirst($key),
          ]);

          $quantity->save();
          $quantity_references[] = $quantity;
        }
      }

      if (empty($quantity_references)) {
        $this->getLogger('Farm CFP')->error('No CO2eq results found in API response for assessment: @name', ['@name' => $form_state->get('name')]);
        $this->messenger()->addError($this->t('No CO2eq results were returned from the CFP assessment.'));
        return;
      }

      // Create and save the calculation log, and assign quantity references.
      $log = $log_storage->create([
        'type' => 'calculation',
        'calculation_type' => Constants::GHG_CALCULATION,
        'name' => $form_state->get('name'),
        'timestamp' => \Drupal::time()->getRequestTime(),
        'status' => 'done',
        'data_sent' => json_encode($cfp_data),
        'data_received' => json_encode($api_response),
        'asset' => [$form_state->get('plant')],
        'cfp' => TRUE,
        'calculation_year' => $form_state->getValue('cropDetails__assessmentYear'),
        'metadata' => $form_state->get('cfp_pathway'),
        'quantity' => $quantity_references,
      ]);
      $log->save();

      $this->messenger()->addStatus($this->t('CFP assessment submitted and saved.'));
      $form_state->setRedirectUrl($log->toUrl());
    }
    elseif (!empty($api_response['inputDataValidationReport'])) {
      $report_data = $api_response['inputDataValidationReport'] ?? $api_response;
      $this->getLogger('Farm CFP')->error('CFP assessment validation failed. Data sent @data_sent Data received @data_received', ['@data_sent' => json_encode($cfp_data), '@data_received' => json_encode($report_data)]);

      if (isset($report_data['userInput'])) {
        foreach ($report_data['userInput'] as $input_key => $input_report) {
          if (!empty($input_report)) {
            foreach ($input_report as $report_item) {
              $message = $report_item['message'] ?? 'Unknown validation error';
              $context = $report_item['field'] ?? $report_item['location'] ?? '';
              if (!empty($context)) {
                $message .= ' (' . $context . ')';
              }
              $this->messenger()->addError($this->t('@message', ['@message' => $message]));
            }
          }
        }
      }
      else {
        $this->messenger()->addError($this->t('CFP assessment validation failed. See log for details.'));
      }
      $form_state->set('step', 2);
      $form_state->setRebuild(TRUE);
    }
    else {
      $this->getLogger('Farm CFP')->error('CFP assessment submission failed due to unknown API error. Response: @data', ['@data' => json_encode($api_response)]);
      $this->messenger()->addError($this->t('CFP assessment submission failed. See log for details.'));
      $form_state->set('step', 1);
      $form_state->setRebuild(TRUE);
    }

  }

  /**
   * Builds the submission JSON for the selected schema.
   *
   * @return array The sample submission JSON.
   */
  private function buildSubmissionData(FormStateInterface $form_state): array {

    $farm_details = [
      'country' => $form_state->get('cfp_country'),
      'latitude' => $form_state->get('latitude'),
      'longitude' => $form_state->get('longitude'),
      'climate' => $form_state->get('cfp_climate'),
      'annualAverageTemperature' => [
        'value' => (int) $form_state->get('annual_avg_temp'),
        'unit' => $form_state->get('annual_avg_temp_unit'),
      ],
    ];
    $input_data = $this->formProcessor->extractFormData($form_state->get('schema'), $form_state);

    return [
      'name' => $form_state->get('name'),
      'pathway' => $form_state->get('cfp_pathway'),
      'farmDetails' => $farm_details,
      'inputData' => $input_data,
    ];
  }

}
