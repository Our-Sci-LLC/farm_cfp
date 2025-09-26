<?php

namespace Drupal\farm_cfp\Form;


use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\farm_cfp\Service\CfpApiService;
use Drupal\farm_cfp\Service\CfpFormProcessor;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating and submitting a CFP assessment.
 */
class AssessmentForm extends FormBase {

  /**
   * The CFP API service.
   *
   * @var \Drupal\farm_cfp\Service\CfpApiService
   */
  protected $cfpApiService;

  /**
   * The CFP form builder service.
   *
   * @var \Drupal\farm_cfp\Service\CfpFormProcessor
   */
  protected $cfpFormProcessor;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new AssessmentLogForm.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, CfpApiService $cfp_api_service, CfpFormProcessor $cfp_form_processor) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cfpApiService = $cfp_api_service;
    $this->cfpFormProcessor = $cfp_form_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('farm_cfp.api'),
      $container->get('farm_cfp.form_processor'),
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
      '#options' => $this->pathwayAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_pathway'),
    ];


    $form['cfp_climate'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Climate'),
      '#options' => $this->climateAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('cfp_climate'),
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

    $term_id = $form_state->get('cfp_pathway');
    $pathway = Term::load($term_id)->label();
    $schema = $this->cfpApiService->fetchPathway($pathway);

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
      ->set('cfp_climate', $form_state->getValue('cfp_climate'))
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
    if (!$plant_id) return;

    $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);

    if ($plant->get('farm')->isEmpty()) {
      $edit_url = Url::fromRoute('entity.asset.edit_form', ['asset' => $plant_id]);
      $link = Link::fromTextAndUrl($this->t('Edit plant'), $edit_url)->toString();
      $form_state->setErrorByName('plant',
        $this->t('The plant must be assigned to a farm. Please @link to assign a farm.', ['@link' => $link]));
      return;
    }

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

  }

  /**
   * Allowed values callback for CFP Pathway Type field.
   */
  private function pathwayAllowedValues() {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'cfp_pathway']);

    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Allowed values callback for CFP Climate field.
   */
  private function climateAllowedValues() {
    $options = [];

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'cfp_climate']);

    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Builds the submission JSON for the selected schema.
   *
   * @return array The sample submission JSON.
   */
  private function buildSubmissionData(FormStateInterface $form_state): array {

    $farm_details = [
      'country' => 'Bermuda',
      'latitude' => 32.3078,
      'longitude' => -64.7505,
      'climate' => $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->load($form_state->get('cfp_climate'))
        ->label(),
      'annualAverageTemperature' => [
        'value' => 20,
        'unit' => '°C',
      ],
    ];
    $input_data = $this->cfpFormProcessor->extractFormData($form_state->get('schema'), $form_state);

    return [
      'name' => $form_state->get('name'),
      'pathway' => $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->load($form_state->get('cfp_pathway'))
        ->label(),
      'farmDetails' => $farm_details,
      'inputData' => $input_data,
    ];
  }

  /**
   * Helper function to get term labels from term IDs.
   *
   * @param array $term_ids
   *   An array of taxonomy term IDs.
   *
   * @return array
   *   An array of term labels.
   */
  protected function getTermLabels(array $term_ids): array {
    $labels = [];
    foreach ($term_ids as $tid) {
      if ($term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) {
        $labels[] = $term->label();
      }
    }
    return $labels;
  }

}
