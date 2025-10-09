<?php

namespace Drupal\farm_cfp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_cfp\Service\CfpLookupService;
use Symfony\Component\DependencyInjection\ContainerInterface; // New requirement for create()
use Drupal\Core\Config\ConfigFactoryInterface; // Parent constructor argument
use Drupal\Core\Logger\LoggerChannelFactoryInterface; // Parent constructor argument

/**
 * Defines the settings form for the Cool Farm Platform module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Constructs a new SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * The logger factory.
   * @param \Drupal\farm_cfp\Service\CfpLookupService $cfpLookupService
   * The CFP lookup service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    protected CfpLookupService $cfpLookupService
  ) {
    parent::__construct($config_factory, $logger_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('farm_cfp.lookup')
    );
  }

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

    $form['default_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Default assessment values'),
      '#open' => FALSE,
    ];

    $form['default_settings']['pathway_default'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Pathway'),
      '#options' => $this->cfpLookupService->pathwayAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $config->get('pathway_default'),
      '#description' => $this->t('Select a default pathway for assessments. This can be overridden per assessment.'),
    ];

    $form['default_settings']['country_default'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Country'),
      '#options' => $this->cfpLookupService->countryAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $config->get('country_default'),
      '#description' => $this->t('Select a default country for assessments. This can be overridden per assessment.'),
    ];

    $form['default_settings']['climate_default'] = [
      '#type' => 'select',
      '#title' => $this->t('CFP Climate'),
      '#options' => $this->cfpLookupService->climateAllowedValues(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $config->get('climate_default'),
      '#description' => $this->t('Select a default climate for assessments. This can be overridden per assessment.'),
    ];

    $form['default_settings']['temperature_defaults'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Annual Average Temperature'),
    ];

    $form['default_settings']['temperature_defaults']['annual_avg_temp'] = [
      '#type' => 'number',
      '#title' => $this->t('Annual average temperature'),
      '#title_display' => 'invisible',
      '#default_value' => $config->get('annual_avg_temp'),
      '#field_suffix' => ' ',
      '#min' => -150,
      '#max' => 150,
      '#step' => 1,
      '#size' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Average annual temperature in the selected unit.'),
    ];

    $form['default_settings']['temperature_defaults']['annual_avg_temp_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Unit'),
      '#title_display' => 'invisible',
      '#options' => [
        '°C' => $this->t('ºC (Celsius)'),
        '°F' => $this->t('ºF (Fahrenheit)'),
      ],
      '#default_value' => $config->get('annual_avg_temp_unit') ?? '°C',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('farm_cfp.settings')
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('pathway_default', $form_state->getValue('pathway_default'))
      ->set('country_default', $form_state->getValue('country_default'))
      ->set('climate_default', $form_state->getValue('climate_default'))
      ->set('annual_avg_temp', $form_state->getValue('annual_avg_temp'))
      ->set('annual_avg_temp_unit', $form_state->getValue('annual_avg_temp_unit'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
