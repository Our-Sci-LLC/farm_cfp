<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the settings form for the Cool Farm Platform module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_cfp_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['farm_cfp.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('farm_cfp.settings');

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cool Farm Platform API URL'),
      '#default_value' => $config->get('api_url') ?? 'https://api.cfp.coolfarm.org',
      '#description' => $this->t('Enter the base URL for the Cool Farm Platform. This is typically <code>https://api.cfp.coolfarm.org</code>'),
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#maxlength' => 4096,
      '#title' => $this->t('Cool Farm Platform API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('The API key for the Cool Farm Platform.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the selected key ID to the module's configuration.
    $this->config('farm_cfp.settings')
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
