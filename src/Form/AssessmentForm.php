<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\farm_cfp\Service\CfpApiService;
use Drupal\farm_cfp\Service\CfpFormBuilder;
use Drupal\log\Form\LogForm;
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
   * @var \Drupal\farm_cfp\Service\CfpFormBuilder
   */
  protected $cfpFormBuilder;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new AssessmentLogForm.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, CfpApiService $cfp_api_service, CfpFormBuilder $cfp_form_builder) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cfpApiService = $cfp_api_service;
    $this->cfpFormBuilder = $cfp_form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('farm_cfp.api'),
      $container->get('farm_cfp.form_builder')
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

    $form['multistep_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'multistep-form-wrapper'],
    ];

    switch ($step) {
      case 1:
        $this->buildStep1Form($form['multistep_wrapper'], $form_state);
        break;
      case 2:
        $this->buildStep2Form($form['multistep_wrapper'], $form_state);
        break;
    }

    $form['#form_id'] = 'cfp_assessment_form';

    return $form;
  }

  /**
   * Build the form for the first step, selecting the plant and pathway.
   */
  protected function buildStep1Form(array &$form, FormStateInterface $form_state) {
    $form['plant'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select a Planting'),
      '#target_type' => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['plant'],
      ],
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [$this, 'plantCallback'],
        'wrapper' => 'step1-wrapper',
        'event' => 'autocompleteclose change',
      ],
    ];

    $form['step1_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'step1-wrapper'],
      'cfp_pathway' => [
        '#type' => 'select',
        '#title' => $this->t('CFP Pathway'),
        '#options' => $this::pathwayAllowedValues(),
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select -'),
        '#empty_value' => '',
      ],
      'messages' => [
        '#type' => 'markup',
        '#markup' => '',
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Assessment'),
      '#name' => 'start_assessment',
      '#ajax' => [
        'callback' => [$this, 'ajaxStartAssessment'],
        'wrapper' => 'multistep-form-wrapper',
      ],
    ];
  }

  /**
   * Ajax callback to handle the "Start Assessment" button.
   */
  public function ajaxStartAssessment(array $form, FormStateInterface $form_state) {
    // Manually run validation on the form.
    $form_state->setSubmitted();
    $this->validateForm($form, $form_state);

    if ($form_state->getErrors()) {
      return $form['multistep_wrapper'];
    }

    // Persist the pathway value for the next step.
    $selected_pathway_value = $form_state->getValue('cfp_pathway');
    $form_state->set('cfp_pathway', $selected_pathway_value);

    // Set the step to 2 and flag for rebuild.
    $form_state->set('step', 2);
    $form_state->setRebuild();

    $new_form = [];
    $new_form['multistep_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'multistep-form-wrapper'],
    ];
    $this->buildStep2Form($new_form['multistep_wrapper'], $form_state);

    return $new_form['multistep_wrapper'];
  }
  
  /**
   * Ajax callback for plant field.
   */
  public function plantCallback(array $form, FormStateInterface $form_state) {
    $plant_id = $form_state->getValue('plant');
    $wrapper = &$form['multistep_wrapper']['step1_wrapper'];

    $wrapper['messages']['#markup'] = '';

    if ($plant_id) {
      $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);
      $farm_reference = $plant->get('farm');

      $term_id = $plant->get('cfp_pathway')->value;

      $wrapper['cfp_pathway']['#value'] = $term_id;
      $form_state->setValue('cfp_pathway', $term_id);

      if ($farm_reference->isEmpty() || $this->entityTypeManager->getStorage('organization')->load($farm_reference->target_id)->get('cfp_farm_id')->isEmpty()) {
        $edit_url = Url::fromRoute('entity.asset.edit_form', ['asset' => $plant_id]);
        $link = Link::fromTextAndUrl($this->t('Edit plant'), $edit_url)->toString();
        $wrapper['messages']['#markup'] = '<div class="messages messages--error">' .
          $this->t('The plant must be assigned to a farm with a Cool Farm Platform ID. Please @link to assign a farm.',
            ['@link' => $link]) .
          '</div>';
      }
    }

    return $wrapper;
  }

  /**
   * Build the form for the second step.
   */
  protected function buildStep2Form(array &$form, FormStateInterface $form_state) {
    $plant_id = $form_state->getValue('plant');
    $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);
    $plant_name = $plant->label();

    // Retrieve the stored CFP pathway ID from the form state.
    $selected_pathway = $form_state->get('cfp_pathway');
    $pathway_label = Term::load($selected_pathway)->label();

    $form['#title'] = $this->t('Assessment for @plant', ['@plant' => $plant_name]);

    $form['pathway_info'] = [
      '#type' => 'markup',
      '#markup' => '<h4>' . $this->t('CFP Pathway: @pathway', ['@pathway' => $pathway_label]) . '</h4>',
    ];

    // Fetch the schema for the selected pathway.
    $schema = $this->cfpApiService->fetchPathway($pathway_label);

    if (!$schema) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('Failed to load schema for the selected CFP pathway.') .
          '</div>',
      ];
      return $form;
    }

    $form['cfp_fields'] = $this->cfpFormBuilder->buildFormFromSchema($schema);

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back_step',
      '#submit' => ['::backStep'],
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
   * Custom submit handler for the "Back" button.
   */
  public function backStep(array &$form, FormStateInterface $form_state) {
    $form_state->set('step', 1);
    $plant = $form_state->getValue('plant');
    $form_state->set('cfp_pathway', $plant);
    $pathway = $form_state->getValue('cfp_pathway');
    $form_state->set('cfp_pathway', $pathway);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $plant_id = $form_state->getValue('plant');

    if ($plant_id) {
      $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);

      // Check if plant has a farm assigned
      if ($plant->get('farm')->isEmpty()) {
        $edit_url = Url::fromRoute('entity.asset.edit_form', ['asset' => $plant_id]);
        $link = Link::fromTextAndUrl($this->t('Edit plant'), $edit_url)->toString();
        $form_state->setErrorByName('plant',
          $this->t('The plant must be assigned to a farm in order to run an assessment. Please @link to assign a farm.',
            ['@link' => $link]));
        return;
      }

      $farm_id = $plant->get('farm')->target_id;
      $farm = $this->entityTypeManager->getStorage('organization')->load($farm_id);

      if ($farm->get('cfp_farm_id')->isEmpty()) {
        $edit_url = Url::fromRoute('entity.organization.edit_form', ['organization' => $farm_id]);
        $link = Link::fromTextAndUrl($this->t('Edit farm'), $edit_url)->toString();

        $form_state->setErrorByName('plant',
          $this->t('The assigned farm must have a Cool Farm Platform ID. Please @link to set it.',
            ['@link' => $link]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->get('step') !== 2) {
      return;
    }

    $plant_id = $form_state->getValue('plant');
    $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);

    $cfp_data = $form_state->getValue('cfp_data');

    $api_response = $this->cfpApiService->createAndRunAssessment($cfp_data);

    if ($api_response) {
      // If the API call is successful, store the submitted data as a JSON
      // string in the log's notes field.
      $log = $this->entityTypeManager->getStorage('log')->create([
        'type' => 'observation',
        'name' => $this->t('Assessment for @plant', ['@plant' => $plant->label()]),
        'timestamp' => $form_state->getValue('assessment_date')->getTimestamp(),
        'status' => 'done',
        'notes' => $form_state->getValue('assessment_notes'),
        'asset' => [$plant_id],
        'field_assessment_flag' => TRUE,
      ]);

      $log->save();

      $this->messenger()->addStatus($this->t('CFP assessment has been submitted and saved.'));
      $form_state->setRedirectUrl($log->toUrl());

      $this->messenger()->addStatus($this->t('CFP assessment has been submitted and saved.'));
      $form_state->setRedirectUrl($log->toUrl());
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

}
