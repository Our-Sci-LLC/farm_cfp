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
        'wrapper' => 'assessment-params-wrapper',
        'event' => 'autocompleteclose change',
      ],
    ];
    
    $form['assessment_params_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'assessment-params-wrapper'],
    ];

    $form['assessment_params_wrapper']['plant_name'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $form_state->getValue('plant') . ' ' . $this->t('Assessment') . '</h3>',
    ];

    $form['assessment_params_wrapper']['cfp_pathway'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Pathway'),
      '#options' => $this::pathwayAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#ajax' => [
        'callback' => [$this, 'pathwayCallback'],
        'wrapper' => 'pathway-params-wrapper',
        'event' => 'change select',
      ],
    ];

    $form['pathway_params_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'pathway-params-wrapper'],
    ];

    $term_id = $form_state->getValue('cfp_pathway');
    if (!empty($term_id)) {
      $form['pathway_params_wrapper']['params'] = $this->buildPathwayFields($term_id);
    }

    $form['assessment_params_wrapper']['farm_id'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Assessment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Ajax callback for plant field.
   */
  public function plantCallback(array $form, FormStateInterface $form_state) {
    $plant_id = $form_state->getValue('plant');
    $wrapper = &$form['assessment_params_wrapper'];

    unset($wrapper['error']);

    if ($plant_id) {
      $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);
      $plant_name = $plant->label();
      $wrapper['plant_name']['#markup'] = '<h3>' . $plant_name . ' ' . $this->t('Assessment') . '</h3>';

      $farm_reference = $plant->get('farm');
      if ($farm_reference->isEmpty()) {
        $edit_url = Url::fromRoute('entity.asset.edit_form', ['asset' => $plant_id]);
        $link = Link::fromTextAndUrl($this->t('Edit plant'), $edit_url)->toString();
        $wrapper['error'] = [
          '#markup' => '<div class="messages messages--error">' .
            $this->t('The plant must be assigned to a farm in order to run an assessment. Please @link to assign a farm.',
              ['@link' => $link]) .
            '</div>',
        ];
        return $wrapper;
      }

      $farm_id = $farm_reference->target_id;
      $farm = $this->entityTypeManager->getStorage('organization')->load($farm_id);

      if ($farm->get('cfp_farm_id')->isEmpty()) {
        $edit_url = Url::fromRoute('entity.organization.edit_form', ['organization' => $farm_id]);
        $link = Link::fromTextAndUrl($this->t('Edit farm'), $edit_url)->toString();
        $wrapper['error'] = [
          '#markup' => '<div class="messages messages--error">' .
            $this->t('The assigned farm must have a Cool Farm Platform ID. Please @link to set it.',
              ['@link' => $link]) .
            '</div>',
        ];
        return $wrapper;
      }

      $wrapper['farm_id']['#value'] = $farm->get('cfp_farm_id')->value;

      $term_id = $plant->get('cfp_pathway')->value;
      $wrapper['cfp_pathway']['#value'] = $term_id;
      $form_state->setValue('cfp_pathway', $term_id);
    }

    return $wrapper;
  }

  /**
   * Ajax callback for pathway field.
   */
  public function pathwayCallback(array $form, FormStateInterface $form_state) {
    return $form['pathway_params_wrapper'];
  }

  /**
   * Build the pathway-specific fields.
   */
  private function buildPathwayFields($term_id) {
    $pathway = $term_id ? Term::load($term_id)->label() : NULL;
    $wrapper = &$form['assessment_params_wrapper'];

    unset($wrapper['error']);

    if ($pathway) {
      $schema = $this->cfpApiService->fetchPathway($pathway);
      if (!$schema) {
        $wrapper['error'] = [
          '#markup' => '<div class="messages messages--error">' .
            $this->t('Failed to load schema for the selected CFP pathway.') .
            '</div>',
        ];
        return $wrapper;
      }

      $wrapper = $this->cfpFormBuilder->buildFormFromSchema($schema);
    }

    return $wrapper;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()) {
      // Ignore errors when changing plant or pathway to allow re-building
      // fields.
      if ($form_state->hasAnyErrors()) {
        $form_state->clearErrors();
      }
    }

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
    $plant_id = $form_state->getValue('plant');
    $plant = $this->entityTypeManager->getStorage('asset')->load($plant_id);

    $cfp_data = $this->cfpFormBuilder->buildSubmissionData($form_state);

    $api_response = $this->cfpApiService->createAssessment($cfp_data);

    if ($api_response) {
      // If the API call is successful, store the submitted data as a JSON
      // string in the log's notes field.
      $log = $this->entityTypeManager->getStorage('log')->create([
        'type' => 'observation',
        'name' => $this->t('Assessment for @plant', ['@plant' => $plant->label()]),
        'timestamp' => \Drupal::time()->getRequestTime(),
        'status' => 'done',
        'notes' => $form_state->getValue('assessment_notes'),
        'asset' => [$plant_id],
        'field_assessment_flag' => TRUE,
      ]);

      $log->save();

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
