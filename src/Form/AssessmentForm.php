<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\farm_cfp\Service\CfpApiService;
use Drupal\farm_cfp\Service\CfpFormProcessor;
use Drupal\farm_cfp\Service\CfpLookupService;
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
    protected CfpFormProcessor $cfpFormProcessor,
    protected CfpLookupService $cfpLookupService
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('farm_cfp.api'),
      $container->get('farm_cfp.form_processor'),
      $container->get('farm_cfp.lookup')
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

    $form['farm_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Farm details'),
      '#open' => FALSE,
    ];

    $form['farm_details']['cfp_pathway'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Pathway'),
      '#options' => $this->cfpLookupService->pathwayAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_pathway') ?? $this->cfpLookupService->getDefaultPathway(),
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

      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('Failed to load schema for the selected CFP pathway. Please confirm that the @settings_link is correct.', [
            '@settings_link' => $settings_link,
          ]) .
          '</div>',
      ];
      return $form;
    }

    // Store schema in form state for use on form submit.
    $form_state->set('schema', $schema);

    $form += $this->cfpFormProcessor->buildFormFromSchema($schema);

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
    $form_state
      ->set('step', 2)
      ->set('name', $form_state->getValue('name'))
      ->set('plant', $form_state->getValue('plant'))
      ->set('cfp_pathway', $form_state->getValue('cfp_pathway'))
      ->set('cfp_country', $form_state->getValue('cfp_country'))
      ->set('cfp_climate', $form_state->getValue('cfp_climate'))
      ->set('annual_avg_temp', $form_state->getValue('annual_avg_temp'))
      ->set('annual_avg_temp_unit', $form_state->getValue('annual_avg_temp_unit'))
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
    $step = $form_state->get('step');

    if ($step == 1) {
      $this->validateStep1($form, $form_state);
    }
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
    // Get the plant's geolocation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Only process final submission on last step.
    $cfp_data = $this->buildSubmissionData($form_state);
    $api_response = $this->cfpApiService->calculateAssessment($cfp_data);

    if (!empty($api_response['resultSummary'])) {
      $log = $this->entityTypeManager->getStorage('log')->create([
        'type' => 'observation',
        'name' => $form_state->get('name'),
        'timestamp' => \Drupal::time()->getRequestTime(),
        'status' => 'done',
        'notes' => json_encode($cfp_data),
        'asset' => [$form_state->get('plant')],
        'cfp' => TRUE,
      ]);
      $log->save();

      $this->messenger()->addStatus($this->t('CFP assessment submitted and saved.'));
      $form_state->setRedirectUrl($log->toUrl());
    }
    elseif (!empty($api_response['inputDataValidationReport'])) {
      $this->getLogger('Farm CFP')->error('CFP assessment validation errors: @data', ['@data' => print_r($api_response['inputDataValidationReport'], TRUE)]);
      $this->messenger()->addError($this->t('CFP assessment validation failed. See log for details.'));
      $form_state->setRebuild(TRUE);
    }
    else {
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
      'latitude' => 32.3078,
      'longitude' => -64.7505,
      'climate' => $form_state->get('cfp_climate'),
      'annualAverageTemperature' => [
        'value' => (int) $form_state->get('annual_avg_temp'),
        'unit' => $form_state->get('annual_avg_temp_unit'),
      ],
    ];
    $input_data = $this->cfpFormProcessor->extractFormData($form_state->get('schema'), $form_state);

    return [
      'name' => $form_state->get('name'),
      'pathway' => $form_state->get('cfp_pathway'),
      'farmDetails' => $farm_details,
      'inputData' => $input_data,
    ];
  }

}
