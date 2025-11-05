<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for duplicating a single CFP assessment.
 */
class DuplicateAssessmentConfirmForm extends ConfirmFormBase {

  /**
   * The source log entity.
   *
   * @var \Drupal\log\Entity\LogInterface
   */
  protected $sourceLog;

  /**
   * Constructs a new DuplicateAssessmentConfirmForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The tempstore factory.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AccountInterface $currentUser
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_cfp_duplicate_assessment_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to duplicate this CFP assessment?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $source_log = $this->getSourceLog();
    if ($source_log) {
      return $this->t('This will create a new CFP assessment form pre-filled with data from "%name". You can review and modify the data before submitting.', [
        '%name' => $source_log->label(),
      ]);
    }
    return $this->t('This will create a new CFP assessment form pre-filled with data from the original assessment.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Duplicate Assessment');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.log.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $source_log = $this->getSourceLog();

    if (!$source_log) {
      $this->messenger()->addError($this->t('Source assessment not found.'));
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    // Display source assessment details.
    $form['source_assessment'] = [
      '#type' => 'details',
      '#title' => $this->t('Source Assessment Details'),
      '#open' => TRUE,
    ];

    $form['source_assessment']['name'] = [
      '#markup' => $this->t('<strong>Name:</strong> @name', ['@name' => $source_log->label()]),
    ];

    $form['source_assessment']['pathway'] = [
      '#markup' => $this->t('<strong>Pathway:</strong> @pathway', [
        '@pathway' => $source_log->get('metadata')->value ?: $this->t('Not specified'),
      ]),
    ];

    $form['source_assessment']['date'] = [
      '#markup' => $this->t('<strong>Original date:</strong> @date', [
        '@date' => \Drupal::service('date.formatter')->format($source_log->get('timestamp')->value),
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $source_log = $this->getSourceLog();

    if (!$source_log) {
      $this->messenger()->addError($this->t('Source assessment not found.'));
      return;
    }

    // Store the source log ID in tempstore for the assessment form.
    $tempstore = $this->tempStoreFactory->get('farm_cfp_duplicate');
    $tempstore->set('source_log_id', $source_log->id());

    // Clear the action tempstore.
    $action_tempstore = $this->tempStoreFactory->get('farm_cfp_duplicate_assessment');
    $action_tempstore->delete($this->currentUser->id());

    // Redirect to the assessment form with the source parameter.
    $form_state->setRedirect('farm_cfp.assessment_form', [], [
      'query' => ['source' => $source_log->id()],
    ]);

    $this->messenger()->addStatus($this->t('Creating new assessment from "%name".', [
      '%name' => $source_log->label(),
    ]));
  }

  /**
   * Gets the source log entity.
   *
   * @return \Drupal\log\Entity\LogInterface|null
   *   The source log entity, or NULL if not found.
   */
  protected function getSourceLog() {
    if (!isset($this->sourceLog)) {
      $tempstore = $this->tempStoreFactory->get('farm_cfp_duplicate_assessment');
      $log_id = $tempstore->get($this->currentUser->id());

      if ($log_id) {
        $log_storage = $this->entityTypeManager->getStorage('log');
        $this->sourceLog = $log_storage->load($log_id);
      }
    }

    return $this->sourceLog;
  }

}
